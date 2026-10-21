<?php

namespace CallStats\Tests\Integration;

use CallStats\Build\AgentOutcome;
use CallStats\Build\CallRecord;
use CallStats\Report\OperatorJournal;
use CallStats\Report\Period;
use CallStats\Store\CallRepository;

class OperatorJournalTest extends DbTestCase
{
    private function period(): Period
    {
        date_default_timezone_set('Asia/Tashkent');
        return Period::fromRequest(['preset' => 'custom', 'from' => '2026-10-18', 'to' => '2026-10-18'], strtotime('2026-10-18 20:00:00'));
    }

    private function journal(): OperatorJournal
    {
        return new OperatorJournal($this->db);
    }

    /** @param AgentOutcome[] $agents */
    private function call(string $key, string $kind, ?string $queue, string $start, array $agents, array $extra = []): CallRecord
    {
        $c = new CallRecord();
        $c->callKey = $key;
        $c->kind = $kind;
        $c->queue = $queue;
        $c->startedAt = $start;
        $c->endedAt = $start;
        $c->clientNumber = '+998901112233';
        $c->agents = $agents;
        foreach ($extra as $k => $v) {
            $c->$k = $v;
        }
        (new CallRepository($this->db))->replace($c);
        return $c;
    }

    private function types(array $items): array
    {
        return array_map(function (array $i): string {
            return $i['type'];
        }, $items);
    }

    public function test_events_are_chronological_and_typed(): void
    {
        $this->db->exec("INSERT INTO queues (queue, name) VALUES ('1000', 'Bananza')");
        $this->db->exec("INSERT INTO agent_sessions (extension, queue, start_at, end_at) VALUES ('1004', '1000', '2026-10-18 09:00:00', '2026-10-18 18:00:00')");
        $this->db->exec("INSERT INTO agent_pauses (extension, queue, start_at, end_at, reason) VALUES ('1004', '1000', '2026-10-18 10:30:00', '2026-10-18 10:45:00', 'Обед')");
        $this->call('k1', 'queue', '1000', '2026-10-18 10:40:00', [
            new AgentOutcome('1004', 'in', 'answered', 12, 195, '2026-10-18 10:40:00', '2026-10-18 10:40:12'),
        ], ['recordingFile' => 'a.wav', 'sources' => ['cdr' => ['u1'], 'qlog' => ['line <b>']]]);
        $this->call('k2', 'outbound', null, '2026-10-18 11:00:00', [
            new AgentOutcome('1004', 'out', 'not_connected', 20, 0, '2026-10-18 11:00:00', null),
        ]);
        // other operator and other day: must not appear
        $this->call('k3', 'direct', null, '2026-10-18 11:10:00', [
            new AgentOutcome('1005', 'in', 'answered', 1, 5, '2026-10-18 11:10:00', '2026-10-18 11:10:01'),
        ]);
        $this->call('k4', 'direct', null, '2026-10-19 11:10:00', [
            new AgentOutcome('1004', 'in', 'answered', 1, 5, '2026-10-19 11:10:00', '2026-10-19 11:10:01'),
        ]);

        $items = $this->journal()->fetch('1004', $this->period());
        $this->assertSame(
            ['session_start', 'pause', 'in_answered', 'out', 'session_end'],
            $this->types($items)
        );
        $this->assertSame('Bananza', $items[0]['source']);
        $this->assertSame('Обед', $items[1]['reason']);
        $this->assertSame('2026-10-18 10:45:00', $items[1]['until']);
        $in = $items[2];
        $this->assertSame('Очередь «Bananza»', $in['source']);
        $this->assertSame(12, $in['ring_sec']);
        $this->assertSame(195, $in['talk_sec']);
        $this->assertTrue($in['has_recording']);
        $this->assertFalse($in['approx']);
        $this->assertSame('+998901112233', $in['client']);
        $this->assertIsInt($in['call_id']);
        $this->assertStringContainsString('line <b>', $in['sources']);
        $this->assertSame('Исходящий', $items[3]['source']);
        $this->assertFalse($items[3]['has_recording']);
        $this->assertSame('not_connected', $items[3]['reason']);
    }

    public function test_recording_status_and_play_cell_states(): void
    {
        $root = sys_get_temp_dir() . '/cs_jrec_' . uniqid();
        mkdir($root . '/2026/10/18', 0777, true);
        file_put_contents($root . '/2026/10/18/ok.wav', str_repeat('x', 100));
        file_put_contents($root . '/2026/10/18/empty.wav', str_repeat('x', 44));
        file_put_contents($root . '/2026/10/18/odd.gsm', str_repeat('x', 100));
        foreach (['ok.wav', 'empty.wav', 'odd.gsm', 'gone.wav'] as $i => $file) {
            $t = sprintf('2026-10-18 10:%02d:00', $i);
            $this->call('r' . $i, 'direct', null, $t, [
                new AgentOutcome('1004', 'in', 'answered', 1, 60, $t, $t),
            ], ['recordingFile' => $file]);
        }
        try {
            $items = $this->journal()->fetch('1004', $this->period());
            $items = $this->journal()->withRecordingStatus($items, new \CallStats\Web\RecordingLocator($root));
            $this->assertSame(['ok', 'empty', 'unsupported', 'missing'], array_column($items, 'rec_status'));

            $t = ['q' => '', 'sort' => 'at', 'order' => 'desc', 'per' => 100];
            $html = (new \CallStats\Web\View(dirname(__DIR__, 2) . '/templates'))->render('journal', [
                'period' => $this->period(), 'operators' => ['1004' => '1004'], 'ext' => '1004',
                'summary' => null, 'hasTimeline' => false, 't' => $t,
                'table' => \CallStats\Report\JournalTable::apply(\CallStats\Report\JournalTable::rows($items), '', 'at', 'desc', 1, 100),
            ]);
            $this->assertSame(1, substr_count($html, 'class="rp"'));
            $this->assertSame(2, substr_count($html, '&amp;dl=1'));
            $this->assertStringContainsString('запись пустая', $html);
            $this->assertStringContainsString('файл не найден', $html);
        } finally {
            foreach (glob($root . '/2026/10/18/*') as $f) {
                unlink($f);
            }
            rmdir($root . '/2026/10/18');
            rmdir($root . '/2026/10');
            rmdir($root . '/2026');
            rmdir($root);
        }
    }

    public function test_other_answered_marked_in_other(): void
    {
        $this->call('k1', 'queue', '1000', '2026-10-18 10:00:00', [
            new AgentOutcome('1004', 'in', 'other_answered', 5, 0, '2026-10-18 10:00:00', null),
            new AgentOutcome('1005', 'in', 'answered', 5, 50, '2026-10-18 10:00:00', '2026-10-18 10:00:05'),
        ]);
        $this->call('k2', 'direct', null, '2026-10-18 10:10:00', [
            new AgentOutcome('1004', 'in', 'missed', 15, 0, '2026-10-18 10:10:00', null),
        ]);
        $this->call('k3', 'direct', null, '2026-10-18 10:20:00', [
            new AgentOutcome('1004', 'in', 'caller_hangup', 4, 0, '2026-10-18 10:20:00', null),
        ]);
        $this->call('k4', 'direct', null, '2026-10-18 10:30:00', [
            new AgentOutcome('1004', 'in', 'offline', 0, 0, '2026-10-18 10:30:00', null),
        ]);
        $items = $this->journal()->fetch('1004', $this->period());
        $this->assertSame(['in_other', 'in_missed', 'in_caller_hangup', 'in_offline'], $this->types($items));
        $this->assertSame('Очередь «1000»', $items[0]['source']);
        $this->assertSame('Прямой', $items[1]['source']);
    }

    public function test_queue_source_falls_back_to_number(): void
    {
        $this->call('k1', 'queue', '1000', '2026-10-18 10:00:00', [
            new AgentOutcome('1004', 'in', 'missed', 5, 0, '2026-10-18 10:00:00', null),
        ]);
        $items = $this->journal()->fetch('1004', $this->period());
        $this->assertSame('Очередь «1000»', $items[0]['source']);
    }

    public function test_internal_and_transfers(): void
    {
        $this->call('k1', 'internal', null, '2026-10-18 10:00:00', [
            new AgentOutcome('1004', 'out', 'answered', 1, 20, '2026-10-18 10:00:00', '2026-10-18 10:00:01'),
        ]);
        $this->call('k2', 'queue', '1000', '2026-10-18 11:00:00', [
            new AgentOutcome('1004', 'in', 'answered', 1, 20, '2026-10-18 11:00:00', '2026-10-18 11:00:01'),
        ], ['transfers' => [['from' => '1004', 'to' => '1005', 'at' => '2026-10-18 11:05:00']]]);
        $this->call('k3', 'queue', '1000', '2026-10-18 12:00:00', [
            new AgentOutcome('1005', 'in', 'answered', 1, 20, '2026-10-18 12:00:00', '2026-10-18 12:00:01'),
        ], ['transfers' => [['from' => '1005', 'to' => '1004', 'at' => '2026-10-18 12:05:00']]]);
        $items = $this->journal()->fetch('1004', $this->period());
        $this->assertSame(['internal', 'in_answered', 'transfer_out', 'transfer_in'], $this->types($items));
        $this->assertSame('1005', $items[2]['reason']);
        $this->assertSame('1005', $items[3]['reason']);
    }

    public function test_internal_call_shows_other_party_from_callers_side(): void
    {
        $this->db->exec("INSERT INTO operators (extension, fpbx_name) VALUES ('1004', 'Иван'), ('1006', 'Мария')");
        // client_number = caller's own number (1004), answered_by = callee
        $this->call('k1', 'internal', null, '2026-10-18 10:00:00', [
            new AgentOutcome('1004', 'out', 'answered', 1, 20, '2026-10-18 10:00:00', '2026-10-18 10:00:01'),
        ], ['clientNumber' => '1004', 'answeredBy' => '1006']);
        // callee side: client_number = caller
        $this->call('k2', 'internal', null, '2026-10-18 11:00:00', [
            new AgentOutcome('1004', 'in', 'answered', 1, 20, '2026-10-18 11:00:00', '2026-10-18 11:00:01'),
        ], ['clientNumber' => '1006', 'answeredBy' => '1004']);
        $items = $this->journal()->fetch('1004', $this->period());
        $this->assertSame('Мария (1006)', $items[0]['client']);
        $this->assertSame('out', $items[0]['reason']);
        $this->assertSame('Мария (1006)', $items[1]['client']);
        $this->assertSame('in', $items[1]['reason']);
    }

    public function test_collapse_consecutive_offline(): void
    {
        $mk = function (string $at, string $type): array {
            return ['at' => $at, 'type' => $type, 'call_id' => 1, 'client' => 'x', 'source' => null, 'ring_sec' => 0,
                'talk_sec' => 0, 'until' => null, 'reason' => null, 'has_recording' => false, 'approx' => false, 'sources' => null];
        };
        $items = [
            $mk('2026-10-18 09:10:00', 'in_offline'),
            $mk('2026-10-18 10:00:00', 'in_offline'),
            $mk('2026-10-18 11:40:00', 'in_offline'),
            $mk('2026-10-18 12:00:00', 'in_missed'),
            $mk('2026-10-18 12:10:00', 'in_offline'),
            $mk('2026-10-19 00:10:00', 'in_offline'),
        ];
        $out = OperatorJournal::collapseOffline($items);
        $this->assertSame(['offline_group', 'in_missed', 'in_offline', 'in_offline'], $this->types($out));
        $this->assertSame(3, $out[0]['count']);
        $this->assertSame('2026-10-18 09:10:00', $out[0]['at']);
        $this->assertSame('2026-10-18 11:40:00', $out[0]['until']);
    }
}

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

            $t = ['q' => '', 'type' => '', 'noise' => '', 'sort' => 'at', 'order' => 'desc', 'per' => 100];
            $rows = \CallStats\Report\JournalTable::rows($items);
            $html = (new \CallStats\Web\View(dirname(__DIR__, 2) . '/templates'))->render('journal', [
                'period' => $this->period(), 'operators' => ['1004' => '1004'], 'ext' => '1004',
                'kpi' => null, 'kpiPrev' => null, 'timeline' => null, 't' => $t,
                'counts' => \CallStats\Report\JournalTable::counts($rows), 'allCount' => count($rows),
                'days' => \CallStats\Report\JournalTable::days($rows),
                'table' => \CallStats\Report\JournalTable::apply($rows, '', 'at', 'desc', 1, 100),
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

    public function test_normalize_number_keeps_last_nine_digits(): void
    {
        $this->assertSame('901112233', OperatorJournal::normalizeNumber('+998 (90) 111-22-33'));
        $this->assertSame('901112233', OperatorJournal::normalizeNumber('901112233'));
        $this->assertSame('1002', OperatorJournal::normalizeNumber('1002'));
        $this->assertSame('', OperatorJournal::normalizeNumber('anonymous'));
    }

    public function test_with_callbacks_finds_callback_got_through_and_none(): void
    {
        // missed #1 (09:00, +998901112233): operator 1002 calls back at 09:05, connected
        $this->call('m1', 'queue', '1000', '2026-10-18 09:00:00', [
            new AgentOutcome('1004', 'in', 'missed', 15, 0, '2026-10-18 09:00:00', null),
        ]);
        $this->call('cb1', 'outbound', null, '2026-10-18 09:05:00', [
            new AgentOutcome('1002', 'out', 'answered', 5, 40, '2026-10-18 09:05:00', '2026-10-18 09:05:05'),
        ], ['clientNumber' => '901112233']);
        // missed #2 (10:00, other number): nobody calls back, but the client gets through at 10:12, answered by 1006
        $this->call('m2', 'direct', null, '2026-10-18 10:00:00', [
            new AgentOutcome('1004', 'in', 'caller_hangup', 8, 0, '2026-10-18 10:00:00', null),
        ], ['clientNumber' => '+998935554433']);
        $this->call('in2', 'queue', '1000', '2026-10-18 10:12:00', [
            new AgentOutcome('1006', 'in', 'answered', 3, 60, '2026-10-18 10:12:00', '2026-10-18 10:12:03'),
        ], ['clientNumber' => '998935554433', 'answeredBy' => '1006']);
        // missed #3 (11:00): a callback to a different number and a callback after 24 h do not count
        $this->call('m3', 'queue', '1000', '2026-10-18 11:00:00', [
            new AgentOutcome('1004', 'in', 'missed', 15, 0, '2026-10-18 11:00:00', null),
        ], ['clientNumber' => '+998977770000']);
        $this->call('x1', 'outbound', null, '2026-10-18 11:10:00', [
            new AgentOutcome('1002', 'out', 'answered', 5, 40, '2026-10-18 11:10:00', '2026-10-18 11:10:05'),
        ], ['clientNumber' => '+998977770001']);
        $this->call('x2', 'outbound', null, '2026-10-19 11:00:01', [
            new AgentOutcome('1002', 'out', 'answered', 5, 40, '2026-10-19 11:00:01', '2026-10-19 11:00:06'),
        ], ['clientNumber' => '+998977770000']);
        // callback attempt that did not connect
        $this->call('m4', 'queue', '1000', '2026-10-18 12:00:00', [
            new AgentOutcome('1004', 'in', 'missed', 15, 0, '2026-10-18 12:00:00', null),
        ], ['clientNumber' => '+998912223344']);
        $this->call('cb4', 'outbound', null, '2026-10-18 12:00:40', [
            new AgentOutcome('1005', 'out', 'not_connected', 20, 0, '2026-10-18 12:00:40', null),
        ], ['clientNumber' => '+998912223344']);

        $items = $this->journal()->withCallbacks($this->journal()->fetch('1004', $this->period()));
        $this->assertSame(['in_missed', 'in_caller_hangup', 'in_missed', 'in_missed'], $this->types($items));
        $this->assertSame(['kind' => 'callback', 'at' => '2026-10-18 09:05:00', 'after_sec' => 300, 'ext' => '1002', 'connected' => true],
            $items[0]['callback']);
        $this->assertSame(['kind' => 'got_through', 'at' => '2026-10-18 10:12:00', 'after_sec' => 720, 'ext' => '1006', 'connected' => true],
            $items[1]['callback']);
        $this->assertSame(['kind' => 'none', 'at' => null, 'after_sec' => null, 'ext' => null, 'connected' => null],
            $items[2]['callback']);
        $this->assertSame('callback', $items[3]['callback']['kind']);
        $this->assertSame(40, $items[3]['callback']['after_sec']);
        $this->assertFalse($items[3]['callback']['connected']);
        $this->assertSame([], $this->journal()->withCallbacks([]));
    }

    public function test_client_history_matches_last_nine_digits_newest_first(): void
    {
        $this->call('h1', 'queue', '1000', '2026-10-17 09:00:00', [
            new AgentOutcome('1004', 'in', 'answered', 3, 60, '2026-10-17 09:00:00', '2026-10-17 09:00:03'),
        ], ['clientNumber' => '+998901112233', 'answeredBy' => '1004', 'talkSec' => 60, 'recordingFile' => 'a.wav']);
        $this->call('h2', 'outbound', null, '2026-10-18 10:00:00', [
            new AgentOutcome('1002', 'out', 'not_connected', 20, 0, '2026-10-18 10:00:00', null),
        ], ['clientNumber' => '901112233']);
        $this->call('h3', 'direct', null, '2026-10-18 11:00:00', [
            new AgentOutcome('1004', 'in', 'missed', 15, 0, '2026-10-18 11:00:00', null),
        ], ['clientNumber' => '998901112233']);
        $this->call('other', 'direct', null, '2026-10-18 12:00:00', [], ['clientNumber' => '+998901112234']);

        $h = $this->journal()->clientHistory('+998 90 111 22 33');
        $this->assertSame([3, 2, 1], array_column($h, 'call_id'));
        $this->assertSame('missed', $h[0]['result']);
        $this->assertSame('in', $h[0]['dir']);
        $this->assertSame('out_failed', $h[1]['result']);
        $this->assertSame('1002', $h[1]['ext']);
        $this->assertSame('out', $h[1]['dir']);
        $this->assertSame('answered', $h[2]['result']);
        $this->assertSame('1004', $h[2]['ext']);
        $this->assertSame(60, $h[2]['talk_sec']);
        $this->assertTrue($h[2]['has_recording']);
        $this->assertCount(2, $this->journal()->clientHistory('901112233', 2));
        $this->assertSame([], $this->journal()->clientHistory('%'));
    }

    public function test_fetch_all_merges_operators_with_ext(): void
    {
        $this->call('a1', 'queue', '1000', '2026-10-18 10:00:00', [
            new AgentOutcome('1004', 'in', 'answered', 1, 5, '2026-10-18 10:00:00', '2026-10-18 10:00:01'),
        ]);
        $this->call('a2', 'direct', null, '2026-10-18 09:00:00', [
            new AgentOutcome('1005', 'in', 'missed', 1, 0, '2026-10-18 09:00:00', null),
        ]);
        $this->call('a3', 'outbound', null, '2026-10-18 11:00:00', [
            new AgentOutcome('1005', 'out', 'answered', 1, 5, '2026-10-18 11:00:00', '2026-10-18 11:00:01'),
        ]);
        $items = $this->journal()->fetchAll($this->period(), ['1004', '1005']);
        $this->assertSame(['in_missed', 'in_answered', 'out'], $this->types($items));
        $this->assertSame(['1005', '1004', '1005'], array_column($items, 'ext'));
    }
}

<?php

namespace CallStats\Tests\Integration;

use CallStats\Build\AgentOutcome;
use CallStats\Build\CallRecord;
use CallStats\Report\Period;
use CallStats\Report\RecordingSearch;
use CallStats\Store\CallRepository;

class RecordingSearchTest extends DbTestCase
{
    private function period(): Period
    {
        date_default_timezone_set('Asia/Tashkent');
        return Period::fromRequest(['preset' => 'custom', 'from' => '2026-10-18', 'to' => '2026-10-18'], strtotime('2026-10-18 20:00:00'));
    }

    private function call(string $key, string $kind, ?string $queue, string $start, string $number, int $talk, ?string $file, ?string $ext): void
    {
        $c = new CallRecord();
        $c->callKey = $key;
        $c->kind = $kind;
        $c->queue = $queue;
        $c->startedAt = $start;
        $c->endedAt = $start;
        $c->clientNumber = $number;
        $c->talkSec = $talk;
        $c->recordingFile = $file;
        $c->answeredBy = $ext;
        if ($ext !== null) {
            $c->agents = [new AgentOutcome($ext, $kind === 'outbound' ? 'out' : 'in', 'answered', 5, $talk, $start, $start)];
        }
        (new CallRepository($this->db))->replace($c);
    }

    private function numbers(array $res): array
    {
        return array_map(function (array $r): string {
            return $r['client_number'];
        }, $res['rows']);
    }

    public function test_filters_by_min_duration_and_number(): void
    {
        $this->call('a', 'queue', '1000', '2026-10-18 09:00:00', '+998901110001', 1000, 'a.wav', '1004');
        $this->call('b', 'queue', '1000', '2026-10-18 10:00:00', '+998901110002', 100, 'b.wav', '1004');
        $this->call('c', 'outbound', null, '2026-10-18 11:00:00', '+998902220003', 1200, 'c.wav', '1005');
        $this->call('d', 'queue', '1000', '2026-10-18 12:00:00', '+998901110004', 2000, null, '1004');
        $this->call('e', 'queue', '1000', '2026-10-19 12:00:00', '+998901110005', 2000, 'e.wav', '1004');

        $s = new RecordingSearch($this->db);
        $all = $s->search($this->period(), null, null, null, null, null, null, 1);
        $this->assertSame(3, $all['total']);
        $this->assertSame(['+998902220003', '+998901110002', '+998901110001'], $this->numbers($all));

        $long = $s->search($this->period(), null, null, 900, null, null, null, 1);
        $this->assertSame(['+998902220003', '+998901110001'], $this->numbers($long));

        $num = $s->search($this->period(), null, '1110', 900, null, null, null, 1);
        $this->assertSame(['+998901110001'], $this->numbers($num));
        $this->assertSame(1, $num['total']);

        $short = $s->search($this->period(), null, null, null, 500, null, null, 1);
        $this->assertSame(['+998901110002'], $this->numbers($short));
    }

    public function test_huge_page_is_clamped_to_last_page(): void
    {
        $this->call('a', 'queue', '1000', '2026-10-18 09:00:00', '+998901110001', 10, 'a.wav', '1004');
        $res = (new RecordingSearch($this->db))->search($this->period(), null, null, null, null, null, null, PHP_INT_MAX);
        $this->assertSame(1, $res['page']);
        $this->assertCount(1, $res['rows']);
        $empty = (new RecordingSearch($this->db))->search($this->period(), '9999', null, null, null, null, null, PHP_INT_MAX);
        $this->assertSame(1, $empty['page']);
        $this->assertSame([], $empty['rows']);
    }

    public function test_operator_direction_queue_and_like_escaping(): void
    {
        $this->call('a', 'queue', '1000', '2026-10-18 09:00:00', '+99890111_001', 10, 'a.wav', '1004');
        $this->call('b', 'queue', '2000', '2026-10-18 10:00:00', '+998901110002', 10, 'b.wav', '1005');
        $this->call('c', 'outbound', null, '2026-10-18 11:00:00', '+998902220003', 10, 'c.wav', '1004');
        $s = new RecordingSearch($this->db);
        $p = $this->period();

        $this->assertSame(['+998902220003', '+99890111_001'], $this->numbers($s->search($p, '1004', null, null, null, null, null, 1)));
        $this->assertSame(['+998902220003'], $this->numbers($s->search($p, null, null, null, null, 'out', null, 1)));
        $this->assertSame(['+998901110002', '+99890111_001'], $this->numbers($s->search($p, null, null, null, null, 'in', null, 1)));
        $this->assertSame(['+998901110002'], $this->numbers($s->search($p, null, null, null, null, 'all', '2000', 1)));
        // "_" is literal, not a wildcard
        $this->assertSame(['+99890111_001'], $this->numbers($s->search($p, null, '1_0', null, null, null, null, 1)));
        $this->assertSame([], $this->numbers($s->search($p, null, '%', null, null, null, null, 1)));
    }

    public function test_pagination_50_per_page(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->call('k' . $i, 'queue', '1000', sprintf('2026-10-18 09:%02d:00', $i), '+9989011100' . $i, 10, 'f' . $i . '.wav', '1004');
        }
        $s = new RecordingSearch($this->db);
        $this->assertCount(50, $s->search($this->period(), null, null, null, null, null, null, 1)['rows']);
        $p2 = $s->search($this->period(), null, null, null, null, null, null, 2);
        $this->assertCount(5, $p2['rows']);
        $this->assertSame(55, $p2['total']);
    }

    public function test_sort_by_duration_and_page_size(): void
    {
        $this->call('a', 'queue', '1000', '2026-10-18 09:00:00', '+998901110001', 300, 'a.wav', '1004');
        $this->call('b', 'queue', '1000', '2026-10-18 10:00:00', '+998901110002', 100, 'b.wav', '1004');
        $this->call('c', 'queue', '1000', '2026-10-18 11:00:00', '+998901110003', 200, 'c.wav', '1004');
        $s = new RecordingSearch($this->db);

        $asc = $s->search($this->period(), null, null, null, null, null, null, 1, 'dur', 'asc', 2);
        $this->assertSame(['+998901110002', '+998901110003'], $this->numbers($asc));
        $this->assertSame(2, $asc['pages']);
        $this->assertSame(1, $asc['from']);
        $this->assertSame(2, $asc['to']);

        $oldFirst = $s->search($this->period(), null, null, null, null, null, null, 2, 'at', 'asc', 2);
        $this->assertSame(['+998901110003'], $this->numbers($oldFirst));
        $this->assertSame(3, $oldFirst['from']);
    }
}

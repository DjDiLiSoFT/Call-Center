<?php

namespace CallStats\Tests\Integration;

use CallStats\Build\AgentOutcome;
use CallStats\Build\CallRecord;
use CallStats\Report\Period;
use CallStats\Report\QueueSummaryReport;
use CallStats\Store\CallRepository;

class QueueSummaryReportTest extends DbTestCase
{
    private function period(string $from = '2026-10-18', string $to = '2026-10-18'): Period
    {
        date_default_timezone_set('Asia/Tashkent');
        return new Period('custom', $from, $to);
    }

    private function report(): QueueSummaryReport
    {
        return new QueueSummaryReport($this->db);
    }

    /** @param AgentOutcome[] $agents */
    private function call(string $key, string $kind, string $start, array $agents, array $extra = []): void
    {
        $c = new CallRecord();
        $c->callKey = $key;
        $c->kind = $kind;
        $c->queue = $kind === 'queue' ? '1000' : null;
        $c->startedAt = $start;
        $c->endedAt = $start;
        $c->clientNumber = '+998901112233';
        $c->agents = $agents;
        foreach ($extra as $k => $v) {
            $c->$k = $v;
        }
        (new CallRepository($this->db))->replace($c);
    }

    private function answered(string $key, string $start, string $ext, int $wait, string $number = '+998901112233'): void
    {
        $this->call($key, 'queue', $start, [
            new AgentOutcome($ext, 'in', 'answered', $wait, 30, $start, $start),
        ], ['answeredBy' => $ext, 'waitSec' => $wait, 'clientNumber' => $number]);
    }

    private function lost(string $key, string $start, int $wait, string $number = '+998907778899', array $rang = ['1001', '1002']): void
    {
        $agents = [];
        foreach ($rang as $ext) {
            $agents[] = new AgentOutcome($ext, 'in', 'missed', $wait, 0, $start, null);
        }
        $agents[] = new AgentOutcome('1009', 'in', 'offline', 0, 0, $start, null);
        $this->call($key, 'queue', $start, $agents, ['waitSec' => $wait, 'clientNumber' => $number]);
    }

    private function seed(): void
    {
        $this->answered('a1', '2026-10-18 09:10:00', '1001', 4);
        $this->answered('a2', '2026-10-18 09:20:00', '1002', 10);
        $this->answered('a3', '2026-10-18 10:05:00', '1001', 25, '+998905556677');
        $this->lost('l1', '2026-10-18 10:30:00', 3);                       // dropped at once, nobody called back
        $this->lost('l2', '2026-10-18 11:00:00', 40, '+998904445566');      // called back by 1002
        $this->call('o1', 'outbound', '2026-10-18 11:05:00', [
            new AgentOutcome('1002', 'out', 'answered', 3, 60, '2026-10-18 11:05:00', '2026-10-18 11:05:03'),
        ], ['clientNumber' => '904445566', 'answeredBy' => '1002']);
        $this->lost('l3', '2026-10-18 12:00:00', 8, '');                    // no number
        // not inbound client calls / outside the period
        $this->call('i1', 'internal', '2026-10-18 12:30:00', [
            new AgentOutcome('1001', 'out', 'answered', 1, 5, '2026-10-18 12:30:00', '2026-10-18 12:30:01'),
        ]);
        $this->answered('x1', '2026-10-19 09:00:00', '1001', 2);
    }

    public function test_summary_counts_waits_and_sla(): void
    {
        $this->seed();
        $s = $this->report()->fetch($this->period());
        $this->assertSame(6, $s['total']);
        $this->assertSame(3, $s['answered']);
        $this->assertSame(3, $s['lost']);
        $this->assertSame(1, $s['lost_short']);
        $this->assertSame(50.0, $s['lost_pct']);
        $this->assertSame(33.3, $s['sla_pct']);        // 2 of 6 answered within 10 s
        $this->assertSame(13, $s['avg_wait']);         // (4 + 10 + 25) / 3
        $this->assertSame(40, $s['max_wait']);
    }

    public function test_called_back_counts_lost_calls_with_a_number(): void
    {
        $this->seed();
        $s = $this->report()->fetch($this->period());
        $this->assertSame(1, $s['called_back']);
        $this->assertSame(50.0, $s['called_back_pct']);
        $this->assertCount(2, $s['lost_calls']);
        $first = $s['lost_calls'][0];                  // newest first
        $this->assertSame('+998904445566', $first['client']);
        $this->assertSame('callback', $first['callback']['kind']);
        $this->assertSame('1002', $first['callback']['ext']);
        $this->assertSame(40, $first['wait_sec']);
        $this->assertSame(['1001', '1002'], $first['rang']);
        $this->assertSame('none', $s['lost_calls'][1]['callback']['kind']);
        $this->assertSame('2026-10-18 10:30:00', $s['lost_calls'][1]['at']);
    }

    public function test_lost_calls_of_a_wider_period_are_reused(): void
    {
        $this->seed();
        $r = $this->report();
        $lost = $r->lostCalls($this->period('2026-10-17', '2026-10-19'));
        $this->assertCount(2, $lost);
        $p = $this->period();
        $p->to = '2026-10-18 10:59:59';
        $s = $r->fetch($p, $lost);
        $this->assertSame(0, $s['called_back']);
        $this->assertSame(0.0, $s['called_back_pct']);
        $this->assertCount(1, $s['lost_calls']);
    }

    public function test_called_back_only_judges_lost_calls_older_than_a_day(): void
    {
        $this->seed();
        $r = $this->report();
        // l1 (10:30) is 24 h old at 10:45 next day, l2 (11:00, called back) is not yet
        $s = $r->fetch($this->period(), null, strtotime('2026-10-19 10:45:00'));
        $this->assertSame(1, $s['called_back_base']);
        $this->assertSame(0, $s['called_back']);
        $this->assertSame(0.0, $s['called_back_pct']);
        $this->assertCount(2, $s['lost_calls'], 'the list still has every lost call');
        $s = $r->fetch($this->period(), null, strtotime('2026-10-19 11:00:00'));
        $this->assertSame(2, $s['called_back_base']);
        $this->assertSame(1, $s['called_back']);
        $this->assertSame(50.0, $s['called_back_pct']);
        $s = $r->fetch($this->period(), null, strtotime('2026-10-18 23:00:00'));
        $this->assertSame(0, $s['called_back_base']);
        $this->assertNull($s['called_back_pct']);
    }

    public function test_empty_period(): void
    {
        $s = $this->report()->fetch($this->period());
        $this->assertSame(0, $s['total']);
        $this->assertNull($s['lost_pct']);
        $this->assertNull($s['sla_pct']);
        $this->assertNull($s['avg_wait']);
        $this->assertNull($s['called_back_pct']);
        $this->assertSame([], $s['lost_calls']);
    }

    public function test_by_hour_and_by_day(): void
    {
        $this->seed();
        $this->assertSame([
            9 => ['answered' => 2, 'lost' => 0],
            10 => ['answered' => 1, 'lost' => 1],
            11 => ['answered' => 0, 'lost' => 1],
            12 => ['answered' => 0, 'lost' => 1],
        ], $this->report()->byHour($this->period()));
        $this->assertSame([
            '2026-10-17' => ['answered' => 0, 'lost' => 0],
            '2026-10-18' => ['answered' => 3, 'lost' => 3],
            '2026-10-19' => ['answered' => 1, 'lost' => 0],
        ], $this->report()->byDay($this->period('2026-10-17', '2026-10-19')));
    }

    public function test_heatmap_by_weekday_and_hour(): void
    {
        $this->seed();
        // 2026-10-18 is a Sunday (7), 2026-10-19 a Monday (1)
        $h = $this->report()->heatmap($this->period('2026-10-12', '2026-10-19'));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_keys($h));
        $this->assertSame([9 => 2, 10 => 2, 11 => 1, 12 => 1], $h[7]);
        $this->assertSame([9 => 1], $h[1]);
        $this->assertSame([], $h[3]);
    }

    public function test_wait_buckets(): void
    {
        $this->seed();
        $b = $this->report()->waitBuckets($this->period());
        $this->assertSame(['0–5' => 1, '6–10' => 1, '11–15' => 0, '16–30' => 1, '31+' => 0], $b['answered']);
        $this->assertSame(['0–5' => 1, '6–10' => 1, '11–15' => 0, '16–30' => 0, '31+' => 1], $b['lost']);
    }

    public function test_repeat_callers_merge_number_forms(): void
    {
        $this->answered('r1', '2026-10-18 09:00:00', '1001', 3, '+998901112233');
        $this->lost('r2', '2026-10-18 09:30:00', 5, '901112233');
        $this->answered('r3', '2026-10-18 10:00:00', '1002', 3, '998901112233');
        $this->answered('r4', '2026-10-18 11:00:00', '1002', 3, '+998905556677');
        $this->answered('r5', '2026-10-18 11:10:00', '1002', 3, '+998905556677');
        $rows = $this->report()->repeatCallers($this->period(), 3, 20);
        $this->assertCount(1, $rows);
        $this->assertSame('998901112233', $rows[0]['number']);  // the last seen form
        $this->assertSame(3, $rows[0]['calls']);
        $this->assertSame(2, $rows[0]['answered']);
        $this->assertSame(1, $rows[0]['lost']);
        $this->assertSame('2026-10-18 10:00:00', $rows[0]['last_at']);
        $this->assertCount(2, $this->report()->repeatCallers($this->period(), 2, 20));
        $this->assertCount(1, $this->report()->repeatCallers($this->period(), 2, 1));
    }
}

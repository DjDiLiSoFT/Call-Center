<?php

namespace CallStats\Tests\Integration;

use CallStats\Build\AgentOutcome;
use CallStats\Build\CallRecord;
use CallStats\Report\DashboardReport;
use CallStats\Report\Period;
use CallStats\Store\CallRepository;
use CallStats\Store\OperatorRepository;

class DashboardReportTest extends DbTestCase
{
    private function call(string $key, string $kind, string $start, ?string $by, int $wait, int $talk, string $client, array $agents = []): void
    {
        $c = new CallRecord();
        $c->callKey = $key;
        $c->kind = $kind;
        $c->queue = $kind === 'queue' ? '1000' : null;
        $c->startedAt = $start;
        $c->endedAt = $start;
        $c->answeredBy = $by;
        $c->waitSec = $wait;
        $c->talkSec = $talk;
        $c->clientNumber = $client;
        $c->agents = $agents;
        (new CallRepository($this->db))->replace($c);
    }

    private function seed(): void
    {
        $ans = function (string $ext, string $at): AgentOutcome {
            return new AgentOutcome($ext, 'in', 'answered', 3, 60, $at, $at);
        };
        // current period 2026-10-20..21
        $this->call('a', 'queue', '2026-10-20 09:10:00', '1001', 10, 60, '900000001', [$ans('1001', '2026-10-20 09:10:00')]);
        $this->call('b', 'queue', '2026-10-20 09:40:00', null, 50, 0, '900000001',
            [new AgentOutcome('1001', 'in', 'missed', 15, 0, '2026-10-20 09:40:00', null)]);
        $this->call('c', 'queue', '2026-10-21 14:00:00', null, 4, 0, '900000002');
        $this->call('d', 'direct', '2026-10-21 14:30:00', '1002', 2, 120, '900000001', [$ans('1002', '2026-10-21 14:30:00')]);
        $this->call('e', 'outbound', '2026-10-21 15:00:00', null, 0, 30, '900000003',
            [new AgentOutcome('1002', 'out', 'answered', 3, 30, '2026-10-21 15:00:00', '2026-10-21 15:00:03')]);
        $this->call('f', 'internal', '2026-10-21 15:10:00', '1001', 0, 5, '1002');
        // previous period 2026-10-18..19
        $this->call('p1', 'queue', '2026-10-18 10:00:00', '1001', 20, 40, '900000009', [$ans('1001', '2026-10-18 10:00:00')]);
        $this->call('p2', 'queue', '2026-10-19 10:00:00', '1001', 40, 80, '900000009', [$ans('1001', '2026-10-19 10:00:00')]);
    }

    private function report(string $from, string $to): array
    {
        date_default_timezone_set('Asia/Tashkent');
        $now = strtotime('2026-10-22 12:00:00');
        $p = Period::fromRequest(['preset' => 'custom', 'from' => $from, 'to' => $to], $now);
        return (new DashboardReport($this->db, new OperatorRepository($this->db)))->fetch($p, $now);
    }

    public function test_kpis_with_previous_period_and_daily_series(): void
    {
        $this->seed();
        $r = $this->report('2026-10-20', '2026-10-21');

        $this->assertSame('day', $r['bucket']);
        $this->assertSame(4, $r['kpi']['calls']['value']);
        $this->assertSame(2, $r['kpi']['calls']['prev']);
        $this->assertSame(2, $r['kpi']['answered']['value']);
        $this->assertSame(50.0, $r['kpi']['answer_pct']['value']);
        $this->assertSame(100.0, $r['kpi']['answer_pct']['prev']);
        $this->assertSame(6, $r['kpi']['avg_wait']['value']);    // (10 + 2) / 2
        $this->assertSame(30, $r['kpi']['avg_wait']['prev']);
        $this->assertSame(90, $r['kpi']['avg_talk']['value']);   // (60 + 120) / 2
        $this->assertSame(['2026-10-20' => 2, '2026-10-21' => 2], $r['kpi']['calls']['series']);
        $this->assertSame(['2026-10-20' => 50.0, '2026-10-21' => 50.0], $r['kpi']['answer_pct']['series']);
    }

    public function test_outcomes_types_hours_and_clients(): void
    {
        $this->seed();
        $r = $this->report('2026-10-20', '2026-10-21');

        $this->assertSame(['answered' => 2, 'missed' => 1, 'abandoned' => 1], $r['outcomes']);
        $this->assertSame(['queue' => 3, 'direct' => 1, 'outbound' => 1], $r['types']);
        $this->assertSame([9 => ['calls' => 2, 'answered' => 1], 14 => ['calls' => 2, 'answered' => 1]], $r['hours']);
        $this->assertSame('900000001', $r['clients'][0]['number']);
        $this->assertSame(3, $r['clients'][0]['calls']);
        $this->assertSame(1, $r['clients'][0]['lost']);
        $this->assertSame('2026-10-21 14:30:00', $r['clients'][0]['last_at']);
        $this->assertSame(['1001', '1002'], array_map('strval', array_column($r['operators'], 'extension')));
    }

    public function test_single_day_uses_hourly_series_and_previous_day(): void
    {
        $this->seed();
        $r = $this->report('2026-10-21', '2026-10-21');
        $this->assertSame('hour', $r['bucket']);
        $this->assertSame([14 => 2], $r['kpi']['calls']['series']);
        $this->assertSame(2, $r['kpi']['calls']['prev']);   // previous day 2026-10-20: calls a, b
    }
}

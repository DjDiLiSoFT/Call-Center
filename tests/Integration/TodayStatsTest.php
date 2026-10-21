<?php

namespace CallStats\Tests\Integration;

use CallStats\Build\AgentOutcome;
use CallStats\Build\CallRecord;
use CallStats\Report\TodayStats;
use CallStats\Store\CallRepository;

class TodayStatsTest extends DbTestCase
{
    private function call(string $key, string $kind, string $start, ?string $answeredBy, int $wait, array $agents = []): void
    {
        $c = new CallRecord();
        $c->callKey = $key;
        $c->kind = $kind;
        $c->queue = $kind === 'queue' ? '1000' : null;
        $c->startedAt = $start;
        $c->endedAt = $start;
        $c->answeredBy = $answeredBy;
        $c->waitSec = $wait;
        $c->agents = $agents;
        (new CallRepository($this->db))->replace($c);
    }

    public function test_today_totals_and_service_level(): void
    {
        $this->call('a', 'queue', '2026-10-22 09:00:00', '1001', 10,
            [new AgentOutcome('1001', 'in', 'answered', 10, 60, '2026-10-22 09:00:00', '2026-10-22 09:00:10')]);
        $this->call('b', 'queue', '2026-10-22 09:05:00', '1001', 70,
            [new AgentOutcome('1001', 'in', 'answered', 5, 60, '2026-10-22 09:06:05', '2026-10-22 09:06:10')]);
        $this->call('c', 'queue', '2026-10-22 09:10:00', null, 40);
        $this->call('d', 'direct', '2026-10-22 09:20:00', '1002', 0,
            [new AgentOutcome('1002', 'in', 'answered', 4, 30, '2026-10-22 09:20:00', '2026-10-22 09:20:04')]);
        $this->call('e', 'outbound', '2026-10-22 09:30:00', null, 0,
            [new AgentOutcome('1002', 'out', 'answered', 3, 20, '2026-10-22 09:30:00', '2026-10-22 09:30:03')]);
        $this->call('f', 'internal', '2026-10-22 09:40:00', '1001', 0,
            [new AgentOutcome('1001', 'in', 'answered', 1, 5, '2026-10-22 09:40:00', '2026-10-22 09:40:01')]);
        $this->call('old', 'queue', '2026-10-21 23:59:59', '1001', 1);

        $t = (new TodayStats($this->db))->fetch('2026-10-22 00:00:00', '2026-10-22 23:59:59', 60);

        $this->assertSame(4, $t['inbound']);
        $this->assertSame(3, $t['answered']);
        $this->assertSame(1, $t['lost']);
        $this->assertSame(75.0, $t['answer_pct']);
        $this->assertSame(27, $t['avg_wait']);       // (10 + 70 + 0) / 3 = 26.7 → 27
        $this->assertSame(50.0, $t['sl_pct']);       // answered within 60 s: a, d = 2 of 4
        $this->assertSame(1, $t['outbound']);
        $this->assertSame(['1001' => 2, '1002' => 1], $t['answered_by_agent']);
    }

    public function test_empty_day(): void
    {
        $t = (new TodayStats($this->db))->fetch('2026-10-22 00:00:00', '2026-10-22 23:59:59', 60);
        $this->assertSame(0, $t['inbound']);
        $this->assertNull($t['answer_pct']);
        $this->assertNull($t['sl_pct']);
        $this->assertSame(0, $t['avg_wait']);
        $this->assertSame([], $t['answered_by_agent']);
    }
}

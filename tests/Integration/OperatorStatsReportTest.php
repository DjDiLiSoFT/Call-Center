<?php

namespace CallStats\Tests\Integration;

use CallStats\Build\AgentOutcome;
use CallStats\Build\CallRecord;
use CallStats\Report\OperatorStatsReport;
use CallStats\Report\Period;
use CallStats\Store\CallRepository;
use CallStats\Store\OperatorRepository;

class OperatorStatsReportTest extends DbTestCase
{
    private const NOW = '2026-10-18 20:00:00';

    private function period(): Period
    {
        date_default_timezone_set('Asia/Tashkent');
        return Period::fromRequest(['preset' => 'custom', 'from' => '2026-10-18', 'to' => '2026-10-18'], strtotime(self::NOW));
    }

    private function report(): OperatorStatsReport
    {
        return new OperatorStatsReport($this->db, new OperatorRepository($this->db));
    }

    /** @param AgentOutcome[] $agents */
    private function call(string $key, string $kind, ?string $queue, string $start, array $agents, bool $approx = false): void
    {
        $c = new CallRecord();
        $c->callKey = $key;
        $c->kind = $kind;
        $c->queue = $queue;
        $c->startedAt = $start;
        $c->endedAt = $start;
        $c->approx = $approx;
        $c->agents = $agents;
        (new CallRepository($this->db))->replace($c);
    }

    private function seed(): void
    {
        $this->call('k1', 'queue', '1000', '2026-10-18 15:59:20', [
            new AgentOutcome('1004', 'in', 'answered', 3, 30, '2026-10-18 15:59:20', '2026-10-18 15:59:23'),
            new AgentOutcome('1002', 'in', 'other_answered', 3, 0, '2026-10-18 15:59:20', null),
            new AgentOutcome('1005', 'in', 'offline', 0, 0, '2026-10-18 15:59:20', null),
        ]);
        $this->call('k2', 'internal', null, '2026-10-18 16:06:54', [
            new AgentOutcome('1006', 'out', 'answered', 1, 8, '2026-10-18 16:06:54', '2026-10-18 16:06:55'),
        ]);
        $this->call('k3', 'outbound', null, '2026-10-18 17:00:00', [
            new AgentOutcome('1004', 'out', 'answered', 4, 100, '2026-10-18 17:00:00', '2026-10-18 17:00:04'),
            ]);
        $this->call('k4', 'outbound', null, '2026-10-18 17:10:00', [
            new AgentOutcome('1004', 'out', 'not_connected', 20, 0, '2026-10-18 17:10:00', null),
        ]);
        $this->call('k5', 'direct', null, '2026-10-18 18:00:00', [
            new AgentOutcome('1001', 'in', 'caller_hangup', 5, 0, '2026-10-18 18:00:00', null),
        ]);
        // outside the period
        $this->call('k6', 'direct', null, '2026-10-19 09:00:00', [
            new AgentOutcome('1007', 'in', 'answered', 2, 10, '2026-10-19 09:00:00', '2026-10-19 09:00:02'),
        ]);
    }

    private function rowsBy(array $res): array
    {
        $by = [];
        foreach ($res['rows'] as $r) {
            $by[$r['extension']] = $r;
        }
        return $by;
    }

    public function test_counts_per_operator(): void
    {
        $this->seed();
        $res = $this->report()->fetch($this->period(), null, [], strtotime(self::NOW));
        $by = $this->rowsBy($res);
        $this->assertSame(1, $by['1004']['answered']);
        $this->assertSame(130, $by['1004']['talk_sec']);
        $this->assertSame(30, $by['1004']['avg_talk_sec']);
        $this->assertEquals(3, $by['1004']['avg_ring_sec']);
        $this->assertSame(2, $by['1004']['out_total']);
        $this->assertSame(1, $by['1004']['out_connected']);
        $this->assertSame(0, $by['1004']['missed']);
        $this->assertEquals(100, $by['1004']['answer_pct']);
        $this->assertSame(0, $by['1001']['answered']);
        $this->assertSame(0, $by['1001']['missed']);
        $this->assertNull($by['1001']['answer_pct']);
        $this->assertSame(1, $res['total']['answered']);
        $this->assertSame(130, $res['total']['talk_sec']);
    }

    public function test_offline_operator_has_zero_missed(): void
    {
        $this->seed();
        $by = $this->rowsBy($this->report()->fetch($this->period(), null, [], strtotime(self::NOW)));
        $this->assertSame(0, $by['1005']['missed']);
        $this->assertSame(1, $by['1005']['offline']);
    }

    public function test_internal_calls_excluded(): void
    {
        $this->seed();
        $by = $this->rowsBy($this->report()->fetch($this->period(), null, [], strtotime(self::NOW)));
        $this->assertArrayNotHasKey('1006', $by);
    }

    public function test_no_timeline_hides_columns(): void
    {
        $this->seed();
        $res = $this->report()->fetch($this->period(), null, [], strtotime(self::NOW));
        $this->assertFalse($res['hasTimeline']);
        $by = $this->rowsBy($res);
        $this->assertNull($by['1004']['session_sec']);
        $this->assertNull($by['1004']['pause_sec']);
    }

    public function test_inactive_operator_not_listed(): void
    {
        $this->seed();
        (new OperatorRepository($this->db))->sync(['1099' => 'Никто']);
        $by = $this->rowsBy($this->report()->fetch($this->period(), null, [], strtotime(self::NOW)));
        $this->assertArrayNotHasKey('1099', $by);
        $this->assertArrayNotHasKey('1007', $by);
    }

    public function test_queue_filter_and_operator_filter(): void
    {
        $this->seed();
        $by = $this->rowsBy($this->report()->fetch($this->period(), '1000', [], strtotime(self::NOW)));
        $this->assertSame(0, $by['1004']['out_total']);
        $this->assertArrayNotHasKey('1001', $by);
        $by = $this->rowsBy($this->report()->fetch($this->period(), null, ['1005'], strtotime(self::NOW)));
        $this->assertSame(['1005'], array_map('strval', array_keys($by)));
    }

    public function test_names_use_display_name(): void
    {
        $this->seed();
        $ops = new OperatorRepository($this->db);
        $ops->sync(['1004' => 'Из FreePBX']);
        $ops->setName('1004', 'Алия');
        $by = $this->rowsBy($this->report()->fetch($this->period(), null, [], strtotime(self::NOW)));
        $this->assertSame('Алия', $by['1004']['name']);
    }

    public function test_approx_flag(): void
    {
        $this->seed();
        $this->assertFalse($this->report()->fetch($this->period(), null, [], strtotime(self::NOW))['hasApprox']);
        $this->call('k7', 'queue', '1000', '2026-10-18 10:00:00', [
            new AgentOutcome('1004', 'in', 'answered', 3, 5, '2026-10-18 10:00:00', '2026-10-18 10:00:03'),
        ], true);
        $this->assertTrue($this->report()->fetch($this->period(), null, [], strtotime(self::NOW))['hasApprox']);
    }

    public function test_overlapping_sessions_are_not_double_counted(): void
    {
        $this->seed();
        $ins = $this->db->prepare('INSERT INTO agent_sessions (extension, queue, start_at, end_at) VALUES (?,?,?,?)');
        $ins->execute(['1004', '1000', '2026-10-18 09:00:00', '2026-10-18 11:00:00']);
        $ins->execute(['1004', '1001', '2026-10-18 10:00:00', '2026-10-18 12:00:00']);
        $ins->execute(['1004', 'NONE', '2026-10-18 09:30:00', '2026-10-18 09:45:00']);
        // session started yesterday, clipped to period start
        $ins->execute(['1002', '1000', '2026-10-17 23:00:00', '2026-10-18 01:00:00']);
        // open session capped at "now" (20:00)
        $ins->execute(['1005', '1000', '2026-10-18 19:00:00', null]);
        $p = $this->db->prepare('INSERT INTO agent_pauses (extension, queue, start_at, end_at, reason) VALUES (?,?,?,?,?)');
        $p->execute(['1004', '1000', '2026-10-18 10:00:00', '2026-10-18 10:30:00', 'Обед']);
        $p->execute(['1004', '1001', '2026-10-18 10:15:00', '2026-10-18 10:45:00', 'Обед']);
        $p->execute(['1004', '1001', '2026-10-18 11:00:00', '2026-10-18 11:10:00', 'Перекур']);

        $res = $this->report()->fetch($this->period(), null, [], strtotime(self::NOW));
        $this->assertTrue($res['hasTimeline']);
        $by = $this->rowsBy($res);
        $this->assertSame(3 * 3600, $by['1004']['session_sec']);       // 09:00..12:00
        $this->assertSame(45 * 60 + 10 * 60, $by['1004']['pause_sec']); // 10:00..10:45 + 11:00..11:10
        $this->assertSame(['Обед' => 45 * 60, 'Перекур' => 600], $by['1004']['pauses_by_reason']);
        $this->assertSame(3600, $by['1002']['session_sec']);
        $this->assertSame(3600, $by['1005']['session_sec']);
        $this->assertNull($by['1001']['session_sec']);
    }

    public function test_queue_filter_limits_sessions(): void
    {
        $this->seed();
        $ins = $this->db->prepare('INSERT INTO agent_sessions (extension, queue, start_at, end_at) VALUES (?,?,?,?)');
        $ins->execute(['1004', '1000', '2026-10-18 09:00:00', '2026-10-18 10:00:00']);
        $ins->execute(['1004', '1001', '2026-10-18 10:00:00', '2026-10-18 12:00:00']);
        $by = $this->rowsBy($this->report()->fetch($this->period(), '1000', [], strtotime(self::NOW)));
        $this->assertSame(3600, $by['1004']['session_sec']);
    }
}

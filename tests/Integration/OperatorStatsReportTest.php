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
    private function call(string $key, string $kind, ?string $queue, string $start, array $agents, bool $approx = false, ?string $answeredBy = null): void
    {
        $c = new CallRecord();
        $c->answeredBy = $answeredBy;
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

        // different reason on another queue, overlapping the lunch pauses: 10:40..11:05 (union with others)
        $p->execute(['1004', '1002', '2026-10-18 10:40:00', '2026-10-18 11:05:00', 'Тренинг']);

        $res = $this->report()->fetch($this->period(), null, [], strtotime(self::NOW));
        $this->assertTrue($res['hasTimeline']);
        $by = $this->rowsBy($res);
        $this->assertSame(3 * 3600, $by['1004']['session_sec']);       // 09:00..12:00
        $this->assertSame(70 * 60, $by['1004']['pause_sec']); // union 10:00..11:10 across reasons, not the per-reason sum
        $this->assertSame(['Обед' => 45 * 60, 'Перекур' => 600, 'Тренинг' => 25 * 60], $by['1004']['pauses_by_reason']);
        $this->assertSame(70 * 60, $res['total']['pause_sec']);
        $this->assertSame(5 * 3600, $res['total']['session_sec']);
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

    public function test_missed_split_into_client_lost_and_taken_by_colleague(): void
    {
        // nobody answered: a lost client for both operators
        $this->call('m1', 'queue', '1000', '2026-10-18 10:00:00', [
            new AgentOutcome('1001', 'in', 'missed', 15, 0, '2026-10-18 10:00:00', null),
            new AgentOutcome('1002', 'in', 'missed', 15, 0, '2026-10-18 10:00:00', null),
        ]);
        // 1001 let it ring out, then 1002 took it on the next round
        $this->call('m2', 'queue', '1000', '2026-10-18 11:00:00', [
            new AgentOutcome('1001', 'in', 'missed', 15, 0, '2026-10-18 11:00:00', null),
            new AgentOutcome('1002', 'in', 'answered', 3, 40, '2026-10-18 11:00:20', '2026-10-18 11:00:23'),
        ], false, '1002');
        $res = $this->report()->fetch($this->period(), null, [], strtotime(self::NOW));
        $by = $this->rowsBy($res);
        $this->assertSame(2, $by['1001']['missed']);
        $this->assertSame(1, $by['1001']['missed_lost']);
        $this->assertSame(1, $by['1001']['missed_other']);
        $this->assertSame(1, $by['1002']['missed_lost']);
        $this->assertSame(0, $by['1002']['missed_other']);
        $this->assertEquals(0.0, $by['1001']['answer_pct']);
        $this->assertSame(2, $res['total']['missed_lost']);
        $this->assertSame(1, $res['total']['missed_other']);
    }

    public function test_busy_falls_back_to_the_active_span_of_each_day(): void
    {
        $period = Period::fromRequest(['preset' => 'custom', 'from' => '2026-10-18', 'to' => '2026-10-19'], strtotime(self::NOW));
        // day 1: 10:00 .. 10:10 (first ring .. end of the last talk), 115 s of talk
        $this->call('b1', 'queue', '1000', '2026-10-18 10:00:00', [
            new AgentOutcome('1001', 'in', 'answered', 5, 60, '2026-10-18 10:00:00', '2026-10-18 10:00:05'),
        ], false, '1001');
        $this->call('b2', 'outbound', null, '2026-10-18 10:08:00', [
            new AgentOutcome('1001', 'out', 'answered', 5, 55, '2026-10-18 10:08:00', '2026-10-18 10:09:05'),
        ]);
        // internal calls do not count as presence either
        $this->call('b6', 'internal', null, '2026-10-18 12:00:00', [
            new AgentOutcome('1001', 'out', 'answered', 1, 30, '2026-10-18 12:00:00', '2026-10-18 12:00:01'),
        ]);
        // offline rings do not count as presence
        $this->call('b3', 'queue', '1000', '2026-10-18 18:00:00', [
            new AgentOutcome('1001', 'in', 'offline', 0, 0, '2026-10-18 18:00:00', null),
        ]);
        // day 2: 09:00 .. 09:05 (a missed ring of 20 s from 09:04:40)
        $this->call('b4', 'queue', '1000', '2026-10-19 09:00:00', [
            new AgentOutcome('1001', 'in', 'answered', 0, 65, '2026-10-19 09:00:00', '2026-10-19 09:00:00'),
        ], false, '1001');
        $this->call('b5', 'queue', '1000', '2026-10-19 09:04:40', [
            new AgentOutcome('1001', 'in', 'missed', 20, 0, '2026-10-19 09:04:40', null),
        ]);
        $res = $this->report()->fetch($period, null, [], strtotime('2026-10-19 20:00:00'));
        $r = $this->rowsBy($res)['1001'];
        $this->assertSame(600 + 300, $r['active_span_sec']);
        $this->assertTrue($r['busy_approx']);
        $this->assertSame(900, $r['work_sec']);
        $this->assertSame(20.0, $r['busy_pct']);                    // 180 s talk / 900 s
        $this->assertSame(12.0, $r['calls_per_hour']);              // 2 answered + 1 out in 0.25 h
        $this->assertTrue($res['total']['busy_approx']);
        $this->assertSame(20.0, $res['total']['busy_pct']);
    }

    public function test_busy_uses_the_session_minus_pauses_when_known(): void
    {
        $this->call('s1', 'queue', '1000', '2026-10-18 10:00:00', [
            new AgentOutcome('1004', 'in', 'answered', 5, 1800, '2026-10-18 10:00:00', '2026-10-18 10:00:05'),
        ], false, '1004');
        $this->db->exec("INSERT INTO agent_sessions (extension, queue, start_at, end_at) VALUES ('1004', '1000', '2026-10-18 09:00:00', '2026-10-18 11:00:00')");
        $this->db->exec("INSERT INTO agent_pauses (extension, queue, start_at, end_at, reason) VALUES ('1004', '1000', '2026-10-18 09:00:00', '2026-10-18 10:00:00', 'Обед')");
        $r = $this->rowsBy($this->report()->fetch($this->period(), null, [], strtotime(self::NOW)))['1004'];
        $this->assertFalse($r['busy_approx']);
        $this->assertSame(3600, $r['work_sec']);
        $this->assertSame(50.0, $r['busy_pct']);
        $this->assertSame(1.0, $r['calls_per_hour']);
    }

    public function test_total_online_uses_the_same_basis_as_busy(): void
    {
        $this->call('s1', 'queue', '1000', '2026-10-18 10:00:00', [
            new AgentOutcome('1004', 'in', 'answered', 5, 1800, '2026-10-18 10:00:00', '2026-10-18 10:00:05'),
        ], false, '1004');
        // 1001 has no queue login: 10:00 .. 10:10 by its calls
        $this->call('s2', 'queue', '1000', '2026-10-18 10:00:00', [
            new AgentOutcome('1001', 'in', 'answered', 0, 600, '2026-10-18 10:00:00', '2026-10-18 10:00:00'),
        ], false, '1001');
        $this->db->exec("INSERT INTO agent_sessions (extension, queue, start_at, end_at) VALUES ('1004', '1000', '2026-10-18 09:00:00', '2026-10-18 11:00:00')");
        $this->db->exec("INSERT INTO agent_pauses (extension, queue, start_at, end_at, reason) VALUES ('1004', '1000', '2026-10-18 09:00:00', '2026-10-18 10:00:00', 'Обед')");
        $res = $this->report()->fetch($this->period(), null, [], strtotime(self::NOW));
        $by = $this->rowsBy($res);
        $this->assertSame(7200, $by['1004']['online_sec']);
        $this->assertSame(600, $by['1001']['online_sec']);
        $t = $res['total'];
        $this->assertSame(7800, $t['online_sec']);
        $this->assertSame(3600, $t['pause_sec']);
        $this->assertSame(4200, $t['work_sec']);
        $this->assertSame($t['online_sec'] - $t['pause_sec'], $t['work_sec']);
        $this->assertTrue($t['busy_approx']);

        // without approximate rows the total is exact
        $only = $this->report()->fetch($this->period(), null, ['1004'], strtotime(self::NOW))['total'];
        $this->assertFalse($only['busy_approx']);
        $this->assertSame(7200, $only['online_sec']);
    }

    public function test_one_operator_by_day_and_by_hour(): void
    {
        $this->seed();
        $this->call('d1', 'queue', '1000', '2026-10-19 09:10:00', [
            new AgentOutcome('1004', 'in', 'missed', 15, 0, '2026-10-19 09:10:00', null),
        ]);
        $this->call('d2', 'queue', '1000', '2026-10-19 09:20:00', [
            new AgentOutcome('1004', 'in', 'answered', 2, 50, '2026-10-19 09:20:00', '2026-10-19 09:20:02'),
        ], false, '1004');
        $period = Period::fromRequest(['preset' => 'custom', 'from' => '2026-10-18', 'to' => '2026-10-19'], strtotime(self::NOW));
        $days = $this->report()->operatorByDay($period, '1004');
        $this->assertSame(['2026-10-18', '2026-10-19'], array_keys($days));
        $this->assertSame(['answered' => 1, 'missed' => 0, 'out' => 2, 'talk_sec' => 130, 'avg_talk_sec' => 30, 'answer_pct' => 100.0],
            $days['2026-10-18']);
        $this->assertSame(['answered' => 1, 'missed' => 1, 'out' => 0, 'talk_sec' => 50, 'avg_talk_sec' => 50, 'answer_pct' => 50.0],
            $days['2026-10-19']);
        $this->assertSame([9 => ['answered' => 1, 'missed' => 1], 15 => ['answered' => 1, 'missed' => 0]],
            $this->report()->operatorByHour($period, '1004'));
    }

    public function test_total_sums_only_operators_shown_as_active(): void
    {
        $this->seed();
        $this->call('k9', 'queue', '1000', '2026-10-18 10:00:00', [
            new AgentOutcome('1004', 'in', 'offline', 0, 0, '2026-10-18 10:00:00', null),
            new AgentOutcome('1005', 'in', 'offline', 0, 0, '2026-10-18 10:00:00', null),
        ]);
        // 1005 has only offline rings: hidden in the inactive group, so not in Итого
        $res = $this->report()->fetch($this->period(), null, [], strtotime(self::NOW));
        $by = $this->rowsBy($res);
        $this->assertSame(2, $by['1005']['offline']);
        $this->assertSame(1, $res['total']['offline']);
        $active = array_values(array_filter($res['rows'], [\CallStats\Report\StatsInsights::class, 'isActive']));
        $this->assertSame(['1004'], array_map('strval', array_column($active, 'extension')));
        foreach (['answered', 'missed', 'offline', 'out_total', 'talk_sec'] as $k) {
            $this->assertSame(array_sum(array_column($active, $k)), $res['total'][$k], $k);
        }
    }
}

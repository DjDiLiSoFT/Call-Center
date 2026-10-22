<?php

namespace CallStats\Tests\Unit;

use CallStats\Report\OperatorStatsReport;
use PHPUnit\Framework\TestCase;

class OperatorWorkloadTest extends TestCase
{
    public function test_active_span_sums_each_day_from_first_to_last_event(): void
    {
        $this->assertSame(600 + 300, OperatorStatsReport::activeSpan([
            ['first' => '2026-10-18 10:00:00', 'last' => '2026-10-18 10:10:00'],
            ['first' => '2026-10-19 09:00:00', 'last' => '2026-10-19 09:05:00'],
        ]));
        $this->assertSame(0, OperatorStatsReport::activeSpan([]));
        $this->assertSame(0, OperatorStatsReport::activeSpan([['first' => '2026-10-18 10:00:00', 'last' => '2026-10-18 09:00:00']]));
    }

    public function test_workload_from_session_minus_pause(): void
    {
        $w = OperatorStatsReport::workload(1800, 4, 7200, 3600, 999);
        $this->assertSame(['online_sec' => 7200, 'work_sec' => 3600, 'busy_approx' => false, 'busy_pct' => 50.0, 'calls_per_hour' => 4.0], $w);
    }

    public function test_workload_falls_back_to_the_active_span(): void
    {
        $w = OperatorStatsReport::workload(180, 3, null, null, 900);
        $this->assertSame(['online_sec' => 900, 'work_sec' => 900, 'busy_approx' => true, 'busy_pct' => 20.0, 'calls_per_hour' => 12.0], $w);
        // a session of zero length is unknown too
        $this->assertTrue(OperatorStatsReport::workload(180, 3, 0, 0, 900)['busy_approx']);
    }

    public function test_workload_without_time_is_null_and_busy_is_capped(): void
    {
        $w = OperatorStatsReport::workload(0, 0, null, null, 0);
        $this->assertNull($w['busy_pct']);
        $this->assertNull($w['calls_per_hour']);
        $this->assertSame(0, $w['work_sec']);
        // a pause as long as the session leaves no working time
        $this->assertNull(OperatorStatsReport::workload(60, 1, 3600, 3600, 100)['busy_pct']);
        // overlapping talks can exceed the span: never above 100%
        $this->assertSame(100.0, OperatorStatsReport::workload(1000, 2, null, null, 600)['busy_pct']);
    }
}

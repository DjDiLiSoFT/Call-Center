<?php

namespace CallStats\Tests\Unit;

use CallStats\Report\QueueSummaryReport;
use PHPUnit\Framework\TestCase;

class QueueSummaryHelpersTest extends TestCase
{
    public function test_wait_bucket_boundaries_have_inclusive_upper_ends(): void
    {
        foreach ([0 => "0–5", 5 => "0–5", 6 => "6–10", 10 => "6–10", 11 => "11–15", 15 => "11–15", 16 => "16–30",
            30 => "16–30", 31 => "31+", 5000 => "31+", -3 => "0–5"] as $sec => $label) {
            $this->assertSame($label, QueueSummaryReport::waitBucket($sec), (string) $sec);
        }
    }

    public function test_bucket_counts_from_grouped_waits(): void
    {
        $this->assertSame(
            ["0–5" => 5, "6–10" => 0, "11–15" => 1, "16–30" => 1, "31+" => 1],
            QueueSummaryReport::bucketCounts([0 => 2, 4 => 3, 12 => 1, 30 => 1, 300 => 1])
        );
    }

    public function test_first_two_buckets_are_the_service_level(): void
    {
        $this->assertSame(10, QueueSummaryReport::SLA_SEC);
        $this->assertSame("6–10", QueueSummaryReport::waitBucket(QueueSummaryReport::SLA_SEC));
        $this->assertSame("11–15", QueueSummaryReport::waitBucket(QueueSummaryReport::SLA_SEC + 1));
    }

    public function test_group_lost_by_number_newest_first(): void
    {
        $g = QueueSummaryReport::groupLostByNumber([
            ['at' => '2026-10-21 15:25:00', 'client' => '877466717', 'wait_sec' => 4, 'rang' => ['1001', '1002']],
            ['at' => '2026-10-21 12:08:00', 'client' => '949421010', 'wait_sec' => 43, 'rang' => []],
            ['at' => '2026-10-21 11:52:00', 'client' => '+998877466717', 'wait_sec' => 9, 'rang' => ['1003']],
        ]);
        $this->assertCount(2, $g);
        $this->assertSame('877466717', $g[0]['client']);
        $this->assertSame('2026-10-21 15:25:00', $g[0]['at']);
        $this->assertSame('2026-10-21 11:52:00', $g[0]['first_at']);
        $this->assertSame(2, $g[0]['times']);
        $this->assertSame(9, $g[0]['max_wait']);
        $this->assertSame(['1001', '1002', '1003'], $g[0]['rang']);
        $this->assertSame(1, $g[1]['times']);
        $this->assertSame([], $g[1]['rang']);
    }
}

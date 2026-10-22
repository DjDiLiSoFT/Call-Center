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
}

<?php

namespace CallStats\Tests\Unit;

use CallStats\Report\QueueSummaryReport;
use PHPUnit\Framework\TestCase;

class QueueSummaryHelpersTest extends TestCase
{
    public function test_wait_bucket_boundaries(): void
    {
        $this->assertSame('0–5', QueueSummaryReport::waitBucket(0));
        $this->assertSame('0–5', QueueSummaryReport::waitBucket(4));
        $this->assertSame('5–10', QueueSummaryReport::waitBucket(5));
        $this->assertSame('5–10', QueueSummaryReport::waitBucket(9));
        $this->assertSame('10–15', QueueSummaryReport::waitBucket(10));
        $this->assertSame('15–30', QueueSummaryReport::waitBucket(29));
        $this->assertSame('30+', QueueSummaryReport::waitBucket(30));
        $this->assertSame('30+', QueueSummaryReport::waitBucket(5000));
        $this->assertSame('0–5', QueueSummaryReport::waitBucket(-3));
    }

    public function test_bucket_counts_from_grouped_waits(): void
    {
        $this->assertSame(
            ['0–5' => 5, '5–10' => 0, '10–15' => 1, '15–30' => 0, '30+' => 2],
            QueueSummaryReport::bucketCounts([0 => 2, 4 => 3, 12 => 1, 30 => 1, 300 => 1])
        );
    }
}

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

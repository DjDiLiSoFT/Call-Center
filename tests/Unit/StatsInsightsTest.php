<?php

namespace CallStats\Tests\Unit;

use CallStats\Report\StatsInsights;
use PHPUnit\Framework\TestCase;

class StatsInsightsTest extends TestCase
{
    private function row(string $ext, int $answered, int $missed, ?float $pct, int $out = 0, int $offline = 0): array
    {
        return ['extension' => $ext, 'name' => $ext, 'answered' => $answered, 'missed' => $missed, 'answer_pct' => $pct,
            'offline' => $offline, 'out_total' => $out, 'out_connected' => 0, 'talk_sec' => $answered * 60,
            'avg_talk_sec' => 60, 'avg_ring_sec' => 3, 'session_sec' => null, 'pause_sec' => null, 'pauses_by_reason' => []];
    }

    public function test_splits_inactive_ranks_and_levels(): void
    {
        $rows = [
            $this->row('1001', 240, 15, 94.1),
            $this->row('1003', 0, 4, 0.0),
            $this->row('1004', 155, 9, 94.5),
            $this->row('1002', 240, 11, 95.6),
            $this->row('1005', 0, 0, null, 0, 625),
            $this->row('1006', 0, 0, null, 3),
        ];
        $s = StatsInsights::build($rows, []);

        $this->assertSame(['1002', '1001', '1004', '1006', '1003'], array_column($s['active'], 'extension'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($s['active'], 'rank'));
        $this->assertSame(['1005'], array_column($s['inactive'], 'extension'));
        $byExt = array_column($s['active'], null, 'extension');
        $this->assertSame('good', $byExt['1001']['pct_level']);
        $this->assertSame('bad', $byExt['1003']['pct_level']);
        $this->assertNull($byExt['1006']['pct_level']);
        // average missed among operators with incoming calls = (15 + 4 + 9 + 11) / 4 = 9.75; high = > 1.5x and >= 3
        $this->assertTrue($byExt['1001']['missed_high']);
        $this->assertFalse($byExt['1002']['missed_high']);
        $this->assertFalse($byExt['1003']['missed_high']);
    }

    public function test_previous_values_by_extension(): void
    {
        $s = StatsInsights::build([$this->row('1001', 240, 15, 94.1), $this->row('1002', 10, 0, 100.0)],
            [$this->row('1001', 200, 20, 90.9)]);
        $byExt = array_column($s['active'], null, 'extension');
        $this->assertSame(200, $byExt['1001']['prev']['answered']);
        $this->assertSame(90.9, $byExt['1001']['prev']['answer_pct']);
        $this->assertNull($byExt['1002']['prev']);
    }

    public function test_pct_levels_thresholds(): void
    {
        $this->assertSame('good', StatsInsights::pctLevel(90.0));
        $this->assertSame('warn', StatsInsights::pctLevel(89.9));
        $this->assertSame('warn', StatsInsights::pctLevel(80.0));
        $this->assertSame('bad', StatsInsights::pctLevel(79.9));
        $this->assertNull(StatsInsights::pctLevel(null));
    }
}

<?php

namespace CallStats\Tests\Unit;

use CallStats\Web\Delta;
use PHPUnit\Framework\TestCase;

class DeltaTest extends TestCase
{
    public function test_relative_change_with_good_and_bad_direction(): void
    {
        $this->assertSame('<span class="dlt good" title="В прошлом периоде: 40">▲25%</span>', Delta::html('answered', 50, 40, 1));
        $this->assertSame('<span class="dlt bad" title="В прошлом периоде: 4">▲50%</span>', Delta::html('missed', 6, 4, -1));
        $this->assertSame('<span class="dlt good" title="В прошлом периоде: 4">▼50%</span>', Delta::html('missed', 2, 4, -1));
        $this->assertSame('<span class="dlt" title="В прошлом периоде: 0:01:40">▲20%</span>', Delta::html('talk_sec', 120, 100, 0));
        $this->assertSame('<span class="dlt bad" title="В прошлом периоде: 0:00:10">▲50%</span>', Delta::html('avg_ring_sec', 15, 10, -1));
    }

    public function test_answer_pct_in_percentage_points(): void
    {
        $this->assertSame('<span class="dlt bad" title="В прошлом периоде: 95,5%">▼2,5 п.п.</span>', Delta::html('answer_pct', 93.0, 95.5, 1));
    }

    public function test_no_marker_without_data_or_change(): void
    {
        $this->assertSame('', Delta::html('answered', 5, null, 1));
        $this->assertSame('', Delta::html('answered', null, 5, 1));
        $this->assertSame('', Delta::html('answered', 5, 0, 1));
        $this->assertSame('', Delta::html('answered', 5, 5, 1));
        $this->assertSame('', Delta::html('answer_pct', 90.0, 90.0, 1));
    }
}

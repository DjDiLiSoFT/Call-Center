<?php

namespace CallStats\Tests\Unit;

use CallStats\Report\Period;
use CallStats\Web\Format;
use PHPUnit\Framework\TestCase;

class PeriodTest extends TestCase
{
    private $now;

    protected function setUp(): void
    {
        date_default_timezone_set('Asia/Tashkent');
        $this->now = mktime(12, 0, 0, 10, 21, 2026); // Wednesday
    }

    public function test_default_is_today(): void
    {
        $p = Period::fromRequest([], $this->now);
        $this->assertSame('today', $p->preset);
        $this->assertSame('2026-10-21 00:00:00', $p->from);
        $this->assertSame('2026-10-21 23:59:59', $p->to);
    }

    public function test_yesterday(): void
    {
        $p = Period::fromRequest(['preset' => 'yesterday'], $this->now);
        $this->assertSame('2026-10-20 00:00:00', $p->from);
        $this->assertSame('2026-10-20 23:59:59', $p->to);
    }

    public function test_this_week_starts_on_monday(): void
    {
        $p = Period::fromRequest(['preset' => 'this_week'], $this->now);
        $this->assertSame('2026-10-19 00:00:00', $p->from);
        $this->assertSame('2026-10-25 23:59:59', $p->to);
    }

    public function test_last_week(): void
    {
        $p = Period::fromRequest(['preset' => 'last_week'], $this->now);
        $this->assertSame('2026-10-12 00:00:00', $p->from);
        $this->assertSame('2026-10-18 23:59:59', $p->to);
    }

    public function test_this_month(): void
    {
        $p = Period::fromRequest(['preset' => 'this_month'], $this->now);
        $this->assertSame('2026-10-01 00:00:00', $p->from);
        $this->assertSame('2026-10-31 23:59:59', $p->to);
    }

    public function test_last_month(): void
    {
        $p = Period::fromRequest(['preset' => 'last_month'], $this->now);
        $this->assertSame('2026-09-01 00:00:00', $p->from);
        $this->assertSame('2026-09-30 23:59:59', $p->to);
    }

    public function test_custom_inclusive_and_swapped(): void
    {
        $p = Period::fromRequest(['preset' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-03'], $this->now);
        $this->assertSame('custom', $p->preset);
        $this->assertSame('2026-10-03 00:00:00', $p->from);
        $this->assertSame('2026-10-05 23:59:59', $p->to);
    }

    public function test_bad_input_falls_back_to_today(): void
    {
        $this->assertSame('today', Period::fromRequest(['preset' => 'zzz'], $this->now)->preset);
        $this->assertSame('today', Period::fromRequest(['preset' => 'custom', 'from' => 'x', 'to' => 'y'], $this->now)->preset);
        $this->assertSame('today', Period::fromRequest(['preset' => ['a']], $this->now)->preset);
    }

    public function test_duration_format(): void
    {
        $this->assertSame('1:05:09', Format::duration(3909));
        $this->assertSame('0:05:09', Format::duration(309));
        $this->assertSame('0:00:00', Format::duration(0));
    }
}

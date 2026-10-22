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

    public function test_previous_of_running_period_is_cut_to_the_same_elapsed_time(): void
    {
        $prev = Period::fromRequest([], $this->now)->previous($this->now); // today 12:00
        $this->assertSame('2026-10-20 00:00:00', $prev->from);
        $this->assertSame('2026-10-20 12:00:00', $prev->to);

        $week = Period::fromRequest(['preset' => 'this_week'], $this->now)->previous($this->now); // Mon 19 .. Wed 12:00
        $this->assertSame('2026-10-12 00:00:00', $week->from);
        $this->assertSame('2026-10-14 12:00:00', $week->to);
    }

    public function test_previous_of_finished_period_has_the_same_length(): void
    {
        $prev = Period::fromRequest(['preset' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-13'], $this->now)
            ->previous($this->now);
        $this->assertSame('2026-10-06 00:00:00', $prev->from);
        $this->assertSame('2026-10-09 23:59:59', $prev->to);
        $this->assertSame('2026-10-06', $prev->fromDate());
    }

    public function test_half_month_is_1_to_15_or_16_to_end(): void
    {
        $first = Period::fromRequest(['preset' => 'half_month'], mktime(9, 0, 0, 10, 10, 2026));
        $this->assertSame('2026-10-01 00:00:00', $first->from);
        $this->assertSame('2026-10-15 23:59:59', $first->to);

        $second = Period::fromRequest(['preset' => 'half_month'], $this->now); // 21 Oct
        $this->assertSame('2026-10-16 00:00:00', $second->from);
        $this->assertSame('2026-10-31 23:59:59', $second->to);

        $feb = Period::fromRequest(['preset' => 'half_month'], mktime(9, 0, 0, 2, 20, 2026));
        $this->assertSame('2026-02-28 23:59:59', $feb->to);
        $this->assertArrayHasKey('half_month', Period::PRESETS);
        $this->assertSame(['today', 'yesterday', 'half_month'], array_slice(array_keys(Period::PRESETS), 0, 3));
    }

    public function test_previous_of_half_month_is_the_previous_half(): void
    {
        $at = mktime(12, 0, 0, 10, 10, 2026); // 1..15 Oct running, 9.5 days elapsed
        $prev = Period::fromRequest(['preset' => 'half_month'], $at)->previous($at);
        $this->assertSame('2026-09-16 00:00:00', $prev->from);
        $this->assertSame('2026-09-25 12:00:00', $prev->to);

        $done = mktime(12, 0, 0, 11, 2, 2026);
        $prevDone = Period::fromRequest(['preset' => 'half_month'], mktime(9, 0, 0, 10, 20, 2026))->previous($done);
        $this->assertSame('2026-10-01 00:00:00', $prevDone->from);
        $this->assertSame('2026-10-15 23:59:59', $prevDone->to);
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
    public function test_days_counts_calendar_days_inclusive(): void
    {
        $this->assertSame(1, (new Period('custom', '2026-10-20', '2026-10-20'))->days());
        $this->assertSame(4, (new Period('custom', '2026-10-18', '2026-10-21'))->days());
        $this->assertSame(31, (new Period('custom', '2026-03-01', '2026-03-31'))->days());
    }
}

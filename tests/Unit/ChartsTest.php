<?php

namespace CallStats\Tests\Unit;

use CallStats\Web\Charts;
use PHPUnit\Framework\TestCase;

class ChartsTest extends TestCase
{
    public function test_donut_has_one_arc_per_nonzero_part_with_escaped_tooltips(): void
    {
        $svg = Charts::donut([
            ['label' => 'Принято <b>', 'value' => 3, 'color' => 'var(--series-1)'],
            ['label' => 'Пусто', 'value' => 0, 'color' => 'var(--series-2)'],
            ['label' => 'Сбросили', 'value' => 1, 'color' => 'var(--series-3)'],
        ], 'звонков');
        $this->assertSame(2, substr_count($svg, '<path'));
        $this->assertStringContainsString('Принято &lt;b&gt;: 3 (75%)', $svg);
        $this->assertStringNotContainsString('<b>', $svg);
        $this->assertStringContainsString('>4<', $svg, 'total in the centre');
    }

    public function test_donut_single_part_is_a_full_ring_and_empty_is_placeholder(): void
    {
        $full = Charts::donut([['label' => 'A', 'value' => 5, 'color' => 'x']], '');
        $this->assertStringContainsString('<circle', $full);
        $this->assertStringContainsString('Нет данных', Charts::donut([['label' => 'A', 'value' => 0, 'color' => 'x']], ''));
    }

    public function test_sparkline_skips_nulls_and_has_hit_targets(): void
    {
        $svg = Charts::sparkline(['2026-10-20' => 2, '2026-10-21' => null, '2026-10-22' => 5], function ($v) {
            return $v . ' шт';
        });
        $this->assertStringContainsString('<polyline', $svg);
        $this->assertSame(2, substr_count($svg, 'data-tip='));
        $this->assertStringContainsString('22.10: 5 шт', $svg);
    }

    public function test_hour_bars_scale_to_max_and_label_hours(): void
    {
        $svg = Charts::hourBars([9 => ['calls' => 10, 'answered' => 8], 10 => ['calls' => 5, 'answered' => 5]]);
        $this->assertSame(2, substr_count($svg, 'class="bar"'));
        $this->assertStringContainsString('09:00–10:00: 10 звонков, отвечено 8 (80%)', $svg);
        $this->assertStringContainsString('>09<', $svg);
    }
}

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

    private const SERIES = ['answered' => ['Обслужено', 'var(--accent)'], 'lost' => ['Потеряно <i>', 'var(--danger)']];

    private function assertWellFormed(string $svg): void
    {
        $doc = new \DOMDocument();
        $this->assertTrue(@$doc->loadXML('<root>' . $svg . '</root>'), 'well-formed markup');
    }

    public function test_hour_range_trims_empty_hours_but_keeps_the_working_day(): void
    {
        $this->assertSame([8, 20], Charts::hourRange([]));
        $this->assertSame([8, 20], Charts::hourRange([10 => 3, 12 => 0]));
        $this->assertSame([6, 22], Charts::hourRange([6 => 1, 22 => 2, 23 => 0]));
    }

    public function test_stacked_hour_bars_have_tips_legend_and_escaping(): void
    {
        $svg = Charts::stackedBars([9 => ['answered' => 20, 'lost' => 3], 11 => ['answered' => 0, 'lost' => 1]], self::SERIES);
        $this->assertWellFormed($svg);
        $this->assertSame(13, substr_count($svg, 'data-tip='), 'one hit target per hour 08..20');
        $this->assertStringContainsString('09:00–10:00: всего 23 · Обслужено 20 · Потеряно &lt;i&gt; 3', $svg);
        $this->assertSame(3, substr_count($svg, 'class="bar '), 'zero segments are not drawn');
        $this->assertStringContainsString('fill="var(--danger)"', $svg);
        $this->assertStringContainsString('class="chart-legend"', $svg);
        $this->assertStringNotContainsString('<i>', $svg);
        $this->assertStringContainsString('>23<', $svg, 'y-axis maximum');
    }

    public function test_stacked_day_bars_label_days(): void
    {
        $svg = Charts::stackedBars(['2026-10-20' => ['answered' => 5, 'lost' => 1], '2026-10-21' => ['answered' => 0, 'lost' => 0]],
            self::SERIES, 'day');
        $this->assertWellFormed($svg);
        $this->assertStringContainsString('Вт 20.10: всего 6', $svg);
        $this->assertStringContainsString('Ср 21.10: всего 0', $svg);
        $this->assertStringContainsString('>20.10<', $svg);
        $this->assertStringContainsString('Нет данных', Charts::stackedBars(['2026-10-20' => ['answered' => 0, 'lost' => 0]], self::SERIES, 'day'));
    }

    public function test_heat_levels(): void
    {
        $this->assertSame(0, Charts::heatLevel(0, 10));
        $this->assertSame(1, Charts::heatLevel(1, 100));
        $this->assertSame(3, Charts::heatLevel(5, 10));
        $this->assertSame(5, Charts::heatLevel(10, 10));
        $this->assertSame(0, Charts::heatLevel(0, 0));
    }

    public function test_heatmap_rows_cells_and_tips(): void
    {
        $grid = array_fill_keys(range(1, 7), []);
        $grid[2] = [14 => 23, 9 => 1];
        $grid[7] = [21 => 4];
        $svg = Charts::heatmap($grid);
        $this->assertWellFormed($svg);
        $this->assertStringContainsString('Вт 14:00 — 23 звонка', $svg);
        $this->assertStringContainsString('Пн 10:00 — 0 звонков', $svg);
        $this->assertStringContainsString('Вс 21:00 — 4 звонка', $svg);
        $this->assertSame(7 * 14, substr_count($svg, 'data-tip='), '7 days x hours 08..21');
        foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $d) {
            $this->assertStringContainsString('>' . $d . '<', $svg);
        }
    }

    public function test_wait_bars_group_answered_and_lost_by_bucket(): void
    {
        $svg = Charts::waitBars([
            'answered' => ['0–5' => 10, '5–10' => 5, '10–15' => 0, '15–30' => 0, '30+' => 1],
            'lost' => ['0–5' => 2, '5–10' => 0, '10–15' => 0, '15–30' => 0, '30+' => 3],
        ]);
        $this->assertWellFormed($svg);
        $this->assertSame(10, substr_count($svg, 'data-tip='));
        $this->assertStringContainsString('Ответили за 0–5 сек: 10 (63%)', $svg);
        $this->assertStringContainsString('Положили трубку, прождав 30+ сек: 3 (60%)', $svg);
        $this->assertSame(5, substr_count($svg, 'class="bar '));
        $this->assertStringContainsString('class="chart-legend"', $svg);
        $this->assertStringContainsString('Нет данных', Charts::waitBars(['answered' => ['0–5' => 0], 'lost' => ['0–5' => 0]]));
    }
}

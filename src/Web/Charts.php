<?php

namespace CallStats\Web;

/**
 * Small server-rendered SVG charts (no JS library, no CDN). Every mark carries data-tip for the
 * shared hover tooltip in app.js; colours come from CSS custom properties (--series-N).
 * Mark specs: 2px lines, >= 8px markers, 4px rounded bar ends on the baseline, 2px surface gaps.
 */
class Charts
{
    /**
     * Single-series trend line. Keys: 'Y-m-d' (shown as d.m) or hour int (shown as HH:00).
     * Null values are gaps (not plotted).
     *
     * @param array<int|string, int|float|null> $values
     * @param callable $fmt value => display string
     */
    public static function sparkline(array $values, callable $fmt): string
    {
        $w = 160;
        $h = 36;
        $pad = 5;
        $nums = array_filter($values, function ($v) {
            return $v !== null;
        });
        if (!$nums) {
            return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true"></svg>';
        }
        $min = min($nums);
        $max = max($nums);
        $span = $max - $min ?: 1;
        $n = count($values);
        $step = $n > 1 ? ($w - 2 * $pad) / ($n - 1) : 0;
        $pts = [];
        $hits = '';
        $i = 0;
        $last = null;
        foreach ($values as $key => $v) {
            $x = $n > 1 ? $pad + $i * $step : $w / 2;
            if ($v !== null) {
                $y = $h - $pad - ($v - $min) / $span * ($h - 2 * $pad);
                $pts[] = round($x, 1) . ',' . round($y, 1);
                $last = [$x, $y];
                $hw = max($step, 8) / 2;
                $hits .= '<rect class="hit" x="' . round($x - $hw, 1) . '" y="0" width="' . round(2 * $hw, 1)
                    . '" height="' . $h . '" data-tip="' . View::e(self::keyLabel($key) . ': ' . $fmt($v)) . '"/>';
            }
            $i++;
        }
        return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img">'
            . '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="var(--series-1)" stroke-width="2"'
            . ' stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>'
            . '<circle cx="' . round($last[0], 1) . '" cy="' . round($last[1], 1) . '" r="4" fill="var(--series-1)"'
            . ' stroke="var(--surface)" stroke-width="2"/>'
            . $hits . '</svg>';
    }

    /**
     * Ring chart of parts of a whole; the total is written in the centre.
     *
     * @param array<int, array{label:string, value:int, color:string}> $parts
     */
    public static function donut(array $parts, string $unit): string
    {
        $size = 150;
        $c = $size / 2;
        $r = 56;
        $stroke = 22;
        $total = 0;
        foreach ($parts as $p) {
            $total += max(0, (int) $p['value']);
        }
        if ($total === 0) {
            return '<div class="donut-empty muted">Нет данных</div>';
        }
        $nonzero = array_values(array_filter($parts, function ($p) {
            return (int) $p['value'] > 0;
        }));
        $svg = '<svg class="donut" viewBox="0 0 ' . $size . ' ' . $size . '" role="img">';
        if (count($nonzero) === 1) {
            $p = $nonzero[0];
            $svg .= '<circle cx="' . $c . '" cy="' . $c . '" r="' . $r . '" fill="none" stroke="' . View::e($p['color'])
                . '" stroke-width="' . $stroke . '" data-tip="' . View::e(self::partTip($p, $total)) . '"/>';
        } else {
            $gap = 2 / $r; // 2px surface gap between segments, in radians
            $a = -M_PI / 2;
            foreach ($nonzero as $p) {
                $sweep = 2 * M_PI * (int) $p['value'] / $total;
                $a1 = $a + $gap / 2;
                $a2 = $a + max($sweep - $gap / 2, $gap / 2 + 0.001);
                $large = ($a2 - $a1) > M_PI ? 1 : 0;
                $svg .= '<path d="M ' . round($c + $r * cos($a1), 2) . ' ' . round($c + $r * sin($a1), 2)
                    . ' A ' . $r . ' ' . $r . ' 0 ' . $large . ' 1 ' . round($c + $r * cos($a2), 2) . ' '
                    . round($c + $r * sin($a2), 2) . '" fill="none" stroke="' . View::e($p['color'])
                    . '" stroke-width="' . $stroke . '" data-tip="' . View::e(self::partTip($p, $total)) . '"/>';
                $a += $sweep;
            }
        }
        $svg .= '<text x="' . $c . '" y="' . ($c - 2) . '" text-anchor="middle" class="donut-total">' . $total . '</text>'
            . '<text x="' . $c . '" y="' . ($c + 16) . '" text-anchor="middle" class="donut-unit">' . View::e($unit) . '</text>'
            . '</svg>';
        return $svg;
    }

    /**
     * Calls per hour as vertical bars (one series), hours from the first to the last with calls.
     *
     * @param array<int, array{calls:int, answered:int}> $hours
     */
    public static function hourBars(array $hours): string
    {
        if (!$hours) {
            return '<div class="donut-empty muted">Нет данных</div>';
        }
        $from = min(array_keys($hours));
        $to = max(array_keys($hours));
        $max = 0;
        foreach ($hours as $v) {
            $max = max($max, $v['calls']);
        }
        $n = $to - $from + 1;
        $w = 440; // fixed drawing width so tick labels keep a readable size at any hour count
        $slot = $w / $n;
        $plot = 120;
        $h = $plot + 18;
        $svg = '<svg class="bars" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">'
            . '<line x1="0" y1="' . $plot . '" x2="' . $w . '" y2="' . $plot . '" class="axis"/>';
        for ($hr = $from; $hr <= $to; $hr++) {
            $v = $hours[$hr] ?? ['calls' => 0, 'answered' => 0];
            $x = ($hr - $from) * $slot;
            $bw = $slot - 2; // 2px gap between adjacent bars
            $bh = $max > 0 ? $v['calls'] / $max * ($plot - 4) : 0;
            $pct = $v['calls'] > 0 ? round($v['answered'] * 100 / $v['calls']) : 0;
            $tip = sprintf('%02d:00–%02d:00: %d %s, отвечено %d (%d%%)', $hr, $hr + 1, $v['calls'],
                self::plural($v['calls'], 'звонок', 'звонка', 'звонков'), $v['answered'], $pct);
            if ($bh > 0) {
                $svg .= '<path class="bar" d="' . self::roundTopBar($x + 1, $plot, $bw, $bh) . '" fill="var(--series-1)"/>';
            }
            $svg .= '<rect class="hit" x="' . $x . '" y="0" width="' . $slot . '" height="' . $plot . '" data-tip="' . View::e($tip) . '"/>'
                . '<text x="' . ($x + $slot / 2) . '" y="' . ($h - 4) . '" text-anchor="middle" class="tick">'
                . sprintf('%02d', $hr) . '</text>';
        }
        return $svg . '</svg>';
    }

    private const STRIP_COLORS = [
        'talk' => 'var(--accent)', 'pause' => '#9333ea', 'missed' => 'var(--danger)', 'offline' => '#9aa5b1',
    ];

    /**
     * One operator's day as a horizontal strip (DayTimeline::build() result): hour ticks, coloured
     * segments with data-tip, and a legend below.
     */
    public static function dayStrip(array $timeline): string
    {
        $fromH = (int) $timeline['from_h'];
        $toH = max($fromH + 1, (int) $timeline['to_h']);
        // The strip stretches to the container width (preserveAspectRatio none), so hour labels are HTML, not SVG text.
        $w = 1000;
        $top = 0;
        $barH = 24;
        $span = ($toH - $fromH) * 60;
        $x = function (float $minute) use ($fromH, $span, $w): float {
            return round(max(0, min($w, ($minute - $fromH * 60) / $span * $w)), 2);
        };
        $svg = '<svg class="strip" viewBox="0 0 ' . $w . ' ' . $barH . '" preserveAspectRatio="none" role="img" aria-label="Шкала дня">'
            . '<rect x="0" y="0" width="' . $w . '" height="' . $barH . '" class="strip-bg"/>';
        $hours = '<div class="strip-hours" aria-hidden="true">';
        for ($hr = $fromH; $hr <= $toH; $hr++) {
            $hx = $x($hr * 60);
            $svg .= '<line x1="' . $hx . '" y1="0" x2="' . $hx . '" y2="' . $barH . '" class="grid" vector-effect="non-scaling-stroke"/>';
            $hours .= '<span class="tick" style="left:' . round($hx / $w * 100, 3) . '%">' . sprintf('%02d', $hr) . '</span>';
        }
        $hours .= '</div>';
        // pauses and offline runs underneath, then talks, then missed marks on top
        $layer = ['pause' => 0, 'offline' => 0, 'talk' => 1, 'missed' => 2];
        $segs = $timeline['segments'];
        usort($segs, function (array $a, array $b) use ($layer): int {
            return [$layer[$a['kind']] ?? 0, $a['from']] <=> [$layer[$b['kind']] ?? 0, $b['from']];
        });
        foreach ($segs as $s) {
            if (!isset(self::STRIP_COLORS[$s['kind']])) {
                continue;
            }
            $x1 = $x((float) $s['from']);
            $x2 = $x((float) $s['to']);
            $sw = max(2, $x2 - $x1); // keep short calls visible
            $svg .= '<rect x="' . $x1 . '" y="' . $top . '" width="' . round($sw, 2) . '" height="' . $barH
                . '" fill="' . self::STRIP_COLORS[$s['kind']] . '" class="seg seg-' . View::e($s['kind'])
                . '" data-tip="' . View::e($s['tip']) . '"/>';
        }
        $svg .= '</svg>';
        $legend = '<ul class="strip-legend">';
        foreach (['talk' => 'разговор', 'pause' => 'пауза', 'missed' => 'пропущен', 'offline' => 'не в сети'] as $k => $label) {
            $legend .= '<li><span class="sw" style="background:' . self::STRIP_COLORS[$k] . '"></span>' . $label . '</li>';
        }
        return '<div class="day-strip">' . $svg . $hours . $legend . '</ul></div>';
    }

    private const WEEKDAYS = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

    /**
     * Hours to draw: from the first to the last hour with calls, but never narrower than $min..$max.
     *
     * @param array<int, int|array> $hours hour => count (or => [series => count])
     * @return array{0:int, 1:int}
     */
    public static function hourRange(array $hours, int $min = 8, int $max = 20): array
    {
        $from = $min;
        $to = $max;
        foreach ($hours as $h => $v) {
            $n = is_array($v) ? array_sum($v) : (int) $v;
            if ($n > 0) {
                $from = min($from, (int) $h);
                $to = max($to, (int) $h);
            }
        }
        return [$from, $to];
    }

    /**
     * Stacked vertical bars, one per hour ($keys 'hour': int keys, range by hourRange()) or per day
     * ('day': 'Y-m-d' keys, as given). Series are stacked bottom-up in $series order; one y-axis
     * (0 and the maximum), a legend when there are two or more series.
     *
     * @param array<int|string, array<string,int>> $data key => [series => count]
     * @param array<string, array{0:string, 1:string}> $series series => [label, colour]
     */
    public static function stackedBars(array $data, array $series, string $keys = 'hour'): string
    {
        if ($keys === 'hour') {
            list($from, $to) = self::hourRange($data);
            $slots = [];
            for ($h = $from; $h <= $to; $h++) {
                $slots[$h] = $data[$h] ?? [];
            }
        } else {
            $slots = $data;
        }
        $max = 0;
        foreach ($slots as $v) {
            $max = max($max, array_sum(array_intersect_key($v, $series)));
        }
        if ($max === 0) {
            return '<div class="donut-empty muted">Нет данных</div>';
        }
        $n = count($slots);
        $axisW = 28;
        $w = 560;
        $plotW = $w - $axisW;
        $slot = $plotW / $n;
        $plot = 130;
        $h = $plot + 18;
        $every = (int) ceil($n / ($keys === 'hour' ? 24 : 16)); // keep tick labels from overlapping
        $svg = '<svg class="bars" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">'
            . self::yAxis($axisW, $w, $plot, $max);
        $i = 0;
        foreach ($slots as $key => $v) {
            $x = $axisW + $i * $slot;
            $bw = max(1, $slot - 2);
            $base = $plot;
            $total = 0;
            $parts = [];
            $topKey = null;
            foreach ($series as $sk => $s) {
                if ((int) ($v[$sk] ?? 0) > 0) {
                    $topKey = $sk;
                }
            }
            foreach ($series as $sk => $s) {
                $cnt = (int) ($v[$sk] ?? 0);
                $total += $cnt;
                $parts[] = $s[0] . ' ' . $cnt;
                if ($cnt <= 0) {
                    continue;
                }
                $bh = $cnt / $max * ($plot - 6);
                $cls = 'bar bar-' . View::e($sk);
                if ($sk === $topKey) { // only the top of the stack gets the rounded corners
                    $svg .= '<path class="' . $cls . '" d="' . self::roundTopBar(round($x + 1, 2), round($base, 2), round($bw, 2), round($bh, 2))
                        . '" fill="' . View::e($s[1]) . '"/>';
                } else {
                    $svg .= '<rect class="' . $cls . '" x="' . round($x + 1, 2) . '" y="' . round($base - $bh, 2)
                        . '" width="' . round($bw, 2) . '" height="' . round($bh, 2) . '" fill="' . View::e($s[1]) . '"/>';
                }
                $base -= $bh;
            }
            if ($keys === 'hour') {
                $label = sprintf('%02d', $key);
                $tipKey = sprintf('%02d:00–%02d:00', $key, $key + 1);
            } else {
                $t = strtotime((string) $key);
                $label = date('d.m', $t);
                $tipKey = self::WEEKDAYS[(int) date('N', $t)] . ' ' . $label;
            }
            $svg .= '<rect class="hit" x="' . round($x, 2) . '" y="0" width="' . round($slot, 2) . '" height="' . $plot
                . '" data-tip="' . View::e($tipKey . ': всего ' . $total . ' · ' . implode(' · ', $parts)) . '"/>';
            if ($i % $every === 0) {
                $svg .= '<text x="' . round($x + $slot / 2, 2) . '" y="' . ($h - 4) . '" text-anchor="middle" class="tick">'
                    . View::e($label) . '</text>';
            }
            $i++;
        }
        return '<div class="chart">' . $svg . '</svg>' . self::legend($series) . '</div>';
    }

    /** Sequential level 0..5 of $v against $max (0 only for no calls). */
    public static function heatLevel(int $v, int $max): int
    {
        if ($v <= 0 || $max <= 0) {
            return 0;
        }
        return max(1, min(5, (int) ceil($v / $max * 5)));
    }

    /**
     * Weekday × hour grid of inbound calls, one hue from light to dark.
     *
     * @param array<int, array<int,int>> $grid weekday 1..7 => [hour => calls]
     */
    public static function heatmap(array $grid): string
    {
        $all = [];
        $max = 0;
        foreach ($grid as $hours) {
            foreach ($hours as $hr => $n) {
                $all[$hr] = ($all[$hr] ?? 0) + $n;
                $max = max($max, (int) $n);
            }
        }
        list($from, $to) = self::hourRange($all);
        $labelW = 26;
        $cell = 24;
        $gap = 2;
        $cols = $to - $from + 1;
        $w = $labelW + $cols * $cell;
        $h = 7 * $cell + 16;
        $svg = '<svg class="heat" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Звонки по дням недели и часам">';
        foreach (self::WEEKDAYS as $dow => $name) {
            $y = ($dow - 1) * $cell;
            $svg .= '<text x="0" y="' . ($y + $cell / 2 + 4) . '" class="tick">' . $name . '</text>';
            for ($hr = $from; $hr <= $to; $hr++) {
                $n = (int) ($grid[$dow][$hr] ?? 0);
                $lvl = self::heatLevel($n, $max);
                $svg .= '<rect class="heat-cell lvl-' . $lvl . '" x="' . ($labelW + ($hr - $from) * $cell) . '" y="' . $y
                    . '" width="' . ($cell - $gap) . '" height="' . ($cell - $gap) . '" rx="3"'
                    . ' data-tip="' . View::e(sprintf('%s %02d:00 — %d %s', $name, $hr, $n, self::plural($n, 'звонок', 'звонка', 'звонков'))) . '"/>';
            }
        }
        for ($hr = $from; $hr <= $to; $hr++) {
            $svg .= '<text x="' . ($labelW + ($hr - $from) * $cell + ($cell - $gap) / 2) . '" y="' . ($h - 2)
                . '" text-anchor="middle" class="tick">' . sprintf('%02d', $hr) . '</text>';
        }
        $scale = '<ul class="chart-legend heat-scale"><li>меньше</li>';
        for ($l = 0; $l <= 5; $l++) {
            $scale .= '<li><span class="sw heat-cell lvl-' . $l . '"></span></li>';
        }
        $scale .= '<li>больше' . ($max > 0 ? ' (до ' . $max . ')' : '') . '</li></ul>';
        return '<div class="chart">' . $svg . '</svg>' . $scale . '</div>';
    }

    /**
     * Two bars per wait bucket: answered calls by the wait until the answer, lost calls by the wait
     * before the client hung up.
     *
     * @param array{answered: array<string,int>, lost: array<string,int>} $buckets QueueSummaryReport::waitBuckets()
     */
    public static function waitBars(array $buckets): string
    {
        $series = [
            'answered' => ['Ответили за', 'var(--accent)', 'Ответили'],
            'lost' => ['Положили трубку, прождав', 'var(--danger)', 'Положили трубку'],
        ];
        $labels = array_keys($buckets['answered'] + $buckets['lost']);
        $max = 0;
        $sum = [];
        foreach ($series as $sk => $s) {
            $sum[$sk] = array_sum($buckets[$sk]);
            $max = max($max, $buckets[$sk] ? max($buckets[$sk]) : 0);
        }
        if ($max === 0) {
            return '<div class="donut-empty muted">Нет данных</div>';
        }
        $axisW = 28;
        $w = 560;
        $slot = ($w - $axisW) / count($labels);
        $plot = 130;
        $h = $plot + 18;
        $bw = min(40, ($slot - 14) / 2);
        $svg = '<svg class="bars" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">' . self::yAxis($axisW, $w, $plot, $max);
        foreach ($labels as $i => $label) {
            $cx = $axisW + $i * $slot + $slot / 2;
            $j = 0;
            foreach ($series as $sk => $s) {
                $cnt = (int) ($buckets[$sk][$label] ?? 0);
                $x = $cx - $bw - 1 + $j * ($bw + 2);
                if ($cnt > 0) {
                    $bh = $cnt / $max * ($plot - 6);
                    $svg .= '<path class="bar bar-' . $sk . '" d="' . self::roundTopBar(round($x, 2), $plot, round($bw, 2), round($bh, 2))
                        . '" fill="' . $s[1] . '"/>';
                }
                $pct = $sum[$sk] > 0 ? round($cnt * 100 / $sum[$sk]) : 0;
                $svg .= '<rect class="hit" x="' . round($x, 2) . '" y="0" width="' . round($bw + 2, 2) . '" height="' . $plot
                    . '" data-tip="' . View::e($s[0] . ' ' . $label . ' сек: ' . $cnt . ' (' . $pct . '%)') . '"/>';
                $j++;
            }
            $svg .= '<text x="' . round($cx, 2) . '" y="' . ($h - 4) . '" text-anchor="middle" class="tick">'
                . View::e($label) . ' сек</text>';
        }
        $legend = [];
        foreach ($series as $sk => $s) {
            $legend[$sk] = [$s[2] . ' (' . $sum[$sk] . ')', $s[1]];
        }
        return '<div class="chart">' . $svg . '</svg>' . self::legend($legend) . '</div>';
    }

    /** Baseline, top grid line and the 0 / max labels of the single y-axis. */
    private static function yAxis(int $axisW, int $w, int $plot, int $max): string
    {
        $top = 6;
        return '<line x1="' . $axisW . '" y1="' . $top . '" x2="' . $w . '" y2="' . $top . '" class="grid-line"/>'
            . '<line x1="' . $axisW . '" y1="' . $plot . '" x2="' . $w . '" y2="' . $plot . '" class="axis"/>'
            . '<text x="' . ($axisW - 4) . '" y="' . ($top + 4) . '" text-anchor="end" class="tick">' . $max . '</text>'
            . '<text x="' . ($axisW - 4) . '" y="' . $plot . '" text-anchor="end" class="tick">0</text>';
    }

    /** @param array<string, array{0:string, 1:string}> $series */
    private static function legend(array $series): string
    {
        if (count($series) < 2) {
            return '';
        }
        $out = '<ul class="chart-legend">';
        foreach ($series as $s) {
            $out .= '<li><span class="sw" style="background:' . View::e($s[1]) . '"></span>' . View::e($s[0]) . '</li>';
        }
        return $out . '</ul>';
    }

    /** Bar anchored on the baseline with 4px rounded top corners. */
    private static function roundTopBar(float $x, float $base, float $w, float $h): string
    {
        $r = min(4, $w / 2, $h);
        $top = $base - $h;
        return 'M' . $x . ' ' . $base . ' V' . round($top + $r, 2)
            . ' Q' . $x . ' ' . round($top, 2) . ' ' . round($x + $r, 2) . ' ' . round($top, 2)
            . ' H' . round($x + $w - $r, 2)
            . ' Q' . round($x + $w, 2) . ' ' . round($top, 2) . ' ' . round($x + $w, 2) . ' ' . round($top + $r, 2)
            . ' V' . $base . ' Z';
    }

    private static function partTip(array $p, int $total): string
    {
        return $p['label'] . ': ' . (int) $p['value'] . ' (' . round((int) $p['value'] * 100 / $total) . '%)';
    }

    /** @param int|string $key */
    private static function keyLabel($key): string
    {
        if (is_int($key)) {
            return sprintf('%02d:00', $key);
        }
        $t = strtotime((string) $key);
        return $t ? date('d.m', $t) : (string) $key;
    }

    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $m10 = $n % 10;
        $m100 = $n % 100;
        if ($m10 === 1 && $m100 !== 11) {
            return $one;
        }
        if ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) {
            return $few;
        }
        return $many;
    }
}

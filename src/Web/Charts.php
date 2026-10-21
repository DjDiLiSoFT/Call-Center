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

<?php

namespace CallStats\Web;

/**
 * ▲/▼ marker of a value vs the previous period: percentage points for answer_pct, relative % otherwise.
 */
class Delta
{
    private const DURATION_KEYS = ['talk_sec', 'avg_ring_sec', 'avg_talk_sec'];

    /**
     * @param int|float|null $cur
     * @param int|float|null $prev
     * @param int $good +1 more is better, -1 less is better, 0 neutral
     */
    public static function html(string $key, $cur, $prev, int $good): string
    {
        if ($cur === null || $prev === null) {
            return '';
        }
        $cur = (float) $cur;
        $was = (float) $prev;
        if ($key === 'answer_pct') {
            $diff = round($cur - $was, 1);
            $txt = str_replace('.', ',', (string) abs($diff)) . ' п.п.';
        } else {
            if ($was == 0.0) {
                return '';
            }
            $diff = round(($cur - $was) * 100 / $was);
            $txt = abs($diff) . '%';
        }
        if ($diff == 0) {
            return '';
        }
        $cls = $good === 0 ? '' : (($diff > 0) === ($good > 0) ? ' good' : ' bad');
        if ($key === 'answer_pct') {
            $wasTxt = Format::pct($was);
        } elseif (in_array($key, self::DURATION_KEYS, true)) {
            $wasTxt = Format::duration((int) $was);
        } else {
            $wasTxt = (string) (int) $was;
        }
        return '<span class="dlt' . $cls . '" title="' . View::e('В прошлом периоде: ' . $wasTxt) . '">'
            . ($diff > 0 ? '▲' : '▼') . $txt . '</span>';
    }
}

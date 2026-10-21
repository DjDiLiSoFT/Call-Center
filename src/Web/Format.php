<?php

namespace CallStats\Web;

class Format
{
    /** Seconds -> h:mm:ss (1:05:09, 0:05:09). */
    public static function duration(int $sec): string
    {
        $sec = max(0, $sec);
        return sprintf('%d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
    }

    /** Like duration(), '—' for null. */
    public static function durationOrDash($sec): string
    {
        return $sec === null ? '—' : self::duration((int) $sec);
    }

    /** 93.9 -> '93,9%', 100.0 -> '100%', null -> '—'. */
    public static function pct($v): string
    {
        return $v === null ? '—' : rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',') . '%';
    }
}

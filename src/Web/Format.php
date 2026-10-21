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

    /** 2 -> '2 сек', 62 -> '01 мин, 02 сек', 3605 -> '1 ч, 00 мин, 05 сек'. */
    public static function humanDuration(int $sec): string
    {
        $sec = max(0, $sec);
        if ($sec < 60) {
            return $sec . ' сек';
        }
        $s = sprintf('%02d мин, %02d сек', intdiv($sec % 3600, 60), $sec % 60);
        return $sec >= 3600 ? intdiv($sec, 3600) . ' ч, ' . $s : $s;
    }

    /** '2026-08-19 10:00:27' -> 'ср, 19 авг. 2026 г., 10:00'. */
    public static function ruDateTime(string $dt): string
    {
        static $days = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];
        static $months = ['янв.', 'февр.', 'мар.', 'апр.', 'мая', 'июн.', 'июл.', 'авг.', 'сент.', 'окт.', 'нояб.', 'дек.'];
        $t = strtotime($dt);
        if ($t === false) {
            return $dt;
        }
        return $days[(int) date('w', $t)] . ', ' . (int) date('j', $t) . ' ' . $months[(int) date('n', $t) - 1]
            . ' ' . date('Y', $t) . ' г., ' . date('H:i', $t);
    }

    /** 93.9 -> '93,9%', 100.0 -> '100%', null -> '—'. */
    public static function pct($v): string
    {
        return $v === null ? '—' : rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',') . '%';
    }
}

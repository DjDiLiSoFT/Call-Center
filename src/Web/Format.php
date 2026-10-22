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

    /** Short wait: 5 -> '0:05', 111 -> '1:51', 3723 -> '1:02:03', null -> '—'. */
    public static function clock($sec): string
    {
        if ($sec === null) {
            return '—';
        }
        $sec = max(0, (int) $sec);
        return $sec >= 3600 ? self::duration($sec) : sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
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

    /** 40 -> 'через 40 сек', 330 -> 'через 5 мин', 7800 -> 'через 2 ч 10 мин'. */
    public static function after(int $sec): string
    {
        $sec = max(0, $sec);
        if ($sec < 60) {
            return 'через ' . $sec . ' сек';
        }
        if ($sec < 3600) {
            return 'через ' . intdiv($sec, 60) . ' мин';
        }
        $m = intdiv($sec % 3600, 60);
        return 'через ' . intdiv($sec, 3600) . ' ч' . ($m > 0 ? ' ' . $m . ' мин' : '');
    }

    /**
     * Badge of OperatorJournal::withCallbacks() data: [text, 'ok'|'bad'].
     *
     * @param array<string,string> $names extension => display name
     */
    public static function callback(array $cb, array $names): array
    {
        $who = function ($ext) use ($names): string {
            $ext = (string) $ext;
            return isset($names[$ext]) && $names[$ext] !== '' ? $names[$ext] : $ext;
        };
        switch ($cb['kind'] ?? 'none') {
            case 'callback':
                return ['✓ перезвонил ' . $who($cb['ext']) . ' ' . self::after((int) $cb['after_sec'])
                    . ($cb['connected'] === false ? ', не дозвонился' : ''), 'ok'];
            case 'got_through':
                return ['✓ клиент дозвонился сам ' . self::after((int) $cb['after_sec']) . ' (' . $who($cb['ext']) . ')', 'ok'];
        }
        return ['✗ не перезвонили', 'bad'];
    }

    /** 93.9 -> '93,9%', 100.0 -> '100%', null -> '—'. */
    public static function pct($v): string
    {
        return $v === null ? '—' : rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',') . '%';
    }
}

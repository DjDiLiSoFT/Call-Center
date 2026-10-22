<?php

namespace CallStats\Report;

/**
 * Report period: whole days, boundaries as 'Y-m-d 00:00:00' .. 'Y-m-d 23:59:59' in the PHP default timezone.
 */
class Period
{
    public const PRESETS = [
        'today' => 'Сегодня',
        'yesterday' => 'Вчера',
        'half_month' => '15 дней',
        'this_week' => 'Эта неделя',
        'last_week' => 'Прошлая неделя',
        'this_month' => 'Этот месяц',
        'last_month' => 'Прошлый месяц',
        'custom' => 'Свой период',
    ];

    /** @var string */
    public $from;
    /** @var string */
    public $to;
    /** @var string */
    public $preset;

    public function __construct(string $preset, string $fromDate, string $toDate)
    {
        $this->preset = $preset;
        $this->from = $fromDate . ' 00:00:00';
        $this->to = $toDate . ' 23:59:59';
    }

    public static function fromRequest(array $get, int $nowTs): Period
    {
        $preset = isset($get['preset']) && is_string($get['preset']) ? $get['preset'] : 'today';
        $today = date('Y-m-d', $nowTs);

        switch ($preset) {
            case 'yesterday':
                $d = date('Y-m-d', strtotime('-1 day', $nowTs));
                return new Period('yesterday', $d, $d);
            case 'half_month':
                // Current half of the month: days 1–15, or 16 to the end of the month.
                return (int) date('j', $nowTs) <= 15
                    ? new Period('half_month', date('Y-m-01', $nowTs), date('Y-m-15', $nowTs))
                    : new Period('half_month', date('Y-m-16', $nowTs), date('Y-m-t', $nowTs));
            case 'this_week':
                $mon = self::monday($nowTs);
                return new Period('this_week', $mon, date('Y-m-d', strtotime($mon . ' +6 days')));
            case 'last_week':
                $mon = date('Y-m-d', strtotime(self::monday($nowTs) . ' -7 days'));
                return new Period('last_week', $mon, date('Y-m-d', strtotime($mon . ' +6 days')));
            case 'this_month':
                $first = date('Y-m-01', $nowTs);
                return new Period('this_month', $first, date('Y-m-t', $nowTs));
            case 'last_month':
                $first = date('Y-m-01', strtotime(date('Y-m-01', $nowTs) . ' -1 month'));
                return new Period('last_month', $first, date('Y-m-t', strtotime($first)));
            case 'custom':
                $from = self::validDate($get['from'] ?? null);
                $to = self::validDate($get['to'] ?? null);
                if ($from !== null || $to !== null) {
                    $from = $from ?? $to;
                    $to = $to ?? $from;
                    if ($from > $to) {
                        list($from, $to) = [$to, $from];
                    }
                    return new Period('custom', $from, $to);
                }
                break;
        }
        return new Period('today', $today, $today);
    }

    private static function monday(int $ts): string
    {
        $dow = (int) date('N', $ts); // 1 = Monday
        return date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
    }

    /** @param mixed $v */
    private static function validDate($v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $v;
    }

    public function fromDate(): string
    {
        return substr($this->from, 0, 10);
    }

    public function toDate(): string
    {
        return substr($this->to, 0, 10);
    }

    /**
     * The period of the same length right before this one. While this period is still running
     * (now inside it), the previous one is cut to the same elapsed time, so "today until 12:00"
     * compares with "yesterday until 12:00", not with the whole of yesterday.
     */
    public function previous(int $nowTs): Period
    {
        if ($this->preset === 'half_month') {
            // The previous half of the month: 16–end of last month before 1–15, 1–15 before 16–end.
            $f = strtotime($this->fromDate());
            $prev = (int) date('j', $f) === 1
                ? new Period('custom', date('Y-m-16', strtotime('-1 day', $f)), date('Y-m-t', strtotime('-1 day', $f)))
                : new Period('custom', date('Y-m-01', $f), date('Y-m-15', $f));
        } else {
            $days = (int) round((strtotime($this->toDate()) - strtotime($this->fromDate())) / 86400) + 1;
            $prev = new Period(
                'custom',
                date('Y-m-d', strtotime($this->fromDate() . " -$days days")),
                date('Y-m-d', strtotime($this->fromDate() . ' -1 day'))
            );
        }
        $start = strtotime($this->from);
        if ($nowTs >= $start && $nowTs <= strtotime($this->to)) {
            $prev->to = date('Y-m-d H:i:s', strtotime($prev->from) + ($nowTs - $start));
        }
        return $prev;
    }

    /** Query parameters that reproduce this period on another page. */
    public function query(): array
    {
        if ($this->preset === 'custom') {
            return ['preset' => 'custom', 'from' => $this->fromDate(), 'to' => $this->toDate()];
        }
        return ['preset' => $this->preset];
    }
}

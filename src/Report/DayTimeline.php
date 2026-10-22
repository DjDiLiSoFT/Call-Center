<?php

namespace CallStats\Report;

use CallStats\Web\Charts;

/**
 * One operator's day as segments on a time axis (for Charts::dayStrip): conversations, pauses,
 * missed calls (1-minute marks) and "phone offline" runs. Minutes are counted from midnight of $date.
 */
class DayTimeline
{
    /**
     * @param array[] $items OperatorJournal items of that day (offline runs collapsed)
     * @return array{from_h:int, to_h:int, segments: array[]}
     */
    public static function build(array $items, string $date, int $nowTs): array
    {
        $midnight = strtotime($date . ' 00:00:00');
        $dayEnd = $midnight + 86400;
        $min = function (int $ts) use ($midnight): float {
            return round(($ts - $midnight) / 60, 2);
        };
        $hm = function (int $ts): string {
            return date('H:i', $ts);
        };
        $segments = [];
        $add = function (string $kind, int $from, int $to, string $tip) use (&$segments, $midnight, $dayEnd, $min): void {
            $from = max($from, $midnight);
            $to = min($to, $dayEnd);
            if ($to <= $from) {
                return;
            }
            $segments[] = ['kind' => $kind, 'from' => $min($from), 'to' => $min($to), 'tip' => $tip];
        };

        foreach ($items as $it) {
            $at = strtotime($it['at']);
            if ($at === false) {
                continue;
            }
            $client = (string) ($it['client'] ?? '');
            $talk = (int) ($it['talk_sec'] ?? 0);
            switch ($it['type']) {
                case 'in_answered':
                case 'out':
                case 'internal':
                    if ($talk <= 0 || ($it['type'] === 'out' && ($it['reason'] ?? '') !== 'answered')) {
                        break;
                    }
                    $from = $at + (int) ($it['ring_sec'] ?? 0);
                    $what = $it['type'] === 'out' ? 'исходящий' : ($it['type'] === 'internal' ? 'внутренний' : 'входящий');
                    $add('talk', $from, $from + $talk, $hm($from) . '–' . $hm($from + $talk) . ' разговор, ' . $what
                        . ($client !== '' ? ' ' . $client : '') . ', ' . self::minutes($talk));
                    break;
                case 'pause':
                    $until = $it['until'] !== null ? strtotime($it['until']) : min($nowTs, $dayEnd);
                    $reason = (string) ($it['reason'] ?? '');
                    $add('pause', $at, (int) $until, $hm($at) . '–' . ($it['until'] !== null ? $hm((int) $until) : 'сейчас')
                        . ' пауза' . ($reason !== '' ? ': ' . $reason : ''));
                    break;
                case 'in_missed':
                    $m = $at - ($at - $midnight) % 60;
                    $add('missed', $m, $m + 60, $hm($at) . ' пропущен' . ($client !== '' ? ' ' . $client : ''));
                    break;
                case 'in_offline':
                    $m = $at - ($at - $midnight) % 60;
                    $add('offline', $m, $m + 60, $hm($at) . ' не в сети' . ($client !== '' ? ', ' . $client : ''));
                    break;
                case 'offline_group':
                    $until = strtotime((string) $it['until']);
                    $n = (int) $it['count'];
                    $add('offline', $at, max((int) $until, $at + 60), $hm($at) . '–' . $hm((int) $until) . ' не в сети, '
                        . $n . ' ' . Charts::plural($n, 'звонок', 'звонка', 'звонков'));
                    break;
            }
        }

        $fromH = 8;
        $toH = 20;
        foreach ($segments as $s) {
            $fromH = min($fromH, (int) floor($s['from'] / 60));
            $toH = max($toH, (int) ceil($s['to'] / 60));
        }
        return ['from_h' => $fromH, 'to_h' => min(24, $toH), 'segments' => $segments];
    }

    private static function minutes(int $sec): string
    {
        return $sec < 60 ? $sec . ' сек' : (int) round($sec / 60) . ' мин';
    }
}

<?php

namespace CallStats\Report;

use CallStats\Web\Charts;

/**
 * Journal as a call-history table: one row per journal item (offline runs stay collapsed),
 * plus search / sort / pagination over the rows.
 *
 * Row: at, dir (in|out|internal|event), failed (not connected), muted (grey), number, text
 * (event description), note (muted detail), duration (seconds or null), call_id, rec_status
 * ('' when no recording), approx, sources, items (offline group members), cat (chip category, see CATS),
 * ext (operator, all-operators mode), callback (OperatorJournal::withCallbacks() result or null).
 */
class JournalTable
{
    public const PER_PAGE = [25, 50, 100];

    /** Category chips in display order. */
    public const CATS = ['answered', 'missed', 'out', 'internal', 'other', 'offline', 'event'];

    /** Categories hidden by default ("noise"): the call was taken by a colleague / the phone was offline. */
    public const NOISE = ['other', 'offline'];

    private const CAT_OF = [
        'in_answered' => 'answered', 'in_missed' => 'missed', 'in_other' => 'other', 'in_caller_hangup' => 'other',
        'in_offline' => 'offline', 'offline_group' => 'offline', 'out' => 'out', 'internal' => 'internal',
    ];

    /** @return array<string,int> category => rows, in CATS order, absent categories omitted */
    public static function counts(array $rows): array
    {
        $n = array_fill_keys(self::CATS, 0);
        foreach ($rows as $r) {
            $n[$r['cat']]++;
        }
        return array_filter($n);
    }

    /** $type '' = everything except noise (unless $noise); a category = only it. */
    public static function filterType(array $rows, string $type, bool $noise): array
    {
        return array_values(array_filter($rows, function (array $r) use ($type, $noise): bool {
            if ($type !== '') {
                return $r['cat'] === $type;
            }
            return $noise || !in_array($r['cat'], self::NOISE, true);
        }));
    }

    /** @return array<string, array{answered:int, missed:int, out:int}> 'Y-m-d' => counts, by date */
    public static function days(array $rows): array
    {
        $d = [];
        foreach ($rows as $r) {
            $day = substr($r['at'], 0, 10);
            if (!isset($d[$day])) {
                $d[$day] = ['answered' => 0, 'missed' => 0, 'out' => 0];
            }
            if (isset($d[$day][$r['cat']])) {
                $d[$day][$r['cat']]++;
            }
        }
        ksort($d);
        return $d;
    }

    public static function rows(array $items): array
    {
        $rows = [];
        foreach ($items as $it) {
            $rows[] = self::row($it);
        }
        return $rows;
    }

    private static function row(array $it): array
    {
        $t = $it['type'];
        $src = (string) ($it['source'] ?? '');
        $r = [
            'at' => $it['at'], 'dir' => 'event', 'failed' => false, 'muted' => false,
            'number' => (string) ($it['client'] ?? ''), 'text' => '', 'note' => '', 'duration' => null,
            'call_id' => $it['call_id'] ?? null,
            'rec_status' => !empty($it['has_recording']) ? (string) ($it['rec_status'] ?? 'missing') : '',
            'approx' => !empty($it['approx']), 'sources' => $it['sources'] ?? null, 'items' => $it['items'] ?? [],
            'cat' => self::CAT_OF[$t] ?? 'event', 'ext' => $it['ext'] ?? null, 'callback' => $it['callback'] ?? null,
        ];
        $ring = ($it['ring_sec'] ?? null) === null ? null : (int) $it['ring_sec'];
        $talk = ($it['talk_sec'] ?? null) === null ? null : (int) $it['talk_sec'];
        $with = function (string $what) use ($src): string {
            return $src !== '' ? $what . ' · ' . $src : $what;
        };
        switch ($t) {
            case 'in_answered':
                $r['dir'] = 'in';
                $r['note'] = $src;
                $r['duration'] = $talk;
                break;
            case 'in_missed':
                $r['dir'] = 'in';
                $r['failed'] = true;
                $r['note'] = $with('пропущен');
                $r['duration'] = $ring;
                break;
            case 'in_other':
                $r['dir'] = 'in';
                $r['failed'] = $r['muted'] = true;
                $r['note'] = $with('взял другой');
                $r['duration'] = $ring;
                break;
            case 'in_caller_hangup':
                $r['dir'] = 'in';
                $r['failed'] = $r['muted'] = true;
                $r['note'] = $with('клиент положил трубку');
                $r['duration'] = $ring;
                break;
            case 'in_offline':
                $r['dir'] = 'in';
                $r['failed'] = $r['muted'] = true;
                $r['note'] = $with('телефон не в сети');
                break;
            case 'out':
                $ok = ($it['reason'] ?? '') === 'answered';
                $r['dir'] = 'out';
                $r['failed'] = !$ok;
                $r['note'] = $ok ? '' : 'не состоялся';
                $r['duration'] = $ok ? $talk : $ring;
                break;
            case 'internal':
                $r['dir'] = 'internal';
                $r['note'] = ($it['reason'] ?? '') === 'out' ? 'внутренний, исходящий' : 'внутренний, входящий';
                $r['duration'] = $talk;
                break;
            case 'transfer_out':
                $r['text'] = 'Перевёл звонок ' . $r['number'] . ' на ' . $it['reason'];
                $r['number'] = '';
                break;
            case 'transfer_in':
                $r['text'] = 'Получил перевод от ' . $it['reason'] . ': ' . $r['number'];
                $r['number'] = '';
                break;
            case 'session_start':
                $r['text'] = 'Вход в очередь «' . $src . '»';
                break;
            case 'session_end':
                $r['text'] = 'Выход из очереди «' . $src . '»';
                break;
            case 'pause':
                $reason = (string) ($it['reason'] ?? '');
                $r['text'] = 'Пауза' . ($reason !== '' ? ': ' . $reason : '') . ($it['until'] === null ? ' (не завершена)' : '');
                $r['note'] = $src !== '' ? 'очередь «' . $src . '»' : '';
                $r['duration'] = $it['until'] === null ? null : max(0, strtotime($it['until']) - strtotime($it['at']));
                break;
            case 'offline_group':
                $n = (int) $it['count'];
                $r['text'] = 'Телефон не в сети: ' . $n . ' ' . Charts::plural($n, 'звонок', 'звонка', 'звонков')
                    . ', ' . substr($it['at'], 11, 5) . '–' . substr((string) $it['until'], 11, 5);
                $r['muted'] = true;
                break;
        }
        if ($r['failed']) {
            $r['rec_status'] = ''; // the operator did not talk: any recording is someone else's conversation
        }
        return $r;
    }

    /**
     * @param string $sort 'at' | 'dur'
     * @param string $order 'asc' | 'desc'
     */
    public static function apply(array $rows, string $q, string $sort, string $order, int $page, int $perPage): array
    {
        $q = trim($q);
        if ($q !== '') {
            $rows = array_values(array_filter($rows, function (array $r) use ($q): bool {
                return mb_stripos($r['number'] . ' ' . $r['text'] . ' ' . $r['note'], $q) !== false;
            }));
        }
        $key = $sort === 'dur' ? 'duration' : 'at';
        $dir = $order === 'asc' ? 1 : -1;
        $idx = array_keys($rows);
        usort($idx, function (int $a, int $b) use ($rows, $key, $dir): int {
            $c = [$rows[$a][$key] ?? -1, $rows[$a]['at']] <=> [$rows[$b][$key] ?? -1, $rows[$b]['at']];
            return $c !== 0 ? $c * $dir : $a <=> $b;
        });
        $total = count($idx);
        $perPage = max(1, $perPage);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $slice = array_slice($idx, ($page - 1) * $perPage, $perPage);
        return [
            'rows' => array_map(function (int $i) use ($rows): array {
                return $rows[$i];
            }, $slice),
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'from' => $total > 0 ? ($page - 1) * $perPage + 1 : 0,
            'to' => $total > 0 ? ($page - 1) * $perPage + count($slice) : 0,
        ];
    }
}

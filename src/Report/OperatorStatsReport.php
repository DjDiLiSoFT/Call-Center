<?php

namespace CallStats\Report;

use CallStats\Store\OperatorRepository;
use PDO;

/**
 * Per-operator statistics for a period (spec 3.1). Calls of kind 'internal' are never counted.
 */
class OperatorStatsReport
{
    /** @var PDO */
    private $db;
    /** @var OperatorRepository */
    private $ops;

    public function __construct(PDO $db, OperatorRepository $ops)
    {
        $this->db = $db;
        $this->ops = $ops;
    }

    /**
     * @param string[] $extensions empty = all operators
     * @param int|null $now        "current time" used to cap open sessions/pauses (default time())
     * @return array{rows: array[], total: array, hasTimeline: bool, hasApprox: bool}
     */
    public function fetch(Period $p, ?string $queue, array $extensions, ?int $now = null): array
    {
        $now = $now ?? time();
        $extensions = array_values(array_unique(array_map('strval', $extensions)));

        $rows = $this->callStats($p, $queue, $extensions);
        list($sessions, $pauses, $pauseTotals) = $this->timeline($p, $queue, $extensions, $now);
        $hasTimeline = $this->anyTimeline($p);

        $spans = $this->activeSpans($p, $queue, $extensions);

        foreach (array_keys($sessions + $pauseTotals) as $ext) {
            if (!isset($rows[$ext])) {
                $rows[$ext] = $this->emptyRow((string) $ext);
            }
        }

        foreach ($rows as $ext => &$r) {
            $r['name'] = $this->ops->displayName((string) $ext);
            $r['answer_pct'] = self::pct($r['answered'], $r['missed']);
            $r['avg_talk_sec'] = $r['answered'] > 0 ? (int) round($r['_in_talk'] / $r['answered']) : 0;
            $r['avg_ring_sec'] = $r['answered'] > 0 ? (int) round($r['_in_ring'] / $r['answered']) : 0;
            if ($hasTimeline) {
                $has = isset($sessions[$ext]);
                $r['session_sec'] = $has ? $sessions[$ext] : null;
                $r['pause_sec'] = ($has || isset($pauseTotals[$ext])) ? ($pauseTotals[$ext] ?? 0) : null;
                $r['pauses_by_reason'] = $pauses[$ext] ?? [];
            } else {
                $r['session_sec'] = null;
                $r['pause_sec'] = null;
                $r['pauses_by_reason'] = [];
            }
            $r['active_span_sec'] = self::activeSpan($spans[$ext] ?? []);
            $r = array_merge($r, self::workload($r['talk_sec'], $r['answered'] + $r['out_total'], $r['session_sec'], $r['pause_sec'], $r['active_span_sec']));
        }
        unset($r);

        $rows = array_values($rows);
        usort($rows, function (array $a, array $b): int {
            return strnatcmp($a['extension'], $b['extension']);
        });

        return [
            'rows' => $rows,
            // only the operators shown in the table (StatsInsights active), or Итого != the visible rows
            'total' => self::sumRows(array_filter($rows, [StatsInsights::class, 'isActive']), $hasTimeline),
            'hasTimeline' => $hasTimeline,
            'hasApprox' => $this->hasApprox($p, $queue, $extensions),
        ];
    }

    /**
     * One operator per day (days with calls only), internal calls excluded.
     *
     * @return array<string, array{answered:int, missed:int, out:int, talk_sec:int, avg_talk_sec:int, answer_pct:?float}>
     */
    public function operatorByDay(Period $p, string $ext): array
    {
        $st = $this->db->prepare(
            "SELECT DATE(c.started_at) AS d,
                SUM(ca.direction = 'in' AND ca.outcome = 'answered') AS answered,
                SUM(ca.direction = 'in' AND ca.outcome = 'missed') AS missed,
                SUM(ca.direction = 'out') AS out_total,
                SUM(CASE WHEN ca.outcome = 'answered' THEN ca.talk_sec ELSE 0 END) AS talk_sec,
                SUM(CASE WHEN ca.direction = 'in' AND ca.outcome = 'answered' THEN ca.talk_sec ELSE 0 END) AS in_talk
             FROM call_agents ca JOIN calls c ON c.id = ca.call_id
             WHERE c.kind <> 'internal' AND ca.extension = ? AND c.started_at >= ? AND c.started_at <= ?
             GROUP BY d ORDER BY d"
        );
        $st->execute([$ext, $p->from, $p->to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $a = (int) $r['answered'];
            $out[(string) $r['d']] = [
                'answered' => $a,
                'missed' => (int) $r['missed'],
                'out' => (int) $r['out_total'],
                'talk_sec' => (int) $r['talk_sec'],
                'avg_talk_sec' => $a > 0 ? (int) round((int) $r['in_talk'] / $a) : 0,
                'answer_pct' => self::pct($a, (int) $r['missed']),
            ];
        }
        return $out;
    }

    /** @return array<int, array{answered:int, missed:int}> hour of the ring => this operator's inbound rings */
    public function operatorByHour(Period $p, string $ext): array
    {
        $st = $this->db->prepare(
            "SELECT HOUR(ca.first_ring_at) AS h,
                SUM(ca.outcome = 'answered') AS answered, SUM(ca.outcome = 'missed') AS missed
             FROM call_agents ca JOIN calls c ON c.id = ca.call_id
             WHERE c.kind <> 'internal' AND ca.direction = 'in' AND ca.outcome IN ('answered', 'missed')
               AND ca.extension = ? AND c.started_at >= ? AND c.started_at <= ?
             GROUP BY h ORDER BY h"
        );
        $st->execute([$ext, $p->from, $p->to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['h']] = ['answered' => (int) $r['answered'], 'missed' => (int) $r['missed']];
        }
        return $out;
    }

    /** The «Итого» row of $rows. */
    public static function sumRows(array $rows, bool $hasTimeline): array
    {
        $total = self::emptyRow('');
        $total['name'] = 'Итого';
        $total['session_sec'] = $hasTimeline ? 0 : null;
        $total['pause_sec'] = $hasTimeline ? 0 : null;
        $work = 0;
        $approx = false;
        foreach ($rows as $r) {
            foreach (['answered', 'missed', 'missed_lost', 'missed_other', 'offline', 'out_total', 'out_connected',
                'talk_sec', '_in_talk', '_in_ring', 'active_span_sec'] as $k) {
                $total[$k] += $r[$k];
            }
            if ($hasTimeline) {
                $total['session_sec'] += (int) $r['session_sec'];
                $total['pause_sec'] += (int) $r['pause_sec'];
                foreach ($r['pauses_by_reason'] as $reason => $sec) {
                    $total['pauses_by_reason'][$reason] = ($total['pauses_by_reason'][$reason] ?? 0) + $sec;
                }
            }
            $work += (int) $r['work_sec'];
            $approx = $approx || !empty($r['busy_approx']);
        }
        $total['answer_pct'] = self::pct($total['answered'], $total['missed']);
        $total['avg_talk_sec'] = $total['answered'] > 0 ? (int) round($total['_in_talk'] / $total['answered']) : 0;
        $total['avg_ring_sec'] = $total['answered'] > 0 ? (int) round($total['_in_ring'] / $total['answered']) : 0;
        $w = self::workload($total['talk_sec'], $total['answered'] + $total['out_total'], $work, 0, 0);
        $w['busy_approx'] = $approx;
        return array_merge($total, $w);
    }

    /**
     * Working time and its use. Working time = session − pauses when the session is known (> 0),
     * otherwise the active span (first to last call of each day; busy_approx = true).
     *
     * @return array{work_sec:int, busy_approx:bool, busy_pct:?float, calls_per_hour:?float}
     */
    public static function workload(int $talkSec, int $calls, ?int $sessionSec, ?int $pauseSec, int $spanSec): array
    {
        $approx = !($sessionSec !== null && $sessionSec > 0);
        $work = $approx ? $spanSec : $sessionSec - (int) $pauseSec;
        $work = max(0, $work);
        return [
            'work_sec' => $work,
            'busy_approx' => $approx,
            'busy_pct' => $work > 0 ? round(min(100, $talkSec * 100 / $work), 1) : null,
            'calls_per_hour' => $work > 0 ? round($calls * 3600 / $work, 1) : null,
        ];
    }

    /**
     * Sum over days of (last call end − first call event) of that day.
     *
     * @param array<int, array{first:string, last:string}> $days
     */
    public static function activeSpan(array $days): int
    {
        $sum = 0;
        foreach ($days as $d) {
            $sum += max(0, strtotime($d['last']) - strtotime($d['first']));
        }
        return $sum;
    }

    /**
     * Per operator and day: the first ring / call start and the end of the last ring or talk
     * (offline rings excluded: the phone was not there).
     *
     * @return array<string, array<int, array{first:string, last:string}>>
     */
    private function activeSpans(Period $p, ?string $queue, array $extensions): array
    {
        $sql = "SELECT ca.extension, DATE(ca.first_ring_at) AS d, MIN(ca.first_ring_at) AS first,
                MAX(CASE WHEN ca.answered_at IS NOT NULL THEN ca.answered_at + INTERVAL ca.talk_sec SECOND
                         ELSE ca.first_ring_at + INTERVAL ca.ring_sec SECOND END) AS last
            FROM call_agents ca JOIN calls c ON c.id = ca.call_id
            WHERE ca.outcome <> 'offline' AND c.started_at >= ? AND c.started_at <= ?";
        $args = [$p->from, $p->to];
        if ($queue !== null && $queue !== '') {
            $sql .= ' AND c.queue = ?';
            $args[] = $queue;
        }
        if ($extensions) {
            $sql .= ' AND ca.extension IN (' . implode(',', array_fill(0, count($extensions), '?')) . ')';
            $args = array_merge($args, $extensions);
        }
        $st = $this->db->prepare($sql . ' GROUP BY ca.extension, d');
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['extension']][] = ['first' => (string) $r['first'], 'last' => (string) $r['last']];
        }
        return $out;
    }

    private static function pct(int $answered, int $missed): ?float
    {
        $d = $answered + $missed;
        return $d > 0 ? round($answered * 100 / $d, 1) : null;
    }

    private static function emptyRow(string $ext): array
    {
        return [
            'extension' => $ext, 'name' => $ext,
            'answered' => 0, 'missed' => 0, 'missed_lost' => 0, 'missed_other' => 0, 'answer_pct' => null, 'offline' => 0,
            'out_total' => 0, 'out_connected' => 0, 'talk_sec' => 0,
            'avg_talk_sec' => 0, 'avg_ring_sec' => 0,
            'session_sec' => null, 'pause_sec' => null, 'pauses_by_reason' => [],
            'active_span_sec' => 0, 'work_sec' => 0, 'busy_approx' => true, 'busy_pct' => null, 'calls_per_hour' => null,
            '_in_talk' => 0, '_in_ring' => 0,
        ];
    }

    /** @return array<string,array> keyed by extension */
    private function callStats(Period $p, ?string $queue, array $extensions): array
    {
        $sql = "SELECT ca.extension,
                SUM(ca.direction = 'in' AND ca.outcome = 'answered') AS answered,
                SUM(ca.direction = 'in' AND ca.outcome = 'missed') AS missed,
                SUM(ca.direction = 'in' AND ca.outcome = 'missed' AND COALESCE(c.answered_by, '') = '') AS missed_lost,
                SUM(ca.direction = 'in' AND ca.outcome = 'offline') AS offline,
                SUM(ca.direction = 'out') AS out_total,
                SUM(ca.direction = 'out' AND ca.outcome = 'answered') AS out_connected,
                SUM(CASE WHEN ca.outcome = 'answered' THEN ca.talk_sec ELSE 0 END) AS talk_sec,
                SUM(CASE WHEN ca.direction = 'in' AND ca.outcome = 'answered' THEN ca.talk_sec ELSE 0 END) AS in_talk,
                SUM(CASE WHEN ca.direction = 'in' AND ca.outcome = 'answered' THEN ca.ring_sec ELSE 0 END) AS in_ring
            FROM call_agents ca JOIN calls c ON c.id = ca.call_id
            WHERE c.kind <> 'internal' AND c.started_at >= ? AND c.started_at <= ?";
        $args = [$p->from, $p->to];
        if ($queue !== null && $queue !== '') {
            $sql .= ' AND c.queue = ?';
            $args[] = $queue;
        }
        if ($extensions) {
            $sql .= ' AND ca.extension IN (' . implode(',', array_fill(0, count($extensions), '?')) . ')';
            $args = array_merge($args, $extensions);
        }
        $sql .= ' GROUP BY ca.extension';
        $st = $this->db->prepare($sql);
        $st->execute($args);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $r = $this->emptyRow((string) $d['extension']);
            $r['answered'] = (int) $d['answered'];
            $r['missed'] = (int) $d['missed'];
            $r['missed_lost'] = (int) $d['missed_lost'];
            $r['missed_other'] = $r['missed'] - $r['missed_lost'];
            $r['offline'] = (int) $d['offline'];
            $r['out_total'] = (int) $d['out_total'];
            $r['out_connected'] = (int) $d['out_connected'];
            $r['talk_sec'] = (int) $d['talk_sec'];
            $r['_in_talk'] = (int) $d['in_talk'];
            $r['_in_ring'] = (int) $d['in_ring'];
            $out[(string) $d['extension']] = $r;
        }
        return $out;
    }

    /** True if there is any session or pause overlapping the period (whatever the filters). */
    private function anyTimeline(Period $p): bool
    {
        foreach (['agent_sessions', 'agent_pauses'] as $t) {
            $st = $this->db->prepare("SELECT 1 FROM $t WHERE start_at <= ? AND (end_at IS NULL OR end_at >= ?) LIMIT 1");
            $st->execute([$p->to, $p->from]);
            if ($st->fetchColumn()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Union of intervals per operator.
     *
     * @return array{0: array<string,int>, 1: array<string,array<string,int>>, 2: array<string,int>} sessions[ext] => sec, pauses[ext][reason] => sec, pause totals[ext] => sec (union over all reasons)
     */
    private function timeline(Period $p, ?string $queue, array $extensions, int $now): array
    {
        $pStart = strtotime($p->from);
        $pEnd = strtotime($p->to) + 1;
        $cap = min($now, $pEnd);

        $fetch = function (string $table, string $cols) use ($p, $queue, $extensions): array {
            $sql = "SELECT $cols FROM $table WHERE start_at <= ? AND (end_at IS NULL OR end_at >= ?)";
            $args = [$p->to, $p->from];
            if ($queue !== null && $queue !== '') {
                $sql .= ' AND queue = ?';
                $args[] = $queue;
            }
            if ($extensions) {
                $sql .= ' AND extension IN (' . implode(',', array_fill(0, count($extensions), '?')) . ')';
                $args = array_merge($args, $extensions);
            }
            $st = $this->db->prepare($sql);
            $st->execute($args);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        };

        $clip = function (array $r) use ($pStart, $cap): ?array {
            $s = max(strtotime($r['start_at']), $pStart);
            $e = $r['end_at'] === null ? $cap : min(strtotime($r['end_at']), $cap);
            return $e > $s ? [$s, $e] : null;
        };

        $sessIv = [];
        foreach ($fetch('agent_sessions', 'extension, start_at, end_at') as $r) {
            if (($iv = $clip($r)) !== null) {
                $sessIv[(string) $r['extension']][] = $iv;
            }
        }
        $pauseIv = [];
        $pauseAll = [];
        foreach ($fetch('agent_pauses', 'extension, start_at, end_at, reason') as $r) {
            if (($iv = $clip($r)) !== null) {
                $pauseIv[(string) $r['extension']][(string) $r['reason']][] = $iv;
                $pauseAll[(string) $r['extension']][] = $iv;
            }
        }

        $sessions = [];
        foreach ($sessIv as $ext => $ivs) {
            $sessions[$ext] = self::unionLength($ivs);
        }
        $pauses = [];
        foreach ($pauseIv as $ext => $byReason) {
            foreach ($byReason as $reason => $ivs) {
                $pauses[$ext][$reason] = self::unionLength($ivs);
            }
        }
        $pauseTotals = [];
        foreach ($pauseAll as $ext => $ivs) {
            $pauseTotals[$ext] = self::unionLength($ivs);
        }
        return [$sessions, $pauses, $pauseTotals];
    }

    /** @param array<int,array{0:int,1:int}> $ivs */
    private static function unionLength(array $ivs): int
    {
        usort($ivs, function (array $a, array $b): int {
            return $a[0] <=> $b[0];
        });
        $sum = 0;
        $curS = null;
        $curE = null;
        foreach ($ivs as $iv) {
            if ($curE === null || $iv[0] > $curE) {
                if ($curE !== null) {
                    $sum += $curE - $curS;
                }
                $curS = $iv[0];
                $curE = $iv[1];
            } elseif ($iv[1] > $curE) {
                $curE = $iv[1];
            }
        }
        if ($curE !== null) {
            $sum += $curE - $curS;
        }
        return $sum;
    }

    private function hasApprox(Period $p, ?string $queue, array $extensions): bool
    {
        $sql = "SELECT 1 FROM calls c WHERE c.approx = 1 AND c.kind <> 'internal' AND c.started_at >= ? AND c.started_at <= ?";
        $args = [$p->from, $p->to];
        if ($queue !== null && $queue !== '') {
            $sql .= ' AND c.queue = ?';
            $args[] = $queue;
        }
        $sql .= ' AND EXISTS (SELECT 1 FROM call_agents ca WHERE ca.call_id = c.id';
        if ($extensions) {
            $sql .= ' AND ca.extension IN (' . implode(',', array_fill(0, count($extensions), '?')) . ')';
            $args = array_merge($args, $extensions);
        }
        $sql .= ')';
        $st = $this->db->prepare($sql . ' LIMIT 1');
        $st->execute($args);
        return (bool) $st->fetchColumn();
    }
}

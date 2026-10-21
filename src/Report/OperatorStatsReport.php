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
        list($sessions, $pauses) = $this->timeline($p, $queue, $extensions, $now);
        $hasTimeline = $this->anyTimeline($p);

        foreach (array_keys($sessions + $pauses) as $ext) {
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
                $r['pause_sec'] = ($has || isset($pauses[$ext])) ? array_sum($pauses[$ext] ?? []) : null;
                $r['pauses_by_reason'] = $pauses[$ext] ?? [];
            } else {
                $r['session_sec'] = null;
                $r['pause_sec'] = null;
                $r['pauses_by_reason'] = [];
            }
        }
        unset($r);

        $rows = array_values($rows);
        usort($rows, function (array $a, array $b): int {
            return strnatcmp($a['extension'], $b['extension']);
        });

        $total = $this->emptyRow('');
        $total['name'] = 'Итого';
        $total['session_sec'] = $hasTimeline ? 0 : null;
        $total['pause_sec'] = $hasTimeline ? 0 : null;
        foreach ($rows as $r) {
            foreach (['answered', 'missed', 'offline', 'out_total', 'out_connected', 'talk_sec', '_in_talk', '_in_ring'] as $k) {
                $total[$k] += $r[$k];
            }
            if ($hasTimeline) {
                $total['session_sec'] += (int) $r['session_sec'];
                $total['pause_sec'] += (int) $r['pause_sec'];
                foreach ($r['pauses_by_reason'] as $reason => $sec) {
                    $total['pauses_by_reason'][$reason] = ($total['pauses_by_reason'][$reason] ?? 0) + $sec;
                }
            }
        }
        $total['answer_pct'] = self::pct($total['answered'], $total['missed']);
        $total['avg_talk_sec'] = $total['answered'] > 0 ? (int) round($total['_in_talk'] / $total['answered']) : 0;
        $total['avg_ring_sec'] = $total['answered'] > 0 ? (int) round($total['_in_ring'] / $total['answered']) : 0;

        return [
            'rows' => $rows,
            'total' => $total,
            'hasTimeline' => $hasTimeline,
            'hasApprox' => $this->hasApprox($p, $queue, $extensions),
        ];
    }

    private static function pct(int $answered, int $missed): ?float
    {
        $d = $answered + $missed;
        return $d > 0 ? round($answered * 100 / $d, 1) : null;
    }

    private function emptyRow(string $ext): array
    {
        return [
            'extension' => $ext, 'name' => $ext,
            'answered' => 0, 'missed' => 0, 'answer_pct' => null, 'offline' => 0,
            'out_total' => 0, 'out_connected' => 0, 'talk_sec' => 0,
            'avg_talk_sec' => 0, 'avg_ring_sec' => 0,
            'session_sec' => null, 'pause_sec' => null, 'pauses_by_reason' => [],
            '_in_talk' => 0, '_in_ring' => 0,
        ];
    }

    /** @return array<string,array> keyed by extension */
    private function callStats(Period $p, ?string $queue, array $extensions): array
    {
        $sql = "SELECT ca.extension,
                SUM(ca.direction = 'in' AND ca.outcome = 'answered') AS answered,
                SUM(ca.direction = 'in' AND ca.outcome = 'missed') AS missed,
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
     * @return array{0: array<string,int>, 1: array<string,array<string,int>>} sessions[ext] => sec, pauses[ext][reason] => sec
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
        foreach ($fetch('agent_pauses', 'extension, start_at, end_at, reason') as $r) {
            if (($iv = $clip($r)) !== null) {
                $pauseIv[(string) $r['extension']][(string) $r['reason']][] = $iv;
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
        return [$sessions, $pauses];
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
        if ($extensions) {
            $sql .= ' AND EXISTS (SELECT 1 FROM call_agents ca WHERE ca.call_id = c.id AND ca.extension IN ('
                . implode(',', array_fill(0, count($extensions), '?')) . '))';
            $args = array_merge($args, $extensions);
        }
        $st = $this->db->prepare($sql . ' LIMIT 1');
        $st->execute($args);
        return (bool) $st->fetchColumn();
    }
}

<?php

namespace CallStats\Report;

use CallStats\Store\OperatorRepository;
use PDO;

/**
 * Data for the dashboard: KPI tiles (with the previous period of the same length and a
 * per-day / per-hour series), call outcomes, call types, calls by hour, frequent clients and
 * the operator rows. Inbound = queue + direct calls; internal calls are never counted.
 */
class DashboardReport
{
    private const IN = "kind IN ('queue', 'direct')";

    private const AGG = 'COUNT(*) AS calls, SUM(answered_by IS NOT NULL) AS answered,
        SUM(CASE WHEN answered_by IS NOT NULL THEN wait_sec ELSE 0 END) AS wait_sum,
        SUM(CASE WHEN answered_by IS NOT NULL THEN talk_sec ELSE 0 END) AS talk_sum';

    /** @var PDO */
    private $db;
    /** @var OperatorRepository */
    private $ops;

    public function __construct(PDO $db, OperatorRepository $ops)
    {
        $this->db = $db;
        $this->ops = $ops;
    }

    public function fetch(Period $p, int $now): array
    {
        $days = (int) round((strtotime($p->toDate()) - strtotime($p->fromDate())) / 86400) + 1;
        $bucket = $days === 1 ? 'hour' : 'day';
        $prevPeriod = $p->previous($now);
        $prevFrom = $prevPeriod->from;
        $prevTo = $prevPeriod->to;

        $cur = $this->totals($p->from, $p->to);
        $prev = $this->totals($prevFrom, $prevTo);
        $series = $this->series($p, $bucket, $now);

        $kpi = [];
        foreach (['calls', 'answered', 'answer_pct', 'avg_wait', 'avg_talk'] as $k) {
            $kpi[$k] = [
                'value' => $cur[$k],
                'prev' => $prev[$k],
                'series' => array_map(function (array $t) use ($k) {
                    return $t[$k];
                }, $series),
            ];
        }

        return [
            'bucket' => $bucket,
            'prev_from' => substr($prevFrom, 0, 10),
            'prev_to' => substr($prevTo, 0, 10),
            'kpi' => $kpi,
            'outcomes' => $this->outcomes($p),
            'types' => $this->types($p),
            'hours' => $this->hours($p),
            'clients' => $this->clients($p),
            'operators' => (new OperatorStatsReport($this->db, $this->ops))->fetch($p, null, [], $now)['rows'],
        ];
    }

    /** Aggregates of one group of inbound calls (a period or one bucket). */
    private static function derive(array $r): array
    {
        $calls = (int) $r['calls'];
        $answered = (int) $r['answered'];
        return [
            'calls' => $calls,
            'answered' => $answered,
            'answer_pct' => $calls > 0 ? round($answered * 100 / $calls, 1) : null,
            'avg_wait' => $answered > 0 ? (int) round((int) $r['wait_sum'] / $answered) : null,
            'avg_talk' => $answered > 0 ? (int) round((int) $r['talk_sum'] / $answered) : null,
        ];
    }

    private function totals(string $from, string $to): array
    {
        $st = $this->db->prepare('SELECT ' . self::AGG . ' FROM calls WHERE started_at BETWEEN ? AND ? AND ' . self::IN);
        $st->execute([$from, $to]);
        return self::derive($st->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * Per-day series (every day of the period up to today, zero-filled) or, for a one-day
     * period, per-hour series from the first to the last hour with calls.
     */
    private function series(Period $p, string $bucket, int $now): array
    {
        $expr = $bucket === 'hour' ? 'HOUR(started_at)' : 'DATE(started_at)';
        $st = $this->db->prepare('SELECT ' . $expr . ' AS b, ' . self::AGG . ' FROM calls
            WHERE started_at BETWEEN ? AND ? AND ' . self::IN . ' GROUP BY b ORDER BY b');
        $st->execute([$p->from, $p->to]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[$bucket === 'hour' ? (int) $r['b'] : (string) $r['b']] = self::derive($r);
        }
        $zero = self::derive(['calls' => 0, 'answered' => 0, 'wait_sum' => 0, 'talk_sum' => 0]);
        $out = [];
        if ($bucket === 'hour') {
            if ($rows) {
                for ($h = min(array_keys($rows)); $h <= max(array_keys($rows)); $h++) {
                    $out[$h] = $rows[$h] ?? $zero;
                }
            }
            return $out;
        }
        $last = min(strtotime($p->toDate()), strtotime(date('Y-m-d', $now)));
        for ($d = strtotime($p->fromDate()); $d <= $last; $d = strtotime('+1 day', $d)) {
            $key = date('Y-m-d', $d);
            $out[$key] = $rows[$key] ?? $zero;
        }
        return $out;
    }

    /** answered / missed (an operator's phone rang, nobody answered) / abandoned (the rest). */
    private function outcomes(Period $p): array
    {
        $st = $this->db->prepare(
            "SELECT SUM(c.answered_by IS NOT NULL) AS answered,
                    SUM(c.answered_by IS NULL AND EXISTS (SELECT 1 FROM call_agents a
                        WHERE a.call_id = c.id AND a.outcome = 'missed')) AS missed,
                    COUNT(*) AS total
             FROM calls c WHERE c.started_at BETWEEN ? AND ? AND c." . self::IN
        );
        $st->execute([$p->from, $p->to]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $answered = (int) $r['answered'];
        $missed = (int) $r['missed'];
        return ['answered' => $answered, 'missed' => $missed, 'abandoned' => (int) $r['total'] - $answered - $missed];
    }

    private function types(Period $p): array
    {
        $st = $this->db->prepare("SELECT kind, COUNT(*) AS n FROM calls
            WHERE started_at BETWEEN ? AND ? AND kind <> 'internal' GROUP BY kind");
        $st->execute([$p->from, $p->to]);
        $out = ['queue' => 0, 'direct' => 0, 'outbound' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['kind']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<int, array{calls:int, answered:int}> hour => counts, hours with calls only */
    private function hours(Period $p): array
    {
        $st = $this->db->prepare('SELECT HOUR(started_at) AS h, COUNT(*) AS calls, SUM(answered_by IS NOT NULL) AS answered
            FROM calls WHERE started_at BETWEEN ? AND ? AND ' . self::IN . ' GROUP BY h ORDER BY h');
        $st->execute([$p->from, $p->to]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['h']] = ['calls' => (int) $r['calls'], 'answered' => (int) $r['answered']];
        }
        return $out;
    }

    private function clients(Period $p): array
    {
        $st = $this->db->prepare("SELECT client_number AS number, COUNT(*) AS calls,
                SUM(answered_by IS NULL) AS lost, MAX(started_at) AS last_at
            FROM calls WHERE started_at BETWEEN ? AND ? AND " . self::IN . " AND client_number <> ''
            GROUP BY client_number ORDER BY calls DESC, last_at DESC LIMIT 10");
        $st->execute([$p->from, $p->to]);
        return array_map(function (array $r): array {
            return ['number' => (string) $r['number'], 'calls' => (int) $r['calls'],
                'lost' => (int) $r['lost'], 'last_at' => (string) $r['last_at']];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }
}

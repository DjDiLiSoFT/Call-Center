<?php

namespace CallStats\Report;

use PDO;

/**
 * The client side of the queue: inbound client calls (kind queue|direct) — how many came, were
 * served, were lost, how long clients waited, whom nobody called back, who calls again and again.
 * A call is answered when calls.answered_by is set; otherwise it is lost.
 */
class QueueSummaryReport
{
    /**
     * wait buckets: label => lowest second; the upper end is inclusive (6–10 = 6..10 s), so the
     * first two buckets are exactly the service level (answered within SLA_SEC = 10 s)
     */
    public const BUCKETS = ['0–5' => 0, '6–10' => 6, '11–15' => 11, '16–30' => 16, '31+' => 31];

    /** answered within this many seconds counts towards the service level */
    public const SLA_SEC = 10;

    /** a lost call is judged "called back or not" once it is this old (withCallbacks looks 24 h ahead) */
    public const CALLBACK_WINDOW = 86400;

    /** a lost call with a shorter wait is "dropped at once" */
    public const SHORT_SEC = 5;

    private const INBOUND = "c.kind IN ('queue', 'direct')";
    private const ANSWERED = "COALESCE(c.answered_by, '') <> ''";

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Counts and waits of the period without callback data.
     *
     * @return array{total:int, answered:int, lost:int, lost_short:int, lost_pct:?float, sla_pct:?float, avg_wait:?int, max_wait:?int}
     */
    public function summary(Period $p): array
    {
        $st = $this->db->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(' . self::ANSWERED . ') AS answered,
                    SUM(NOT (' . self::ANSWERED . ') AND c.wait_sec < ' . self::SHORT_SEC . ') AS lost_short,
                    SUM(' . self::ANSWERED . ' AND c.wait_sec <= ' . self::SLA_SEC . ') AS sla,
                    SUM(CASE WHEN ' . self::ANSWERED . ' THEN c.wait_sec ELSE 0 END) AS answered_wait,
                    MAX(c.wait_sec) AS max_wait
             FROM calls c WHERE ' . self::INBOUND . ' AND c.started_at >= ? AND c.started_at <= ?'
        );
        $st->execute([$p->from, $p->to]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $total = (int) $r['total'];
        $answered = (int) $r['answered'];
        $lost = $total - $answered;
        return [
            'total' => $total,
            'answered' => $answered,
            'lost' => $lost,
            'lost_short' => (int) $r['lost_short'],
            'lost_pct' => self::pct($lost, $total),
            'sla_pct' => self::pct((int) $r['sla'], $total),
            'avg_wait' => $answered > 0 ? (int) round((int) $r['answered_wait'] / $answered) : null,
            'max_wait' => $total > 0 ? (int) $r['max_wait'] : null,
        ];
    }

    /**
     * summary() plus called_back / called_back_pct (lost calls with a number that were called back
     * or got through by themselves) and lost_calls (those lost calls, newest first).
     *
     * Only lost calls at least CALLBACK_WINDOW old (relative to $now) are judged: a call lost a few
     * minutes ago has had no time to be called back, so counting it would make a running period
     * look worse than a finished one. called_back_base = how many were judged.
     *
     * @param array[]|null $lostCalls lostCalls() of this period or of a wider one (filtered here),
     *                                so one callback batch can serve several periods; null = load
     * @param int|null $now default time()
     */
    public function fetch(Period $p, ?array $lostCalls = null, ?int $now = null): array
    {
        $s = $this->summary($p);
        if ($lostCalls === null) {
            $lostCalls = $this->lostCalls($p);
        }
        $mine = array_values(array_filter($lostCalls, function (array $it) use ($p): bool {
            return $it['at'] >= $p->from && $it['at'] <= $p->to;
        }));
        $ripe = date('Y-m-d H:i:s', ($now ?? time()) - self::CALLBACK_WINDOW);
        $back = 0;
        $base = 0;
        foreach ($mine as $it) {
            if ($it['at'] > $ripe) {
                continue;
            }
            $base++;
            if (in_array($it['callback']['kind'] ?? 'none', ['callback', 'got_through'], true)) {
                $back++;
            }
        }
        $s['called_back'] = $back;
        $s['called_back_base'] = $base;
        $s['called_back_pct'] = self::pct($back, $base);
        $s['lost_calls'] = $mine;
        return $s;
    }

    /**
     * Lost inbound calls with a client number, newest first, with OperatorJournal::withCallbacks()
     * data (one batch) and the operators whose phones rang ('rang', extensions; offline ones excluded).
     * Item: type 'in_missed', call_id, at, client, wait_sec, rang, callback.
     *
     * @return array[]
     */
    public function lostCalls(Period $p): array
    {
        $st = $this->db->prepare(
            "SELECT c.id, c.started_at, c.client_number, c.wait_sec,
                    GROUP_CONCAT(DISTINCT CASE WHEN ca.outcome <> 'offline' THEN ca.extension END ORDER BY ca.extension SEPARATOR ',') AS rang
             FROM calls c LEFT JOIN call_agents ca ON ca.call_id = c.id AND ca.direction = 'in'
             WHERE " . self::INBOUND . ' AND NOT (' . self::ANSWERED . ') AND c.started_at >= ? AND c.started_at <= ?
             GROUP BY c.id, c.started_at, c.client_number, c.wait_sec
             ORDER BY c.started_at, c.id'
        );
        $st->execute([$p->from, $p->to]);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $client = (string) $r['client_number'];
            if (OperatorJournal::normalizeNumber($client) === '') {
                continue;
            }
            $items[] = [
                'type' => 'in_missed',
                'call_id' => (int) $r['id'],
                'at' => (string) $r['started_at'],
                'client' => $client,
                'wait_sec' => (int) $r['wait_sec'],
                'rang' => $r['rang'] === null || $r['rang'] === '' ? [] : explode(',', (string) $r['rang']),
            ];
        }
        $items = (new OperatorJournal($this->db))->withCallbacks($items);
        return array_reverse($items);
    }

    /** @return array<int, array{answered:int, lost:int}> hour => counts, hours with calls only */
    public function byHour(Period $p): array
    {
        $out = [];
        foreach ($this->grouped('HOUR(c.started_at)', $p) as $r) {
            $out[(int) $r['k']] = ['answered' => (int) $r['answered'], 'lost' => (int) $r['total'] - (int) $r['answered']];
        }
        ksort($out);
        return $out;
    }

    /** @return array<string, array{answered:int, lost:int}> 'Y-m-d' => counts, every day of the period */
    public function byDay(Period $p): array
    {
        $out = [];
        for ($d = $p->fromDate(); $d <= $p->toDate(); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $out[$d] = ['answered' => 0, 'lost' => 0];
        }
        foreach ($this->grouped('DATE(c.started_at)', $p) as $r) {
            $out[(string) $r['k']] = ['answered' => (int) $r['answered'], 'lost' => (int) $r['total'] - (int) $r['answered']];
        }
        return $out;
    }

    /** @return array<int, array<int,int>> weekday (1 = Monday .. 7 = Sunday) => [hour => inbound calls] */
    public function heatmap(Period $p): array
    {
        $out = array_fill_keys(range(1, 7), []);
        $st = $this->db->prepare(
            'SELECT WEEKDAY(c.started_at) + 1 AS dow, HOUR(c.started_at) AS h, COUNT(*) AS n
             FROM calls c WHERE ' . self::INBOUND . ' AND c.started_at >= ? AND c.started_at <= ?
             GROUP BY dow, h ORDER BY dow, h'
        );
        $st->execute([$p->from, $p->to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['dow']][(int) $r['h']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Answered calls by how long the client waited for the answer, lost calls by how long the
     * client waited before hanging up.
     *
     * @return array{answered: array<string,int>, lost: array<string,int>} bucket label => calls
     */
    public function waitBuckets(Period $p): array
    {
        $st = $this->db->prepare(
            'SELECT ' . self::ANSWERED . ' AS ans, c.wait_sec, COUNT(*) AS n
             FROM calls c WHERE ' . self::INBOUND . ' AND c.started_at >= ? AND c.started_at <= ?
             GROUP BY ans, c.wait_sec'
        );
        $st->execute([$p->from, $p->to]);
        $waits = ['answered' => [], 'lost' => []];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $waits[(int) $r['ans'] ? 'answered' : 'lost'][(int) $r['wait_sec']] = (int) $r['n'];
        }
        return ['answered' => self::bucketCounts($waits['answered']), 'lost' => self::bucketCounts($waits['lost'])];
    }

    /** Bucket label of a wait in seconds. */
    public static function waitBucket(int $sec): string
    {
        $found = '0–5';
        foreach (self::BUCKETS as $label => $from) {
            if ($sec >= $from) {
                $found = $label;
            }
        }
        return $found;
    }

    /**
     * «Кому не перезвонили»: one row per client number (last 9 digits) instead of one per attempt.
     * Row: client (newest original), at (last attempt), first_at, times, max_wait, rang (union, sorted).
     *
     * @param array[] $lost lost items, newest first (at, client, wait_sec, rang)
     * @return array[] newest last attempt first
     */
    public static function groupLostByNumber(array $lost): array
    {
        $out = [];
        foreach ($lost as $it) {
            $key = OperatorJournal::normalizeNumber((string) $it['client']);
            $key = $key === '' ? 'raw:' . $it['client'] : $key;
            if (!isset($out[$key])) {
                $out[$key] = ['client' => (string) $it['client'], 'at' => $it['at'], 'first_at' => $it['at'],
                    'times' => 0, 'max_wait' => 0, 'rang' => []];
            }
            $g = &$out[$key];
            $g['times']++;
            $g['max_wait'] = max($g['max_wait'], (int) $it['wait_sec']);
            if ($it['at'] > $g['at']) {
                $g['at'] = $it['at'];
                $g['client'] = (string) $it['client'];
            }
            if ($it['at'] < $g['first_at']) {
                $g['first_at'] = $it['at'];
            }
            $g['rang'] = array_values(array_unique(array_merge($g['rang'], array_map('strval', $it['rang']))));
            sort($g['rang'], SORT_NATURAL);
            unset($g);
        }
        $out = array_values($out);
        usort($out, function (array $a, array $b): int {
            return strcmp($b['at'], $a['at']);
        });
        return $out;
    }

    /**
     * @param array<int,int> $waits wait seconds => calls
     * @return array<string,int> every bucket label => calls
     */
    public static function bucketCounts(array $waits): array
    {
        $out = array_fill_keys(array_keys(self::BUCKETS), 0);
        foreach ($waits as $sec => $n) {
            $out[self::waitBucket((int) $sec)] += (int) $n;
        }
        return $out;
    }

    /**
     * Numbers (matched by their last 9 digits) with at least $min inbound calls in the period, most
     * calls first. Row: number (the form seen last), calls, answered, lost, last_at.
     *
     * @return array[]
     */
    public function repeatCallers(Period $p, int $min = 3, int $limit = 20): array
    {
        $st = $this->db->prepare(
            'SELECT c.client_number, COUNT(*) AS calls, SUM(' . self::ANSWERED . ') AS answered, MAX(c.started_at) AS last_at
             FROM calls c WHERE ' . self::INBOUND . " AND c.client_number <> '' AND c.started_at >= ? AND c.started_at <= ?
             GROUP BY c.client_number"
        );
        $st->execute([$p->from, $p->to]);
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = OperatorJournal::normalizeNumber((string) $r['client_number']);
            if ($key === '') {
                continue;
            }
            if (!isset($by[$key])) {
                $by[$key] = ['number' => '', 'calls' => 0, 'answered' => 0, 'lost' => 0, 'last_at' => ''];
            }
            $g = &$by[$key];
            $g['calls'] += (int) $r['calls'];
            $g['answered'] += (int) $r['answered'];
            $g['lost'] = $g['calls'] - $g['answered'];
            if ((string) $r['last_at'] > $g['last_at']) {
                $g['last_at'] = (string) $r['last_at'];
                $g['number'] = (string) $r['client_number'];
            }
            unset($g);
        }
        $rows = array_values(array_filter($by, function (array $g) use ($min): bool {
            return $g['calls'] >= $min;
        }));
        usort($rows, function (array $a, array $b): int {
            return [$b['calls'], $b['last_at']] <=> [$a['calls'], $a['last_at']];
        });
        return array_slice($rows, 0, max(1, $limit));
    }

    /** @return array[] rows k, total, answered grouped by $expr */
    private function grouped(string $expr, Period $p): array
    {
        $st = $this->db->prepare(
            "SELECT $expr AS k, COUNT(*) AS total, SUM(" . self::ANSWERED . ') AS answered
             FROM calls c WHERE ' . self::INBOUND . ' AND c.started_at >= ? AND c.started_at <= ?
             GROUP BY k'
        );
        $st->execute([$p->from, $p->to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function pct(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }
}

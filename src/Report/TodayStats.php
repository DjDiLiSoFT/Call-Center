<?php

namespace CallStats\Report;

use PDO;

/**
 * Inbound totals for the live board (normally "today"): offered = queue + direct calls,
 * service level = share of offered calls answered within $slSec of waiting.
 */
class TodayStats
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function fetch(string $from, string $to, int $slSec): array
    {
        $st = $this->db->prepare(
            "SELECT COUNT(*) AS inbound,
                    SUM(answered_by IS NOT NULL) AS answered,
                    SUM(CASE WHEN answered_by IS NOT NULL THEN wait_sec ELSE 0 END) AS wait_sum,
                    SUM(answered_by IS NOT NULL AND wait_sec <= ?) AS within_sl
             FROM calls
             WHERE started_at BETWEEN ? AND ? AND kind IN ('queue', 'direct')"
        );
        $st->execute([$slSec, $from, $to]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $inbound = (int) $r['inbound'];
        $answered = (int) $r['answered'];

        $st = $this->db->prepare("SELECT COUNT(*) FROM calls WHERE started_at BETWEEN ? AND ? AND kind = 'outbound'");
        $st->execute([$from, $to]);
        $outbound = (int) $st->fetchColumn();

        $st = $this->db->prepare(
            "SELECT a.extension, COUNT(*) AS n
             FROM call_agents a JOIN calls c ON c.id = a.call_id
             WHERE c.started_at BETWEEN ? AND ? AND c.kind <> 'internal'
               AND a.direction = 'in' AND a.outcome = 'answered'
             GROUP BY a.extension ORDER BY a.extension"
        );
        $st->execute([$from, $to]);
        $byAgent = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byAgent[(string) $row['extension']] = (int) $row['n'];
        }

        return [
            'inbound' => $inbound,
            'answered' => $answered,
            'lost' => $inbound - $answered,
            'answer_pct' => $inbound > 0 ? round($answered * 100 / $inbound, 1) : null,
            'avg_wait' => $answered > 0 ? (int) round((int) $r['wait_sum'] / $answered) : 0,
            'sl_pct' => $inbound > 0 ? round((int) $r['within_sl'] * 100 / $inbound, 1) : null,
            'outbound' => $outbound,
            'answered_by_agent' => $byAgent,
        ];
    }
}

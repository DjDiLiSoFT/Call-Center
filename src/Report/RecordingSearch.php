<?php

namespace CallStats\Report;

use PDO;

/**
 * Calls that have a recording file, filtered for the "Записи" page.
 */
class RecordingSearch
{
    public const PER_PAGE = 50;

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param string|null $ext operator extension (calls he answered), null = everybody
     * @param string|null $direction 'in' (queue/direct), 'out' (outbound), null/'all' = all kinds
     * @param string $sort 'at' (start time) | 'dur' (talk time)
     * @param string $order 'asc' | 'desc'
     * @return array{rows: array[], total: int, page: int, pages: int, from: int, to: int}
     *         page is clamped to the last page
     */
    public function search(
        Period $p,
        ?string $ext,
        ?string $numberLike,
        ?int $minSec,
        ?int $maxSec,
        ?string $direction,
        ?string $queue,
        int $page,
        string $sort = 'at',
        string $order = 'desc',
        int $perPage = self::PER_PAGE
    ): array {
        $where = ['c.started_at BETWEEN ? AND ?', 'c.recording_file IS NOT NULL', "c.recording_file <> ''"];
        $args = [$p->from, $p->to];

        if ($ext !== null && $ext !== '') {
            $where[] = "EXISTS (SELECT 1 FROM call_agents a WHERE a.call_id = c.id AND a.extension = ? AND a.outcome = 'answered')";
            $args[] = $ext;
        }
        if ($numberLike !== null && $numberLike !== '') {
            $where[] = "c.client_number LIKE ? ESCAPE '|'";
            $args[] = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $numberLike) . '%';
        }
        if ($minSec !== null) {
            $where[] = 'c.talk_sec >= ?';
            $args[] = $minSec;
        }
        if ($maxSec !== null) {
            $where[] = 'c.talk_sec <= ?';
            $args[] = $maxSec;
        }
        if ($direction === 'in') {
            $where[] = "c.kind IN ('queue','direct')";
        } elseif ($direction === 'out') {
            $where[] = "c.kind = 'outbound'";
        }
        if ($queue !== null && $queue !== '') {
            $where[] = 'c.queue = ?';
            $args[] = $queue;
        }
        $w = implode(' AND ', $where);

        $st = $this->db->prepare("SELECT COUNT(*) FROM calls c WHERE $w");
        $st->execute($args);
        $total = (int) $st->fetchColumn();

        $perPage = max(1, $perPage);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $dir = $order === 'asc' ? 'ASC' : 'DESC';
        $orderBy = $sort === 'dur' ? "c.talk_sec $dir, c.started_at $dir" : "c.started_at $dir";
        $st = $this->db->prepare(
            'SELECT c.id, c.started_at, c.kind, c.queue, c.client_number, c.talk_sec, c.answered_by, c.recording_file'
            . " FROM calls c WHERE $w ORDER BY $orderBy, c.id $dir LIMIT " . $perPage
            . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return [
            'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages,
            'from' => $total > 0 ? ($page - 1) * $perPage + 1 : 0,
            'to' => $total > 0 ? ($page - 1) * $perPage + count($rows) : 0,
        ];
    }
}

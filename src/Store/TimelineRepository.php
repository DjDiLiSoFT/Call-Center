<?php

namespace CallStats\Store;

use PDO;

/**
 * Tables `agent_sessions` and `agent_pauses`.
 */
class TimelineRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Deletes sessions and pauses with start_at in [$from, $to] and inserts the rows of
     * $timeline (AgentTimeline::build result) whose start_at is in that range, in one transaction.
     *
     * @param array{sessions: array[], pauses: array[]} $timeline
     */
    public function replaceRange(string $from, string $to, array $timeline): void
    {
        $this->db->beginTransaction();
        try {
            foreach (['agent_sessions', 'agent_pauses'] as $t) {
                $this->db->prepare("DELETE FROM $t WHERE start_at >= ? AND start_at <= ?")->execute([$from, $to]);
            }
            $s = $this->db->prepare('INSERT INTO agent_sessions (extension, queue, start_at, end_at) VALUES (?,?,?,?)');
            foreach ($timeline['sessions'] ?? [] as $r) {
                if ($r['start_at'] >= $from && $r['start_at'] <= $to) {
                    $s->execute([$r['extension'], $r['queue'], $r['start_at'], $r['end_at']]);
                }
            }
            $p = $this->db->prepare(
                'INSERT INTO agent_pauses (extension, queue, start_at, end_at, reason) VALUES (?,?,?,?,?)'
            );
            foreach ($timeline['pauses'] ?? [] as $r) {
                if ($r['start_at'] >= $from && $r['start_at'] <= $to) {
                    $p->execute([$r['extension'], $r['queue'], $r['start_at'], $r['end_at'], mb_substr((string) ($r['reason'] ?? ''), 0, 80)]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** True if there is any session or pause overlapping [$from, $to]. */
    public function hasAny(string $from, string $to): bool
    {
        foreach (['agent_sessions', 'agent_pauses'] as $t) {
            $st = $this->db->prepare(
                "SELECT 1 FROM $t WHERE start_at <= ? AND (end_at IS NULL OR end_at >= ?) LIMIT 1"
            );
            $st->execute([$to, $from]);
            if ($st->fetchColumn() !== false) {
                return true;
            }
        }
        return false;
    }

    /** Earliest start_at of a session or pause that is still open, or null. */
    public function earliestOpenStart(): ?string
    {
        $min = null;
        foreach (['agent_sessions', 'agent_pauses'] as $t) {
            $v = $this->db->query("SELECT MIN(start_at) FROM $t WHERE end_at IS NULL")->fetchColumn();
            if ($v !== null && $v !== false && ($min === null || $v < $min)) {
                $min = (string) $v;
            }
        }
        return $min;
    }
}

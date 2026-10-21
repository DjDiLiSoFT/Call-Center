<?php

namespace CallStats\Store;

use CallStats\Build\CallRecord;
use PDO;

/**
 * Tables `calls`, `call_agents`, `transfers`.
 */
class CallRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Replaces the call with the same call_key (with its call_agents and transfers), in one transaction. */
    public function replace(CallRecord $c): void
    {
        $this->db->beginTransaction();
        try {
            $this->deleteByKey($c->callKey);

            $st = $this->db->prepare(
                'INSERT INTO calls (call_key, kind, queue, started_at, answered_at, ended_at, client_number, answered_by,'
                . ' wait_sec, talk_sec, recording_file, approx, sources) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $st->execute([
                $c->callKey,
                $c->kind,
                $c->queue,
                $c->startedAt,
                $c->answeredAt,
                $c->endedAt,
                mb_substr((string) $c->clientNumber, 0, 80),
                $c->answeredBy,
                (int) $c->waitSec,
                (int) $c->talkSec,
                $c->recordingFile,
                $c->approx ? 1 : 0,
                json_encode($c->sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $callId = (int) $this->db->lastInsertId();

            if ($c->agents) {
                $st = $this->db->prepare(
                    'INSERT INTO call_agents (call_id, extension, direction, outcome, ring_sec, talk_sec, first_ring_at, answered_at)'
                    . ' VALUES (?,?,?,?,?,?,?,?)'
                );
                foreach ($c->agents as $a) {
                    $st->execute([
                        $callId, $a->extension, $a->direction, $a->outcome,
                        (int) $a->ringSec, (int) $a->talkSec, $a->firstRingAt, $a->answeredAt,
                    ]);
                }
            }
            if ($c->transfers) {
                $st = $this->db->prepare('INSERT INTO transfers (call_id, from_ext, to_ext, at) VALUES (?,?,?,?)');
                foreach ($c->transfers as $t) {
                    $st->execute([$callId, $t['from'], mb_substr((string) $t['to'], 0, 80), $t['at']]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** call_keys of calls with ended_at >= $since. @return string[] */
    public function keysEndedSince(string $since): array
    {
        $st = $this->db->prepare('SELECT call_key FROM calls WHERE ended_at >= ?');
        $st->execute([$since]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Deletes calls with started_at in [$from, $to] whose call_key is not in $keep.
     *
     * @param string[] $keep
     * @return int number of deleted calls
     */
    public function deleteStartedBetweenExcept(string $from, string $to, array $keep): int
    {
        $st = $this->db->prepare('SELECT call_key FROM calls WHERE started_at >= ? AND started_at <= ?');
        $st->execute([$from, $to]);
        $keep = array_flip($keep);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $key) {
            if (!isset($keep[$key])) {
                $this->db->beginTransaction();
                try {
                    $this->deleteByKey((string) $key);
                    $this->db->commit();
                } catch (\Throwable $e) {
                    $this->db->rollBack();
                    throw $e;
                }
                $n++;
            }
        }
        return $n;
    }

    private function deleteByKey(string $key): void
    {
        $st = $this->db->prepare('SELECT id FROM calls WHERE call_key = ?');
        $st->execute([$key]);
        $id = $st->fetchColumn();
        if ($id === false) {
            return;
        }
        foreach (['call_agents', 'transfers'] as $t) {
            $this->db->prepare("DELETE FROM $t WHERE call_id = ?")->execute([$id]);
        }
        $this->db->prepare('DELETE FROM calls WHERE id = ?')->execute([$id]);
    }
}

<?php

namespace CallStats\Report;

use CallStats\Store\OperatorRepository;
use CallStats\Web\RecordingLocator;
use PDO;

/**
 * Chronological log of one operator for a period (spec 3.2): queue logins, pauses, every call with its outcome.
 */
class OperatorJournal
{
    private const IN_TYPES = [
        'answered' => 'in_answered',
        'missed' => 'in_missed',
        'other_answered' => 'in_other',
        'caller_hangup' => 'in_caller_hangup',
        'offline' => 'in_offline',
    ];

    /** order of events with the same timestamp */
    private const ORDER = [
        'session_start' => 0, 'pause' => 1, 'in_answered' => 2, 'in_missed' => 2, 'in_other' => 2,
        'in_caller_hangup' => 2, 'in_offline' => 2, 'out' => 2, 'internal' => 2,
        'transfer_out' => 3, 'transfer_in' => 3, 'session_end' => 4,
    ];

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array[] items sorted by 'at' */
    public function fetch(string $ext, Period $p): array
    {
        $names = [];
        foreach ($this->db->query('SELECT queue, name FROM queues')->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $names[(string) $q['queue']] = (string) $q['name'];
        }
        $queueName = function (?string $q) use ($names): string {
            $q = (string) $q;
            return ($q !== '' && isset($names[$q]) && $names[$q] !== '') ? $names[$q] : $q;
        };

        $items = [];

        $st = $this->db->prepare('SELECT queue, start_at, end_at FROM agent_sessions WHERE extension = ? AND ((start_at >= ? AND start_at <= ?) OR (end_at >= ? AND end_at <= ?))');
        $st->execute([$ext, $p->from, $p->to, $p->from, $p->to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['start_at'] >= $p->from && $r['start_at'] <= $p->to) {
                $items[] = self::item($r['start_at'], 'session_start', ['source' => $queueName($r['queue'])]);
            }
            if ($r['end_at'] !== null && $r['end_at'] >= $p->from && $r['end_at'] <= $p->to) {
                $items[] = self::item($r['end_at'], 'session_end', ['source' => $queueName($r['queue'])]);
            }
        }

        $st = $this->db->prepare('SELECT queue, start_at, end_at, reason FROM agent_pauses WHERE extension = ? AND start_at >= ? AND start_at <= ?');
        $st->execute([$ext, $p->from, $p->to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = self::item($r['start_at'], 'pause', [
                'source' => $queueName($r['queue']),
                'until' => $r['end_at'],
                'reason' => (string) $r['reason'],
            ]);
        }

        $st = $this->db->prepare(
            'SELECT c.id, c.kind, c.queue, c.started_at, c.client_number, c.answered_by, c.recording_file, c.approx, c.sources,
                    ca.direction, ca.outcome, ca.ring_sec, ca.talk_sec, ca.first_ring_at
             FROM call_agents ca JOIN calls c ON c.id = ca.call_id
             WHERE ca.extension = ? AND c.started_at >= ? AND c.started_at <= ?'
        );
        $st->execute([$ext, $p->from, $p->to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $common = [
                'call_id' => (int) $r['id'],
                'client' => (string) $r['client_number'],
                'ring_sec' => (int) $r['ring_sec'],
                'talk_sec' => (int) $r['talk_sec'],
                'has_recording' => (string) $r['recording_file'] !== '',
                'approx' => (bool) $r['approx'],
                'sources' => $r['sources'] === null ? null : (string) $r['sources'],
            ];
            if ($r['kind'] === 'internal') {
                $common['client'] = $this->otherParty($ext, $r);
                $items[] = self::item($r['direction'] === 'out' ? $r['started_at'] : $r['first_ring_at'], 'internal', $common + [
                    'source' => 'Внутренний',
                    'reason' => $r['direction'] === 'out' ? 'out' : 'in',
                ]);
                continue;
            }
            if ($r['direction'] === 'out') {
                $items[] = self::item($r['started_at'], 'out', $common + ['source' => 'Исходящий', 'reason' => (string) $r['outcome']]);
                continue;
            }
            if (!isset(self::IN_TYPES[$r['outcome']])) {
                continue;
            }
            if ($r['kind'] === 'queue') {
                $source = 'Очередь «' . $queueName($r['queue']) . '»';
            } else {
                $source = 'Прямой';
            }
            $items[] = self::item($r['first_ring_at'], self::IN_TYPES[$r['outcome']], $common + ['source' => $source]);
        }

        $st = $this->db->prepare(
            'SELECT c.id, c.client_number, c.approx, c.sources, t.from_ext, t.to_ext, t.at
             FROM transfers t JOIN calls c ON c.id = t.call_id
             WHERE (t.from_ext = ? OR t.to_ext = ?) AND c.started_at >= ? AND c.started_at <= ?'
        );
        $st->execute([$ext, $ext, $p->from, $p->to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out = (string) $r['from_ext'] === $ext;
            $items[] = self::item($r['at'], $out ? 'transfer_out' : 'transfer_in', [
                'call_id' => (int) $r['id'],
                'client' => (string) $r['client_number'],
                'approx' => (bool) $r['approx'],
                'sources' => $r['sources'] === null ? null : (string) $r['sources'],
                'reason' => $out ? (string) $r['to_ext'] : (string) $r['from_ext'],
            ]);
        }

        foreach ($items as $i => &$it) {
            $it['_i'] = $i;
        }
        unset($it);
        usort($items, function (array $a, array $b): int {
            return [$a['at'], self::ORDER[$a['type']], $a['_i']] <=> [$b['at'], self::ORDER[$b['type']], $b['_i']];
        });
        foreach ($items as &$it) {
            unset($it['_i']);
        }
        unset($it);
        return $items;
    }

    /**
     * Journal of several operators merged into one list: every item gets 'ext'; sorted by 'at'
     * (ties keep operator order). With $collapseOffline each operator's offline runs are folded first.
     *
     * @param string[] $exts
     * @return array[]
     */
    public function fetchAll(Period $p, array $exts, bool $collapseOffline = false): array
    {
        $all = [];
        foreach (array_values(array_unique(array_map('strval', $exts))) as $ext) {
            $items = $this->fetch($ext, $p);
            if ($collapseOffline) {
                $items = self::collapseOffline($items);
            }
            foreach ($items as $it) {
                $it['ext'] = $ext;
                $it['_i'] = count($all);
                $all[] = $it;
            }
        }
        usort($all, function (array $a, array $b): int {
            return [$a['at'], $a['_i']] <=> [$b['at'], $b['_i']];
        });
        foreach ($all as &$it) {
            unset($it['_i']);
        }
        unset($it);
        return $all;
    }

    /** Phone number key for matching: the last 9 digits of its digits ('' when there are none). */
    public static function normalizeNumber(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number);
        return strlen($digits) > 9 ? substr($digits, -9) : $digits;
    }

    /**
     * Adds 'callback' to every missed / caller-hangup item with a client number:
     * ['kind' => 'callback'|'got_through'|'none', 'at', 'after_sec', 'ext', 'connected'].
     * callback = the first outbound call to the same number within 24 h after the item (any operator;
     * ext = caller, connected = it was answered); got_through = otherwise the first later inbound call
     * from that number answered by anyone within 24 h (ext = who answered). One query per batch.
     *
     * @param array[] $items
     * @return array[]
     */
    public function withCallbacks(array $items): array
    {
        $targets = [];
        foreach ($items as $i => $it) {
            if (($it['type'] === 'in_missed' || $it['type'] === 'in_caller_hangup')
                && self::normalizeNumber((string) ($it['client'] ?? '')) !== '') {
                $targets[] = $i;
            }
        }
        if (!$targets) {
            return $items;
        }
        $ats = array_map(function (int $i) use ($items): string {
            return $items[$i]['at'];
        }, $targets);
        $st = $this->db->prepare(
            "SELECT c.id, c.kind, c.started_at, c.client_number, c.answered_by, ca.extension, ca.outcome
             FROM calls c LEFT JOIN call_agents ca ON ca.call_id = c.id AND ca.direction = 'out'
             WHERE c.kind IN ('outbound', 'queue', 'direct') AND c.started_at > ? AND c.started_at <= ?
             ORDER BY c.started_at, c.id, ca.id"
        );
        $st->execute([min($ats), date('Y-m-d H:i:s', strtotime(max($ats)) + 86400)]);
        $out = [];
        $in = [];
        $seen = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            if (isset($seen[$id])) {
                continue; // several outbound legs: the first one counts
            }
            $seen[$id] = true;
            $num = self::normalizeNumber((string) $r['client_number']);
            if ($num === '') {
                continue;
            }
            if ($r['kind'] === 'outbound') {
                if ($r['extension'] !== null) {
                    $out[$num][] = ['id' => $id, 'at' => (string) $r['started_at'], 'ext' => (string) $r['extension'],
                        'connected' => $r['outcome'] === 'answered'];
                }
            } elseif ((string) $r['answered_by'] !== '') {
                $in[$num][] = ['id' => $id, 'at' => (string) $r['started_at'], 'ext' => (string) $r['answered_by'], 'connected' => true];
            }
        }
        $first = function (array $list, string $at, $callId): ?array {
            $limit = date('Y-m-d H:i:s', strtotime($at) + 86400);
            foreach ($list as $c) {
                if ($c['at'] > $at && $c['at'] <= $limit && $c['id'] !== $callId) {
                    return $c;
                }
            }
            return null;
        };
        foreach ($targets as $i) {
            $it = $items[$i];
            $num = self::normalizeNumber((string) $it['client']);
            $kind = 'callback';
            $hit = $first($out[$num] ?? [], $it['at'], $it['call_id']);
            if ($hit === null) {
                $kind = 'got_through';
                $hit = $first($in[$num] ?? [], $it['at'], $it['call_id']);
            }
            $items[$i]['callback'] = $hit === null
                ? ['kind' => 'none', 'at' => null, 'after_sec' => null, 'ext' => null, 'connected' => null]
                : ['kind' => $kind, 'at' => $hit['at'], 'after_sec' => strtotime($hit['at']) - strtotime($it['at']),
                    'ext' => $hit['ext'], 'connected' => $hit['connected']];
        }
        return $items;
    }

    /**
     * Calls with this client number (last 9 digits), any operator, newest first; internal calls excluded.
     * Item: call_id, at, kind, dir (in|out), client, ext (who answered / who called), result
     * (answered|missed|out_answered|out_failed), talk_sec, wait_sec, has_recording.
     *
     * @return array[]
     */
    public function clientHistory(string $number, int $limit = 100): array
    {
        $key = self::normalizeNumber($number);
        if ($key === '') {
            return [];
        }
        $like = '%' . addcslashes($key, '\\%_');
        $st = $this->db->prepare(
            "SELECT c.id, c.kind, c.started_at, c.client_number, c.answered_by, c.talk_sec, c.wait_sec, c.recording_file,
                    (SELECT ca.extension FROM call_agents ca WHERE ca.call_id = c.id AND ca.direction = 'out' ORDER BY ca.id LIMIT 1) AS out_ext,
                    (SELECT ca.outcome FROM call_agents ca WHERE ca.call_id = c.id AND ca.direction = 'out' ORDER BY ca.id LIMIT 1) AS out_outcome
             FROM calls c
             WHERE c.kind <> 'internal' AND c.client_number LIKE ?
             ORDER BY c.started_at DESC, c.id DESC
             LIMIT " . max(1, min(1000, $limit))
        );
        $st->execute([$like]);
        $res = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (self::normalizeNumber((string) $r['client_number']) !== $key) {
                continue; // LIKE '%key' also matches longer tails of a different short number
            }
            $outbound = $r['kind'] === 'outbound';
            if ($outbound) {
                $result = $r['out_outcome'] === 'answered' ? 'out_answered' : 'out_failed';
                $ext = $r['out_ext'] === null ? null : (string) $r['out_ext'];
            } else {
                $ext = (string) $r['answered_by'] !== '' ? (string) $r['answered_by'] : null;
                $result = $ext !== null ? 'answered' : 'missed';
            }
            $res[] = [
                'call_id' => (int) $r['id'],
                'at' => (string) $r['started_at'],
                'kind' => (string) $r['kind'],
                'dir' => $outbound ? 'out' : 'in',
                'client' => (string) $r['client_number'],
                'ext' => $ext,
                'result' => $result,
                'talk_sec' => (int) $r['talk_sec'],
                'wait_sec' => (int) $r['wait_sec'],
                'has_recording' => (string) $r['recording_file'] !== '',
            ];
        }
        return $res;
    }

    /**
     * Adds 'rec_status' (ok|empty|missing|unsupported) to every item that has a recording, using the
     * same locator as the recordings page.
     *
     * @param array[] $items
     * @return array[]
     */
    public function withRecordingStatus(array $items, RecordingLocator $locator): array
    {
        $ids = [];
        foreach ($items as $it) {
            if (!empty($it['has_recording']) && $it['call_id'] !== null) {
                $ids[(int) $it['call_id']] = true;
            }
        }
        $status = [];
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            $st = $this->db->prepare(
                'SELECT id, started_at, recording_file FROM calls WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $status[(int) $r['id']] = $locator->locate((string) $r['started_at'], (string) $r['recording_file'])['status'];
            }
        }
        foreach ($items as &$it) {
            if (!empty($it['has_recording']) && $it['call_id'] !== null) {
                $it['rec_status'] = $status[(int) $it['call_id']] ?? 'missing';
            }
        }
        unset($it);
        return $items;
    }

    /**
     * Folds runs of two or more consecutive in_offline items of the same day into one
     * ['type' => 'offline_group', 'at' => first, 'until' => last, 'count' => N] item.
     *
     * @param array[] $items sorted
     * @return array[]
     */
    public static function collapseOffline(array $items): array
    {
        $out = [];
        $run = [];
        $flush = function () use (&$out, &$run): void {
            if (count($run) >= 2) {
                $out[] = [
                    'at' => $run[0]['at'], 'type' => 'offline_group', 'count' => count($run),
                    'until' => $run[count($run) - 1]['at'], 'items' => $run,
                ];
            } else {
                foreach ($run as $r) {
                    $out[] = $r;
                }
            }
            $run = [];
        };
        foreach ($items as $it) {
            if ($it['type'] === 'in_offline') {
                if ($run && substr($run[0]['at'], 0, 10) !== substr($it['at'], 0, 10)) {
                    $flush();
                }
                $run[] = $it;
                continue;
            }
            $flush();
            $out[] = $it;
        }
        $flush();
        return $out;
    }

    /** The other side of an internal call as seen by $ext: "Name (1005)" or the bare number. */
    private function otherParty(string $ext, array $r): string
    {
        $other = '';
        $candidates = [];
        if ($r['direction'] === 'out') {
            $candidates[] = (string) $r['answered_by'];
        } else {
            $candidates[] = (string) $r['client_number'];
        }
        $st = $this->db->prepare('SELECT extension FROM call_agents WHERE call_id = ? AND extension <> ? ORDER BY id');
        $st->execute([(int) $r['id'], $ext]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $e) {
            $candidates[] = (string) $e;
        }
        $candidates[] = (string) $r['client_number'];
        foreach ($candidates as $c) {
            if ($c !== '' && $c !== $ext) {
                $other = $c;
                break;
            }
        }
        if ($other === '') {
            return '';
        }
        $name = (new OperatorRepository($this->db))->displayName($other);
        return $name !== $other ? $name . ' (' . $other . ')' : $other;
    }

    private static function item(string $at, string $type, array $extra): array
    {
        return array_merge([
            'at' => $at, 'type' => $type, 'call_id' => null, 'client' => null, 'source' => null,
            'ring_sec' => null, 'talk_sec' => null, 'until' => null, 'reason' => null,
            'has_recording' => false, 'approx' => false, 'sources' => null,
        ], $extra);
    }
}

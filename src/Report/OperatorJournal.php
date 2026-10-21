<?php

namespace CallStats\Report;

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
            'SELECT c.id, c.kind, c.queue, c.started_at, c.client_number, c.recording_file, c.approx, c.sources,
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

    private static function item(string $at, string $type, array $extra): array
    {
        return array_merge([
            'at' => $at, 'type' => $type, 'call_id' => null, 'client' => null, 'source' => null,
            'ring_sec' => null, 'talk_sec' => null, 'until' => null, 'reason' => null,
            'has_recording' => false, 'approx' => false, 'sources' => null,
        ], $extra);
    }
}

<?php

namespace CallStats;

use CallStats\Build\AgentResolver;
use CallStats\Build\AgentTimeline;
use CallStats\Build\CallBuilder;
use CallStats\Source\CdrSource;
use CallStats\Source\FreepbxDirectory;
use CallStats\Source\QueueLogEvent;
use CallStats\Source\QueueLogParser;
use CallStats\Source\QueueLogReader;
use CallStats\Store\CallRepository;
use CallStats\Store\OperatorRepository;
use CallStats\Store\StateRepository;
use CallStats\Store\TimelineRepository;
use PDO;

/**
 * Ties the sources (queue_log, CDR, FreePBX directory) to the builders and the own DB.
 *
 * runOnce(): cron pass (new queue_log lines, calls of the last lookback window, timeline of
 * yesterday + today). rebuild(): the same from scratch for a date range, reading every
 * queue_log* file (rotated, .gz).
 */
class Collector
{
    private const BATCH = 500;
    private const TIMELINE_EVENTS = [
        'ADDMEMBER', 'REMOVEMEMBER', 'AGENTLOGIN', 'AGENTLOGOFF', 'PAUSE', 'UNPAUSE', 'PAUSEALL', 'UNPAUSEALL',
    ];
    private const LINKEDID_RE = '/^\d+\.\d+$/';

    /** @var Config */
    private $cfg;
    /** @var PDO */
    private $db;
    /** @var StateRepository */
    private $state;
    /** @var CdrSource */
    private $cdr;
    /** @var FreepbxDirectory */
    private $dir;
    /** @var CallRepository */
    private $calls;
    /** @var TimelineRepository */
    private $timeline;
    /** @var OperatorRepository */
    private $operators;
    /** @var AgentResolver|null */
    private $resolver;
    /** @var callable|null */
    private $warningHandler;

    public function __construct(Config $cfg, PDO $db, PDO $src, PDO $fpbx)
    {
        $this->cfg = $cfg;
        $this->db = $db;
        $this->state = new StateRepository($db);
        $this->cdr = new CdrSource($src);
        $this->dir = new FreepbxDirectory($fpbx);
        $this->calls = new CallRepository($db);
        $this->timeline = new TimelineRepository($db);
        $this->operators = new OperatorRepository($db);
    }

    /** @return array<string,int> pass counters */
    public function runOnce(int $nowTs): array
    {
        $this->syncDirectory();
        $lookback = 60 * (int) $this->cfg->get('collector.lookback_minutes', 120);

        // queue_log: readNew() commits the offset itself, so remember the old state and
        // put it back if the events cannot be stored.
        $reader = new QueueLogReader((string) $this->cfg->get('queue_log.path', '/var/log/asterisk/queue_log'), $this->state);
        $before = [];
        foreach (['queue_log.inode', 'queue_log.offset'] as $k) {
            $before[$k] = $this->state->get($k);
        }
        $events = $reader->readNew();
        try {
            $inserted = $this->storeEvents($events);
        } catch (\Throwable $e) {
            foreach ($before as $k => $v) {
                if ($v === null) {
                    $this->state->remove($k);
                } else {
                    $this->state->set($k, $v);
                }
            }
            throw $e;
        }

        $lastRun = $this->state->get('collector.last_run');
        $fromTs = ($lastRun !== null ? min((int) $lastRun, $nowTs) : $nowTs) - $lookback;
        $maxHours = (int) $this->cfg->get('collector.max_call_hours', 24);
        $maxCall = 3600 * $maxHours;
        if ($lastRun !== null && $nowTs - (int) $lastRun > $maxCall) {
            $this->warn(sprintf(
                'collector was not run for %.1f h (more than collector.max_call_hours = %d): calls of the gap are'
                . ' collected from CDR by start time; queue_log events of rotated files need bin/rebuild.php',
                ($nowTs - (int) $lastRun) / 3600,
                $maxHours
            ));
        }
        // After an outage longer than max_call_hours the scan must still reach back to the last run.
        $startedFrom = min($nowTs - $maxCall, $fromTs);
        $ids = $this->cdr->linkedidsEndedSince($this->fmt($startedFrom), $this->fmt($fromTs), $this->fmt($nowTs));
        foreach ($events as $e) {
            $ids[] = $e->callid;
        }
        foreach ($this->calls->keysEndedSince($this->fmt($nowTs - $lookback)) as $k) {
            $ids[] = $k;
        }
        [$built, $empty] = $this->processCalls($ids);

        $yesterday = strtotime('yesterday', $nowTs);
        $tl = $this->recomputeTimeline($yesterday, $nowTs, $nowTs);

        $this->state->set('collector.last_run', (string) $nowTs);
        return [
            'qlog_new' => count($events),
            'qlog_inserted' => $inserted,
            'calls' => $built,
            'calls_skipped' => $empty,
            'sessions' => $tl[0],
            'pauses' => $tl[1],
        ];
    }

    /**
     * Full rebuild for [$fromDate 00:00:00, $toDate 23:59:59] ('Y-m-d').
     *
     * @return array<string,int>
     */
    public function rebuild(string $fromDate, string $toDate): array
    {
        $fromTs = strtotime($fromDate . ' 00:00:00');
        $toTs = strtotime($toDate . ' 23:59:59');
        if ($fromTs === false || $toTs === false || $fromTs > $toTs) {
            throw new \InvalidArgumentException("Bad date range: $fromDate .. $toDate");
        }
        $this->syncDirectory();

        $path = (string) $this->cfg->get('queue_log.path', '/var/log/asterisk/queue_log');
        $reader = new QueueLogReader($path, $this->state);
        $read = 0;
        $inserted = 0;
        $files = $this->queueLogFiles($path);
        foreach ($files as $file) {
            $events = $reader->readFile($file);
            $read += count($events);
            $inserted += $this->storeEvents($events);
        }

        $from = $this->fmt($fromTs);
        $to = $this->fmt($toTs);
        $ids = $this->cdr->linkedidsBetween($from, $to);
        $st = $this->db->prepare(
            "SELECT DISTINCT callid FROM qlog_events WHERE ts >= ? AND ts <= ? AND event = 'ENTERQUEUE'"
        );
        $st->execute([$fromTs, $toTs]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (string) $id;
        }
        [$built, $empty, $keys] = $this->processCalls($ids);
        $deleted = $this->calls->deleteStartedBetweenExcept($from, $to, $keys);

        $tl = $this->recomputeTimeline($fromTs, $toTs, time());
        return [
            'qlog_files' => count($files),
            'qlog_read' => $read,
            'qlog_inserted' => $inserted,
            'calls' => $built,
            'calls_skipped' => $empty,
            'calls_deleted' => $deleted,
            'sessions' => $tl[0],
            'pauses' => $tl[1],
        ];
    }

    /** @param callable $handler function (string $message): void; default writes to stderr */
    public function setWarningHandler(callable $handler): void
    {
        $this->warningHandler = $handler;
    }

    private function warn(string $msg): void
    {
        if ($this->warningHandler !== null) {
            ($this->warningHandler)($msg);
            return;
        }
        $line = date('Y-m-d H:i:s') . ' callstats warning: ' . $msg . "\n";
        if (defined('STDERR')) {
            fwrite(STDERR, $line);
        } else {
            error_log(rtrim($line));
        }
    }

    /** Operators and queue names from FreePBX into own tables; builds the resolver. */
    private function syncDirectory(): void
    {
        $exts = $this->dir->extensions();
        $this->operators->sync($exts);

        $st = $this->db->prepare('INSERT INTO queues (queue, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
        foreach ($this->dir->queues() as $q => $name) {
            $st->execute([(string) $q, mb_substr((string) $name, 0, 80)]);
        }

        // Queue members may be named by the operator's name: map unique non-numeric names.
        $names = [];
        $dup = [];
        foreach ($exts as $ext => $name) {
            if ($name === '' || $name === (string) $ext) {
                continue;
            }
            if (isset($names[$name])) {
                $dup[$name] = true;
            }
            $names[$name] = (string) $ext;
        }
        $this->resolver = new AgentResolver(array_map('strval', array_keys($exts)), array_diff_key($names, $dup));
    }

    /**
     * INSERT IGNORE into qlog_events (line_hash = sha1(raw)).
     *
     * @param QueueLogEvent[] $events
     * @return int rows actually inserted
     */
    private function storeEvents(array $events): int
    {
        $n = 0;
        foreach (array_chunk($events, self::BATCH) as $chunk) {
            $marks = [];
            $params = [];
            foreach ($chunk as $e) {
                $marks[] = '(?,?,?,?,?,?,?,?)';
                array_push(
                    $params,
                    $e->ts,
                    mb_substr($e->callid, 0, 40),
                    mb_substr($e->queue, 0, 40),
                    mb_substr($e->agent, 0, 80),
                    mb_substr($e->event, 0, 32),
                    mb_substr(implode('|', $e->data), 0, 255),
                    mb_substr($e->raw, 0, 512),
                    sha1($e->raw)
                );
            }
            $st = $this->db->prepare(
                'INSERT IGNORE INTO qlog_events (ts, callid, queue, agent, event, data, raw, line_hash) VALUES ' . implode(',', $marks)
            );
            $st->execute($params);
            $n += $st->rowCount();
        }
        return $n;
    }

    /**
     * Builds and stores every call of $ids.
     *
     * @param string[] $ids
     * @return array{0:int,1:int,2:string[]} [built, skipped (nothing to build), built keys]
     */
    private function processCalls(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), function ($id) {
            return preg_match(self::LINKEDID_RE, $id) === 1;
        })));
        $builder = new CallBuilder(
            $this->resolver,
            (int) $this->cfg->get('collector.queue_ring_timeout', 15),
            (int) $this->cfg->get('collector.direct_ring_timeout', 20)
        );
        sort($ids, SORT_STRING); // tight calldate window per batch
        $built = 0;
        $empty = 0;
        $keys = [];
        foreach (array_chunk($ids, self::BATCH) as $batch) {
            $rows = $this->cdr->rowsFor($batch);
            $qlog = $this->eventsFor($batch);
            foreach ($batch as $id) {
                $call = $builder->build($id, $rows[$id] ?? [], $qlog[$id] ?? []);
                if ($call === null) {
                    $empty++;
                    continue;
                }
                $this->calls->replace($call);
                $keys[] = $id;
                $built++;
            }
        }
        return [$built, $empty, $keys];
    }

    /**
     * qlog_events of the given callids, re-hydrated, in file order.
     *
     * @param string[] $callids
     * @return array<string,QueueLogEvent[]>
     */
    private function eventsFor(array $callids): array
    {
        $out = [];
        if (!$callids) {
            return $out;
        }
        $st = $this->db->prepare(
            'SELECT ts, callid, queue, agent, event, data, raw FROM qlog_events WHERE callid IN ('
            . implode(',', array_fill(0, count($callids), '?')) . ') ORDER BY ts, id'
        );
        $st->execute($callids);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(string) $r['callid']][] = $this->hydrate($r);
        }
        return $out;
    }

    private function hydrate(array $r): QueueLogEvent
    {
        // The stored raw line is authoritative; columns are the fallback (raw longer than 512).
        $raw = (string) ($r['raw'] ?? '');
        if ($raw !== '' && mb_strlen($raw) < 512) {
            $e = QueueLogParser::parse($raw);
            if ($e !== null) {
                return $e;
            }
        }
        $data = (string) $r['data'] === '' ? [] : explode('|', (string) $r['data']);
        $head = [(string) $r['ts'], (string) $r['callid'], (string) $r['queue'], (string) $r['agent'], (string) $r['event']];
        return new QueueLogEvent(
            (int) $r['ts'],
            (string) $r['callid'],
            (string) $r['queue'],
            (string) $r['agent'],
            (string) $r['event'],
            $data,
            implode('|', array_merge($head, $data))
        );
    }

    /**
     * Recomputes sessions and pauses with start_at in [$fromTs, $toTs]. The window is widened
     * back to the earliest still-open interval so that an open session is closed, not cut.
     *
     * @return int[] [sessions, pauses] built
     */
    private function recomputeTimeline(int $fromTs, int $toTs, int $nowTs): array
    {
        $open = $this->timeline->earliestOpenStart();
        if ($open !== null) {
            $openTs = strtotime($open);
            if ($openTs !== false && $openTs < $fromTs) {
                $fromTs = $openTs;
            }
        }
        $marks = implode(',', array_fill(0, count(self::TIMELINE_EVENTS), '?'));
        $st = $this->db->prepare(
            "SELECT ts, callid, queue, agent, event, data, raw FROM qlog_events WHERE ts >= ? AND ts <= ? AND event IN ($marks) ORDER BY ts, id"
        );
        $st->execute(array_merge([$fromTs, $nowTs], self::TIMELINE_EVENTS));
        $events = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $events[] = $this->hydrate($r);
        }
        $tl = (new AgentTimeline($this->resolver))->build($events, $nowTs);
        $this->timeline->replaceRange($this->fmt($fromTs), $this->fmt($toTs), $tl);
        return [count($tl['sessions']), count($tl['pauses'])];
    }

    /** Current queue_log plus every rotated file next to it (basename*, incl. .gz). @return string[] */
    private function queueLogFiles(string $path): array
    {
        $files = glob(dirname($path) . DIRECTORY_SEPARATOR . basename($path) . '*') ?: [];
        $files[] = $path;
        $out = [];
        foreach ($files as $f) {
            $real = is_file($f) ? realpath($f) : false;
            if ($real !== false) {
                $out[$real] = $real;
            }
        }
        return array_values($out);
    }

    private function fmt(int $ts): string
    {
        return date('Y-m-d H:i:s', $ts);
    }
}

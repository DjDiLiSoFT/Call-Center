<?php

namespace CallStats\Build;

use CallStats\Source\QueueLogEvent;

/**
 * Turns the CDR rows and queue_log events of one call (one linkedid) into a CallRecord
 * with exactly one AgentOutcome per operator and direction (spec 5.3).
 *
 * Per-source attempt extraction lives in AttemptCollector; this class decides the call kind,
 * merges the sources and fills the call-level columns.
 */
class CallBuilder
{
    /** @var AgentResolver */
    private $resolver;
    /** @var AttemptCollector */
    private $collector;
    /** @var int */
    private $directRingTimeoutSec;

    /**
     * @param int $ringTimeoutSec       queue member ring timeout; used only for queue calls without
     *                                  queue_log to tell a full ring-out from a cut-short leg
     * @param int $directRingTimeoutSec ring timeout of direct/internal calls: an unanswered leg
     *                                  shorter than this is 'caller hung up', not a miss
     */
    public function __construct(AgentResolver $r, int $ringTimeoutSec = 15, int $directRingTimeoutSec = 20)
    {
        $this->resolver = $r;
        $this->collector = new AttemptCollector($r, $ringTimeoutSec);
        $this->directRingTimeoutSec = $directRingTimeoutSec;
    }

    /**
     * @param array[]         $cdrRows    associative rows of asteriskcdrdb.cdr
     * @param QueueLogEvent[] $qlogEvents queue_log events with callid = $linkedid
     */
    public function build(string $linkedid, array $cdrRows, array $qlogEvents): ?CallRecord
    {
        $rows = $this->sortRows($cdrRows);
        $enter = null;
        foreach ($qlogEvents as $e) {
            if ($e->event === 'ENTERQUEUE') {
                $enter = $e;
                break;
            }
        }
        if (!$rows && $enter === null) {
            return null;
        }

        $call = new CallRecord();
        $call->callKey = $linkedid;
        $this->fillTimesAndSources($call, $rows, $qlogEvents);

        $queueRows = array_values(array_filter($rows, function (array $r) {
            return $r['dcontext'] === 'ext-queues';
        }));

        if ($enter !== null || $queueRows) {
            $this->buildQueue($call, $rows, $queueRows, $qlogEvents, $enter);
        } else {
            $first = $rows[0];
            $callerExt = $this->collector->phoneOf($first['channel']);
            if ($callerExt !== null && !$this->resolver->isExtension($first['dst'])) {
                $this->buildOutbound($call, $first, $callerExt);
            } else {
                $this->buildDirect($call, $rows, $callerExt);
            }
        }
        return $call;
    }

    private function buildQueue(CallRecord $call, array $rows, array $queueRows, array $events, ?QueueLogEvent $enter): void
    {
        $call->kind = 'queue';
        $call->approx = $enter === null;
        $call->queue = $enter !== null ? $enter->queue : $queueRows[0]['dst'];
        $call->clientNumber = ($enter !== null && ($enter->data[1] ?? '') !== '')
            ? $enter->data[1]
            : ($rows ? $rows[0]['src'] : '');

        $q = $this->collector->fromQueueLog($events, $queueRows);
        $eq = $this->collector->fromQueueRows($queueRows);
        $legRows = array_filter($rows, function (array $r) {
            return $r['dcontext'] === 'ext-local' && preg_match('#^Local/[^@]+@from-queue#', $r['channel']);
        });
        $legs = $this->collector->fromLegs($legRows, 'channel');
        $call->transfers = $q['transfers'];

        $agents = [];
        foreach (array_keys($q['agents'] + $eq + $legs) as $ext) {
            $a = $q['agents'][$ext] ?? $eq[$ext] ?? $legs[$ext];
            $leg = $legs[$ext] ?? null;
            if ($a['answered'] && $leg !== null && $leg['answered']) {
                // The leg to the phone knows the real ring time and talk time of the answerer.
                $a['answerRing'] = $leg['answerRing'];
                if (!isset($q['agents'][$ext])) {
                    // Not from queue_log: the ext-queues row has duration == billsec, so only the
                    // phone leg knows when the operator actually picked up and how long they talked.
                    $a['answeredAt'] = $leg['answeredAt'];
                    $a['talk'] = $leg['talk'];
                } elseif ($a['talk'] === null) {
                    $a['talk'] = $leg['talk']; // queue_log COMPLETE* wins when present
                }
            }
            $agents[(string) $ext] = $a;
        }

        $call->answeredBy = $q['connectedBy'] ?? $this->firstAnswered($agents);
        $answerer = $call->answeredBy !== null ? $agents[$call->answeredBy] : null;
        if ($q['talkSec'] !== null) {
            $call->talkSec = $q['talkSec'];
        } elseif ($answerer !== null) {
            $call->talkSec = (int) $answerer['talk'];
        }
        $this->finish($call, $agents, $q['waitSec']);
    }

    private function buildOutbound(CallRecord $call, array $row, string $ext): void
    {
        $call->kind = 'outbound';
        $call->clientNumber = $row['dst'];
        $start = strtotime($row['calldate']);
        $billsec = (int) $row['billsec'];
        $ring = max(0, (int) $row['duration'] - $billsec);
        $connected = $row['disposition'] === 'ANSWERED';
        $answeredAt = $connected ? $this->fmt($start + $ring) : null;

        $call->agents = [new AgentOutcome(
            $ext,
            'out',
            $connected ? 'answered' : 'not_connected',
            $ring,
            $connected ? $billsec : 0,
            $this->fmt($start),
            $answeredAt
        )];
        $call->answeredBy = $connected ? $ext : null;
        $call->answeredAt = $answeredAt;
        $call->talkSec = $connected ? $billsec : 0;
        $call->waitSec = $ring;
    }

    private function buildDirect(CallRecord $call, array $rows, ?string $callerExt): void
    {
        $first = $rows[0];
        $call->kind = ($callerExt !== null && $this->resolver->isExtension($first['dst'])) ? 'internal' : 'direct';
        $call->clientNumber = $first['src'];

        $legRows = array_filter($rows, function (array $r) {
            return $r['dcontext'] === 'ext-local' && $this->collector->phoneOf($r['dstchannel']) !== null;
        });
        $agents = $this->collector->fromLegs($legRows, 'dstchannel', $this->directRingTimeoutSec);
        if ($callerExt !== null) {
            unset($agents[$callerExt]);
        }

        $call->answeredBy = $this->firstAnswered($agents);
        if ($call->answeredBy !== null) {
            $call->talkSec = (int) $agents[$call->answeredBy]['talk'];
        }
        $this->finish($call, $agents, null);

        if ($call->kind === 'internal') {
            $connected = $call->answeredBy !== null;
            $ring = $connected
                ? $agents[$call->answeredBy]['answerRing']
                : max(0, (int) $first['duration'] - (int) $first['billsec']);
            $call->agents[] = new AgentOutcome(
                $callerExt,
                'out',
                $connected ? 'answered' : 'not_connected',
                $ring,
                $call->talkSec,
                $first['calldate'],
                $call->answeredAt
            );
        }
    }

    /**
     * Turns accumulated attempts into AgentOutcomes and fills answeredAt / waitSec.
     *
     * @param array<string,array> $agents
     */
    private function finish(CallRecord $call, array $agents, ?int $waitSec): void
    {
        ksort($agents, SORT_STRING);
        foreach ($agents as $ext => $a) {
            $ext = (string) $ext;
            $byThis = $a['answered'];
            $byOther = $call->answeredBy !== null && $call->answeredBy !== $ext;
            $outcome = OutcomeRules::decide($a['kinds'], $byThis, $byOther);
            $answeredAt = $byThis && $a['answeredAt'] !== null ? $this->fmt($a['answeredAt']) : null;
            $call->agents[] = new AgentOutcome(
                $ext,
                'in',
                $outcome,
                $byThis ? (int) $a['answerRing'] : (int) round($a['ringMs'] / 1000),
                $byThis ? (int) $a['talk'] : 0,
                $this->fmt($a['first']),
                $answeredAt
            );
        }

        $answerer = $call->answeredBy !== null ? $agents[$call->answeredBy] : null;
        if ($answerer !== null && $answerer['answeredAt'] !== null) {
            $call->answeredAt = $this->fmt($answerer['answeredAt']);
        }
        if ($waitSec !== null) {
            $call->waitSec = $waitSec;
        } else {
            $until = $call->answeredAt ?? $call->endedAt;
            $call->waitSec = max(0, strtotime($until) - strtotime($call->startedAt));
        }
    }

    /** @param array<string,array> $agents */
    private function firstAnswered(array $agents): ?string
    {
        $best = null;
        foreach ($agents as $ext => $a) {
            if ($a['answered'] && ($best === null || $a['answeredAt'] < $agents[$best]['answeredAt'])) {
                $best = (string) $ext;
            }
        }
        return $best;
    }

    /**
     * startedAt / endedAt / recordingFile / sources from all raw inputs.
     *
     * @param QueueLogEvent[] $events
     */
    private function fillTimesAndSources(CallRecord $call, array $rows, array $events): void
    {
        $start = null;
        $end = null;
        foreach ($rows as $r) {
            $t = strtotime($r['calldate']);
            $start = $start === null ? $t : min($start, $t);
            $end = $end === null ? $t + (int) $r['duration'] : max($end, $t + (int) $r['duration']);
            if ($call->recordingFile === null && $r['recordingfile'] !== '') {
                $call->recordingFile = $r['recordingfile'];
            }
            if (!in_array($r['uniqueid'], $call->sources['cdr'], true)) {
                $call->sources['cdr'][] = $r['uniqueid'];
            }
        }
        foreach ($events as $e) {
            if ($e->event === 'ENTERQUEUE') {
                $start = $start === null ? $e->ts : min($start, $e->ts);
            }
            $end = $end === null ? $e->ts : max($end, $e->ts);
            $call->sources['qlog'][] = $e->raw;
        }
        if ($start === null) {
            $start = $end;
        }
        $call->startedAt = $this->fmt($start);
        $call->endedAt = $this->fmt($end);
    }

    private function sortRows(array $rows): array
    {
        usort($rows, function (array $a, array $b) {
            return [$a['calldate'], (int) $a['sequence']] <=> [$b['calldate'], (int) $b['sequence']];
        });
        return $rows;
    }

    private function fmt(int $ts): string
    {
        return date('Y-m-d H:i:s', $ts);
    }
}

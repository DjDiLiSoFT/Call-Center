<?php

namespace CallStats\Build;

use CallStats\Source\QueueLogEvent;

/**
 * Extracts per-operator ring attempts of one call from each source.
 *
 * Every method returns accumulators keyed by extension:
 *   kinds      string[]  subset of {'rang','canceled','offline'}
 *   answered   bool      this operator picked up
 *   ringMs     int       total ring time of unanswered attempts, ms
 *   answerRing int       seconds it rang before this operator answered
 *   talk       ?int      talk seconds of the answered attempt
 *   first      int       unix ts of the first attempt
 *   answeredAt ?int      unix ts of the answer
 */
class AttemptCollector
{
    /**
     * RINGNOANSWER ringtime at or below this is an offline phone, not a real ring
     * (offline members log 0 or exactly 1000 ms on this PBX; real rings log >= 15000).
     */
    public const OFFLINE_MAX_RING_MS = 1000;

    /** @var AgentResolver */
    private $resolver;
    /** @var int queue member ring timeout, seconds */
    private $ringTimeoutSec;

    public function __construct(AgentResolver $resolver, int $ringTimeoutSec = 15)
    {
        $this->resolver = $resolver;
        $this->ringTimeoutSec = $ringTimeoutSec;
    }

    /** Extension of an operator phone channel (PJSIP/1001-…), null for trunks and others. */
    public function phoneOf(string $channel): ?string
    {
        if (!preg_match('#^(?:PJSIP|SIP|IAX2)/#', $channel) || !$this->resolver->isExtension($channel)) {
            return null;
        }
        return $this->resolver->resolve($channel);
    }

    /**
     * Queue attempts from queue_log (exact data).
     *
     * @param QueueLogEvent[] $events
     * @param array[]         $queueRows CDR ext-queues rows of the same call: a RINGCANCELED of an
     *                                   operator whose legs are all FAILED is an offline phone
     * @return array{agents: array<string,array>, connectedBy: ?string, talkSec: ?int, waitSec: ?int, transfers: array}
     */
    public function fromQueueLog(array $events, array $queueRows = []): array
    {
        $failedOnly = $this->failedOnlyOperators($queueRows);
        $agents = [];
        $connectedBy = null;
        $talkSec = null;
        $waitSec = null;
        $transfers = [];

        foreach ($events as $e) {
            $d = $e->data;
            switch ($e->event) {
                case 'RINGNOANSWER':
                case 'RINGCANCELED':
                    $ext = $this->agentOf($e->agent);
                    $ms = (int) ($d[0] ?? 0);
                    if ($e->event === 'RINGCANCELED') {
                        // A cancel within ~0 ms (new round started as the caller hung up) is a
                        // blip: it only counts when the operator has no other attempt.
                        $kind = isset($failedOnly[$ext]) ? 'offline'
                            : ($ms <= self::OFFLINE_MAX_RING_MS ? 'blip' : 'canceled');
                    } else {
                        $kind = $ms > self::OFFLINE_MAX_RING_MS ? 'rang' : 'offline';
                    }
                    $this->touch($agents, $ext, $e->ts - (int) round($ms / 1000));
                    $this->addKind($agents[$ext], $kind);
                    if ($kind !== 'offline') {
                        $agents[$ext]['ringMs'] += $ms;
                    }
                    break;
                case 'CONNECT':
                    $ext = $this->agentOf($e->agent);
                    $ring = (int) ($d[2] ?? 0);
                    $this->touch($agents, $ext, $e->ts - $ring);
                    if (!$agents[$ext]['answered']) {
                        $agents[$ext]['answered'] = true;
                        $agents[$ext]['answerRing'] = $ring;
                        $agents[$ext]['answeredAt'] = $e->ts;
                    }
                    if ($connectedBy === null) {
                        $connectedBy = $ext;
                        $waitSec = (int) ($d[0] ?? 0);
                    }
                    break;
                case 'COMPLETEAGENT':
                case 'COMPLETECALLER':
                    $talkSec = (int) ($d[1] ?? 0);
                    if ($connectedBy !== null) {
                        $agents[$connectedBy]['talk'] = $talkSec;
                    }
                    break;
                case 'ABANDON':
                case 'EXITWITHTIMEOUT':
                case 'EXITEMPTY':
                    if ($connectedBy === null) {
                        $waitSec = (int) ($d[2] ?? 0);
                    }
                    break;
                case 'TRANSFER':
                case 'BLINDTRANSFER':
                case 'ATTENDEDTRANSFER':
                    // TRANSFER (Asterisk < 12) / BLINDTRANSFER: <ext>|<context>|holdtime|calltime|origposition
                    // ATTENDEDTRANSFER (Asterisk 18): BRIDGE|bridge|… / APP|app|… / LINK|chan1|chan2|…
                    // (target known only for LINK; otherwise CallBuilder fills it from CDR)
                    $from = $this->agentOf($e->agent);
                    if ($e->event === 'ATTENDEDTRANSFER') {
                        $to = ($d[0] ?? '') === 'LINK' ? $this->agentOf($d[2] ?? '') : '';
                    } else {
                        $to = $this->agentOf($d[0] ?? '');
                    }
                    $transfers[] = ['from' => $from, 'to' => $to, 'at' => date('Y-m-d H:i:s', $e->ts)];
                    // Every format ends with holdtime|calltime|origposition: calltime is the
                    // transferring operator's own talk (no COMPLETE* follows a transfer).
                    $n = count($d);
                    if ($n >= 3 && isset($agents[$from]) && $agents[$from]['answered'] && $agents[$from]['talk'] === null) {
                        $agents[$from]['talk'] = (int) $d[$n - 2];
                    }
                    break;
            }
        }
        $this->settleBlips($agents);
        return [
            'agents' => $agents,
            'connectedBy' => $connectedBy,
            'talkSec' => $talkSec,
            'waitSec' => $waitSec,
            'transfers' => $transfers,
        ];
    }

    /**
     * Queue attempts from CDR `ext-queues` rows (no queue_log available; approximate).
     *
     * @return array<string,array>
     */
    public function fromQueueRows(array $rows): array
    {
        $agents = [];
        foreach ($rows as $r) {
            if (!$this->resolver->isExtension($r['dstchannel'])) {
                continue;
            }
            $ext = $this->resolver->resolve($r['dstchannel']);
            $t = strtotime($r['calldate']);
            $duration = (int) $r['duration'];
            $this->touch($agents, $ext, $t);
            $disp = $r['disposition'];
            if ($disp === 'ANSWERED') {
                $this->answer($agents[$ext], $t, $duration, (int) $r['billsec']);
            } elseif ($disp === 'FAILED' || $disp === 'CONGESTION') {
                $this->addKind($agents[$ext], 'offline');
            } else {
                // NO ANSWER / BUSY: a leg shorter than the member ring timeout was cut short
                // (caller hung up or a colleague answered); a full-length leg really rang out.
                // A 0 s leg never reached the phone: a blip, see settleBlips().
                $this->addKind($agents[$ext], $duration >= $this->ringTimeoutSec ? 'rang' : ($duration > 0 ? 'canceled' : 'blip'));
                $agents[$ext]['ringMs'] += $duration * 1000;
            }
        }
        $this->settleBlips($agents);
        return $agents;
    }

    /**
     * Operators whose ext-queues legs in this call are all FAILED (phone offline).
     *
     * @return array<string,true>
     */
    private function failedOnlyOperators(array $queueRows): array
    {
        $state = [];
        foreach ($queueRows as $r) {
            if (!$this->resolver->isExtension($r['dstchannel'])) {
                continue;
            }
            $ext = $this->resolver->resolve($r['dstchannel']);
            $failed = $r['disposition'] === 'FAILED';
            $state[$ext] = ($state[$ext] ?? true) && $failed;
        }
        return array_filter($state);
    }

    /**
     * Attempts from `ext-local` legs to operator phones (direct/internal calls, and the
     * Local/…@from-queue legs of queue calls). $column names the column holding the operator.
     *
     * @param int|null $ringTimeoutSec when given (direct/internal calls), an unanswered leg shorter
     *                                 than this was cut short by the caller ('canceled'); when null
     *                                 (queue legs) any leg with duration > 0 counts as 'rang'
     * @return array<string,array>
     */
    public function fromLegs(array $rows, string $column, ?int $ringTimeoutSec = null): array
    {
        $agents = [];
        foreach ($rows as $r) {
            if (!$this->resolver->isExtension($r[$column])) {
                continue;
            }
            $ext = $this->resolver->resolve($r[$column]);
            $t = strtotime($r['calldate']);
            $duration = (int) $r['duration'];
            $billsec = (int) $r['billsec'];
            $this->touch($agents, $ext, $t);
            $disp = $r['disposition'];
            if ($disp === 'FAILED' || $disp === 'CONGESTION') {
                $this->addKind($agents[$ext], 'offline');
            } elseif ($disp === 'ANSWERED' && $billsec > 0) {
                $this->answer($agents[$ext], $t, $duration, $billsec);
            } elseif ($duration > 0) {
                // NO ANSWER / BUSY (and ANSWERED with billsec 0): the phone rang.
                $full = $ringTimeoutSec === null || $duration >= $ringTimeoutSec;
                $this->addKind($agents[$ext], $full ? 'rang' : 'canceled');
                $agents[$ext]['ringMs'] += $duration * 1000;
            } else {
                $this->addKind($agents[$ext], 'canceled');
            }
        }
        return $agents;
    }

    private function agentOf(string $raw): string
    {
        return $this->resolver->resolve($raw);
    }

    private function touch(array &$agents, string $ext, int $at): void
    {
        if (!isset($agents[$ext])) {
            $agents[$ext] = [
                'kinds' => [],
                'answered' => false,
                'ringMs' => 0,
                'answerRing' => 0,
                'talk' => null,
                'first' => $at,
                'answeredAt' => null,
            ];
        } elseif ($at < $agents[$ext]['first']) {
            $agents[$ext]['first'] = $at;
        }
    }

    /**
     * A 'blip' (cancel after ~0 ms, 0 s leg) is not a ring: it is dropped when the operator has
     * any other attempt in the call, and becomes 'canceled' (caller hung up) when it is the only one.
     */
    private function settleBlips(array &$agents): void
    {
        foreach ($agents as $ext => $a) {
            $kinds = array_values(array_diff($a['kinds'], ['blip']));
            if ($kinds === [] && in_array('blip', $a['kinds'], true)) {
                $kinds = ['canceled'];
            }
            $agents[$ext]['kinds'] = $kinds;
        }
    }

    private function addKind(array &$a, string $kind): void
    {
        if (!in_array($kind, $a['kinds'], true)) {
            $a['kinds'][] = $kind;
        }
    }

    private function answer(array &$a, int $start, int $duration, int $billsec): void
    {
        if ($a['answered']) {
            return; // keep the first answer
        }
        $ring = max(0, $duration - $billsec);
        $a['answered'] = true;
        $a['answerRing'] = $ring;
        $a['talk'] = $billsec;
        $a['answeredAt'] = $start + $ring;
    }
}

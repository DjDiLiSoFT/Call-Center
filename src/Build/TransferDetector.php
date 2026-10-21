<?php

namespace CallStats\Build;

/**
 * Transfers inside one call (spec 5.3: talk before the transfer goes to the first operator,
 * after it to the second).
 *
 * In CDR a transfer shows up, in the same linkedid and after the first answer, as
 *  - a row that dials another operator's phone (dstchannel PJSIP/<ext>) from a channel that is not
 *    that operator's own leg: the caller channel (blind transfer), the transferring operator's
 *    phone (attended consultation) or the transferring operator's Local/…;2 half;
 *  - a from-internal* row whose dst is not an extension (transfer to an external number).
 * queue_log TRANSFER/BLINDTRANSFER/ATTENDEDTRANSFER events, when present, give the exact time.
 */
class TransferDetector
{
    /** @var AgentResolver */
    private $resolver;
    /** @var AttemptCollector */
    private $collector;

    public function __construct(AgentResolver $resolver, AttemptCollector $collector)
    {
        $this->resolver = $resolver;
        $this->collector = $collector;
    }

    /**
     * Credits transfer targets with their own answered legs and fills $call->transfers. With a
     * transfer the call's talk time is the caller's whole conversation: the sum over operators.
     * $call->answeredBy must already be set (the first answerer keeps it).
     *
     * @param array<string,array> $agents        per-operator accumulators of the original ringing
     * @param array[]             $foreign       foreign phone legs of the call (any time)
     * @param array[]             $rows          all CDR rows of the call
     * @param array               $qlogTransfers transfers parsed from queue_log
     */
    public function apply(CallRecord $call, array &$agents, array $foreign, array $rows, array $qlogTransfers): void
    {
        $since = $call->answeredBy !== null ? $agents[$call->answeredBy]['answeredAt'] : null;
        $targets = $this->after($foreign, $since);
        $this->mergeTargets($agents, $targets);
        $call->transfers = $this->transfers($qlogTransfers, $targets, $rows, $call->answeredBy, $since);
        if ($call->transfers) {
            $call->talkSec = 0;
            foreach ($agents as $a) {
                $call->talkSec += $a['answered'] ? (int) $a['talk'] : 0;
            }
        }
    }

    /** Row ringing an operator phone that is not the leg of that same operator (Local/<ext>@… -> PJSIP/<ext>). */
    public function isForeignPhoneLeg(array $r): bool
    {
        if ($r['dcontext'] === 'ext-queues') {
            return false;
        }
        $phone = $this->collector->phoneOf($r['dstchannel']);
        return $phone !== null && $this->resolver->resolve($r['channel']) !== $phone;
    }

    /**
     * Rows that started after $since (unix ts of the first answer).
     *
     * @return array[]
     */
    public function after(array $rows, ?int $since): array
    {
        if ($since === null) {
            return [];
        }
        return array_values(array_filter($rows, function (array $r) use ($since) {
            return strtotime($r['calldate']) > $since;
        }));
    }

    /**
     * Adds the transfer-target legs to the per-operator accumulators. A target's talk is the sum of the
     * billsec of its answered transfer legs (consultation + talk with the caller), added to the talk it
     * already had if it had answered before (transfer back).
     *
     * @param array<string,array> $agents
     * @param array[]             $legs   foreign phone legs after the first answer
     */
    private function mergeTargets(array &$agents, array $legs): void
    {
        $talk = [];
        foreach ($legs as $r) {
            if ($r['disposition'] === 'ANSWERED' && (int) $r['billsec'] > 0) {
                $ext = $this->resolver->resolve($r['dstchannel']);
                $talk[$ext] = ($talk[$ext] ?? 0) + (int) $r['billsec'];
            }
        }
        foreach ($this->collector->fromLegs($legs, 'dstchannel') as $ext => $a) {
            $ext = (string) $ext;
            $before = 0;
            if (!isset($agents[$ext])) {
                $agents[$ext] = $a;
            } else {
                $cur = $agents[$ext];
                $before = $cur['answered'] ? (int) $cur['talk'] : 0;
                $cur['kinds'] = array_values(array_unique(array_merge($cur['kinds'], $a['kinds'])));
                $cur['first'] = min($cur['first'], $a['first']);
                $cur['ringMs'] += $a['ringMs'];
                if (!$cur['answered'] && $a['answered']) {
                    $cur['answered'] = true;
                    $cur['answerRing'] = $a['answerRing'];
                    $cur['answeredAt'] = $a['answeredAt'];
                }
                $agents[$ext] = $cur;
            }
            if (isset($talk[$ext])) {
                $agents[$ext]['talk'] = $before + $talk[$ext];
            }
        }
    }

    /**
     * Transfer list of the call: queue_log events when present (an ATTENDEDTRANSFER without a target
     * gets it from CDR), otherwise the CDR rows.
     *
     * @param array   $qlog       [['from','to','at']] from queue_log
     * @param array[] $phoneLegs  foreign phone legs after the first answer
     * @param array[] $rows       all CDR rows of the call (sorted)
     * @return array<int,array{from:string,to:string,at:string}>
     */
    private function transfers(array $qlog, array $phoneLegs, array $rows, ?string $answeredBy, ?int $since): array
    {
        $cdr = $answeredBy !== null ? $this->fromCdr($phoneLegs, $this->externalRows($rows, $since), $answeredBy) : [];
        if (!$qlog) {
            return $cdr;
        }
        foreach ($qlog as $i => $t) {
            if ($t['to'] !== '') {
                continue;
            }
            foreach ($cdr as $j => $c) {
                if ($c['from'] === $t['from'] && $c['at'] <= $t['at']) {
                    $qlog[$i]['to'] = $c['to'];
                    unset($cdr[$j]);
                    break;
                }
            }
        }
        return $qlog;
    }

    /** from-internal* rows to a non-extension number after the first answer. */
    private function externalRows(array $rows, ?int $since): array
    {
        return array_values(array_filter($this->after($rows, $since), function (array $r) {
            return strpos($r['dcontext'], 'from-internal') === 0
                && $r['dst'] !== ''
                && !$this->resolver->isExtension($r['dst'])
                && $this->collector->phoneOf($r['dstchannel']) === null;
        }));
    }

    private function fromCdr(array $phoneLegs, array $external, string $answeredBy): array
    {
        $rows = array_merge($phoneLegs, $external);
        usort($rows, function (array $a, array $b) {
            return [$a['calldate'], (int) $a['sequence']] <=> [$b['calldate'], (int) $b['sequence']];
        });
        $holder = $answeredBy; // operator currently talking to the caller
        $out = [];
        foreach ($rows as $r) {
            $phone = $this->collector->phoneOf($r['dstchannel']);
            $to = $phone ?? $r['dst'];
            $chan = $this->resolver->isExtension($r['channel']) ? $this->resolver->resolve($r['channel']) : null;
            $from = ($chan !== null && $chan !== $to) ? $chan : $holder;
            if ($from !== $to) {
                $last = $out ? $out[count($out) - 1] : null;
                if ($last === null || $last['from'] !== $from || $last['to'] !== $to) {
                    $out[] = ['from' => $from, 'to' => $to, 'at' => $r['calldate']];
                }
            }
            if ($phone !== null && $r['disposition'] === 'ANSWERED' && (int) $r['billsec'] > 0) {
                $holder = $phone;
            }
        }
        return $out;
    }
}

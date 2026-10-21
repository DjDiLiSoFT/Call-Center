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
    /** @var AgentResolver */
    private $resolver;

    public function __construct(AgentResolver $resolver)
    {
        $this->resolver = $resolver;
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
     * @return array{agents: array<string,array>, connectedBy: ?string, talkSec: ?int, waitSec: ?int, transfers: array}
     */
    public function fromQueueLog(array $events): array
    {
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
                    $kind = $e->event === 'RINGCANCELED' ? 'canceled' : ($ms > 0 ? 'rang' : 'offline');
                    $this->touch($agents, $ext, $e->ts - (int) round($ms / 1000));
                    $this->addKind($agents[$ext], $kind);
                    $agents[$ext]['ringMs'] += $ms;
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
                case 'BLINDTRANSFER':
                    $transfers[] = ['from' => $this->agentOf($e->agent), 'to' => $this->agentOf($d[0] ?? ''), 'at' => date('Y-m-d H:i:s', $e->ts)];
                    break;
                case 'ATTENDEDTRANSFER':
                    // Asterisk 18: ATTENDEDTRANSFER|BRIDGE|bridge|… / APP|app|… / LINK|chan1|chan2|…
                    $to = ($d[0] ?? '') === 'LINK' ? $this->agentOf($d[2] ?? '') : '';
                    $transfers[] = ['from' => $this->agentOf($e->agent), 'to' => $to, 'at' => date('Y-m-d H:i:s', $e->ts)];
                    break;
            }
        }
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
            } elseif ($duration > 0) {
                $this->addKind($agents[$ext], 'rang');
                $agents[$ext]['ringMs'] += $duration * 1000;
            } else {
                $this->addKind($agents[$ext], 'canceled');
            }
        }
        return $agents;
    }

    /**
     * Attempts from `ext-local` legs to operator phones (direct/internal calls, and the
     * Local/…@from-queue legs of queue calls). $column names the column holding the operator.
     *
     * @return array<string,array>
     */
    public function fromLegs(array $rows, string $column): array
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
                $this->addKind($agents[$ext], 'rang');
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

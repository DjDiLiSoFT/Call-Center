<?php

namespace CallStats\Build;

use CallStats\Source\QueueLogEvent;

/**
 * Turns queue_log events into operator login sessions and pauses.
 */
class AgentTimeline
{
    /** @var AgentResolver */
    private $resolver;

    public function __construct(AgentResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * @param QueueLogEvent[] $events
     * @param int             $nowTs  Events with ts > $nowTs are ignored (future events).
     *                                It is NOT used to close open intervals: those keep end_at = null.
     * @return array{sessions: array<int,array>, pauses: array<int,array>}
     */
    public function build(array $events, int $nowTs): array
    {
        $events = array_values($events);
        $idx = [];
        foreach ($events as $i => $e) {
            $idx[$i] = $i;
        }
        usort($idx, function ($a, $b) use ($events) {
            return $events[$a]->ts <=> $events[$b]->ts ?: $a <=> $b;
        });

        $sessions = [];
        $pauses = [];
        $openS = []; // "ext|queue" => index in $sessions
        $openP = []; // "ext|queue" => index in $pauses
        $seen = [];  // ext => [queue => true]

        foreach ($idx as $i) {
            $e = $events[$i];
            if ($e->ts > $nowTs) {
                continue;
            }
            $ext = $this->resolver->resolve($e->agent);
            if ($ext === '' || $ext === 'NONE') {
                continue;
            }
            $q = $e->queue;
            $at = date('Y-m-d H:i:s', $e->ts);
            $key = $ext . '|' . $q;
            if ($q !== 'NONE' && $q !== '') {
                $seen[$ext][$q] = true;
            }

            switch ($e->event) {
                case 'ADDMEMBER':
                case 'AGENTLOGIN':
                    if (!isset($openS[$key])) {
                        $sessions[] = ['extension' => $ext, 'queue' => $q, 'start_at' => $at, 'end_at' => null];
                        $openS[$key] = count($sessions) - 1;
                    }
                    break;

                case 'REMOVEMEMBER':
                case 'AGENTLOGOFF':
                    if (isset($openS[$key])) {
                        $sessions[$openS[$key]]['end_at'] = $at;
                        unset($openS[$key]);
                    }
                    if (isset($openP[$key])) {
                        $pauses[$openP[$key]]['end_at'] = $at;
                        unset($openP[$key]);
                    }
                    break;

                case 'PAUSE':
                    $this->openPause($pauses, $openP, $ext, $q, $at, $this->reason($e->data));
                    break;

                case 'PAUSEALL':
                    $queues = [];
                    foreach ($openS as $k => $_) {
                        list($x, $sq) = explode('|', $k, 2);
                        if ($x === $ext && $sq !== 'NONE' && $sq !== '') {
                            $queues[$sq] = true;
                        }
                    }
                    foreach ($seen[$ext] ?? [] as $sq => $_) {
                        $queues[$sq] = true;
                    }
                    if (!$queues) {
                        $queues = ['NONE' => true];
                    }
                    $reason = $this->reason($e->data);
                    foreach (array_keys($queues) as $sq) {
                        $this->openPause($pauses, $openP, $ext, (string) $sq, $at, $reason);
                    }
                    break;

                case 'UNPAUSE':
                    if ($q === 'NONE' || $q === '') {
                        $this->closeAll($pauses, $openP, $ext, $at);
                    } elseif (isset($openP[$key])) {
                        $pauses[$openP[$key]]['end_at'] = $at;
                        unset($openP[$key]);
                    }
                    break;

                case 'UNPAUSEALL':
                    $this->closeAll($pauses, $openP, $ext, $at);
                    break;
            }
        }

        return ['sessions' => $sessions, 'pauses' => $pauses];
    }

    private function openPause(array &$pauses, array &$openP, string $ext, string $q, string $at, string $reason): void
    {
        $key = $ext . '|' . $q;
        if (isset($openP[$key])) {
            return;
        }
        $pauses[] = ['extension' => $ext, 'queue' => $q, 'start_at' => $at, 'end_at' => null, 'reason' => $reason];
        $openP[$key] = count($pauses) - 1;
    }

    private function closeAll(array &$pauses, array &$openP, string $ext, string $at): void
    {
        foreach ($openP as $k => $pi) {
            if (strncmp($k, $ext . '|', strlen($ext) + 1) === 0) {
                $pauses[$pi]['end_at'] = $at;
                unset($openP[$k]);
            }
        }
    }

    /** data[1] if non-empty, else data[0] if non-numeric, else ''. */
    private function reason(array $data): string
    {
        $d1 = isset($data[1]) ? trim((string) $data[1]) : '';
        if ($d1 !== '') {
            return $d1;
        }
        $d0 = isset($data[0]) ? trim((string) $data[0]) : '';
        if ($d0 !== '' && !is_numeric($d0)) {
            return $d0;
        }
        return '';
    }
}

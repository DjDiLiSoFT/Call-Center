<?php

namespace CallStats\Live;

use CallStats\Build\AgentResolver;

/**
 * Turns raw AMI lists (QueueStatus, CoreShowChannels, ExtensionStateList) into the live board
 * model: callers waiting per queue and one state per operator.
 */
class LiveSnapshot
{
    /** Display order of operator states on the board. */
    private const ORDER = ['talking' => 0, 'ringing' => 1, 'busy' => 2, 'free' => 3, 'paused' => 4, 'offline' => 5];

    /** QueueMember Status: 4 invalid, 5 unavailable, 6 ringing, 7 ringinuse, 2 inuse, 3 busy, 8 onhold. */
    private const QUEUE_OFFLINE = [4, 5];
    private const QUEUE_RINGING = [6, 7];
    private const QUEUE_BUSY = [2, 3, 8];

    /** ExtensionStatus Status: -1 removed, -2 deleted, 4 unavailable, 1 inuse, 2 busy, 8 ringing, 16 onhold. */
    private const EXT_OFFLINE = [-2, -1, 4];

    /** @var AgentResolver */
    private $resolver;

    public function __construct(AgentResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * @param array $queueEvents QueueStatus events (QueueParams, QueueMember, QueueEntry)
     * @param array $channels    CoreShowChannel events
     * @param array $extStates   ExtensionStatus events
     * @param array $names       extension => display name
     * @param array $queueNames  queue number => name
     */
    public function build(array $queueEvents, array $channels, array $extStates, array $names, array $queueNames): array
    {
        $agents = [];
        $queues = [];

        foreach ($queueEvents as $e) {
            $event = $e['Event'] ?? '';
            $q = (string) ($e['Queue'] ?? '');
            if ($q === '') {
                continue;
            }
            if (!isset($queues[$q])) {
                $queues[$q] = ['queue' => $q, 'name' => $queueNames[$q] ?? $q, 'waiting' => []];
            }
            if ($event === 'QueueEntry') {
                $queues[$q]['waiting'][] = [
                    'position' => (int) ($e['Position'] ?? 0),
                    'number' => (string) ($e['CallerIDNum'] ?? ''),
                    'name' => (string) ($e['CallerIDName'] ?? ''),
                    'wait_sec' => (int) ($e['Wait'] ?? 0),
                ];
            } elseif ($event === 'QueueMember') {
                $ext = $this->resolver->resolve((string) ($e['Location'] ?? ($e['Name'] ?? '')));
                if (!$this->resolver->isExtension($ext)) {
                    $ext = $this->resolver->resolve((string) ($e['Name'] ?? ''));
                }
                $a = $agents[$ext] ?? $this->agent($ext, $names);
                $a['in_queue'] = true;
                $a['queues'][] = $queueNames[$q] ?? $q;
                $status = (int) ($e['Status'] ?? 0);
                if (($e['Paused'] ?? '0') === '1') {
                    $a['paused'] = true;
                    $a['pause_reason'] = (string) ($e['PausedReason'] ?? '');
                }
                if (in_array($status, self::QUEUE_OFFLINE, true)) {
                    $a['offline'] = true;
                } elseif (in_array($status, self::QUEUE_RINGING, true)) {
                    $a['ringing'] = true;
                } elseif (in_array($status, self::QUEUE_BUSY, true)) {
                    $a['busy'] = true;
                }
                $agents[$ext] = $a;
            }
        }

        foreach ($extStates as $e) {
            $ext = (string) ($e['Exten'] ?? '');
            if (!$this->resolver->isExtension($ext)) {
                continue;
            }
            $status = (int) ($e['Status'] ?? 0);
            $offline = in_array($status, self::EXT_OFFLINE, true);
            if ($offline && !isset($agents[$ext])) {
                continue;
            }
            $a = $agents[$ext] ?? $this->agent($ext, $names);
            if ($offline) {
                $a['offline'] = true;
            } elseif ($status & 8) {
                $a['ringing'] = true;
            } elseif ($status & 3 || $status & 16) {
                $a['busy'] = true;
            }
            $agents[$ext] = $a;
        }

        foreach ($channels as $c) {
            if (!preg_match('#^(?:PJSIP|SIP|IAX2)/([^-]+)-#', (string) ($c['Channel'] ?? ''), $m)) {
                continue;
            }
            $ext = $this->resolver->resolve($m[1]);
            if (!$this->resolver->isExtension($ext)) {
                continue;
            }
            $a = $agents[$ext] ?? $this->agent($ext, $names);
            $state = (int) ($c['ChannelState'] ?? 0);
            $since = self::seconds((string) ($c['Duration'] ?? ''));
            if ($state === 6 && ($c['BridgeId'] ?? '') !== '') {
                $a['channel'] = 'talking';
                $a['peer'] = (string) ($c['ConnectedLineNum'] ?? '');
                $a['since_sec'] = $since;
            } elseif ($state === 5 && ($a['channel'] ?? '') !== 'talking') {
                $a['channel'] = 'ringing';
                $a['peer'] = (string) ($c['ConnectedLineNum'] ?? '');
                $a['since_sec'] = $since;
            } elseif (!isset($a['channel'])) {
                $a['channel'] = 'busy';
                $a['peer'] = (string) ($c['ConnectedLineNum'] ?? '');
                $a['since_sec'] = $since;
            }
            $agents[$ext] = $a;
        }

        $out = [];
        foreach ($agents as $a) {
            $out[] = $this->finish($a);
        }
        usort($out, function (array $x, array $y): int {
            return [self::ORDER[$x['state']], $x['ext']] <=> [self::ORDER[$y['state']], $y['ext']];
        });

        $summary = ['waiting' => 0, 'longest_wait' => 0];
        foreach (array_keys(self::ORDER) as $state) {
            $summary[$state] = 0;
        }
        foreach ($queues as &$queue) {
            usort($queue['waiting'], function (array $x, array $y): int {
                return $x['position'] <=> $y['position'];
            });
            $summary['waiting'] += count($queue['waiting']);
            foreach ($queue['waiting'] as $w) {
                $summary['longest_wait'] = max($summary['longest_wait'], $w['wait_sec']);
            }
        }
        unset($queue);
        foreach ($out as $a) {
            $summary[$a['state']]++;
        }
        $summary = [
            'waiting' => $summary['waiting'], 'longest_wait' => $summary['longest_wait'],
            'talking' => $summary['talking'], 'ringing' => $summary['ringing'], 'free' => $summary['free'],
            'paused' => $summary['paused'], 'busy' => $summary['busy'], 'offline' => $summary['offline'],
        ];

        return ['summary' => $summary, 'queues' => array_values($queues), 'agents' => $out];
    }

    private function agent(string $ext, array $names): array
    {
        return ['ext' => $ext, 'name' => (string) ($names[$ext] ?? $ext), 'in_queue' => false, 'queues' => []];
    }

    /** Collapses the collected flags into one state; a live channel wins over queue/hint status. */
    private function finish(array $a): array
    {
        $channel = $a['channel'] ?? null;
        if ($channel === 'talking' || $channel === 'ringing') {
            $state = $channel;
        } elseif (!empty($a['ringing'])) {
            $state = 'ringing';
        } elseif (!empty($a['paused'])) {
            $state = 'paused';
        } elseif ($channel === 'busy') {
            $state = 'busy';
        } elseif (!empty($a['offline'])) {
            $state = 'offline';
        } elseif (!empty($a['busy'])) {
            $state = 'busy';
        } else {
            $state = 'free';
        }
        return [
            'ext' => $a['ext'],
            'name' => $a['name'],
            'state' => $state,
            'since_sec' => in_array($state, ['talking', 'ringing', 'busy'], true) ? ($a['since_sec'] ?? null) : null,
            'peer' => in_array($state, ['talking', 'ringing', 'busy'], true) && ($a['peer'] ?? '') !== '' ? $a['peer'] : null,
            'pause_reason' => $state === 'paused' ? ($a['pause_reason'] ?? '') : '',
            'in_queue' => $a['in_queue'],
            'queues' => $a['queues'],
        ];
    }

    /** "HH:MM:SS" → seconds. */
    private static function seconds(string $hms): int
    {
        $parts = array_map('intval', explode(':', $hms));
        $sec = 0;
        foreach ($parts as $p) {
            $sec = $sec * 60 + $p;
        }
        return $sec;
    }
}

<?php

namespace CallStats\Tests\Unit;

use CallStats\Build\AgentResolver;
use CallStats\Live\LiveSnapshot;
use PHPUnit\Framework\TestCase;

class LiveSnapshotTest extends TestCase
{
    private function snapshot(array $queue, array $channels, array $ext): array
    {
        $exts = ['1001', '1002', '1003', '1004', '1005', '1006'];
        $names = ['1001' => 'Анна', '1002' => '1002', '1003' => '1003', '1004' => 'Иван', '1005' => '1005', '1006' => '1006'];
        return (new LiveSnapshot(new AgentResolver($exts)))->build($queue, $channels, $ext, $names, ['1000' => 'Bananza']);
    }

    private function member(string $ext, int $status, int $paused = 0, string $reason = ''): array
    {
        return ['Event' => 'QueueMember', 'Queue' => '1000', 'Name' => $ext, 'Location' => "Local/$ext@from-queue/n",
            'Status' => (string) $status, 'Paused' => (string) $paused, 'PausedReason' => $reason, 'CallsTaken' => '3'];
    }

    public function test_states_waiting_callers_and_summary(): void
    {
        $queue = [
            ['Event' => 'QueueParams', 'Queue' => '1000', 'Calls' => '2'],
            $this->member('1001', 2),
            $this->member('1002', 1),
            $this->member('1003', 1, 1, 'Обед'),
            $this->member('1004', 6),
            $this->member('1005', 5),
            ['Event' => 'QueueEntry', 'Queue' => '1000', 'Position' => '2', 'CallerIDNum' => '900000002', 'CallerIDName' => '', 'Wait' => '15'],
            ['Event' => 'QueueEntry', 'Queue' => '1000', 'Position' => '1', 'CallerIDNum' => '900000001', 'CallerIDName' => 'ООО Ромашка', 'Wait' => '42'],
        ];
        $channels = [
            ['Event' => 'CoreShowChannel', 'Channel' => 'PJSIP/1001-0000aa01', 'ChannelState' => '6', 'BridgeId' => 'b1',
                'ConnectedLineNum' => '900000009', 'Duration' => '00:02:31'],
            ['Event' => 'CoreShowChannel', 'Channel' => 'PJSIP/1004-0000aa02', 'ChannelState' => '5', 'BridgeId' => '',
                'ConnectedLineNum' => '900000002', 'Duration' => '00:00:04'],
            ['Event' => 'CoreShowChannel', 'Channel' => 'PJSIP/552020515-0000aa03', 'ChannelState' => '6', 'BridgeId' => 'b1',
                'ConnectedLineNum' => '1001', 'Duration' => '00:02:40'],
        ];
        $ext = [
            ['Event' => 'ExtensionStatus', 'Exten' => '1006', 'Context' => 'ext-local', 'Status' => '0'],
        ];
        $s = $this->snapshot($queue, $channels, $ext);

        $byExt = array_column($s['agents'], null, 'ext');
        $this->assertSame('talking', $byExt['1001']['state']);
        $this->assertSame('900000009', $byExt['1001']['peer']);
        $this->assertSame(151, $byExt['1001']['since_sec']);
        $this->assertSame('free', $byExt['1002']['state']);
        $this->assertSame('paused', $byExt['1003']['state']);
        $this->assertSame('Обед', $byExt['1003']['pause_reason']);
        $this->assertSame('ringing', $byExt['1004']['state']);
        $this->assertSame('Иван', $byExt['1004']['name']);
        $this->assertSame('offline', $byExt['1005']['state']);
        $this->assertSame('free', $byExt['1006']['state']);
        $this->assertFalse($byExt['1006']['in_queue']);
        $this->assertArrayNotHasKey('552020515', $byExt, 'trunk is not an operator');

        $this->assertSame(['talking', 'ringing', 'free', 'free', 'paused', 'offline'], array_column($s['agents'], 'state'));

        $this->assertSame('Bananza', $s['queues'][0]['name']);
        $this->assertSame(['900000001', '900000002'], array_column($s['queues'][0]['waiting'], 'number'));
        $this->assertSame(42, $s['queues'][0]['waiting'][0]['wait_sec']);

        $this->assertSame(['waiting' => 2, 'longest_wait' => 42, 'talking' => 1, 'ringing' => 1, 'free' => 2,
            'paused' => 1, 'busy' => 0, 'offline' => 1], $s['summary']);
    }

    public function test_offline_non_queue_extensions_are_hidden(): void
    {
        $s = $this->snapshot([], [], [
            ['Event' => 'ExtensionStatus', 'Exten' => '1006', 'Context' => 'ext-local', 'Status' => '4'],
            ['Event' => 'ExtensionStatus', 'Exten' => '*97', 'Context' => 'ext-local', 'Status' => '0'],
        ]);
        $this->assertSame([], $s['agents']);
    }

    public function test_up_channel_without_bridge_is_busy_dialing(): void
    {
        $s = $this->snapshot([$this->member('1002', 2)], [
            ['Event' => 'CoreShowChannel', 'Channel' => 'PJSIP/1002-0000ab01', 'ChannelState' => '6', 'BridgeId' => '',
                'ConnectedLineNum' => '', 'Duration' => '00:00:09'],
        ], []);
        $this->assertSame('busy', $s['agents'][0]['state']);
        $this->assertSame(9, $s['agents'][0]['since_sec']);
    }
}

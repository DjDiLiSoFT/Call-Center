<?php

namespace CallStats\Tests\Unit;

use CallStats\Build\AgentResolver;
use CallStats\Build\AgentTimeline;
use CallStats\Source\QueueLogParser;
use PHPUnit\Framework\TestCase;

class AgentTimelineTest extends TestCase
{
    protected function setUp(): void
    {
        date_default_timezone_set('Asia/Tashkent');
    }

    private function ev(string $time, string $queue, string $agent, string $event, string $data = ''): \CallStats\Source\QueueLogEvent
    {
        $ts = strtotime('2026-10-21 ' . $time);
        return QueueLogParser::parse("$ts|NONE|$queue|$agent|$event|$data");
    }

    private function tl(): AgentTimeline
    {
        return new AgentTimeline(new AgentResolver(['1001', '1002']));
    }

    private function now(): int
    {
        return strtotime('2026-10-21 23:00:00');
    }

    public function test_pause_with_reason(): void
    {
        $r = $this->tl()->build([
            $this->ev('10:00:00', '800', 'Local/1001@from-queue/n', 'ADDMEMBER'),
            $this->ev('10:30:00', '800', 'Local/1001@from-queue/n', 'PAUSE', 'Обед'),
            $this->ev('10:45:00', '800', 'Local/1001@from-queue/n', 'UNPAUSE', 'Обед'),
        ], $this->now());
        $this->assertCount(1, $r['pauses']);
        $this->assertSame([
            'extension' => '1001', 'queue' => '800',
            'start_at' => '2026-10-21 10:30:00', 'end_at' => '2026-10-21 10:45:00', 'reason' => 'Обед',
        ], $r['pauses'][0]);
    }

    public function test_old_format_reason_in_data1(): void
    {
        $r = $this->tl()->build([
            $this->ev('10:30:00', '800', '1001', 'PAUSE', '|Перерыв'),
        ], $this->now());
        $this->assertSame('Перерыв', $r['pauses'][0]['reason']);
        $this->assertNull($r['pauses'][0]['end_at']);
    }

    public function test_numeric_reason_ignored(): void
    {
        $r = $this->tl()->build([$this->ev('10:30:00', '800', '1001', 'PAUSE', '5')], $this->now());
        $this->assertSame('', $r['pauses'][0]['reason']);
    }

    public function test_open_session_has_null_end(): void
    {
        $r = $this->tl()->build([$this->ev('09:00:00', '800', '1001', 'ADDMEMBER')], $this->now());
        $this->assertSame([[
            'extension' => '1001', 'queue' => '800', 'start_at' => '2026-10-21 09:00:00', 'end_at' => null,
        ]], $r['sessions']);
    }

    public function test_session_closed_and_remove_closes_pause(): void
    {
        $r = $this->tl()->build([
            $this->ev('09:00:00', '800', '1001', 'ADDMEMBER'),
            $this->ev('12:00:00', '800', '1001', 'PAUSE', 'Обед'),
            $this->ev('12:10:00', '800', '1001', 'REMOVEMEMBER'),
        ], $this->now());
        $this->assertSame('2026-10-21 12:10:00', $r['sessions'][0]['end_at']);
        $this->assertSame('2026-10-21 12:10:00', $r['pauses'][0]['end_at']);
    }

    public function test_double_pause_ignored(): void
    {
        $r = $this->tl()->build([
            $this->ev('10:30:00', '800', '1001', 'PAUSE', 'Обед'),
            $this->ev('10:31:00', '800', '1001', 'PAUSE', 'Другое'),
            $this->ev('10:45:00', '800', '1001', 'UNPAUSE'),
        ], $this->now());
        $this->assertCount(1, $r['pauses']);
        $this->assertSame('Обед', $r['pauses'][0]['reason']);
        $this->assertSame('2026-10-21 10:30:00', $r['pauses'][0]['start_at']);
        $this->assertSame('2026-10-21 10:45:00', $r['pauses'][0]['end_at']);
    }

    public function test_pauseall_covers_all_queues(): void
    {
        $r = $this->tl()->build([
            $this->ev('09:00:00', '800', '1001', 'ADDMEMBER'),
            $this->ev('09:00:00', '801', '1001', 'ADDMEMBER'),
            $this->ev('10:00:00', 'NONE', '1001', 'PAUSEALL', 'Обед'),
            $this->ev('10:20:00', 'NONE', '1001', 'UNPAUSEALL'),
        ], $this->now());
        $this->assertCount(2, $r['pauses']);
        foreach ($r['pauses'] as $p) {
            $this->assertSame('Обед', $p['reason']);
            $this->assertSame('2026-10-21 10:20:00', $p['end_at']);
        }
    }

    public function test_pauseall_unknown_queue_uses_none(): void
    {
        $r = $this->tl()->build([$this->ev('10:00:00', 'NONE', '1001', 'PAUSEALL', 'X')], $this->now());
        $this->assertSame('NONE', $r['pauses'][0]['queue']);
    }

    public function test_unsorted_input_and_future_ignored(): void
    {
        $r = $this->tl()->build([
            $this->ev('10:45:00', '800', '1001', 'UNPAUSE'),
            $this->ev('10:30:00', '800', '1001', 'PAUSE', 'Обед'),
            $this->ev('23:30:00', '800', '1001', 'REMOVEMEMBER'),
        ], $this->now());
        $this->assertSame('2026-10-21 10:45:00', $r['pauses'][0]['end_at']);
        $this->assertSame([], $r['sessions']);
    }
}

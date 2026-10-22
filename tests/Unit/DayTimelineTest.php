<?php

namespace CallStats\Tests\Unit;

use CallStats\Report\DayTimeline;
use CallStats\Web\Charts;
use PHPUnit\Framework\TestCase;

class DayTimelineTest extends TestCase
{
    private function item(string $at, string $type, array $extra = []): array
    {
        return array_merge(['at' => $at, 'type' => $type, 'call_id' => null, 'client' => null, 'source' => null,
            'ring_sec' => null, 'talk_sec' => null, 'until' => null, 'reason' => null, 'has_recording' => false,
            'approx' => false, 'sources' => null], $extra);
    }

    private function seg(array $tl): array
    {
        return array_map(function (array $s): array {
            return [$s['kind'], $s['from'], $s['to']];
        }, $tl['segments']);
    }

    public function test_segments_of_each_kind(): void
    {
        date_default_timezone_set('Asia/Tashkent');
        $tl = DayTimeline::build([
            $this->item('2026-10-20 09:00:00', 'session_start'),
            $this->item('2026-10-20 09:10:00', 'in_answered', ['client' => '901', 'ring_sec' => 30, 'talk_sec' => 150]),
            $this->item('2026-10-20 09:20:00', 'out', ['client' => '902', 'reason' => 'answered', 'ring_sec' => 6, 'talk_sec' => 54]),
            $this->item('2026-10-20 09:25:00', 'out', ['client' => '903', 'reason' => 'not_connected', 'ring_sec' => 20, 'talk_sec' => 0]),
            $this->item('2026-10-20 09:30:00', 'internal', ['client' => '1002', 'reason' => 'out', 'ring_sec' => 0, 'talk_sec' => 60]),
            $this->item('2026-10-20 09:40:00', 'internal', ['client' => '1003', 'reason' => 'in', 'ring_sec' => 5, 'talk_sec' => 0]),
            $this->item('2026-10-20 10:00:00', 'pause', ['until' => '2026-10-20 10:15:00', 'reason' => 'Обед']),
            $this->item('2026-10-20 10:20:30', 'in_missed', ['client' => '904', 'ring_sec' => 15]),
            $this->item('2026-10-20 11:00:00', 'offline_group', ['count' => 4, 'until' => '2026-10-20 11:40:00']),
            $this->item('2026-10-20 11:50:00', 'in_other', ['client' => '905']),
        ], '2026-10-20', strtotime('2026-10-20 18:00:00'));

        $this->assertEquals([
            ['talk', 550.5, 553.0],
            ['talk', 560.1, 561.0],
            ['talk', 570.0, 571.0],
            ['pause', 600.0, 615.0],
            ['missed', 620.0, 621.0],
            ['offline', 660.0, 700.0],
        ], $this->seg($tl));
        $this->assertSame(8, $tl['from_h']);
        $this->assertSame(20, $tl['to_h']);
        $this->assertStringContainsString('09:10', $tl['segments'][0]['tip']);
        $this->assertStringContainsString('901', $tl['segments'][0]['tip']);
        $this->assertStringContainsString('Обед', $tl['segments'][3]['tip']);
        $this->assertStringContainsString('4 звонка', $tl['segments'][5]['tip']);
    }

    public function test_open_pause_ends_now_or_at_end_of_day_and_hours_widen(): void
    {
        date_default_timezone_set('Asia/Tashkent');
        $items = [
            $this->item('2026-10-20 06:30:00', 'in_answered', ['client' => '1', 'ring_sec' => 0, 'talk_sec' => 60]),
            $this->item('2026-10-20 21:00:00', 'pause', ['until' => null, 'reason' => '']),
        ];
        $now = DayTimeline::build($items, '2026-10-20', strtotime('2026-10-20 21:30:00'));
        $this->assertEquals(['pause', 1260.0, 1290.0], $this->seg($now)[1]);
        $this->assertSame(6, $now['from_h']);
        $this->assertSame(22, $now['to_h']);

        $past = DayTimeline::build($items, '2026-10-20', strtotime('2026-10-22 12:00:00'));
        $this->assertEquals(['pause', 1260.0, 1440.0], $this->seg($past)[1]);
        $this->assertSame(24, $past['to_h']);

        $empty = DayTimeline::build([], '2026-10-20', strtotime('2026-10-20 12:00:00'));
        $this->assertSame(['from_h' => 8, 'to_h' => 20, 'segments' => []], $empty);
    }

    public function test_day_strip_svg_smoke(): void
    {
        date_default_timezone_set('Asia/Tashkent');
        $tl = DayTimeline::build([
            $this->item('2026-10-20 09:10:00', 'in_answered', ['client' => '<b>', 'ring_sec' => 30, 'talk_sec' => 150]),
            $this->item('2026-10-20 10:00:00', 'pause', ['until' => '2026-10-20 10:15:00', 'reason' => 'Обед']),
        ], '2026-10-20', strtotime('2026-10-20 18:00:00'));
        $svg = Charts::dayStrip($tl);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('var(--accent)', $svg);
        $this->assertStringContainsString('#9333ea', $svg);
        $this->assertStringContainsString('data-tip="', $svg);
        $this->assertStringNotContainsString('<b>', $svg, 'tips are escaped');
        $this->assertStringContainsString('&lt;b&gt;', $svg);
        $this->assertSame(13, substr_count($svg, 'class="tick"'), 'hour labels 08..20');
        $this->assertStringContainsString('разговор', $svg);
        $this->assertStringContainsString('не в сети', $svg);
    }
}

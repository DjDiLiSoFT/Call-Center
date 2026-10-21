<?php

namespace CallStats\Tests\Unit;

use CallStats\Report\JournalTable;
use CallStats\Web\Format;
use PHPUnit\Framework\TestCase;

class JournalTableTest extends TestCase
{
    private function item(string $at, string $type, array $extra = []): array
    {
        return array_merge(['at' => $at, 'type' => $type, 'call_id' => null, 'client' => null, 'source' => null,
            'ring_sec' => null, 'talk_sec' => null, 'until' => null, 'reason' => null, 'has_recording' => false,
            'approx' => false, 'sources' => null], $extra);
    }

    public function test_rows_map_types_to_direction_icon_and_duration(): void
    {
        $rows = JournalTable::rows([
            $this->item('2026-08-19 09:31:00', 'out', ['client' => '975036555', 'reason' => 'answered', 'talk_sec' => 37, 'call_id' => 7,
                'has_recording' => true, 'rec_status' => 'ok']),
            $this->item('2026-08-19 09:32:00', 'in_missed', ['client' => '916104632', 'source' => 'Очередь «Bananza»', 'ring_sec' => 1, 'call_id' => 8,
                'has_recording' => true, 'rec_status' => 'ok']),
            $this->item('2026-08-19 09:33:00', 'in_answered', ['client' => '932401440', 'ring_sec' => 3, 'talk_sec' => 46, 'call_id' => 9]),
            $this->item('2026-08-19 10:30:00', 'pause', ['until' => '2026-08-19 10:45:00', 'reason' => 'Обед']),
            $this->item('2026-08-19 11:00:00', 'offline_group', ['count' => 4, 'until' => '2026-08-19 11:40:00']),
        ]);

        $this->assertSame(['out', 'in', 'in', 'event', 'event'], array_column($rows, 'dir'));
        $this->assertSame([false, true, false, false, false], array_column($rows, 'failed'));
        $this->assertSame('975036555', $rows[0]['number']);
        $this->assertSame([37, 1, 46, 900, null], array_column($rows, 'duration'));
        $this->assertSame('ok', $rows[0]['rec_status']);
        $this->assertSame('', $rows[1]['rec_status']);
        $this->assertStringContainsString('пропущен', $rows[1]['note']);
        $this->assertSame('Пауза: Обед', $rows[3]['text']);
        $this->assertSame('Телефон не в сети: 4 звонка, 11:00–11:40', $rows[4]['text']);
        $this->assertTrue($rows[4]['muted']);
    }

    public function test_apply_filters_sorts_and_paginates(): void
    {
        $items = [];
        for ($i = 0; $i < 25; $i++) {
            $items[] = $this->item(sprintf('2026-08-19 09:%02d:00', $i), 'in_answered',
                ['client' => '90000' . sprintf('%04d', $i), 'talk_sec' => 100 - $i, 'call_id' => $i]);
        }
        $rows = JournalTable::rows($items);

        $page = JournalTable::apply($rows, '', 'at', 'desc', 2, 10);
        $this->assertSame(25, $page['total']);
        $this->assertSame(3, $page['pages']);
        $this->assertSame(2, $page['page']);
        $this->assertSame(11, $page['from']);
        $this->assertSame(20, $page['to']);
        $this->assertSame('2026-08-19 09:14:00', $page['rows'][0]['at']);

        $dur = JournalTable::apply($rows, '', 'dur', 'asc', 1, 10);
        $this->assertSame(76, $dur['rows'][0]['duration']);

        $found = JournalTable::apply($rows, '0012', 'at', 'desc', 1, 10);
        $this->assertSame(1, $found['total']);

        $clamped = JournalTable::apply($rows, '', 'at', 'desc', 999, 10);
        $this->assertSame(3, $clamped['page']);
        $empty = JournalTable::apply([], '', 'at', 'desc', 5, 10);
        $this->assertSame(['total' => 0, 'pages' => 1, 'page' => 1, 'from' => 0, 'to' => 0],
            array_intersect_key($empty, array_flip(['total', 'pages', 'page', 'from', 'to'])));
    }

    public function test_human_duration_and_russian_date(): void
    {
        $this->assertSame('2 сек', Format::humanDuration(2));
        $this->assertSame('01 мин, 02 сек', Format::humanDuration(62));
        $this->assertSame('1 ч, 00 мин, 05 сек', Format::humanDuration(3605));
        $this->assertSame('ср, 19 авг. 2026 г., 10:00', Format::ruDateTime('2026-08-19 10:00:27'));
    }
}

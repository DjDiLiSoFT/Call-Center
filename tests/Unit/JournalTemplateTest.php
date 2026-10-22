<?php

namespace CallStats\Tests\Unit;

use CallStats\Report\JournalTable;
use CallStats\Report\Period;
use CallStats\Web\View;
use PHPUnit\Framework\TestCase;

class JournalTemplateTest extends TestCase
{
    private function item(string $at, string $type, array $extra = []): array
    {
        return array_merge(['at' => $at, 'type' => $type, 'call_id' => null, 'client' => null, 'source' => null,
            'ring_sec' => null, 'talk_sec' => null, 'until' => null, 'reason' => null, 'has_recording' => false,
            'approx' => false, 'sources' => null], $extra);
    }

    public function test_all_operators_mode_with_day_headers_chips_and_badges(): void
    {
        date_default_timezone_set('Asia/Tashkent');
        $rows = JournalTable::rows([
            $this->item('2026-10-20 09:00:00', 'in_answered', ['client' => '+998901112233', 'talk_sec' => 10, 'ext' => '1001']),
            $this->item('2026-10-21 09:05:00', 'in_missed', ['client' => '901112233', 'ring_sec' => 5, 'ext' => '1002',
                'callback' => ['kind' => 'callback', 'at' => '2026-10-21 09:10:00', 'after_sec' => 300, 'ext' => '1001', 'connected' => true]]),
            $this->item('2026-10-21 09:06:00', 'in_other', ['client' => '901112233', 'ext' => '1002']),
        ]);
        $t = ['q' => '', 'type' => '', 'noise' => '', 'sort' => 'at', 'order' => 'desc', 'per' => 100];
        $shown = JournalTable::filterType($rows, '', false);
        $kpi = ['answered' => 1, 'missed' => 1, 'answer_pct' => 50.0, 'talk_sec' => 10, 'avg_talk_sec' => 10, 'avg_ring_sec' => 0];
        $html = (new View(dirname(__DIR__, 2) . '/templates'))->render('journal', [
            'period' => new Period('custom', '2026-10-20', '2026-10-21'),
            'operators' => ['1001' => 'Иван <b>', '1002' => '1002'],
            'ext' => '*', 'kpi' => $kpi, 'kpiPrev' => array_merge($kpi, ['answered' => 2]), 'timeline' => null, 't' => $t,
            'counts' => JournalTable::counts($rows), 'allCount' => count($shown), 'days' => JournalTable::days($shown),
            'table' => JournalTable::apply($shown, '', 'at', 'desc', 1, 100),
        ]);
        $this->assertStringContainsString('<option value="*" selected>Все операторы</option>', $html);
        $this->assertStringContainsString('>Оператор</th>', $html);
        $this->assertStringContainsString('Ср, 21 октября · принято 0 · пропущено 1 · исходящих 0', $html);
        $this->assertStringContainsString('Вт, 20 октября · принято 1 · пропущено 0 · исходящих 0', $html);
        $this->assertSame(2, substr_count($html, 'class="day-row"'));
        $this->assertStringContainsString('✓ перезвонил Иван &lt;b&gt; через 5 мин', $html);
        $this->assertStringNotContainsString('Иван <b>', $html);
        $this->assertStringContainsString('Взял другой / сбросили <span class="chip-n">1</span>', $html);
        $this->assertStringContainsString('Все <span class="chip-n">2</span>', $html);
        $this->assertStringContainsString('noise=1', $html);
        $this->assertStringContainsString('class="num js-client" data-number="+998901112233"', $html);
        $this->assertStringContainsString('p=journal_csv', $html);
        $this->assertSame(6, substr_count($html, 'class="tile"'));
        $this->assertStringContainsString('class="dlt bad"', $html, 'answered fell from 2 to 1');
        $this->assertStringContainsString('class="drawer"', $html);
    }
}

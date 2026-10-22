<?php

namespace CallStats\Tests\Unit;

use CallStats\Report\OperatorStatsReport;
use CallStats\Report\Period;
use CallStats\Report\StatsInsights;
use CallStats\Web\View;
use PHPUnit\Framework\TestCase;

class StatsTemplateTest extends TestCase
{
    private function view(): View
    {
        return new View(dirname(__DIR__, 2) . '/templates');
    }

    private function row(string $ext, string $name, int $answered, int $lost, int $other, array $extra = []): array
    {
        $r = array_merge([
            'extension' => $ext, 'name' => $name, 'answered' => $answered, 'missed' => $lost + $other,
            'missed_lost' => $lost, 'missed_other' => $other, 'answer_pct' => 90.0, 'offline' => 2,
            'out_total' => 5, 'out_connected' => 3, 'talk_sec' => 3600, 'avg_talk_sec' => 120, 'avg_ring_sec' => 6,
            'session_sec' => null, 'pause_sec' => null, 'pauses_by_reason' => [], 'active_span_sec' => 7200,
            '_in_talk' => 0, '_in_ring' => 0,
        ], $extra);
        return array_merge($r, OperatorStatsReport::workload($r['talk_sec'], $answered + $r['out_total'], $r['session_sec'], $r['pause_sec'], $r['active_span_sec']));
    }

    private function summary(int $total, int $answered): array
    {
        return [
            'total' => $total, 'answered' => $answered, 'lost' => $total - $answered, 'lost_short' => 1,
            'lost_pct' => $total ? round(($total - $answered) * 100 / $total, 1) : null, 'sla_pct' => 91.0,
            'avg_wait' => 5, 'max_wait' => 111, 'called_back' => 1, 'called_back_pct' => 50.0,
            'lost_calls' => [],
        ];
    }

    private function section(Period $p, array $rows): array
    {
        $report = ['rows' => $rows, 'total' => OperatorStatsReport::sumRows(array_filter($rows, [StatsInsights::class, 'isActive']), false),
            'hasTimeline' => false, 'hasApprox' => false];
        return ['title' => 'Период <x>', 'period' => $p, 'report' => $report, 'insights' => StatsInsights::build($rows, []),
            'prevLabel' => '19.10 00:00 — 19.10 23:59', 'line' => $this->summary(120, 112)];
    }

    private function lostItem(string $at, string $number, string $kind): array
    {
        return ['type' => 'in_missed', 'call_id' => 1, 'at' => $at, 'client' => $number, 'wait_sec' => 42,
            'rang' => ['1001', '1002'], 'callback' => ['kind' => $kind, 'at' => null, 'after_sec' => null, 'ext' => null, 'connected' => null]];
    }

    private function vars(Period $p, array $over = []): array
    {
        $rows = [
            $this->row('1001', 'Иван <b>', 50, 2, 9),
            $this->row('1002', 'Мария', 40, 0, 0, ['session_sec' => 7200, 'pause_sec' => 600, 'pauses_by_reason' => ['Обед <x>' => 600]]),
            $this->row('1009', '1009', 0, 0, 0, ['out_total' => 0, 'offline' => 300]),
        ];
        $s = $this->summary(120, 112);
        $s['lost_calls'] = [$this->lostItem('2026-10-20 10:00:00', '+998907778899', 'none')];
        return array_merge([
            'period' => $p,
            'default' => false,
            'names' => ['1001' => 'Иван <b>', '1002' => 'Мария'],
            'summary' => [
                'title' => 'Свой период', 'period' => $p, 's' => $s, 'prev' => $this->summary(100, 98),
                'prevLabel' => '13.10 — 19.10', 'byHour' => [9 => ['answered' => 10, 'lost' => 1]],
                'waits' => ['answered' => ['0–5' => 3, '5–10' => 1, '10–15' => 0, '15–30' => 0, '30+' => 0],
                    'lost' => ['0–5' => 1, '5–10' => 0, '10–15' => 0, '15–30' => 0, '30+' => 1]],
                'byDay' => ['2026-10-20' => ['answered' => 5, 'lost' => 1], '2026-10-21' => ['answered' => 6, 'lost' => 0]],
                'heatmap' => null, 'callbacks' => true,
            ],
            'sections' => [$this->section($p, $rows)],
            'clients' => [
                'period' => $p, 'tooLong' => null,
                'lost' => [$this->lostItem('2026-10-20 10:00:00', '+998907778899', 'none')],
                'repeat' => [['number' => '+998905556677', 'calls' => 4, 'answered' => 3, 'lost' => 1, 'last_at' => '2026-10-21 09:00:00']],
            ],
        ], $over);
    }

    public function test_stats_page_with_summary_charts_tables_and_drawer(): void
    {
        date_default_timezone_set('Asia/Tashkent');
        $p = new Period('custom', '2026-10-20', '2026-10-21');
        $html = $this->view()->render('stats', $this->vars($p));

        foreach (['Поступило', 'Обслужено', 'Потеряно', '% потерянных', 'Ответ за 10 сек', 'Ожидание ср. / макс.', 'Перезвонили потерянным'] as $label) {
            $this->assertStringContainsString('>' . $label . '<', $html, $label);
        }
        $this->assertStringContainsString('из них сбросили сразу 1', $html);
        $this->assertStringContainsString('1 из 1', $html, 'called back N of M lost with a number');
        $this->assertStringContainsString('class="dlt', $html);
        $this->assertStringContainsString('По часам', $html);
        $this->assertStringContainsString('Ожидание ответа', $html);
        $this->assertStringContainsString('По дням', $html);
        $this->assertStringNotContainsString('Дни недели и часы', $html, 'no heatmap for a short period');
        $this->assertStringContainsString('поступило 120 · потеряно 8 (6,7%) · ответ за 10 сек 91%', $html);

        // operator table: missed split, busy, link to the operator card, escaping
        $this->assertStringContainsString('ушло коллегам 9', $html);
        $this->assertStringContainsString('?p=operator&amp;ext=1001&amp;preset=custom&amp;from=2026-10-20&amp;to=2026-10-21', $html);
        $this->assertStringContainsString('Иван &lt;b&gt;', $html);
        $this->assertStringNotContainsString('Иван <b>', $html);
        $this->assertStringContainsString('Обед &lt;x&gt;', $html);
        $this->assertStringContainsString('по первому и последнему звонку дня', $html);
        $this->assertStringContainsString('Занятость', $html);
        $this->assertStringContainsString('Звонков в час', $html);
        $this->assertStringContainsString('Период &lt;x&gt;', $html);

        // clients: lost not called back, repeat callers, drawer, CSV links
        $this->assertStringContainsString('Кому не перезвонили', $html);
        $this->assertStringContainsString('class="num js-client" data-number="+998907778899"', $html);
        $this->assertStringContainsString('Иван &lt;b&gt;, Мария', $html);
        $this->assertStringContainsString('Повторные звонки', $html);
        $this->assertStringContainsString('data-number="+998905556677"', $html);
        $this->assertStringContainsString('class="drawer"', $html);
        $this->assertStringContainsString('id="chart-tip"', $html);
        foreach (['operators', 'lost', 'repeat'] as $t) {
            $this->assertStringContainsString('p=stats_csv&amp;table=' . $t, $html);
        }
        $this->assertStringContainsString('data-autorefresh="60"', $html);
    }

    public function test_stats_page_empty_states_and_heatmap(): void
    {
        date_default_timezone_set('Asia/Tashkent');
        $p = new Period('custom', '2026-10-01', '2026-10-21');
        $v = $this->vars($p);
        $v['summary']['heatmap'] = array_fill_keys(range(1, 7), [10 => 3]);
        $v['clients']['lost'] = [];
        $v['clients']['repeat'] = [];
        $html = $this->view()->render('stats', $v);
        $this->assertStringContainsString('Дни недели и часы', $html);
        $this->assertStringContainsString('Всем потерянным клиентам перезвонили или они дозвонились сами ✓', $html);
        $this->assertStringContainsString('Повторных звонков нет', $html);
    }

    public function test_stats_page_lost_list_is_capped_and_long_periods_refused(): void
    {
        date_default_timezone_set('Asia/Tashkent');
        $p = new Period('custom', '2026-10-20', '2026-10-21');
        $v = $this->vars($p);
        $v['clients']['lost'] = [];
        for ($i = 0; $i < 53; $i++) {
            $v['clients']['lost'][] = $this->lostItem('2026-10-20 10:00:00', '+99890777' . sprintf('%04d', $i), 'none');
        }
        $html = $this->view()->render('stats', $v);
        $this->assertSame(50, substr_count($html, 'data-number="+99890777'));
        $this->assertStringContainsString('ещё 3', $html);

        $v['clients']['tooLong'] = 92;
        $v['summary']['callbacks'] = false;
        $html = $this->view()->render('stats', $v);
        $this->assertStringContainsString('не дольше 92 дней', $html);
        $this->assertStringNotContainsString('data-number="+99890777', $html);
    }

    public function test_default_view_has_sections_and_top_summary(): void
    {
        date_default_timezone_set('Asia/Tashkent');
        $today = new Period('today', '2026-10-21', '2026-10-21');
        $v = $this->vars($today, ['default' => true]);
        $v['summary']['byDay'] = null;
        $v['sections'] = [$this->section($today, []), $this->section(new Period('yesterday', '2026-10-20', '2026-10-20'), [])];
        $html = $this->view()->render('stats', $v);
        $this->assertSame(2, substr_count($html, 'class="stats-section"'));
        $this->assertStringNotContainsString('По дням', $html);
        $this->assertStringContainsString('За выбранный период данных нет.', $html);
    }

    public function test_operator_card(): void
    {
        date_default_timezone_set('Asia/Tashkent');
        $p = new Period('custom', '2026-10-18', '2026-10-21');
        $row = $this->row('1002', 'Мария <i>', 40, 2, 5);
        $html = $this->view()->render('operator', [
            'period' => $p, 'ext' => '1002', 'name' => 'Мария <i>',
            'row' => $row, 'prev' => array_merge($row, ['answered' => 20]),
            'prevLabel' => '14.10 — 17.10',
            'days' => ['2026-10-18' => ['answered' => 10, 'missed' => 1, 'out' => 2, 'talk_sec' => 600, 'avg_talk_sec' => 60, 'answer_pct' => 90.9]],
            'hours' => [9 => ['answered' => 3, 'missed' => 1]],
        ]);
        $this->assertStringContainsString('Мария &lt;i&gt;', $html);
        $this->assertStringNotContainsString('Мария <i>', $html);
        $this->assertStringContainsString('<input type="hidden" name="p" value="operator">', $html);
        $this->assertStringContainsString('<input type="hidden" name="ext" value="1002">', $html);
        foreach (['Принято', 'Клиент потерян', 'Ушло коллегам', '% ответа', 'Время разговоров', 'Средний разговор', 'Ср. время до ответа', 'Занятость'] as $label) {
            $this->assertStringContainsString('>' . $label . '<', $html, $label);
        }
        $this->assertSame(8, substr_count($html, 'class="tile"'));
        $this->assertStringContainsString('class="dlt good"', $html);
        $this->assertStringContainsString('90,9%', $html);
        $this->assertStringContainsString('data-tip=', $html);
        $this->assertStringContainsString('?p=journal&amp;ext=1002&amp;preset=custom', $html);
        $this->assertStringContainsString('?p=recordings&amp;ext=1002&amp;preset=custom', $html);
    }
}

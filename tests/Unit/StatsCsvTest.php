<?php

namespace CallStats\Tests\Unit;

use CallStats\Report\OperatorStatsReport;
use CallStats\Report\StatsInsights;
use CallStats\Web\StatsCsv;
use PHPUnit\Framework\TestCase;

class StatsCsvTest extends TestCase
{
    private function row(string $ext, int $answered, int $offline, array $extra = []): array
    {
        $r = array_merge([
            'extension' => $ext, 'name' => $ext, 'answered' => $answered, 'missed' => 1, 'missed_lost' => 1, 'missed_other' => 0,
            'answer_pct' => 50.0, 'offline' => $offline, 'out_total' => 2, 'out_connected' => 1, 'talk_sec' => 600,
            'avg_talk_sec' => 60, 'avg_ring_sec' => 4, 'session_sec' => null, 'pause_sec' => null, 'pauses_by_reason' => [],
            'active_span_sec' => 3600, '_in_talk' => 0, '_in_ring' => 0,
        ], $extra);
        return array_merge($r, OperatorStatsReport::workload($r['talk_sec'], $r['answered'] + $r['out_total'], $r['session_sec'], $r['pause_sec'], $r['active_span_sec']));
    }

    public function test_operators_exports_shown_operators_and_their_sum(): void
    {
        $rows = [
            $this->row('1001', 10, 3),
            $this->row('1002', 5, 2, ['session_sec' => 7200, 'pause_sec' => 600]),
            $this->row('1009', 0, 300, ['missed' => 0, 'missed_lost' => 0, 'out_total' => 0, 'out_connected' => 0, 'talk_sec' => 0]),
        ];
        $report = ['rows' => $rows, 'total' => OperatorStatsReport::sumRows(array_filter($rows, [StatsInsights::class, 'isActive']), true)];
        list($header, $out) = StatsCsv::operators($report, ['1001' => 'Иван']);
        $this->assertSame('В сети, сек', $header[11]);
        $this->assertSame(['Иван (1001)', '1002', 'Итого'], array_column($out, 0));
        $last = $out[2];
        foreach ([1, 2, 3, 5, 6, 7, 8, 11, 12] as $col) {
            $this->assertSame((int) $out[0][$col] + (int) $out[1][$col], (int) $last[$col], $header[$col]);
        }
        $this->assertSame(3600, $out[0][11], 'active span of an operator without a login');
        $this->assertSame(7200, $out[1][11]);
        $this->assertSame(10800, $last[11]);
        $this->assertSame('', $out[0][12], 'pause unknown');
        $this->assertSame('50', $out[0][4]);
        $this->assertSame('16,7', $out[0][13], 'busy % with a decimal comma');
    }
}

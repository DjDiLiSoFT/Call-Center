<?php

namespace CallStats\Web;

use CallStats\Report\StatsInsights;

/**
 * Rows of the stats page CSV exports (?p=stats_csv): [header, rows, text columns] for Csv::build().
 */
class StatsCsv
{
    /**
     * The operators shown in the table (StatsInsights active) and the «Итого» row, which the
     * report already sums over exactly those operators.
     *
     * @param array $report OperatorStatsReport::fetch()
     * @param array<string,string> $names extension => display name
     * @return array{0: string[], 1: array[], 2: int[]}
     */
    public static function operators(array $report, array $names): array
    {
        $header = ['Оператор', 'Принято', 'Пропущено (клиент потерян)', 'Ушло коллегам', '% ответа', 'Не в сети',
            'Исходящие всего', 'Исходящие состоялись', 'Время разговоров, сек', 'Ср. разговор, сек', 'Ср. ответ, сек',
            'В сети, сек', 'Пауза, сек', 'Занятость %', 'Звонков в час'];
        $ins = StatsInsights::build($report['rows'], []);
        $rows = [];
        foreach ($ins['active'] as $r) {
            $rows[] = self::operatorLine($r, self::who((string) $r['extension'], $names));
        }
        $rows[] = self::operatorLine($report['total'], 'Итого');
        return [$header, $rows, []];
    }

    /**
     * @param array[] $lost QueueSummaryReport lost items (all of the period)
     * @return array{0: string[], 1: array[], 2: int[]}
     */
    public static function lost(array $lost, array $names): array
    {
        $rows = [];
        foreach ($lost as $it) {
            if (($it['callback']['kind'] ?? 'none') === 'none') {
                $rows[] = [$it['at'], $it['client'], $it['wait_sec'], implode(', ', array_map(function ($e) use ($names): string {
                    return self::who((string) $e, $names);
                }, $it['rang']))];
            }
        }
        return [['Дата', 'Номер', 'Ожидание, сек', 'Звонил операторам'], $rows, [1]];
    }

    /**
     * @param array[] $repeat QueueSummaryReport::repeatCallers()
     * @return array{0: string[], 1: array[], 2: int[]}
     */
    public static function repeat(array $repeat): array
    {
        $rows = [];
        foreach ($repeat as $r) {
            $rows[] = [$r['number'], $r['calls'], $r['answered'], $r['lost'], $r['last_at']];
        }
        return [['Номер', 'Звонков', 'Принято', 'Потеряно', 'Последний звонок'], $rows, [0]];
    }

    private static function operatorLine(array $r, string $name): array
    {
        return [$name, $r['answered'], $r['missed_lost'], $r['missed_other'], self::dec($r['answer_pct']), $r['offline'],
            $r['out_total'], $r['out_connected'], $r['talk_sec'], $r['avg_talk_sec'], $r['avg_ring_sec'],
            (int) ($r['online_sec'] ?? 0) > 0 ? (int) $r['online_sec'] : '', $r['pause_sec'] === null ? '' : $r['pause_sec'],
            self::dec($r['busy_pct']), self::dec($r['calls_per_hour'])];
    }

    private static function who(string $ext, array $names): string
    {
        $n = $names[$ext] ?? $ext;
        return $n !== $ext ? $n . ' (' . $ext . ')' : $ext;
    }

    /** @param int|float|null $v */
    private static function dec($v): string
    {
        return $v === null ? '' : str_replace('.', ',', (string) $v);
    }
}

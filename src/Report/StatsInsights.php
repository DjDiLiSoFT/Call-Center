<?php

namespace CallStats\Report;

/**
 * Reading aids for the operator stats table: operators with no calls at all are split off
 * (they only add noise), the rest get a rank, an answer-rate level and a "missed above average"
 * flag, plus their values in the previous period for ▲/▼ comparison.
 */
class StatsInsights
{
    /** Previous-period values shown as ▲/▼ next to the current ones. */
    public const COMPARED = ['answered', 'missed', 'answer_pct', 'talk_sec', 'avg_ring_sec'];

    /**
     * @param array[] $rows OperatorStatsReport rows of the period
     * @param array[] $prevRows OperatorStatsReport rows of the previous period
     * @return array{active: array[], inactive: array[], avg_missed: float}
     */
    public static function build(array $rows, array $prevRows): array
    {
        $prev = [];
        foreach ($prevRows as $r) {
            $prev[(string) $r['extension']] = $r;
        }
        $active = [];
        $inactive = [];
        foreach ($rows as $r) {
            $calls = (int) $r['answered'] + (int) $r['missed'] + (int) $r['out_total'];
            if ($calls > 0 || (int) ($r['session_sec'] ?? 0) > 0) {
                $active[] = $r;
            } else {
                $inactive[] = $r;
            }
        }

        $withIn = array_filter($active, function (array $r): bool {
            return (int) $r['answered'] + (int) $r['missed'] > 0;
        });
        $avgMissed = $withIn ? array_sum(array_column($withIn, 'missed')) / count($withIn) : 0.0;

        usort($active, function (array $a, array $b): int {
            return [-(int) $a['answered'], (int) $a['missed'], (string) $a['extension']]
                <=> [-(int) $b['answered'], (int) $b['missed'], (string) $b['extension']];
        });
        foreach ($active as $i => &$r) {
            $r['rank'] = $i + 1;
            $r['pct_level'] = self::pctLevel($r['answer_pct']);
            $r['missed_high'] = (int) $r['missed'] >= 3 && (int) $r['missed'] > 1.5 * $avgMissed;
            $p = $prev[(string) $r['extension']] ?? null;
            $r['prev'] = $p === null ? null : array_intersect_key($p, array_flip(self::COMPARED));
        }
        unset($r);

        return ['active' => $active, 'inactive' => $inactive, 'avg_missed' => $avgMissed];
    }

    /** Answer rate level: good >= 90%, warn >= 80%, bad below, null when there were no calls. */
    public static function pctLevel($pct): ?string
    {
        if ($pct === null) {
            return null;
        }
        return $pct >= 90 ? 'good' : ($pct >= 80 ? 'warn' : 'bad');
    }
}

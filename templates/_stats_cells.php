<?php
use CallStats\Web\Delta;
use CallStats\Web\Format;
use CallStats\Web\View;

/** @var array $r operator row of OperatorStatsReport (+ StatsInsights fields on the stats page) */
/** @var bool $tl show session/pause columns */
/** @var string|null $link journal URL for the name, null = plain text */
/** @var string $pauseTitle tooltip for the pause cell ('' = none) */
/** @var bool $rank show the rank cell, default false */
$rank = $rank ?? false;
$prev = $r['prev'] ?? null;
$d = function ($sec) {
    return View::e(Format::durationOrDash($sec));
};
// ▲/▼ vs the previous period: $good = +1 (more is better), -1 (less is better), 0 (neutral).
$delta = function (string $k, int $good) use ($r, $prev): string {
    return $prev === null ? '' : Delta::html($k, $r[$k], $prev[$k] ?? null, $good);
};
$level = $r['pct_level'] ?? null;
?>
<?php if ($rank): ?>
      <td class="num rank" data-sort="<?= (int) $r['rank'] ?>"><?= (int) $r['rank'] === 1 ? '<span class="top" title="Лучший за период">★</span>' : (int) $r['rank'] ?></td>
<?php endif; ?>
      <td data-sort="<?= View::e(mb_strtolower($r['name'])) ?>"><?php if ($link !== null): ?><a href="<?= View::e($link) ?>"><?= View::e($r['name']) ?></a><?php else: ?><?= View::e($r['name']) ?><?php endif; ?><?= $r['name'] !== (string) $r['extension'] ? ' <span class="muted">' . View::e($r['extension']) . '</span>' : '' ?></td>
      <td class="num" data-sort="<?= $r['answered'] ?>"><?= $r['answered'] ?><?= $delta('answered', 1) ?></td>
      <td class="num<?= !empty($r['missed_high']) ? ' missed-high' : '' ?>" data-sort="<?= $r['missed'] ?>"<?= !empty($r['missed_high']) ? ' title="Пропусков заметно больше, чем в среднем у операторов"' : '' ?>><?= $r['missed'] ?><?= $delta('missed', -1) ?></td>
      <td class="num pct" data-sort="<?= $r['answer_pct'] === null ? -1 : number_format($r['answer_pct'], 1, '.', '') ?>">
        <?= View::e(Format::pct($r['answer_pct'])) ?><?= $delta('answer_pct', 1) ?>
<?php if ($level !== null): ?>
        <span class="pct-bar lvl-<?= $level ?>" aria-hidden="true"><span style="width:<?= max(0, min(100, (float) $r['answer_pct'])) ?>%"></span></span>
<?php endif; ?>
      </td>
      <td class="num" data-sort="<?= $r['offline'] ?>"><?= $r['offline'] ?></td>
      <td class="num" data-sort="<?= $r['out_total'] ?>"><?= $r['out_connected'] ?> / <?= $r['out_total'] ?></td>
      <td class="num" data-sort="<?= $r['talk_sec'] ?>"><?= $d($r['talk_sec']) ?><?= $delta('talk_sec', 0) ?></td>
      <td class="num" data-sort="<?= $r['avg_talk_sec'] ?>"><?= $d($r['avg_talk_sec']) ?></td>
      <td class="num" data-sort="<?= $r['avg_ring_sec'] ?>"><?= $d($r['avg_ring_sec']) ?><?= $delta('avg_ring_sec', -1) ?></td>
<?php if ($tl): ?>
      <td class="num" data-sort="<?= (int) $r['session_sec'] ?>"><?= $d($r['session_sec']) ?></td>
      <td class="num" data-sort="<?= (int) $r['pause_sec'] ?>"<?= $pauseTitle !== '' ? ' title="' . View::e($pauseTitle) . '"' : '' ?>><?= $d($r['pause_sec']) ?></td>
<?php endif; ?>

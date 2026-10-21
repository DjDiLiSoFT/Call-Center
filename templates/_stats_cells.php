<?php
use CallStats\Web\Format;
use CallStats\Web\View;

/** @var array $r operator row of OperatorStatsReport */
/** @var bool $tl show session/pause columns */
/** @var string|null $link journal URL for the name, null = plain text */
/** @var string $pauseTitle tooltip for the pause cell ('' = none) */
$d = function ($sec) {
    return View::e(Format::durationOrDash($sec));
};
?>
      <td data-sort="<?= View::e(mb_strtolower($r['name'])) ?>"><?php if ($link !== null): ?><a href="<?= View::e($link) ?>"><?= View::e($r['name']) ?></a><?php else: ?><?= View::e($r['name']) ?><?php endif; ?><?= $r['name'] !== (string) $r['extension'] ? ' <span class="muted">' . View::e($r['extension']) . '</span>' : '' ?></td>
      <td class="num" data-sort="<?= $r['answered'] ?>"><?= $r['answered'] ?></td>
      <td class="num" data-sort="<?= $r['missed'] ?>"><?= $r['missed'] ?></td>
      <td class="num" data-sort="<?= $r['answer_pct'] === null ? -1 : number_format($r['answer_pct'], 1, '.', '') ?>"><?= View::e(Format::pct($r['answer_pct'])) ?></td>
      <td class="num" data-sort="<?= $r['offline'] ?>"><?= $r['offline'] ?></td>
      <td class="num" data-sort="<?= $r['out_total'] ?>"><?= $r['out_connected'] ?> / <?= $r['out_total'] ?></td>
      <td class="num" data-sort="<?= $r['talk_sec'] ?>"><?= $d($r['talk_sec']) ?></td>
      <td class="num" data-sort="<?= $r['avg_talk_sec'] ?>"><?= $d($r['avg_talk_sec']) ?></td>
      <td class="num" data-sort="<?= $r['avg_ring_sec'] ?>"><?= $d($r['avg_ring_sec']) ?></td>
<?php if ($tl): ?>
      <td class="num" data-sort="<?= (int) $r['session_sec'] ?>"><?= $d($r['session_sec']) ?></td>
      <td class="num" data-sort="<?= (int) $r['pause_sec'] ?>"<?= $pauseTitle !== '' ? ' title="' . View::e($pauseTitle) . '"' : '' ?>><?= $d($r['pause_sec']) ?></td>
<?php endif; ?>

<?php
use CallStats\Report\Period;
use CallStats\Web\Format;
use CallStats\Web\View;

/** @var \CallStats\Report\Period $period */
/** @var array $report */
/** @var array $insights StatsInsights::build() */
/** @var string $prevLabel previous period, e.g. "20.10 00:00 — 20.10 12:00" */
$tl = $report['hasTimeline'];
$dur = function ($sec) {
    return Format::durationOrDash($sec);
};
$pct = function ($v) {
    return Format::pct($v);
};
$journal = function (string $ext) use ($period) {
    return '?' . http_build_query(array_merge(['p' => 'journal', 'ext' => $ext], $period->query()));
};
$reasonTitle = function (array $r) {
    if (!$r) {
        return '';
    }
    $parts = [];
    foreach ($r as $reason => $sec) {
        $parts[] = ($reason === '' ? 'без причины' : $reason) . ': ' . Format::duration((int) $sec);
    }
    return implode("\n", $parts);
};
?>
<form method="get" action="" class="filters card">
  <input type="hidden" name="p" value="stats">
<?= $this->render('_period_fields', ['period' => $period]) ?>
  <button type="submit" class="primary">Показать</button>
</form>

<p class="muted" data-autorefresh="60">Период: <?= View::e($period->fromDate()) ?> — <?= View::e($period->toDate()) ?>. Стрелки ▲▼ — сравнение с <?= View::e($prevLabel) ?>. Страница обновляется сама раз в минуту.</p>
<?php if ($report['hasApprox']): ?>
<p class="notice">часть данных за выбранный период приблизительная</p>
<?php endif; ?>

<?php if (!$report['rows']): ?>
<p class="muted">За выбранный период данных нет.</p>
<?php else: ?>
<div class="table-wrap">
<table class="sortable">
  <thead>
    <tr>
<?= $this->render('_stats_head', ['tl' => $tl, 'rank' => true]) ?>    </tr>
  </thead>
  <tbody>
<?php foreach ($insights['active'] as $r): ?>
    <tr>
<?= $this->render('_stats_cells', ['r' => $r, 'tl' => $tl, 'rank' => true, 'link' => $journal($r['extension']), 'pauseTitle' => $reasonTitle($r['pauses_by_reason'])]) ?>    </tr>
<?php endforeach; ?>
  </tbody>
<?php if ($insights['inactive']): $n = count($insights['inactive']); ?>
  <tbody class="inactive-toggle">
    <tr><td colspan="<?= $tl ? 12 : 10 ?>"><button type="button" class="linkish" data-toggle-inactive aria-expanded="false">▸ Ещё <?= $n ?> <?= \CallStats\Web\Charts::plural($n, 'оператор', 'оператора', 'операторов') ?> без звонков за период: <?= View::e(implode(', ', array_column($insights['inactive'], 'name'))) ?></button></td></tr>
  </tbody>
  <tbody class="inactive" hidden>
<?php foreach ($insights['inactive'] as $r): ?>
    <tr>
      <td class="num rank">—</td>
<?= $this->render('_stats_cells', ['r' => $r, 'tl' => $tl, 'link' => $journal($r['extension']), 'pauseTitle' => '']) ?>    </tr>
<?php endforeach; ?>
  </tbody>
<?php endif; ?>
<?php $t = $report['total']; ?>
  <tfoot>
    <tr>
      <th></th>
      <th>Итого</th>
      <th class="num"><?= $t['answered'] ?></th>
      <th class="num"><?= $t['missed'] ?></th>
      <th class="num"><?= View::e($pct($t['answer_pct'])) ?></th>
      <th class="num"><?= $t['offline'] ?></th>
      <th class="num"><?= $t['out_connected'] ?> / <?= $t['out_total'] ?></th>
      <th class="num"><?= View::e($dur($t['talk_sec'])) ?></th>
      <th class="num"><?= View::e($dur($t['avg_talk_sec'])) ?></th>
      <th class="num"><?= View::e($dur($t['avg_ring_sec'])) ?></th>
<?php if ($tl): ?>
      <th class="num"><?= View::e($dur($t['session_sec'])) ?></th>
      <th class="num"<?= $t['pauses_by_reason'] ? ' title="' . View::e($reasonTitle($t['pauses_by_reason'])) . '"' : '' ?>><?= View::e($dur($t['pause_sec'])) ?></th>
<?php endif; ?>
    </tr>
  </tfoot>
</table>
</div>
<?php endif; ?>

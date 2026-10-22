<?php
use CallStats\Web\Charts;
use CallStats\Web\Format;
use CallStats\Web\View;

/** One operator stats table (used by the stats page for each section). */
/** @var \CallStats\Report\Period $period */
/** @var array $report OperatorStatsReport::fetch() */
/** @var array $insights StatsInsights::build() */
$card = function (string $ext) use ($period) {
    return '?' . http_build_query(array_merge(['p' => 'operator', 'ext' => $ext], $period->query()));
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
$cols = 12; // rank + operator + 10 figures (_stats_head.php)
?>
<?php if ($report['hasApprox']): ?>
<p class="notice">часть данных за выбранный период приблизительная</p>
<?php endif; ?>

<?php if (!$report['rows']): ?>
<p class="muted">За выбранный период данных нет.</p>
<?php else: ?>
<div class="table-wrap">
<table class="sortable ops-table">
  <thead>
    <tr>
<?= $this->render('_stats_head', ['rank' => true]) ?>    </tr>
  </thead>
  <tbody>
<?php foreach ($insights['active'] as $r): ?>
    <tr>
<?= $this->render('_stats_cells', ['r' => $r, 'rank' => true, 'link' => $card((string) $r['extension']), 'pauseTitle' => $reasonTitle($r['pauses_by_reason'])]) ?>    </tr>
<?php endforeach; ?>
  </tbody>
<?php if ($insights['inactive']): $n = count($insights['inactive']); ?>
  <tbody class="inactive-toggle">
    <tr><td colspan="<?= $cols ?>"><button type="button" class="linkish" data-toggle-inactive aria-expanded="false">▸ Ещё <?= $n ?> <?= Charts::plural($n, 'оператор', 'оператора', 'операторов') ?> без звонков за период: <?= View::e(implode(', ', array_column($insights['inactive'], 'name'))) ?></button></td></tr>
  </tbody>
  <tbody class="inactive" hidden>
<?php foreach ($insights['inactive'] as $r): ?>
    <tr>
      <td class="num rank">—</td>
<?= $this->render('_stats_cells', ['r' => $r, 'link' => $card((string) $r['extension']), 'pauseTitle' => $reasonTitle($r['pauses_by_reason'])]) ?>    </tr>
<?php endforeach; ?>
  </tbody>
<?php endif; ?>
  <tfoot>
    <tr title="Сумма по операторам в таблице (без операторов без звонков)">
      <th></th>
<?= $this->render('_stats_cells', ['r' => $report['total'], 'total' => true, 'link' => null, 'pauseTitle' => $reasonTitle($report['total']['pauses_by_reason'])]) ?>    </tr>
  </tfoot>
</table>
</div>
<?php endif; ?>

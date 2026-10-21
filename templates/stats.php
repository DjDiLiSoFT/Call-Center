<?php
use CallStats\Report\Period;
use CallStats\Web\Format;
use CallStats\Web\View;

/** @var \CallStats\Report\Period $period */
/** @var array $report */
/** @var array $queues queue => name */
/** @var array $operators extension => display name */
/** @var string $queue */
/** @var string[] $selected */
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
  <label>Очередь
    <select name="queue">
      <option value="">Все очереди</option>
<?php foreach ($queues as $q => $name): ?>
      <option value="<?= View::e($q) ?>"<?= (string) $q === $queue ? ' selected' : '' ?>><?= View::e($name !== '' ? $name . ' (' . $q . ')' : $q) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label>Операторы <span class="muted">(пусто — все)</span>
    <select name="ext[]" multiple size="4">
<?php foreach ($operators as $ext => $name): ?>
      <option value="<?= View::e($ext) ?>"<?= in_array((string) $ext, $selected, true) ? ' selected' : '' ?>><?= View::e($ext . ($name !== (string) $ext ? ' — ' . $name : '')) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <button type="submit" class="primary">Показать</button>
</form>

<p class="muted">Период: <?= View::e($period->fromDate()) ?> — <?= View::e($period->toDate()) ?></p>
<?php if ($report['hasApprox']): ?>
<p class="notice">часть данных до 22.09.2026 приблизительная</p>
<?php endif; ?>

<?php if (!$report['rows']): ?>
<p class="muted">За выбранный период данных нет.</p>
<?php else: ?>
<div class="table-wrap">
<table class="sortable">
  <thead>
    <tr>
<?= $this->render('_stats_head', ['tl' => $tl]) ?>    </tr>
  </thead>
  <tbody>
<?php foreach ($report['rows'] as $r): ?>
    <tr>
<?= $this->render('_stats_cells', ['r' => $r, 'tl' => $tl, 'link' => $journal($r['extension']), 'pauseTitle' => $reasonTitle($r['pauses_by_reason'])]) ?>    </tr>
<?php endforeach; ?>
  </tbody>
<?php $t = $report['total']; ?>
  <tfoot>
    <tr>
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

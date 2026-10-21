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
    return $sec === null ? '—' : Format::duration((int) $sec);
};
$pct = function ($v) {
    return $v === null ? '—' : rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',') . '%';
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
  <label>Период
    <select name="preset" data-preset>
<?php foreach (Period::PRESETS as $key => $label): ?>
      <option value="<?= View::e($key) ?>"<?= $key === $period->preset ? ' selected' : '' ?>><?= View::e($label) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label>С
    <input type="date" name="from" value="<?= View::e($period->fromDate()) ?>" data-custom>
  </label>
  <label>По
    <input type="date" name="to" value="<?= View::e($period->toDate()) ?>" data-custom>
  </label>
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
      <th data-type="text">Оператор</th>
      <th data-type="num" class="num">Принято</th>
      <th data-type="num" class="num">Пропущено</th>
      <th data-type="num" class="num">% ответа</th>
      <th data-type="num" class="num">Не в сети</th>
      <th data-type="num" class="num">Исходящие<br><span class="sub">состоялось / всего</span></th>
      <th data-type="num" class="num">Время разговоров</th>
      <th data-type="num" class="num">Средний разговор</th>
      <th data-type="num" class="num">Ср. время до ответа</th>
<?php if ($tl): ?>
      <th data-type="num" class="num">Время в системе</th>
      <th data-type="num" class="num">На паузе</th>
<?php endif; ?>
    </tr>
  </thead>
  <tbody>
<?php foreach ($report['rows'] as $r): ?>
    <tr>
      <td data-sort="<?= View::e(mb_strtolower($r['name'])) ?>"><a href="<?= View::e($journal($r['extension'])) ?>"><?= View::e($r['name']) ?></a><?= $r['name'] !== (string) $r['extension'] ? ' <span class="muted">' . View::e($r['extension']) . '</span>' : '' ?></td>
      <td class="num" data-sort="<?= $r['answered'] ?>"><?= $r['answered'] ?></td>
      <td class="num" data-sort="<?= $r['missed'] ?>"><?= $r['missed'] ?></td>
      <td class="num" data-sort="<?= $r['answer_pct'] === null ? -1 : $r['answer_pct'] ?>"><?= View::e($pct($r['answer_pct'])) ?></td>
      <td class="num" data-sort="<?= $r['offline'] ?>"><?= $r['offline'] ?></td>
      <td class="num" data-sort="<?= $r['out_total'] ?>"><?= $r['out_connected'] ?> / <?= $r['out_total'] ?></td>
      <td class="num" data-sort="<?= $r['talk_sec'] ?>"><?= View::e($dur($r['talk_sec'])) ?></td>
      <td class="num" data-sort="<?= $r['avg_talk_sec'] ?>"><?= View::e($dur($r['avg_talk_sec'])) ?></td>
      <td class="num" data-sort="<?= $r['avg_ring_sec'] ?>"><?= View::e($dur($r['avg_ring_sec'])) ?></td>
<?php if ($tl): ?>
      <td class="num" data-sort="<?= (int) $r['session_sec'] ?>"><?= View::e($dur($r['session_sec'])) ?></td>
      <td class="num" data-sort="<?= (int) $r['pause_sec'] ?>"<?= $r['pauses_by_reason'] ? ' title="' . View::e($reasonTitle($r['pauses_by_reason'])) . '"' : '' ?>><?= View::e($dur($r['pause_sec'])) ?></td>
<?php endif; ?>
    </tr>
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

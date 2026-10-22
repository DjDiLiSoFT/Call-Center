<?php
use CallStats\Web\Format;
use CallStats\Web\View;

/** «Кому не перезвонили»: one row per client number whose lost calls got no callback, newest first. */
/** @var array[] $rows QueueSummaryReport::groupLostByNumber() rows */
/** @var array $names extension => display name */
$max = 50;
$more = count($rows) - $max;
?>
<?php if (!$rows): ?>
<p class="muted panel-empty">Всем потерянным клиентам перезвонили или они дозвонились сами ✓</p>
<?php else: ?>
<div class="table-wrap">
<table class="clients">
  <thead><tr><th>Последний звонок</th><th>Номер</th><th class="num">Раз</th><th class="num">Ждал до</th><th>Звонил операторам</th></tr></thead>
  <tbody>
<?php foreach (array_slice($rows, 0, $max) as $r): ?>
    <tr>
      <td class="nowrap"><?= View::e(Format::ruDateTime($r['at'])) ?></td>
      <td><button type="button" class="num js-client" data-number="<?= View::e($r['client']) ?>" title="История номера"><?= View::e($r['client']) ?></button></td>
      <td class="num"<?= $r['times'] > 1 ? ' title="первый раз: ' . View::e(Format::ruDateTime($r['first_at'])) . '"' : '' ?>><?= (int) $r['times'] ?></td>
      <td class="num"><?= View::e(Format::humanDuration((int) $r['max_wait'])) ?></td>
      <td><?= $r['rang'] ? View::e(implode(', ', array_map(function ($e) use ($names) {
          return $names[(string) $e] ?? (string) $e;
      }, $r['rang']))) : '<span class="muted" title="клиент ждал в очереди, но ни один телефон не зазвонил: все заняты, на паузе или не в сети">операторы были недоступны</span>' ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($more > 0): ?>
<p class="muted panel-more">… и ещё <?= (int) $more ?> — полный список в CSV</p>
<?php endif; ?>
<?php endif; ?>

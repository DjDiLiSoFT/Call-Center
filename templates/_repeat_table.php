<?php
use CallStats\Web\Format;
use CallStats\Web\View;

/** «Повторные звонки»: numbers that called three or more times. */
/** @var array[] $rows QueueSummaryReport::repeatCallers() */
?>
<?php if (!$rows): ?>
<p class="muted panel-empty">Повторных звонков нет</p>
<?php else: ?>
<div class="table-wrap">
<table class="clients">
  <thead><tr><th>Номер</th><th class="num">Звонков</th><th class="num">Принято</th><th class="num">Потеряно</th><th>Последний звонок</th></tr></thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <td><button type="button" class="num js-client" data-number="<?= View::e($r['number']) ?>" title="История номера"><?= View::e($r['number']) ?></button></td>
      <td class="num"><?= (int) $r['calls'] ?></td>
      <td class="num"><?= (int) $r['answered'] ?></td>
      <td class="num<?= $r['lost'] > 0 ? ' lost' : '' ?>"><?= (int) $r['lost'] ?></td>
      <td class="nowrap"><?= View::e(Format::ruDateTime($r['last_at'])) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

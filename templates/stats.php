<?php
use CallStats\Web\View;

/** @var \CallStats\Report\Period $period period shown in the filter form */
/** @var array[] $sections each: title, period, report, insights, prevLabel */
/** @var bool $default true when no period was chosen (Сегодня + Вчера + 15 дней) */
?>
<form method="get" action="" class="filters card compact">
  <input type="hidden" name="p" value="stats">
<?= $this->render('_period_fields', ['period' => $period]) ?>
  <button type="submit" class="primary">Показать</button>
<?php if (!$default): ?>
  <a class="reset-link" href="?p=stats">← Сегодня, вчера и 15 дней</a>
<?php endif; ?>
</form>

<p class="muted" data-autorefresh="60">Стрелки ▲▼ — сравнение с прошлым периодом такой же длины. Страница обновляется сама раз в минуту.</p>

<?php foreach ($sections as $s): ?>
<section class="stats-section">
  <h2 class="section-title"><?= View::e($s['title']) ?>
    <span class="section-sub"><?= View::e(date('d.m.Y', strtotime($s['period']->from))) ?><?= $s['period']->fromDate() !== $s['period']->toDate() ? ' — ' . View::e(date('d.m.Y', strtotime($s['period']->to))) : '' ?> · сравнение с <?= View::e($s['prevLabel']) ?></span>
  </h2>
<?= $this->render('_stats_table', ['period' => $s['period'], 'report' => $s['report'], 'insights' => $s['insights']]) ?>
</section>
<?php endforeach; ?>

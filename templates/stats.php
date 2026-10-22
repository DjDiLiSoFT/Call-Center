<?php
use CallStats\Web\Charts;
use CallStats\Web\Format;
use CallStats\Web\View;

/** @var \CallStats\Report\Period $period period shown in the filter form */
/** @var bool $default true when no period was chosen (Сегодня + Вчера + 15 дней) */
/** @var array $summary title, period, s (QueueSummaryReport::fetch), prev, prevLabel, byHour, waits, byDay|null, heatmap|null, callbacks */
/** @var array[] $sections each: title, period, report, insights, prevLabel, line (QueueSummaryReport::summary) */
/** @var array $clients period, tooLong (int|null), lost (items with callback none), repeat */
/** @var array $names extension => display name */
$csv = function (string $table, \CallStats\Report\Period $p): string {
    return '?' . http_build_query(array_merge(['p' => 'stats_csv', 'table' => $table], $p->query()));
};
$csvLink = function (string $table, \CallStats\Report\Period $p) use ($csv): string {
    return '<a class="csv-link" href="' . View::e($csv($table, $p)) . '" title="Скачать CSV">CSV</a>';
};
$dates = function (\CallStats\Report\Period $p): string {
    return date('d.m.Y', strtotime($p->from)) . ($p->fromDate() !== $p->toDate() ? ' — ' . date('d.m.Y', strtotime($p->to)) : '');
};
$line = function (array $l): string {
    return 'поступило ' . $l['total'] . ' · потеряно ' . $l['lost'] . ($l['lost_pct'] !== null ? ' (' . Format::pct($l['lost_pct']) . ')' : '')
        . ' · ответ за 10 сек ' . Format::pct($l['sla_pct']);
};
$series = ['answered' => ['Обслужено', 'var(--accent)'], 'lost' => ['Потеряно', 'var(--danger)']];
$sum = $summary;
?>
<form method="get" action="" class="filters card compact">
  <input type="hidden" name="p" value="stats">
<?= $this->render('_period_fields', ['period' => $period]) ?>
  <button type="submit" class="primary">Показать</button>
<?php if (!$default): ?>
  <a class="reset-link" href="?p=stats">← Сегодня, вчера и 15 дней</a>
<?php endif; ?>
</form>

<p class="muted page-note" data-autorefresh="60">Стрелки ▲▼ — сравнение с прошлым периодом такой же длины. Страница обновляется сама раз в минуту.</p>

<section class="stats-block">
  <h2 class="section-title">Клиенты · <?= View::e($sum['title']) ?>
    <span class="section-sub"><?= View::e($dates($sum['period'])) ?> · сравнение с <?= View::e($sum['prevLabel']) ?></span>
  </h2>
<?= $this->render('_queue_tiles', ['s' => $sum['s'], 'prev' => $sum['prev'], 'callbacks' => $sum['callbacks']]) ?>
  <div class="chart-grid">
    <div class="card panel">
      <h3 class="panel-title">По часам <span class="panel-sub">поступившие звонки</span></h3>
<?= Charts::stackedBars($sum['byHour'], $series) ?>
    </div>
    <div class="card panel">
      <h3 class="panel-title">Ожидание ответа <span class="panel-sub">сколько ждали клиенты</span></h3>
<?= Charts::waitBars($sum['waits']) ?>
    </div>
<?php if ($sum['byDay'] !== null): ?>
    <div class="card panel">
      <h3 class="panel-title">По дням</h3>
<?= Charts::stackedBars($sum['byDay'], $series, 'day') ?>
    </div>
<?php endif; ?>
<?php if ($sum['heatmap'] !== null): ?>
    <div class="card panel">
      <h3 class="panel-title">Дни недели и часы <span class="panel-sub">когда звонят чаще</span></h3>
<?= Charts::heatmap($sum['heatmap']) ?>
    </div>
<?php endif; ?>
  </div>
</section>

<?php foreach ($sections as $s): ?>
<section class="stats-section">
  <h2 class="section-title"><?= View::e($s['title']) ?>
    <span class="section-sub"><?= View::e($dates($s['period'])) ?> · сравнение с <?= View::e($s['prevLabel']) ?></span>
    <?= $csvLink('operators', $s['period']) ?>
  </h2>
  <p class="section-line"><?= View::e($line($s['line'])) ?></p>
<?= $this->render('_stats_table', ['period' => $s['period'], 'report' => $s['report'], 'insights' => $s['insights']]) ?>
</section>
<?php endforeach; ?>

<div class="client-grid">
  <section class="card panel">
    <h2 class="panel-title">Кому не перезвонили
      <span class="panel-sub"><?= View::e($dates($clients['period'])) ?><?= $clients['tooLong'] === null ? ' · ' . count($clients['lost']) . ' ' . \CallStats\Web\Charts::plural(count($clients['lost']), 'номер', 'номера', 'номеров') : '' ?></span>
<?php if ($clients['tooLong'] === null): ?>
      <?= $csvLink('lost', $clients['period']) ?>
<?php endif; ?>
    </h2>
<?php if ($clients['tooLong'] !== null): ?>
    <p class="muted panel-empty">Список считается для периода не дольше <?= (int) $clients['tooLong'] ?> дней. Выберите период короче.</p>
<?php else: ?>
<?= $this->render('_lost_table', ['rows' => $clients['lost'], 'names' => $names]) ?>
<?php endif; ?>
  </section>
  <section class="card panel">
    <h2 class="panel-title">Повторные звонки
      <span class="panel-sub">номера, звонившие 3 раза и больше</span>
<?php if ($clients['tooLong'] === null): ?>
      <?= $csvLink('repeat', $clients['period']) ?>
<?php endif; ?>
    </h2>
<?php if ($clients['tooLong'] !== null): ?>
    <p class="muted panel-empty">Список считается для периода не дольше <?= (int) $clients['tooLong'] ?> дней. Выберите период короче.</p>
<?php else: ?>
<?= $this->render('_repeat_table', ['rows' => $clients['repeat']]) ?>
<?php endif; ?>
  </section>
</div>

<div id="chart-tip" class="chart-tip" hidden></div>
<?= $this->render('_client_drawer') ?>

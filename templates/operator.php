<?php
use CallStats\Web\Charts;
use CallStats\Web\Delta;
use CallStats\Web\Format;
use CallStats\Web\View;

/** Operator card (?p=operator&ext=…). */
/** @var \CallStats\Report\Period $period */
/** @var string $ext */
/** @var string $name display name */
/** @var array|null $row OperatorStatsReport row of the period (null = no calls) */
/** @var array|null $prev the same for the previous period */
/** @var string $prevLabel */
/** @var array|null $days OperatorStatsReport::operatorByDay(), null for a one-day period */
/** @var array $hours OperatorStatsReport::operatorByHour() */
$q = $period->query();
$journal = '?' . http_build_query(array_merge(['p' => 'journal', 'ext' => $ext], $q));
$recordings = '?' . http_build_query(array_merge(['p' => 'recordings', 'ext' => $ext], $q));
$back = '?' . http_build_query(array_merge(['p' => 'stats'], $q));
$r = $row;
$dl = function (string $k, int $good) use ($r, $prev): string {
    return $r === null || $prev === null ? '' : Delta::html($k, $r[$k], $prev[$k] ?? null, $good);
};
$v = function (string $k) use ($r) {
    return $r === null ? null : $r[$k];
};
$approx = $r !== null && !empty($r['busy_approx']) && $r['busy_pct'] !== null;
$tiles = [
    ['Принято', (string) (int) $v('answered'), $dl('answered', 1), 'Входящие звонки, на которые оператор ответил'],
    ['Клиент потерян', (string) (int) $v('missed_lost'), $dl('missed_lost', -1), 'Телефон оператора звонил, но трубку не взял никто'],
    ['Ушло коллегам', (string) (int) $v('missed_other'), $dl('missed_other', 0), 'Оператор не ответил, звонок взял коллега'],
    ['% ответа', Format::pct($v('answer_pct')), $dl('answer_pct', 1), 'Принято / (принято + пропущено)'],
    ['Время разговоров', Format::duration((int) $v('talk_sec')), $dl('talk_sec', 0), 'Входящие и исходящие разговоры'],
    ['Средний разговор', Format::duration((int) $v('avg_talk_sec')), $dl('avg_talk_sec', 0), 'Средняя длительность принятого входящего'],
    ['Ср. время до ответа', Format::duration((int) $v('avg_ring_sec')), $dl('avg_ring_sec', -1), 'Сколько в среднем звонил телефон до ответа'],
    ['Занятость', ($approx ? '≈' : '') . Format::pct($v('busy_pct')), $dl('busy_pct', 0),
        $approx ? 'Доля времени в разговорах; приблизительно: по первому и последнему звонку дня' : 'Доля рабочего времени (в сети минус паузы) в разговорах'],
];
?>
<form method="get" action="" class="filters card compact">
  <input type="hidden" name="p" value="operator">
  <input type="hidden" name="ext" value="<?= View::e($ext) ?>">
<?= $this->render('_period_fields', ['period' => $period]) ?>
  <button type="submit" class="primary">Показать</button>
  <a class="reset-link" href="<?= View::e($back) ?>">← Вся статистика</a>
</form>

<section class="stats-block">
  <h2 class="section-title"><?= View::e($name) ?><?= $name !== $ext ? ' <span class="muted">' . View::e($ext) . '</span>' : '' ?>
    <span class="section-sub"><?= View::e($period->fromDate() === $period->toDate() ? date('d.m.Y', strtotime($period->from))
        : date('d.m.Y', strtotime($period->from)) . ' — ' . date('d.m.Y', strtotime($period->to))) ?> · сравнение с <?= View::e($prevLabel) ?></span>
    <span class="section-links"><a href="<?= View::e($journal) ?>">Журнал за период →</a> <a href="<?= View::e($recordings) ?>">Записи →</a></span>
  </h2>
<?php if ($r === null): ?>
  <p class="muted">За выбранный период звонков у оператора нет.</p>
<?php endif; ?>
  <div class="tiles kpi-tiles op-tiles">
<?php foreach ($tiles as list($label, $value, $delta, $tip)): ?>
    <div class="tile" title="<?= View::e($tip) ?>">
      <span class="tile-v"><?= View::e($value) ?></span>
      <span class="tile-l"><?= View::e($label) ?></span>
      <?= $delta ?>
    </div>
<?php endforeach; ?>
  </div>

  <div class="chart-grid">
    <div class="card panel">
      <h3 class="panel-title">По часам <span class="panel-sub">звонки на телефон оператора</span></h3>
<?= Charts::stackedBars($hours, ['answered' => ['Принято', 'var(--accent)'], 'missed' => ['Пропущено', 'var(--danger)']]) ?>
    </div>
<?php if ($days !== null): ?>
    <div class="card panel panel-wide">
      <h3 class="panel-title">По дням</h3>
<?php if (!$days): ?>
      <p class="muted">Звонков нет.</p>
<?php else: ?>
      <div class="table-wrap">
      <table class="sortable clients">
        <thead><tr>
          <th data-type="text">Дата</th><th data-type="num" class="num">Принято</th><th data-type="num" class="num">Пропущено</th>
          <th data-type="num" class="num">Исходящие</th><th data-type="num" class="num">Разговоры</th>
          <th data-type="num" class="num">Ср. разговор</th><th data-type="num" class="num">% ответа</th>
        </tr></thead>
        <tbody>
<?php foreach ($days as $day => $d): ?>
          <tr>
            <td data-sort="<?= View::e($day) ?>"><?= View::e(date('d.m.Y', strtotime($day))) ?></td>
            <td class="num"><?= (int) $d['answered'] ?></td>
            <td class="num"><?= (int) $d['missed'] ?></td>
            <td class="num"><?= (int) $d['out'] ?></td>
            <td class="num" data-sort="<?= (int) $d['talk_sec'] ?>"><?= View::e(Format::duration((int) $d['talk_sec'])) ?></td>
            <td class="num" data-sort="<?= (int) $d['avg_talk_sec'] ?>"><?= View::e(Format::duration((int) $d['avg_talk_sec'])) ?></td>
            <td class="num" data-sort="<?= $d['answer_pct'] === null ? -1 : $d['answer_pct'] ?>"><?= View::e(Format::pct($d['answer_pct'])) ?></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
      </div>
<?php endif; ?>
    </div>
<?php endif; ?>
  </div>
</section>
<div id="chart-tip" class="chart-tip" hidden></div>

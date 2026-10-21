<?php
use CallStats\Web\Charts;
use CallStats\Web\Format;
use CallStats\Web\View;

/** @var \CallStats\Report\Period $period */
/** @var array $d DashboardReport::fetch() */
$q = $period->query();
$link = function (string $page, array $extra = []) use ($q): string {
    return '?' . http_build_query(array_merge(['p' => $page], $extra, $q));
};
$dur = function ($v) {
    return Format::durationOrDash($v);
};
$num = function ($v) {
    return $v === null ? '—' : (string) $v;
};
$tiles = [
    'calls' => ['Входящих звонков', $num, 0],
    'answered' => ['Принято', $num, 1],
    'answer_pct' => ['% ответа', function ($v) { return Format::pct($v); }, 1],
    'avg_wait' => ['Среднее ожидание', $dur, -1],
    'avg_talk' => ['Средний разговор', $dur, 0],
];
// Change vs the previous period: percentage points for %, relative % otherwise.
$delta = function (string $k, $cur, $prev, int $goodDir): array {
    if ($cur === null || $prev === null || ($k !== 'answer_pct' && (float) $prev == 0.0)) {
        return ['', 'нет данных для сравнения', ''];
    }
    $diff = $k === 'answer_pct' ? round($cur - $prev, 1) : round(($cur - $prev) * 100 / $prev);
    if ($diff == 0) {
        return ['=', 'без изменений', ''];
    }
    $unit = $k === 'answer_pct' ? ' п.п.' : '%';
    $cls = $goodDir === 0 ? '' : (($diff > 0) === ($goodDir > 0) ? 'good' : 'bad');
    return [$diff > 0 ? '▲' : '▼', str_replace('.', ',', (string) abs($diff)) . $unit, $cls];
};
$outcomes = [
    ['label' => 'Приняты', 'value' => $d['outcomes']['answered'], 'color' => 'var(--series-1)'],
    ['label' => 'Звонило, не взяли', 'value' => $d['outcomes']['missed'], 'color' => 'var(--series-2)'],
    ['label' => 'Клиент не дождался', 'value' => $d['outcomes']['abandoned'], 'color' => 'var(--series-3)'],
];
$types = [
    ['label' => 'Через очередь', 'value' => $d['types']['queue'], 'color' => 'var(--series-1)'],
    ['label' => 'Прямые входящие', 'value' => $d['types']['direct'], 'color' => 'var(--series-2)'],
    ['label' => 'Исходящие', 'value' => $d['types']['outbound'], 'color' => 'var(--series-3)'],
];
$legend = function (array $parts): string {
    $total = array_sum(array_column($parts, 'value'));
    $h = '<ul class="legend">';
    foreach ($parts as $p) {
        $pct = $total > 0 ? round($p['value'] * 100 / $total) . '%' : '—';
        $h .= '<li><span class="sw" style="background:' . View::e($p['color']) . '"></span>'
            . '<span class="lg-l">' . View::e($p['label']) . '</span>'
            . '<span class="lg-v">' . (int) $p['value'] . '</span><span class="lg-p">' . $pct . '</span></li>';
    }
    return $h . '</ul>';
};
?>
<form method="get" class="filters card compact">
  <input type="hidden" name="p" value="dashboard">
<?= $this->render('_period_fields', ['period' => $period]) ?>
  <button type="submit" class="primary">Показать</button>
</form>
<p class="muted dash-note">
  Сравнение с периодом <?= View::e(date('d.m.Y', strtotime($d['prev_from']))) ?> — <?= View::e(date('d.m.Y', strtotime($d['prev_to']))) ?>.
  Страница обновляется сама раз в минуту.
</p>

<div class="dash" data-autorefresh="60">
  <section class="kpis">
<?php foreach ($tiles as $k => [$label, $fmt, $goodDir]):
    $t = $d['kpi'][$k];
    [$arrow, $text, $cls] = $delta($k, $t['value'], $t['prev'], $goodDir); ?>
    <div class="card kpi">
      <div class="kpi-l"><?= View::e($label) ?></div>
      <div class="kpi-v"><?= View::e($fmt($t['value'])) ?></div>
      <div class="kpi-d"><?php if ($arrow !== ''): ?><span class="arr <?= $cls ?>"><?= $arrow ?></span> <?php endif; ?><?= View::e($text) ?><?= $arrow !== '' && $arrow !== '=' ? ' к прошлому периоду' : '' ?></div>
      <?= Charts::sparkline($t['series'], $fmt) ?>
    </div>
<?php endforeach; ?>
  </section>

  <div class="dash-grid">
    <div class="dash-col">
      <section class="card">
        <h2>Входящие: чем закончились</h2>
        <div class="donut-box"><?= Charts::donut($outcomes, 'входящих') ?><?= $legend($outcomes) ?></div>
      </section>
      <section class="card">
        <h2>Типы звонков</h2>
        <div class="donut-box"><?= Charts::donut($types, 'звонков') ?><?= $legend($types) ?></div>
      </section>
    </div>

    <div class="dash-col">
      <section class="card">
        <h2>Операторы</h2>
<?php if (!$d['operators']): ?>
        <p class="muted">Нет звонков за период</p>
<?php else: ?>
        <table>
          <thead><tr><th>Оператор</th><th class="num">Принято</th><th class="num">Пропущено</th><th class="num">% ответа</th><th class="num">Ср. разговор</th></tr></thead>
          <tbody>
<?php foreach ($d['operators'] as $r): if ((int) $r['answered'] + (int) $r['missed'] === 0) { continue; } ?>
            <tr>
              <td><a href="<?= View::e($link('journal', ['ext' => (string) $r['extension']])) ?>"><?= View::e((string) $r['name']) ?></a></td>
              <td class="num"><?= (int) $r['answered'] ?></td>
              <td class="num"><?= (int) $r['missed'] ?></td>
              <td class="num"><?= View::e(Format::pct($r['answer_pct'])) ?></td>
              <td class="num"><?= View::e($dur($r['avg_talk_sec'])) ?></td>
            </tr>
<?php endforeach; ?>
          </tbody>
        </table>
<?php endif; ?>
      </section>
      <section class="card">
        <h2>Входящие по часам</h2>
        <?= Charts::hourBars($d['hours']) ?>
<?php if ($d['hours']): ?>
        <details class="tbl-view"><summary>Таблица</summary>
          <table>
            <thead><tr><th>Час</th><th class="num">Звонков</th><th class="num">Принято</th><th class="num">% ответа</th></tr></thead>
            <tbody>
<?php foreach ($d['hours'] as $h => $v): ?>
              <tr><td><?= sprintf('%02d:00–%02d:00', $h, $h + 1) ?></td><td class="num"><?= $v['calls'] ?></td><td class="num"><?= $v['answered'] ?></td>
                <td class="num"><?= View::e(Format::pct($v['calls'] > 0 ? round($v['answered'] * 100 / $v['calls'], 1) : null)) ?></td></tr>
<?php endforeach; ?>
            </tbody>
          </table>
        </details>
<?php endif; ?>
      </section>
    </div>

    <div class="dash-col">
      <section class="card">
        <h2>Частые клиенты</h2>
<?php if (!$d['clients']): ?>
        <p class="muted">Нет звонков за период</p>
<?php else: ?>
        <table>
          <thead><tr><th>Номер</th><th class="num">Звонил</th><th class="num">Потеряно</th><th>Последний</th></tr></thead>
          <tbody>
<?php foreach ($d['clients'] as $c): ?>
            <tr>
              <td><a href="<?= View::e($link('recordings', ['number' => $c['number']])) ?>"><?= View::e($c['number']) ?></a></td>
              <td class="num"><?= $c['calls'] ?></td>
              <td class="num<?= $c['lost'] > 0 ? ' lost' : '' ?>"><?= $c['lost'] ?></td>
              <td class="nowrap"><?= View::e(date('d.m H:i', strtotime($c['last_at']))) ?></td>
            </tr>
<?php endforeach; ?>
          </tbody>
        </table>
<?php endif; ?>
      </section>
    </div>
  </div>
</div>
<div id="chart-tip" class="chart-tip" hidden></div>

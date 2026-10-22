<?php
use CallStats\Report\JournalTable;
use CallStats\Report\OperatorJournal;
use CallStats\Web\Charts;
use CallStats\Web\Delta;
use CallStats\Web\Format;
use CallStats\Web\View;

/** @var \CallStats\Report\Period $period */
/** @var array $operators extension => display name */
/** @var string $ext selected extension, '*' = all operators, '' = none */
/** @var array|null $kpi operator row (or the total in all-operators mode) of OperatorStatsReport */
/** @var array|null $kpiPrev the same for the previous period */
/** @var array|null $timeline DayTimeline::build() result (one operator, one day) */
/** @var array $counts JournalTable::counts() of the searched rows */
/** @var int $allCount rows of the «Все» chip */
/** @var array $days JournalTable::days() of the filtered rows */
/** @var array $table JournalTable::apply() result */
/** @var array $t ['q','type','noise','sort','order','per'] */
$all = $ext === '*';
$base = array_merge(['p' => 'journal', 'ext' => $ext], $period->query(), ['type' => $t['type'], 'noise' => $t['noise']]);
$clean = function (array $q): array {
    return array_filter($q, function ($v) {
        return $v !== '' && $v !== null;
    });
};
$url = function (array $over = []) use ($base, $t, $table, $clean): string {
    return '?' . http_build_query($clean(array_merge($base, ['q' => $t['q'], 'sort' => $t['sort'], 'order' => $t['order'],
        'per' => $t['per'], 'page' => $table['page']], $over)));
};
$csvUrl = '?' . http_build_query($clean(array_merge($base, ['p' => 'journal_csv', 'q' => $t['q'], 'sort' => $t['sort'],
    'order' => $t['order']])));
$sortLink = function (string $col, string $label) use ($t, $url): string {
    return $this->render('_sort_link', ['url' => $url, 'sort' => $t['sort'], 'order' => $t['order'], 'col' => $col, 'label' => $label]);
};
$sourcesBlock = function (?string $json) {
    if ($json === null || $json === '') {
        return '';
    }
    $d = json_decode($json, true);
    $lines = [];
    if (is_array($d)) {
        foreach (['cdr' => 'CDR', 'qlog' => 'queue_log'] as $k => $label) {
            if (!empty($d[$k]) && is_array($d[$k])) {
                $lines[] = '[' . $label . ']';
                foreach ($d[$k] as $l) {
                    $lines[] = is_scalar($l) ? (string) $l : json_encode($l, JSON_UNESCAPED_UNICODE);
                }
            }
        }
    }
    if (!$lines) {
        $lines[] = $json;
    }
    return '<details class="src"><summary>Источник</summary><pre>' . View::e(implode("\n", $lines)) . '</pre></details>';
};
$name = function ($e) use ($operators): string {
    // a merged missed row lists several operators: "1001, 1002"
    return implode(', ', array_map(function (string $one) use ($operators): string {
        return $operators[$one] ?? $one;
    }, explode(', ', (string) $e)));
};
$arrows = ['in' => '←', 'out' => '→', 'internal' => '⇄'];
$arrowTitles = ['in' => 'входящий', 'out' => 'исходящий', 'internal' => 'внутренний'];
$chipLabels = [
    'answered' => 'Принятые', 'missed' => 'Пропущенные', 'out' => 'Исходящие', 'internal' => 'Внутренние',
    'other' => 'Взял другой / сбросили', 'offline' => 'Не в сети', 'event' => 'Входы и паузы',
];
$multiDay = $period->fromDate() !== $period->toDate();
$dayHeads = $multiDay && $t['sort'] === 'at';
$weekdays = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
$months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
$dayTitle = function (string $day) use ($weekdays, $months, $days): string {
    $ts = strtotime($day);
    $c = $days[$day] ?? ['answered' => 0, 'missed' => 0, 'out' => 0];
    return $weekdays[(int) date('w', $ts)] . ', ' . (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1]
        . ' · принято ' . $c['answered'] . ' · пропущено ' . $c['missed'] . ' · исходящих ' . $c['out'];
};
$cols = $all ? 6 : 5;
?>
<form method="get" action="" class="filters card compact">
  <input type="hidden" name="p" value="journal">
  <label>Оператор
    <select name="ext">
      <option value="">— выберите —</option>
      <option value="*"<?= $all ? ' selected' : '' ?>>Все операторы</option>
<?php foreach ($operators as $e => $n): ?>
      <option value="<?= View::e($e) ?>"<?= (string) $e === $ext ? ' selected' : '' ?>><?= View::e($e . ($n !== (string) $e ? ' — ' . $n : '')) ?></option>
<?php endforeach; ?>
    </select>
  </label>
<?= $this->render('_period_fields', ['period' => $period]) ?>
  <button type="submit" class="primary">Показать</button>
</form>

<div data-journal-page data-ext="<?= View::e($ext) ?>" hidden></div>
<?php if ($ext === ''): ?>
<p class="muted">Выберите оператора, чтобы увидеть историю его звонков.</p>
<?php elseif (!empty($tooLong)): ?>
<p class="muted">Период слишком длинный: для <?= $ext === '*' ? 'всех операторов' : 'одного оператора' ?> журнал показывается максимум за <?= (int) $tooLong ?> <?= \CallStats\Web\Charts::plural((int) $tooLong, 'день', 'дня', 'дней') ?>. Выберите период короче.</p>
<?php else: ?>
<?php if ($kpi): ?>
<div class="tiles kpi-tiles">
<?php foreach ([
    ['answered', 'Принято', (string) (int) $kpi['answered'], 1],
    ['missed', 'Пропущено', (string) (int) $kpi['missed'], -1],
    ['answer_pct', '% ответа', Format::pct($kpi['answer_pct']), 1],
    ['talk_sec', 'Время разговоров', Format::duration((int) $kpi['talk_sec']), 0],
    ['avg_talk_sec', 'Средний разговор', Format::duration((int) $kpi['avg_talk_sec']), 0],
    ['avg_ring_sec', 'Ср. время до ответа', Format::duration((int) $kpi['avg_ring_sec']), -1],
] as list($k, $label, $value, $good)): ?>
  <div class="tile">
    <span class="tile-v"><?= View::e($value) ?></span>
    <span class="tile-l"><?= View::e($label) ?></span>
    <?= $kpiPrev === null ? '' : Delta::html($k, $kpi[$k], $kpiPrev[$k] ?? null, $good) ?>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($timeline !== null): ?>
<section class="card day-card">
<?= Charts::dayStrip($timeline) ?>
</section>
<div id="chart-tip" class="chart-tip" hidden></div>
<?php endif; ?>

<section class="history card">
  <header class="history-head">
    <div>
      <div class="history-title"><?= View::e($all ? 'Все операторы' : $ext . ($name($ext) !== $ext ? ' — ' . $name($ext) : '')) ?></div>
      <div class="history-sub">история звонков · <?= View::e($period->fromDate()) ?> — <?= View::e($period->toDate()) ?></div>
    </div>
    <form method="get" action="" class="history-tools">
<?php foreach ($clean($base) as $k => $v): ?>
      <input type="hidden" name="<?= View::e($k) ?>" value="<?= View::e((string) $v) ?>">
<?php endforeach; ?>
      <input type="hidden" name="sort" value="<?= View::e($t['sort']) ?>">
      <input type="hidden" name="order" value="<?= View::e($t['order']) ?>">
      <input type="hidden" name="per" value="<?= (int) $t['per'] ?>">
      <input type="search" name="q" value="<?= View::e($t['q']) ?>" placeholder="Поиск по номеру" aria-label="Поиск">
      <button type="submit" class="tool" title="Найти" aria-label="Найти">⌕</button>
      <a class="tool" href="<?= View::e($url()) ?>" title="Обновить" aria-label="Обновить">⟳</a>
      <a class="tool tool-text" href="<?= View::e($csvUrl) ?>" title="Скачать CSV" aria-label="Скачать CSV">CSV</a>
    </form>
  </header>

  <nav class="chips" aria-label="Тип записей">
    <a class="chip<?= $t['type'] === '' ? ' on' : '' ?>" href="<?= View::e($url(['type' => '', 'page' => 1])) ?>"<?= $t['type'] === '' ? ' aria-current="true"' : '' ?>>Все <span class="chip-n"><?= (int) $allCount ?></span></a>
<?php foreach ($chipLabels as $cat => $label): ?>
<?php if (!empty($counts[$cat])): ?>
    <a class="chip<?= $t['type'] === $cat ? ' on' : '' ?>" href="<?= View::e($url(['type' => $cat, 'page' => 1])) ?>"<?= $t['type'] === $cat ? ' aria-current="true"' : '' ?>><?= View::e($label) ?> <span class="chip-n"><?= (int) $counts[$cat] ?></span></a>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($t['type'] === ''): ?>
    <a class="noise-toggle" href="<?= View::e($url(['noise' => $t['noise'] === '1' ? '' : '1', 'page' => 1])) ?>" role="switch" aria-checked="<?= $t['noise'] === '1' ? 'true' : 'false' ?>"><span class="box" aria-hidden="true"><?= $t['noise'] === '1' ? '✓' : '' ?></span>Показывать звонки, взятые коллегами, и «не в сети»</a>
<?php endif; ?>
  </nav>

<?php if ($table['total'] === 0): ?>
  <p class="muted history-empty"><?= $t['q'] !== '' || $t['type'] !== '' ? 'Ничего не найдено.' : 'За выбранный период звонков нет.' ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="calls">
    <thead>
      <tr>
        <th class="c-date"><?= $sortLink('at', 'Дата') ?></th>
<?php if ($all): ?>
        <th class="c-op">Оператор</th>
<?php endif; ?>
        <th>Описание</th>
        <th class="c-dur"><?= $sortLink('dur', 'Длительность') ?></th>
        <th class="c-play">Прослушать</th>
        <th class="c-ctl">Скачать</th>
      </tr>
    </thead>
    <tbody>
<?php $prevDay = null; foreach ($table['rows'] as $r): ?>
<?php if ($dayHeads && substr($r['at'], 0, 10) !== $prevDay): $prevDay = substr($r['at'], 0, 10); ?>
      <tr class="day-row"><td colspan="<?= $cols ?>"><?= View::e($dayTitle($prevDay)) ?></td></tr>
<?php endif; ?>
      <tr class="<?= $r['muted'] ? 'muted-row' : '' ?>">
        <td class="c-date"><?= View::e(Format::ruDateTime($r['at'])) ?></td>
<?php if ($all): ?>
        <td class="c-op"><?= View::e($r['ext'] === null ? '' : $name($r['ext'])) ?></td>
<?php endif; ?>
        <td class="c-desc">
<?php if ($r['dir'] !== 'event'): ?>
          <span class="dir" title="<?= View::e($arrowTitles[$r['dir']] . ($r['failed'] ? ', не состоялся' : '')) ?>"><?= $r['failed'] ? '<span class="fail">⊘</span>' : '' ?><?= $arrows[$r['dir']] ?></span>
<?php if ($r['dir'] !== 'internal' && OperatorJournal::normalizeNumber($r['number']) !== ''): ?>
          <button type="button" class="num js-client" data-number="<?= View::e($r['number']) ?>" title="История номера"><?= View::e($r['number']) ?></button>
<?php else: ?>
          <span class="num"><?= View::e($r['number'] !== '' ? $r['number'] : 'номер скрыт') ?></span>
<?php endif; ?>
<?php else: ?>
          <span class="ev-text"><?= View::e($r['text']) ?></span>
<?php endif; ?>
<?php if ($r['note'] !== ''): ?>
          <span class="note"><?= View::e($r['note']) ?></span>
<?php endif; ?>
<?php if ($r['approx']): ?>
          <span class="approx">приблизительно</span>
<?php endif; ?>
<?php if ($r['items']): ?>
          <details class="src"><summary>Звонки</summary><pre><?php foreach ($r['items'] as $sub): ?><?= View::e(substr($sub['at'], 11, 5) . ' ' . $sub['client'] . ', ' . $sub['source']) ?>
<?php endforeach; ?></pre></details>
<?php elseif ($r['call_id'] !== null && $r['sources'] !== null): ?>
          <?= $sourcesBlock($r['sources']) ?>
<?php endif; ?>
<?php if ($r['callback'] !== null): list($cbText, $cbCls) = Format::callback($r['callback'], $operators); ?>
          <div class="cb cb-<?= $cbCls ?>"><?= View::e($cbText) ?></div>
<?php endif; ?>
        </td>
        <td class="c-dur"><?= $r['duration'] === null ? '' : View::e(Format::humanDuration((int) $r['duration'])) ?></td>
        <td class="c-play">
<?php if ($r['rec_status'] === 'ok'): ?>
<?= $this->render('_row_player', ['id' => (int) $r['call_id']]) ?>
<?php elseif ($r['rec_status'] === 'empty'): ?>
          <span class="muted">запись пустая</span>
<?php elseif ($r['rec_status'] === 'missing'): ?>
          <span class="muted">файл не найден</span>
<?php endif; ?>
        </td>
        <td class="c-ctl">
<?php if ($r['rec_status'] === 'ok' || $r['rec_status'] === 'unsupported'): ?>
<?= $this->render('_dl_icon', ['id' => (int) $r['call_id']]) ?>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?= $this->render('_pager', ['url' => $url, 'res' => $table, 'per' => (int) $t['per'],
    'hidden' => $clean(array_merge($base, ['q' => $t['q'], 'sort' => $t['sort'], 'order' => $t['order']]))]) ?>
</section>

<div class="drawer-overlay" hidden></div>
<aside class="drawer" role="dialog" aria-modal="true" aria-label="История номера" tabindex="-1" hidden>
  <button type="button" class="drawer-close" aria-label="Закрыть" title="Закрыть (Esc)">✕</button>
  <div class="drawer-body"></div>
</aside>
<?php endif; ?>

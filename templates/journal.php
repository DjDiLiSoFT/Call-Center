<?php
use CallStats\Report\JournalTable;
use CallStats\Web\Format;
use CallStats\Web\View;

/** @var \CallStats\Report\Period $period */
/** @var array $operators extension => display name */
/** @var string $ext selected extension or '' */
/** @var array|null $summary operator row of OperatorStatsReport */
/** @var bool $hasTimeline */
/** @var array $table JournalTable::apply() result */
/** @var array $t ['q','sort','order','per'] */
$base = array_merge(['p' => 'journal', 'ext' => $ext], $period->query());
$url = function (array $over = []) use ($base, $t, $table): string {
    $q = array_merge($base, ['q' => $t['q'], 'sort' => $t['sort'], 'order' => $t['order'], 'per' => $t['per'],
        'page' => $table['page']], $over);
    $q = array_filter($q, function ($v) {
        return $v !== '' && $v !== null;
    });
    return '?' . http_build_query($q);
};
// Clicking a sortable header: same column flips the order, a new column starts descending.
$sortLink = function (string $col, string $label) use ($t, $url): string {
    $active = $t['sort'] === $col;
    $order = $active && $t['order'] === 'desc' ? 'asc' : 'desc';
    $arrow = $active ? ($t['order'] === 'desc' ? ' ▼' : ' ▲') : ' <span class="sort-off">⇅</span>';
    return '<a class="th-sort" href="' . View::e($url(['sort' => $col, 'order' => $order, 'page' => 1])) . '">'
        . View::e($label) . $arrow . '</a>';
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
$arrows = ['in' => '←', 'out' => '→', 'internal' => '⇄'];
$arrowTitles = ['in' => 'входящий', 'out' => 'исходящий', 'internal' => 'внутренний'];
?>
<form method="get" action="" class="filters card">
  <input type="hidden" name="p" value="journal">
  <label>Оператор
    <select name="ext">
      <option value="">— выберите —</option>
<?php foreach ($operators as $e => $name): ?>
      <option value="<?= View::e($e) ?>"<?= (string) $e === $ext ? ' selected' : '' ?>><?= View::e($e . ($name !== (string) $e ? ' — ' . $name : '')) ?></option>
<?php endforeach; ?>
    </select>
  </label>
<?= $this->render('_period_fields', ['period' => $period]) ?>
  <button type="submit" class="primary">Показать</button>
</form>

<?php if ($ext === ''): ?>
<p class="muted">Выберите оператора, чтобы увидеть историю его звонков.</p>
<?php else: ?>
<?php if ($summary): ?>
<div class="table-wrap">
<table>
  <thead>
    <tr>
<?= $this->render('_stats_head', ['tl' => $hasTimeline]) ?>    </tr>
  </thead>
  <tbody>
    <tr>
<?= $this->render('_stats_cells', ['r' => $summary, 'tl' => $hasTimeline, 'link' => null, 'pauseTitle' => '']) ?>    </tr>
  </tbody>
</table>
</div>
<?php endif; ?>

<section class="history card">
  <header class="history-head">
    <div>
      <div class="history-title"><?= View::e($ext . (($operators[$ext] ?? $ext) !== $ext ? ' — ' . $operators[$ext] : '')) ?></div>
      <div class="history-sub">история звонков · <?= View::e($period->fromDate()) ?> — <?= View::e($period->toDate()) ?></div>
    </div>
    <form method="get" action="" class="history-tools">
<?php foreach ($base as $k => $v): ?>
      <input type="hidden" name="<?= View::e($k) ?>" value="<?= View::e((string) $v) ?>">
<?php endforeach; ?>
      <input type="hidden" name="sort" value="<?= View::e($t['sort']) ?>">
      <input type="hidden" name="order" value="<?= View::e($t['order']) ?>">
      <input type="hidden" name="per" value="<?= (int) $t['per'] ?>">
      <input type="search" name="q" value="<?= View::e($t['q']) ?>" placeholder="Поиск по номеру" aria-label="Поиск">
      <button type="submit" class="tool" title="Найти" aria-label="Найти">⌕</button>
      <a class="tool" href="<?= View::e($url()) ?>" title="Обновить" aria-label="Обновить">⟳</a>
    </form>
  </header>

<?php if ($table['total'] === 0): ?>
  <p class="muted history-empty"><?= $t['q'] !== '' ? 'Ничего не найдено.' : 'За выбранный период звонков нет.' ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="calls">
    <thead>
      <tr>
        <th class="c-date"><?= $sortLink('at', 'Дата') ?></th>
        <th>Описание</th>
        <th class="c-dur"><?= $sortLink('dur', 'Длительность') ?></th>
        <th class="c-play">Прослушать</th>
        <th class="c-ctl">Скачать</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($table['rows'] as $r): ?>
      <tr class="<?= $r['muted'] ? 'muted-row' : '' ?>">
        <td class="c-date"><?= View::e(Format::ruDateTime($r['at'])) ?></td>
        <td class="c-desc">
<?php if ($r['dir'] !== 'event'): ?>
          <span class="dir" title="<?= View::e($arrowTitles[$r['dir']] . ($r['failed'] ? ', не состоялся' : '')) ?>"><?= $r['failed'] ? '<span class="fail">⊘</span>' : '' ?><?= $arrows[$r['dir']] ?></span>
          <span class="num"><?= View::e($r['number'] !== '' ? $r['number'] : 'номер скрыт') ?></span>
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
          <a class="dl-icon" href="?p=play&amp;id=<?= (int) $r['call_id'] ?>&amp;dl=1" title="Скачать запись" aria-label="Скачать запись">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 3v11m0 0l-4-4m4 4l4-4M5 17v3h14v-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </a>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

  <footer class="history-foot">
    <form method="get" action="" class="per-page">
<?php foreach ($base as $k => $v): ?>
      <input type="hidden" name="<?= View::e($k) ?>" value="<?= View::e((string) $v) ?>">
<?php endforeach; ?>
      <input type="hidden" name="q" value="<?= View::e($t['q']) ?>">
      <input type="hidden" name="sort" value="<?= View::e($t['sort']) ?>">
      <input type="hidden" name="order" value="<?= View::e($t['order']) ?>">
      Показано <?= (int) $table['from'] ?>–<?= (int) $table['to'] ?> из <?= (int) $table['total'] ?>, по
      <select name="per" data-autosubmit aria-label="Строк на странице">
<?php foreach (JournalTable::PER_PAGE as $n): ?>
        <option value="<?= $n ?>"<?= $n === (int) $t['per'] ? ' selected' : '' ?>><?= $n ?></option>
<?php endforeach; ?>
      </select>
      строк на странице
    </form>
<?php if ($table['pages'] > 1):
    $p = $table['page'];
    $last = $table['pages'];
    $show = array_unique(array_filter([1, $p - 2, $p - 1, $p, $p + 1, $p + 2, $last], function ($n) use ($last) {
        return $n >= 1 && $n <= $last;
    }));
    sort($show); ?>
    <nav class="pager" aria-label="Страницы">
      <a class="pg<?= $p === 1 ? ' off' : '' ?>" href="<?= View::e($url(['page' => max(1, $p - 1)])) ?>" aria-label="Предыдущая">‹</a>
<?php $prev = 0; foreach ($show as $n): ?>
<?php if ($n - $prev > 1): ?>      <span class="pg gap">…</span>
<?php endif; ?>
      <a class="pg<?= $n === $p ? ' on' : '' ?>" href="<?= View::e($url(['page' => $n])) ?>"<?= $n === $p ? ' aria-current="page"' : '' ?>><?= $n ?></a>
<?php $prev = $n; endforeach; ?>
      <a class="pg<?= $p === $last ? ' off' : '' ?>" href="<?= View::e($url(['page' => min($last, $p + 1)])) ?>" aria-label="Следующая">›</a>
    </nav>
<?php endif; ?>
  </footer>
</section>
<?php endif; ?>

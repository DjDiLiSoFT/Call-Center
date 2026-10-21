<?php
use CallStats\Web\Format;
use CallStats\Web\View;

/** @var \CallStats\Report\Period $period */
/** @var array $operators extension => display name */
/** @var array $queues queue => name */
/** @var array $f filters: ext, number, min, max, dir, queue (strings as typed) */
/** @var array[] $rows search rows + 'status' (ok|empty|missing|unsupported) + 'operator' */
/** @var array $res RecordingSearch::search() result (page, pages, from, to, total) */
/** @var array $t ['sort','order','per'] */
$filters = array_filter(['ext' => $f['ext'], 'min' => $f['min'], 'max' => $f['max'], 'dir' => $f['dir'], 'queue' => $f['queue']],
    function ($v) {
        return $v !== '';
    });
$base = array_merge(['p' => 'recordings'], $period->query(), $filters);
$url = function (array $over = []) use ($base, $f, $t, $res): string {
    $q = array_merge($base, ['number' => $f['number'], 'sort' => $t['sort'], 'order' => $t['order'], 'per' => $t['per'],
        'page' => $res['page']], $over);
    return '?' . http_build_query(array_filter($q, function ($v) {
        return $v !== '' && $v !== null;
    }));
};
$sortLink = function (string $col, string $label) use ($t, $url): string {
    return $this->render('_sort_link', ['url' => $url, 'sort' => $t['sort'], 'order' => $t['order'], 'col' => $col, 'label' => $label]);
};
$arrows = ['queue' => '←', 'direct' => '←', 'outbound' => '→', 'internal' => '⇄'];
$dirTitle = ['queue' => 'входящий', 'direct' => 'входящий', 'outbound' => 'исходящий', 'internal' => 'внутренний'];
?>
<form method="get" action="" class="filters card">
  <input type="hidden" name="p" value="recordings">
  <input type="hidden" name="number" value="<?= View::e($f['number']) ?>">
  <label>Оператор
    <select name="ext">
      <option value="">— все —</option>
<?php foreach ($operators as $e => $name): ?>
      <option value="<?= View::e($e) ?>"<?= (string) $e === $f['ext'] ? ' selected' : '' ?>><?= View::e($e . ($name !== (string) $e ? ' — ' . $name : '')) ?></option>
<?php endforeach; ?>
    </select>
  </label>
<?= $this->render('_period_fields', ['period' => $period]) ?>
  <label>Длительность от, мин
    <input type="number" name="min" min="0" step="1" value="<?= View::e($f['min']) ?>">
  </label>
  <label>до, мин
    <input type="number" name="max" min="0" step="1" value="<?= View::e($f['max']) ?>">
  </label>
  <label>Направление
    <select name="dir">
      <option value="">все</option>
      <option value="in"<?= $f['dir'] === 'in' ? ' selected' : '' ?>>входящие</option>
      <option value="out"<?= $f['dir'] === 'out' ? ' selected' : '' ?>>исходящие</option>
    </select>
  </label>
  <label>Очередь
    <select name="queue">
      <option value="">— все —</option>
<?php foreach ($queues as $q => $name): ?>
      <option value="<?= View::e($q) ?>"<?= (string) $q === $f['queue'] ? ' selected' : '' ?>><?= View::e($name !== '' ? $name : $q) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <button type="submit" class="primary">Показать</button>
</form>

<section class="history card">
  <header class="history-head">
    <div>
      <div class="history-title"><?= View::e($f['ext'] !== '' ? $f['ext'] . (($operators[$f['ext']] ?? $f['ext']) !== $f['ext'] ? ' — ' . $operators[$f['ext']] : '') : 'Все операторы') ?></div>
      <div class="history-sub">записи разговоров · <?= View::e($period->fromDate()) ?> — <?= View::e($period->toDate()) ?></div>
    </div>
    <form method="get" action="" class="history-tools">
<?php foreach ($base as $k => $v): ?>
      <input type="hidden" name="<?= View::e((string) $k) ?>" value="<?= View::e((string) $v) ?>">
<?php endforeach; ?>
      <input type="hidden" name="sort" value="<?= View::e($t['sort']) ?>">
      <input type="hidden" name="order" value="<?= View::e($t['order']) ?>">
      <input type="hidden" name="per" value="<?= (int) $t['per'] ?>">
      <input type="search" name="number" value="<?= View::e($f['number']) ?>" placeholder="Поиск по номеру" aria-label="Поиск по номеру">
      <button type="submit" class="tool" title="Найти" aria-label="Найти">⌕</button>
      <a class="tool" href="<?= View::e($url()) ?>" title="Обновить" aria-label="Обновить">⟳</a>
    </form>
  </header>

<?php if (!$rows): ?>
  <p class="muted history-empty">Записей по выбранным условиям нет.</p>
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
<?php foreach ($rows as $r):
    $kind = (string) $r['kind'];
    $qname = $r['queue'] === null ? '' : 'Очередь «' . ($queues[$r['queue']] ?? $r['queue']) . '»';
    $note = implode(' · ', array_filter([(string) $r['operator'], $qname]));
?>
      <tr>
        <td class="c-date"><?= View::e(Format::ruDateTime((string) $r['started_at'])) ?></td>
        <td class="c-desc">
          <span class="dir" title="<?= View::e($dirTitle[$kind] ?? $kind) ?>"><?= $arrows[$kind] ?? '' ?></span>
          <span class="num"><?= View::e($r['client_number'] !== '' ? (string) $r['client_number'] : 'номер скрыт') ?></span>
<?php if ($note !== ''): ?>
          <span class="note"><?= View::e($note) ?></span>
<?php endif; ?>
        </td>
        <td class="c-dur"><?= View::e(Format::humanDuration((int) $r['talk_sec'])) ?></td>
        <td class="c-play">
<?php if ($r['status'] === 'ok'): ?>
<?= $this->render('_row_player', ['id' => (int) $r['id']]) ?>
<?php elseif ($r['status'] === 'empty'): ?>
          <span class="muted">запись пустая</span>
<?php elseif ($r['status'] === 'missing'): ?>
          <span class="muted">файл не найден</span>
<?php else: ?>
          <span class="muted">формат не поддерживается</span>
<?php endif; ?>
        </td>
        <td class="c-ctl">
<?php if ($r['status'] === 'ok' || $r['status'] === 'unsupported'): ?>
          <a class="dl-icon" href="?p=play&amp;id=<?= (int) $r['id'] ?>&amp;dl=1" title="Скачать запись" aria-label="Скачать запись">
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
<?= $this->render('_pager', ['url' => $url, 'res' => $res, 'per' => (int) $t['per'],
    'hidden' => array_merge($base, ['number' => $f['number'], 'sort' => $t['sort'], 'order' => $t['order']])]) ?>
</section>

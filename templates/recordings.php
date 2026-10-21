<?php
use CallStats\Report\RecordingSearch;
use CallStats\Web\Format;
use CallStats\Web\View;

/** @var \CallStats\Report\Period $period */
/** @var array $operators extension => display name */
/** @var array $queues queue => name */
/** @var array $f filters: ext, number, min, max, dir, queue (strings as typed) */
/** @var array[] $rows search rows + 'status' (ok|empty|missing|unsupported) + 'operator' */
/** @var int $total */
/** @var int $page */
$pages = max(1, (int) ceil($total / RecordingSearch::PER_PAGE));
$dirLabel = ['queue' => 'входящий', 'direct' => 'входящий', 'outbound' => 'исходящий', 'internal' => 'внутренний'];
$base = array_merge(
    ['p' => 'recordings'],
    $period->query(),
    array_filter($f, function ($v) {
        return $v !== '';
    })
);
$pageUrl = function (int $n) use ($base) {
    return '?' . http_build_query(array_merge($base, ['page' => $n]));
};
?>
<form method="get" action="" class="filters card">
  <input type="hidden" name="p" value="recordings">
  <label>Оператор
    <select name="ext">
      <option value="">— все —</option>
<?php foreach ($operators as $e => $name): ?>
      <option value="<?= View::e($e) ?>"<?= (string) $e === $f['ext'] ? ' selected' : '' ?>><?= View::e($e . ($name !== (string) $e ? ' — ' . $name : '')) ?></option>
<?php endforeach; ?>
    </select>
  </label>
<?= $this->render('_period_fields', ['period' => $period]) ?>
  <label>Номер клиента
    <input type="text" name="number" value="<?= View::e($f['number']) ?>" placeholder="часть номера">
  </label>
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
  <button type="submit" class="primary">Найти</button>
</form>

<p class="muted">Период: <?= View::e($period->fromDate()) ?> — <?= View::e($period->toDate()) ?>. Найдено записей: <?= (int) $total ?></p>

<?php if (!$rows): ?>
<p class="muted">Записей по выбранным условиям нет.</p>
<?php else: ?>
<div class="table-wrap">
<table class="recordings">
  <thead>
    <tr>
      <th>Дата и время</th>
      <th>Направление</th>
      <th>Номер клиента</th>
      <th>Оператор</th>
      <th>Очередь</th>
      <th>Длительность</th>
      <th>Запись</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r):
    $id = (int) $r['id'];
    $when = substr($r['started_at'], 8, 2) . '.' . substr($r['started_at'], 5, 2) . '.' . substr($r['started_at'], 0, 4) . ' ' . substr($r['started_at'], 11, 5);
    $qname = $r['queue'] === null ? '' : ($queues[$r['queue']] ?? $r['queue']);
    $title = $when . ', ' . $r['client_number'] . ($r['operator'] !== '' ? ', ' . $r['operator'] : '');
?>
    <tr>
      <td><?= View::e($when) ?></td>
      <td><?= View::e($dirLabel[$r['kind']] ?? $r['kind']) ?></td>
      <td><?= View::e($r['client_number']) ?></td>
      <td><?= View::e($r['operator']) ?></td>
      <td><?= View::e($qname) ?></td>
      <td><?= View::e(Format::duration((int) $r['talk_sec'])) ?></td>
      <td class="rec">
<?php if ($r['status'] === 'ok'): ?>
        <a class="js-play play" href="?p=play&amp;id=<?= $id ?>" data-id="<?= $id ?>" data-title="<?= View::e($title) ?>" title="Слушать запись">▶</a>
        <a class="dl" href="?p=play&amp;id=<?= $id ?>&amp;dl=1">скачать</a>
<?php elseif ($r['status'] === 'empty'): ?>
        <span class="muted">запись пустая</span>
<?php elseif ($r['status'] === 'unsupported'): ?>
        <a class="dl" href="?p=play&amp;id=<?= $id ?>&amp;dl=1">скачать</a>
<?php else: ?>
        <span class="muted">файл не найден</span>
<?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Страницы">
<?php for ($n = 1; $n <= $pages; $n++): ?>
<?php if ($n === $page): ?>
  <span class="cur" aria-current="page"><?= $n ?></span>
<?php else: ?>
  <a href="<?= View::e($pageUrl($n)) ?>"><?= $n ?></a>
<?php endif; ?>
<?php endfor; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?= $this->render('_player') ?>

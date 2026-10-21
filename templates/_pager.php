<?php
use CallStats\Report\JournalTable;
use CallStats\Web\View;

/** Table footer: "Показано X–Y из N", rows-per-page select and page links. */
/** @var callable $url array $override => string */
/** @var array $res page, pages, from, to, total */
/** @var int $per current rows per page */
/** @var array $hidden name => value kept by the rows-per-page form (filters, sort) */
$p = (int) $res['page'];
$last = (int) $res['pages'];
?>
  <footer class="history-foot">
    <form method="get" action="" class="per-page">
<?php foreach ($hidden as $k => $v): ?>
      <input type="hidden" name="<?= View::e((string) $k) ?>" value="<?= View::e((string) $v) ?>">
<?php endforeach; ?>
      Показано <?= (int) $res['from'] ?>–<?= (int) $res['to'] ?> из <?= (int) $res['total'] ?>, по
      <select name="per" data-autosubmit aria-label="Строк на странице">
<?php foreach (JournalTable::PER_PAGE as $n): ?>
        <option value="<?= $n ?>"<?= $n === $per ? ' selected' : '' ?>><?= $n ?></option>
<?php endforeach; ?>
      </select>
      строк на странице
    </form>
<?php if ($last > 1):
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

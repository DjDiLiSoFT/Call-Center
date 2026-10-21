<?php
use CallStats\Web\View;

/** Sortable column header: same column flips the order, a new column starts descending. */
/** @var callable $url array $override => string */
/** @var string $sort current sort column */
/** @var string $order current order asc|desc */
/** @var string $col this column */
/** @var string $label */
$active = $sort === $col;
$next = $active && $order === 'desc' ? 'asc' : 'desc';
?>
<a class="th-sort" href="<?= View::e($url(['sort' => $col, 'order' => $next, 'page' => 1])) ?>"><?= View::e($label) ?><?= $active ? ($order === 'desc' ? ' ▼' : ' ▲') : ' <span class="sort-off">⇅</span>' ?></a>

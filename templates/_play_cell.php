<?php
use CallStats\Web\View;

/** @var int $id call id */
/** @var string $status ok|empty|missing|unsupported */
/** @var string $title player caption */
?>
<?php if ($status === 'ok'): ?>
<a class="js-play play" href="?p=play&amp;id=<?= (int) $id ?>" data-id="<?= (int) $id ?>" data-title="<?= View::e($title) ?>" title="Слушать запись">▶</a>
<a class="dl" href="?p=play&amp;id=<?= (int) $id ?>&amp;dl=1">скачать</a>
<?php elseif ($status === 'empty'): ?>
<span class="muted">запись пустая</span>
<?php elseif ($status === 'unsupported'): ?>
<a class="dl" href="?p=play&amp;id=<?= (int) $id ?>&amp;dl=1">скачать</a>
<?php else: ?>
<span class="muted">файл не найден</span>
<?php endif; ?>

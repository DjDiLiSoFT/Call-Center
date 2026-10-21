<?php
use CallStats\Web\View;

/** @var array[] $operators rows extension, fpbx_name, custom_name */
/** @var string $csrf */
/** @var bool $saved */
?>
<?php if (!empty($saved)): ?>
<p class="notice ok" role="status">Сохранено</p>
<?php endif; ?>
<p class="muted">Своё имя показывается в статистике и журнале вместо имени из FreePBX. Пустое поле — вернуть имя из FreePBX.</p>
<?php if (!$operators): ?>
<p class="muted">Операторов пока нет — они появятся после первого запуска сборщика.</p>
<?php else: ?>
<div class="table-wrap">
<table>
  <thead>
    <tr><th>Номер</th><th>Имя в FreePBX</th><th>Своё имя</th><th></th></tr>
  </thead>
  <tbody>
<?php foreach ($operators as $i => $o): ?>
    <tr>
      <td><?= View::e($o['extension']) ?></td>
      <td><?= View::e($o['fpbx_name']) ?></td>
      <td><input type="text" name="custom_name" maxlength="80" form="op<?= $i ?>" value="<?= View::e((string) $o['custom_name']) ?>" aria-label="Своё имя для <?= View::e($o['extension']) ?>"></td>
      <td>
        <form method="post" action="?p=operators" id="op<?= $i ?>" class="inline">
          <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
          <input type="hidden" name="ext" value="<?= View::e($o['extension']) ?>">
          <button type="submit">Сохранить</button>
        </form>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

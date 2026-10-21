<?php use CallStats\Web\View; ?>
<div class="card login">
  <h1>CallStats</h1>
  <p class="muted">Статистика колл-центра. Войдите, чтобы продолжить.</p>
<?php if ($error !== ''): ?>
  <p class="error" role="alert"><?= View::e($error) ?></p>
<?php endif; ?>
  <form method="post" action="?p=login">
    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
    <label>Логин
      <input type="text" name="login" value="<?= View::e($user) ?>" autocomplete="username" autofocus required>
    </label>
    <label>Пароль
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button type="submit" class="primary">Войти</button>
  </form>
</div>

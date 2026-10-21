<?php use CallStats\Web\View; ?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title) ?> — CallStats</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<?php if ($menu): ?>
<header class="topbar">
  <span class="brand">CallStats</span>
  <nav class="menu">
<?php foreach ($menu as $key => $label): ?>
    <a href="?p=<?= View::e($key) ?>"<?= $key === $active ? ' class="active" aria-current="page"' : '' ?>><?= View::e($label) ?></a>
<?php endforeach; ?>
    <form method="post" action="?p=logout" class="logout">
      <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
      <button type="submit">Выход</button>
    </form>
  </nav>
</header>
<?php endif; ?>
<main class="<?= $menu ? 'page' : 'page narrow' ?>">
<?php if ($heading): ?>
<h1><?= View::e($title) ?></h1>
<?php endif; ?>
<?= $content ?>
</main>
<script src="assets/app.js" defer></script>
</body>
</html>

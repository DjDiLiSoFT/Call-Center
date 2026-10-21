<?php
use CallStats\Report\Period;
use CallStats\Web\View;

/** @var \CallStats\Report\Period $period */
?>
  <label>Период
    <select name="preset" data-preset>
<?php foreach (Period::PRESETS as $key => $label): ?>
      <option value="<?= View::e($key) ?>"<?= $key === $period->preset ? ' selected' : '' ?>><?= View::e($label) ?></option>
<?php endforeach; ?>
    </select>
  </label>
  <label>С
    <input type="date" name="from" value="<?= View::e($period->fromDate()) ?>" data-custom>
  </label>
  <label>По
    <input type="date" name="to" value="<?= View::e($period->toDate()) ?>" data-custom>
  </label>

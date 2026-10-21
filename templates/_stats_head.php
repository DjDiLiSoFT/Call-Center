<?php
/** @var bool $tl show session/pause columns */
?>
      <th data-type="text">Оператор</th>
      <th data-type="num" class="num">Принято</th>
      <th data-type="num" class="num">Пропущено</th>
      <th data-type="num" class="num">% ответа</th>
      <th data-type="num" class="num">Не в сети</th>
      <th data-type="num" class="num">Исходящие<br><span class="sub">состоялось / всего</span></th>
      <th data-type="num" class="num">Время разговоров</th>
      <th data-type="num" class="num">Средний разговор</th>
      <th data-type="num" class="num">Ср. время до ответа</th>
<?php if ($tl): ?>
      <th data-type="num" class="num">Время в системе</th>
      <th data-type="num" class="num">На паузе</th>
<?php endif; ?>

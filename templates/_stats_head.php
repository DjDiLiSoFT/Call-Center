<?php
/** @var bool $tl show session/pause columns */
/** @var bool $rank show the rank column (stats page), default false */
$rank = $rank ?? false;
?>
<?php if ($rank): ?>
      <th data-type="num" class="num" title="Место по числу принятых звонков (при равенстве — меньше пропущенных)">№</th>
<?php endif; ?>
      <th data-type="text">Оператор</th>
      <th data-type="num" class="num" title="Входящие звонки, на которые оператор ответил">Принято</th>
      <th data-type="num" class="num" title="Телефон оператора звонил, но звонок в итоге никто не взял. Если взял коллега — это не пропуск">Пропущено</th>
      <th data-type="num" class="num" title="Принято / (принято + пропущено). Зелёный — от 90%, жёлтый — от 80%, красный — ниже">% ответа</th>
      <th data-type="num" class="num" title="Сколько звонков пришло, когда телефон оператора был выключен или не зарегистрирован. Не пропуск">Не в сети</th>
      <th data-type="num" class="num" title="Исходящие звонки оператора: сколько состоялось из всех">Исходящие<br><span class="sub">состоялось / всего</span></th>
      <th data-type="num" class="num" title="Сумма длительности входящих и исходящих разговоров">Время разговоров</th>
      <th data-type="num" class="num" title="Средняя длительность принятого входящего разговора">Средний разговор</th>
      <th data-type="num" class="num" title="Сколько в среднем звонил телефон, прежде чем оператор взял трубку">Ср. время до ответа</th>
<?php if ($tl): ?>
      <th data-type="num" class="num" title="Время между входом в очередь и выходом из неё">Время в системе</th>
      <th data-type="num" class="num" title="Сумма пауз; разбивка по причинам — при наведении на ячейку">На паузе</th>
<?php endif; ?>

<?php
use CallStats\Web\Delta;
use CallStats\Web\Format;
use CallStats\Web\View;

/** Client-side summary tiles (QueueSummaryReport::fetch()) with ▲▼ against the previous period. */
/** @var array $s current period */
/** @var array|null $prev previous period */
/** @var bool $callbacks false when callback data was not loaded (period too long) */
$dl = function (string $k, int $good) use ($s, $prev): string {
    return $prev === null ? '' : Delta::html($k, $s[$k], $prev[$k] ?? null, $good);
};
$judged = (int) ($s['called_back_base'] ?? 0);
$early = count($s['lost_calls'] ?? []) - $judged;
$tiles = [
    ['Поступило', (string) $s['total'], $dl('total', 0), '', 'Все входящие звонки клиентов за период: через очередь и напрямую на операторов'],
    ['Обслужено', (string) $s['answered'], $dl('answered', 1), '', 'Звонки, на которые ответил оператор'],
    ['Потеряно', (string) $s['lost'], $dl('lost', -1), 'из них сбросили сразу ' . $s['lost_short'],
        'Звонки, на которые никто не ответил; «сбросили сразу» — клиент положил трубку раньше чем через 5 секунд'],
    ['% потерянных', Format::pct($s['lost_pct']), $dl('lost_pct', -1), '', 'Доля потерянных звонков среди всех поступивших'],
    ['Ответ за 10 сек', Format::pct($s['sla_pct']), $dl('sla_pct', 1), '', 'Доля всех поступивших звонков, на которые ответили не позже чем через 10 секунд'],
    ['Ожидание ср. / макс.', Format::clock($s['avg_wait']) . ' / ' . Format::clock($s['max_wait']), $dl('avg_wait', -1), '',
        'Сколько клиент ждал ответа: в среднем по принятым звонкам и самое долгое ожидание среди всех'],
    $callbacks
        ? ['Перезвонили потерянным', $s['called_back'] . ' из ' . $judged, $dl('called_back_pct', 1),
            ($judged > 0 ? Format::pct($s['called_back_pct']) : '') . ($early > 0 ? ($judged > 0 ? ' · ' : '') . 'ещё рано: ' . $early : ''),
            'Потерянные звонки с номером, по которым оператор перезвонил или клиент сам дозвонился в течение суток. '
            . 'Считаются звонки старше суток: за последние 24 ч ещё рано судить, поэтому сравнение с прошлым периодом честное']
        : ['Перезвонили потерянным', '—', '', 'период длиннее 92 дней', 'Для таких длинных периодов перезвоны не считаются'],
];
?>
<div class="tiles kpi-tiles queue-tiles">
<?php foreach ($tiles as list($label, $value, $delta, $sub, $tip)): ?>
  <div class="tile" title="<?= View::e($tip) ?>">
    <span class="tile-v"><?= View::e($value) ?></span>
    <span class="tile-l"><?= View::e($label) ?></span>
<?php if ($sub !== ''): ?>
    <span class="tile-s"><?= View::e($sub) ?></span>
<?php endif; ?>
    <?= $delta ?>
  </div>
<?php endforeach; ?>
</div>

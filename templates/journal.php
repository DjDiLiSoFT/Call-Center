<?php
use CallStats\Report\Period;
use CallStats\Web\Format;
use CallStats\Web\View;

/** @var \CallStats\Report\Period $period */
/** @var array $operators extension => display name */
/** @var string $ext selected extension or '' */
/** @var array|null $summary operator row of OperatorStatsReport */
/** @var bool $hasTimeline */
/** @var array[] $items journal items (offline runs already collapsed) */
$dur = function ($sec) {
    return $sec === null ? '—' : Format::duration((int) $sec);
};
$hm = function (string $at) {
    return substr($at, 11, 5);
};
$ruDate = function (string $day) {
    return substr($day, 8, 2) . '.' . substr($day, 5, 2) . '.' . substr($day, 0, 4);
};
$minutes = function (?string $from, ?string $to) {
    if ($from === null || $to === null) {
        return '';
    }
    $sec = max(0, strtotime($to) - strtotime($from));
    return $sec < 60 ? $sec . ' с' : (int) round($sec / 60) . ' мин';
};
$pct = function ($v) {
    return $v === null ? '—' : rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',') . '%';
};
$sourcesBlock = function (?string $json) {
    if ($json === null || $json === '') {
        return '';
    }
    $d = json_decode($json, true);
    $lines = [];
    if (is_array($d)) {
        foreach (['cdr' => 'CDR', 'qlog' => 'queue_log'] as $k => $label) {
            if (!empty($d[$k]) && is_array($d[$k])) {
                $lines[] = '[' . $label . ']';
                foreach ($d[$k] as $l) {
                    $lines[] = is_scalar($l) ? (string) $l : json_encode($l, JSON_UNESCAPED_UNICODE);
                }
            }
        }
    }
    if (!$lines) {
        $lines[] = $json;
    }
    return '<details class="src"><summary>Источник</summary><pre>' . View::e(implode("\n", $lines)) . '</pre></details>';
};
$playLink = function (array $it) {
    if (empty($it['has_recording']) || $it['call_id'] === null) {
        return '';
    }
    $id = (int) $it['call_id'];
    return ' <a class="js-play play" href="?p=play&amp;id=' . $id . '" data-id="' . $id . '" title="Слушать запись">▶</a>';
};
$query = function (string $e) use ($period) {
    return http_build_query(array_merge(['p' => 'journal', 'ext' => $e], $period->query()));
};
?>
<form method="get" action="" class="filters card">
  <input type="hidden" name="p" value="journal">
  <label>Оператор
    <select name="ext">
      <option value="">— выберите —</option>
<?php foreach ($operators as $e => $name): ?>
      <option value="<?= View::e($e) ?>"<?= (string) $e === $ext ? ' selected' : '' ?>><?= View::e($e . ($name !== (string) $e ? ' — ' . $name : '')) ?></option>
<?php endforeach; ?>
    </select>
  </label>
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
  <button type="submit" class="primary">Показать</button>
</form>

<?php if ($ext === ''): ?>
<p class="muted">Выберите оператора, чтобы увидеть его журнал.</p>
<?php else: ?>
<p class="muted">Период: <?= View::e($period->fromDate()) ?> — <?= View::e($period->toDate()) ?></p>
<?php if ($summary): ?>
<div class="table-wrap">
<table>
  <thead>
    <tr>
      <th>Оператор</th>
      <th class="num">Принято</th>
      <th class="num">Пропущено</th>
      <th class="num">% ответа</th>
      <th class="num">Не в сети</th>
      <th class="num">Исходящие<br><span class="sub">состоялось / всего</span></th>
      <th class="num">Время разговоров</th>
      <th class="num">Средний разговор</th>
      <th class="num">Ср. время до ответа</th>
<?php if ($hasTimeline): ?>
      <th class="num">Время в системе</th>
      <th class="num">На паузе</th>
<?php endif; ?>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><?= View::e($summary['name']) ?><?= $summary['name'] !== (string) $summary['extension'] ? ' <span class="muted">' . View::e($summary['extension']) . '</span>' : '' ?></td>
      <td class="num"><?= (int) $summary['answered'] ?></td>
      <td class="num"><?= (int) $summary['missed'] ?></td>
      <td class="num"><?= View::e($pct($summary['answer_pct'])) ?></td>
      <td class="num"><?= (int) $summary['offline'] ?></td>
      <td class="num"><?= (int) $summary['out_connected'] ?> / <?= (int) $summary['out_total'] ?></td>
      <td class="num"><?= View::e($dur($summary['talk_sec'])) ?></td>
      <td class="num"><?= View::e($dur($summary['avg_talk_sec'])) ?></td>
      <td class="num"><?= View::e($dur($summary['avg_ring_sec'])) ?></td>
<?php if ($hasTimeline): ?>
      <td class="num"><?= View::e($dur($summary['session_sec'])) ?></td>
      <td class="num"><?= View::e($dur($summary['pause_sec'])) ?></td>
<?php endif; ?>
    </tr>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if (!$items): ?>
<p class="muted">За выбранный период событий нет.</p>
<?php else: ?>
<div class="journal">
<?php $day = null; foreach ($items as $it): ?>
<?php if (substr($it['at'], 0, 10) !== $day): $day = substr($it['at'], 0, 10); ?>
  <h2 class="day"><?= View::e($ruDate($day)) ?></h2>
<?php endif; ?>
<?php
$t = $it['type'];
$grey = in_array($t, ['in_other', 'in_offline', 'offline_group'], true);
$cls = 'ev ev-' . str_replace('_', '-', $t) . ($grey ? ' grey' : '');
$client = $it['client'] ?? '';
?>
  <div class="<?= View::e($cls) ?>">
    <span class="time"><?= View::e($hm($it['at'])) ?></span>
    <span class="body">
<?php switch ($t):
    case 'session_start': ?>
      Вход в очередь «<?= View::e($it['source']) ?>»
<?php break; case 'session_end': ?>
      Выход из очереди «<?= View::e($it['source']) ?>»
<?php break; case 'pause': ?>
      Пауза<?= $it['reason'] !== '' ? ' «' . View::e($it['reason']) . '»' : '' ?>
      <?= $it['until'] !== null ? '(' . View::e($hm($it['at'])) . '–' . View::e($hm($it['until'])) . ', ' . View::e($minutes($it['at'], $it['until'])) . ')' : '(не завершена)' ?>
      <span class="muted">очередь «<?= View::e($it['source']) ?>»</span>
<?php break; case 'in_answered': ?>
      <strong>Принят</strong> <?= View::e($client) ?>, <?= View::e($it['source']) ?>, ждал <?= (int) $it['ring_sec'] ?> с, говорил <?= View::e($dur($it['talk_sec'])) ?><?= $playLink($it) ?>
<?php break; case 'in_missed': ?>
      <strong class="bad">Пропущен</strong> <?= View::e($client) ?>, <?= View::e($it['source']) ?>, звонило <?= (int) $it['ring_sec'] ?> с
<?php break; case 'in_other': ?>
      Ответил другой оператор: <?= View::e($client) ?>, <?= View::e($it['source']) ?>, звонило <?= (int) $it['ring_sec'] ?> с
<?php break; case 'in_caller_hangup': ?>
      Клиент положил трубку: <?= View::e($client) ?>, <?= View::e($it['source']) ?>, звонило <?= (int) $it['ring_sec'] ?> с
<?php break; case 'in_offline': ?>
      Телефон не в сети: <?= View::e($client) ?>, <?= View::e($it['source']) ?>
<?php break; case 'offline_group': ?>
      Телефон не в сети: <?= (int) $it['count'] ?> звонков, <?= View::e($hm($it['at'])) ?>–<?= View::e($hm($it['until'])) ?>
<?php break; case 'out': ?>
      <?php if ($it['reason'] === 'answered'): ?><strong>Исходящий</strong> <?= View::e($client) ?>, состоялся, говорил <?= View::e($dur($it['talk_sec'])) ?><?= $playLink($it) ?>
      <?php else: ?><strong>Исходящий</strong> <?= View::e($client) ?>, не состоялся<?= (int) $it['ring_sec'] > 0 ? ', звонил ' . (int) $it['ring_sec'] . ' с' : '' ?><?php endif; ?>

<?php break; case 'internal': ?>
      Внутренний звонок <?= $it['reason'] === 'out' ? 'на' : 'от' ?> <?= View::e($client) ?><?= (int) $it['talk_sec'] > 0 ? ', говорил ' . View::e($dur($it['talk_sec'])) : '' ?><?= $playLink($it) ?>
<?php break; case 'transfer_out': ?>
      Перевёл звонок <?= View::e($client) ?> на <?= View::e($it['reason']) ?>
<?php break; case 'transfer_in': ?>
      Получил перевод от <?= View::e($it['reason']) ?>: <?= View::e($client) ?>
<?php break; endswitch; ?>
<?php if (!empty($it['approx'])): ?>
      <span class="approx">приблизительно</span>
<?php endif; ?>
<?php if ($t === 'offline_group'): ?>
      <details class="src"><summary>Звонки</summary><pre><?php foreach ($it['items'] as $sub): ?><?= View::e($hm($sub['at']) . ' ' . $sub['client'] . ', ' . $sub['source']) ?>
<?php endforeach; ?></pre></details>
<?php elseif (!empty($it['call_id']) && $it['sources'] !== null): ?>
      <?= $sourcesBlock($it['sources']) ?>
<?php endif; ?>
    </span>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

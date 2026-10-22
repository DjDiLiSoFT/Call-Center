<?php
use CallStats\Web\Delta;
use CallStats\Web\Format;
use CallStats\Web\View;

/** @var array $r operator row of OperatorStatsReport (+ StatsInsights fields on the stats page), or the total */
/** @var string|null $link operator card URL for the name, null = plain text */
/** @var string $pauseTitle tooltip for the pause cell ('' = none) */
/** @var bool $rank show the rank cell, default false */
/** @var bool $total render the «Итого» row (th cells, no deltas), default false */
$rank = $rank ?? false;
$total = $total ?? false;
$c = $total ? 'th' : 'td';
$prev = $r['prev'] ?? null;
$d = function ($sec) {
    return View::e(Format::durationOrDash($sec));
};
// ▲/▼ vs the previous period: $good = +1 (more is better), -1 (less is better), 0 (neutral).
$delta = function (string $k, int $good) use ($r, $prev): string {
    return $prev === null ? '' : Delta::html($k, $r[$k], $prev[$k] ?? null, $good);
};
$level = $total ? null : ($r['pct_level'] ?? null);
$approxTip = ($total ? 'сумма по операторам; у части из них ' : '') . 'приблизительно: по первому и последнему звонку дня';
$approx = !empty($r['busy_approx']);
// the basis of busy % / calls per hour: session, or the active span (≈); for Итого the sum of the rows' bases
$online = (int) ($r['online_sec'] ?? 0) > 0 ? (int) $r['online_sec'] : null;
$num = function (?float $v): string {
    return $v === null ? '—' : str_replace('.', ',', (string) $v);
};
?>
<?php if ($rank): ?>
      <td class="num rank" data-sort="<?= (int) $r['rank'] ?>"><?= (int) $r['rank'] === 1 ? '<span class="top" title="Лучший за период">★</span>' : (int) $r['rank'] ?></td>
<?php endif; ?>
<?php if ($total): ?>
      <th>Итого</th>
<?php else: ?>
      <td data-sort="<?= View::e(mb_strtolower($r['name'])) ?>"><?php if ($link !== null): ?><a href="<?= View::e($link) ?>" title="Карточка оператора"><?= View::e($r['name']) ?></a><?php else: ?><?= View::e($r['name']) ?><?php endif; ?><?= $r['name'] !== (string) $r['extension'] ? ' <span class="muted">' . View::e($r['extension']) . '</span>' : '' ?></td>
<?php endif; ?>
      <<?= $c ?> class="num" data-sort="<?= (int) $r['answered'] ?>"><?= (int) $r['answered'] ?><?= $delta('answered', 1) ?>
        <span class="sub2" title="Исходящие: состоялось <?= (int) $r['out_connected'] ?> из <?= (int) $r['out_total'] ?>">исх. <?= (int) $r['out_connected'] ?> / <?= (int) $r['out_total'] ?></span></<?= $c ?>>
      <<?= $c ?> class="num<?= !empty($r['missed_high']) ? ' missed-high' : '' ?>" data-sort="<?= (int) $r['missed_lost'] ?>"<?= !empty($r['missed_high']) ? ' title="Потерянных клиентов заметно больше, чем в среднем у операторов"' : '' ?>><span class="<?= $r['missed_lost'] > 0 ? 'miss-lost' : '' ?>"><?= (int) $r['missed_lost'] ?></span><?= $delta('missed_lost', -1) ?>
<?php if ($r['missed_other'] > 0): ?>
        <span class="sub2" title="Оператор не ответил, но звонок взял коллега — клиент обслужен">ушло коллегам <?= (int) $r['missed_other'] ?></span>
<?php endif; ?>
      </<?= $c ?>>
      <<?= $c ?> class="num pct" data-sort="<?= $r['answer_pct'] === null ? -1 : number_format($r['answer_pct'], 1, '.', '') ?>">
        <?= View::e(Format::pct($r['answer_pct'])) ?><?= $delta('answer_pct', 1) ?>
<?php if ($level !== null): ?>
        <span class="pct-bar lvl-<?= $level ?>" aria-hidden="true"><span style="width:<?= max(0, min(100, (float) $r['answer_pct'])) ?>%"></span></span>
<?php endif; ?>
      </<?= $c ?>>
      <<?= $c ?> class="num" data-sort="<?= (int) $r['offline'] ?>"><?= (int) $r['offline'] ?></<?= $c ?>>
      <<?= $c ?> class="num" data-sort="<?= (int) $r['talk_sec'] ?>"><?= $d($r['talk_sec']) ?><?= $delta('talk_sec', 0) ?></<?= $c ?>>
      <<?= $c ?> class="num" data-sort="<?= (int) $r['avg_talk_sec'] ?>"><?= $d($r['avg_talk_sec']) ?>
        <span class="sub2" title="Сколько в среднем звонил телефон, прежде чем оператор взял трубку">до ответа <?= View::e(Format::clock($r['avg_ring_sec'])) ?></span><?= $delta('avg_ring_sec', -1) ?></<?= $c ?>>
      <<?= $c ?> class="num" data-sort="<?= (int) $online ?>"<?= $approx && $online !== null ? ' title="' . View::e($approxTip) . '"' : '' ?>><?= $approx && $online !== null ? '≈' : '' ?><?= $d($online) ?></<?= $c ?>>
      <<?= $c ?> class="num" data-sort="<?= (int) $r['pause_sec'] ?>"<?= $pauseTitle !== '' ? ' title="' . View::e($pauseTitle) . '"' : '' ?>><?= $d($r['pause_sec']) ?></<?= $c ?>>
      <<?= $c ?> class="num" data-sort="<?= $r['busy_pct'] === null ? -1 : $r['busy_pct'] ?>"<?= $approx && $r['busy_pct'] !== null ? ' title="' . View::e($approxTip) . '"' : '' ?>><?= $approx && $r['busy_pct'] !== null ? '≈' : '' ?><?= View::e(Format::pct($r['busy_pct'])) ?><?= $delta('busy_pct', 0) ?></<?= $c ?>>
      <<?= $c ?> class="num" data-sort="<?= $r['calls_per_hour'] === null ? -1 : $r['calls_per_hour'] ?>"<?= $approx && $r['calls_per_hour'] !== null ? ' title="' . View::e($approxTip) . '"' : '' ?>><?= $approx && $r['calls_per_hour'] !== null ? '≈' : '' ?><?= View::e($num($r['calls_per_hour'])) ?></<?= $c ?>>

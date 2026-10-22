<?php
use CallStats\Web\Charts;
use CallStats\Web\Format;
use CallStats\Web\View;

/** Fragment for the journal drawer (?p=client): calls with one client number, newest first. */
/** @var string $number as requested */
/** @var array[] $calls OperatorJournal::clientHistory() items with rec_status */
/** @var array $names extension => display name */
$who = function ($ext) use ($names): string {
    $ext = (string) $ext;
    return $names[$ext] ?? $ext;
};
$n = count($calls);
?>
<h2 class="drawer-title" id="drawer-title">История номера <?= View::e($number) ?></h2>
<p class="drawer-sub muted"><?= $n === 0 ? 'Звонков с этим номером нет.' : View::e($n . ' ' . Charts::plural($n, 'звонок', 'звонка', 'звонков') . ($n >= 100 ? ' (последние)' : '')) ?></p>
<?php if ($n > 0): ?>
<ul class="client-calls">
<?php foreach ($calls as $c):
    if ($c['result'] === 'answered') {
        $res = 'принял ' . ($c['ext'] !== null ? $who($c['ext']) : '');
    } elseif ($c['result'] === 'missed') {
        $res = 'пропущен';
    } elseif ($c['result'] === 'out_answered') {
        $res = 'исходящий' . ($c['ext'] !== null ? ', ' . $who($c['ext']) : '');
    } else {
        $res = 'исходящий, не состоялся' . ($c['ext'] !== null ? ' (' . $who($c['ext']) . ')' : '');
    }
    $failed = $c['result'] === 'missed' || $c['result'] === 'out_failed';
    $rec = $c['rec_status'] ?? '';
?>
  <li class="cc<?= $failed ? ' cc-failed' : '' ?>">
    <div class="cc-line">
      <span class="dir" title="<?= $c['dir'] === 'out' ? 'исходящий' : 'входящий' ?>"><?= $failed ? '<span class="fail">⊘</span>' : '' ?><?= $c['dir'] === 'out' ? '→' : '←' ?></span>
      <span class="cc-date"><?= View::e(Format::ruDateTime($c['at'])) ?></span>
      <span class="cc-res"><?= View::e($res) ?></span>
<?php if ($c['talk_sec'] > 0): ?>
      <span class="cc-dur"><?= View::e(Format::humanDuration((int) $c['talk_sec'])) ?></span>
<?php endif; ?>
    </div>
<?php if ($rec === 'ok' || $rec === 'unsupported'): ?>
    <div class="cc-rec">
<?php if ($rec === 'ok'): ?>
<?= $this->render('_row_player', ['id' => (int) $c['call_id']]) ?>
<?php endif; ?>
<?= $this->render('_dl_icon', ['id' => (int) $c['call_id']]) ?>
    </div>
<?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

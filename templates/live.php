<?php use CallStats\Web\View; ?>
<div id="live" class="live" data-refresh="<?= (int) $refreshSec ?>">
  <div class="live-status">
    <span id="live-time" class="muted">Загрузка…</span>
    <button type="button" id="live-fullscreen" class="live-fs">На весь экран</button>
  </div>
  <div id="live-error" class="error" hidden></div>

  <section class="tiles" aria-label="Сейчас">
    <div class="tile tile-wait"><span class="tile-v" data-k="waiting">—</span><span class="tile-l">ждут в очереди</span></div>
    <div class="tile tile-wait"><span class="tile-v" data-k="longest_wait" data-fmt="dur">—</span><span class="tile-l">дольше всех ждёт</span></div>
    <div class="tile tile-free"><span class="tile-v" data-k="free">—</span><span class="tile-l">свободны</span></div>
    <div class="tile tile-talk"><span class="tile-v" data-k="talking">—</span><span class="tile-l">разговаривают</span></div>
    <div class="tile tile-pause"><span class="tile-v" data-k="paused">—</span><span class="tile-l">на паузе</span></div>
    <div class="tile tile-off"><span class="tile-v" data-k="offline">—</span><span class="tile-l">не в сети</span></div>
  </section>

  <h2 class="live-h">Сегодня</h2>
  <section class="tiles tiles-today" aria-label="Сегодня">
    <div class="tile"><span class="tile-v" data-t="inbound">—</span><span class="tile-l">поступило</span></div>
    <div class="tile"><span class="tile-v" data-t="answered">—</span><span class="tile-l">отвечено</span></div>
    <div class="tile"><span class="tile-v" data-t="lost">—</span><span class="tile-l">потеряно</span></div>
    <div class="tile"><span class="tile-v" data-t="answer_pct" data-fmt="pct">—</span><span class="tile-l">% ответа</span></div>
    <div class="tile"><span class="tile-v" data-t="sl_pct" data-fmt="pct">—</span><span class="tile-l">отвечено за <?= (int) $slSec ?> с</span></div>
    <div class="tile"><span class="tile-v" data-t="avg_wait" data-fmt="dur">—</span><span class="tile-l">ср. ожидание</span></div>
    <div class="tile"><span class="tile-v" data-t="outbound">—</span><span class="tile-l">исходящих</span></div>
  </section>

  <div class="live-cols">
    <section>
      <h2 class="live-h">Ждут ответа</h2>
      <table class="live-table">
        <thead><tr><th>#</th><th>Номер</th><th>Очередь</th><th class="num">Ждёт</th></tr></thead>
        <tbody id="live-waiting"><tr><td colspan="4" class="muted">—</td></tr></tbody>
      </table>
    </section>
    <section>
      <h2 class="live-h">Операторы</h2>
      <table class="live-table">
        <thead><tr><th>Оператор</th><th>Состояние</th><th>С кем</th><th class="num">Время</th><th class="num">Принято сегодня</th></tr></thead>
        <tbody id="live-agents"><tr><td colspan="5" class="muted">—</td></tr></tbody>
      </table>
    </section>
  </div>
  <p class="muted live-note">Состояние — сейчас, по данным Asterisk. «Сегодня» обновляется сборщиком раз в минуту.</p>
</div>
<script src="assets/live.js" defer></script>

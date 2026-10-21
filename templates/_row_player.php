<?php /* Inline player of one journal row; driven by assets/app.js (one audio element per row, created on first play). */ ?>
<div class="rp" data-src="?p=play&amp;id=<?= (int) $id ?>">
  <button type="button" class="rp-btn rp-play" aria-label="Слушать" title="Слушать">▶</button>
  <button type="button" class="rp-btn rp-restart" aria-label="С начала" title="С начала">↺</button>
  <input type="range" class="rp-seek" min="0" max="1000" value="0" step="1" aria-label="Перемотка">
  <span class="rp-time">0:00</span>
  <span class="rp-total">--:--</span>
  <button type="button" class="rp-btn rp-speed" aria-label="Скорость 1×" title="Скорость">1×</button>
  <button type="button" class="rp-btn rp-mute" aria-label="Выключить звук" title="Звук">🔊</button>
</div>

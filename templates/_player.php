<?php /* Shared player bar; hidden until a ▶ (.js-play) is clicked. Driven by assets/app.js. */ ?>
<div class="player" id="player" hidden>
  <div class="player-title" data-player-title></div>
  <div class="player-row">
    <button type="button" class="player-btn" data-player-toggle aria-label="Пауза / воспроизведение">❚❚</button>
    <span class="player-time" data-player-cur>0:00</span>
    <input type="range" class="player-seek" data-player-seek min="0" max="1000" value="0" step="1" aria-label="Перемотка">
    <span class="player-time" data-player-total>0:00</span>
    <label class="player-vol" title="Громкость">🔊
      <input type="range" data-player-vol min="0" max="1" step="0.05" value="1" aria-label="Громкость">
    </label>
    <span class="player-speeds" role="group" aria-label="Скорость">
      <button type="button" data-player-speed="1" class="on">1×</button>
      <button type="button" data-player-speed="1.5">1.5×</button>
      <button type="button" data-player-speed="2">2×</button>
    </span>
    <a class="player-dl" data-player-dl href="#">скачать</a>
    <button type="button" class="player-close" data-player-close aria-label="Закрыть плеер">✕</button>
  </div>
  <audio preload="metadata" data-player-audio></audio>
</div>

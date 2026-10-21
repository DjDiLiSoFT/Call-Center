(function () {
  'use strict';

  // Sortable tables: click a header of table.sortable; cells carry data-sort (raw seconds / numbers / lower-case names).
  function initSortable(table) {
    var heads = table.querySelectorAll('thead th');
    var collator = new Intl.Collator('ru');
    Array.prototype.forEach.call(heads, function (th, idx) {
      th.classList.add('sort');
      th.setAttribute('tabindex', '0');
      var run = function () {
        var asc = th.getAttribute('aria-sort') !== 'ascending';
        Array.prototype.forEach.call(heads, function (h) { h.removeAttribute('aria-sort'); });
        th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
        var numeric = th.getAttribute('data-type') === 'num';
        var body = table.tBodies[0];
        var rows = Array.prototype.slice.call(body.rows);
        var val = function (tr) {
          var cell = tr.cells[idx];
          var v = cell.hasAttribute('data-sort') ? cell.getAttribute('data-sort') : cell.textContent.trim();
          return numeric ? parseFloat(v) || 0 : v;
        };
        rows.sort(function (a, b) {
          var x = val(a), y = val(b);
          var c = numeric ? x - y : collator.compare(x, y);
          return asc ? c : -c;
        });
        rows.forEach(function (tr) { body.appendChild(tr); });
      };
      th.addEventListener('click', run);
      th.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); run(); }
      });
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('table.sortable'), initSortable);

  // Shared audio player: one bar per page, any a.js-play loads its href into the single <audio>.
  (function initPlayer() {
    var bar = document.getElementById('player');
    if (!bar) { return; }
    var audio = bar.querySelector('[data-player-audio]');
    var title = bar.querySelector('[data-player-title]');
    var toggle = bar.querySelector('[data-player-toggle]');
    var seek = bar.querySelector('[data-player-seek]');
    var cur = bar.querySelector('[data-player-cur]');
    var total = bar.querySelector('[data-player-total]');
    var vol = bar.querySelector('[data-player-vol]');
    var dl = bar.querySelector('[data-player-dl]');
    var speeds = bar.querySelectorAll('[data-player-speed]');
    var rate = 1;
    var seeking = false;
    var activeRow = null;

    function fmt(s) {
      if (!isFinite(s) || s < 0) { s = 0; }
      s = Math.floor(s);
      var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
      return (h ? h + ':' + (m < 10 ? '0' : '') + m : m) + ':' + (x < 10 ? '0' : '') + x;
    }
    function setSpeed(r) {
      rate = r;
      audio.playbackRate = r;
      Array.prototype.forEach.call(speeds, function (b) {
        b.classList.toggle('on', parseFloat(b.getAttribute('data-player-speed')) === r);
      });
    }
    function describe(a) {
      var t = a.getAttribute('data-title');
      if (t) { return t; }
      var ev = a.closest ? a.closest('.ev') : null;
      if (ev) { return ev.textContent.replace(/\s+/g, ' ').replace('▶', '').trim().slice(0, 140); }
      return 'Запись разговора';
    }
    function mark(a) {
      if (activeRow) { activeRow.classList.remove('playing'); activeRow = null; }
      var row = a && a.closest ? a.closest('tr') : null;
      if (row) { row.classList.add('playing'); activeRow = row; }
    }
    function sync() { toggle.textContent = audio.paused ? '▶' : '❚❚'; }

    document.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('a.js-play') : null;
      if (!a) { return; }
      e.preventDefault();
      var src = a.getAttribute('href');
      if (audio.getAttribute('data-src') === src && !audio.error) {
        if (audio.paused) { audio.play(); } else { audio.pause(); }
        return;
      }
      audio.pause();
      title.classList.remove('player-error');
      title.textContent = describe(a);
      audio.setAttribute('data-src', src);
      audio.src = src;
      dl.href = src + '&dl=1';
      seek.value = 0;
      cur.textContent = '0:00';
      total.textContent = '0:00';
      bar.hidden = false;
      document.body.classList.add('has-player');
      mark(a);
      audio.load();
      audio.playbackRate = rate;
      var p = audio.play();
      if (p && p.catch) { p.catch(function () { sync(); }); }
    });

    toggle.addEventListener('click', function () {
      if (!audio.getAttribute('data-src')) { return; }
      if (audio.paused) { audio.play(); } else { audio.pause(); }
    });
    bar.querySelector('[data-player-close]').addEventListener('click', function () {
      audio.pause();
      audio.removeAttribute('src');
      audio.removeAttribute('data-src');
      audio.load();
      bar.hidden = true;
      document.body.classList.remove('has-player');
      mark(null);
    });
    audio.addEventListener('play', sync);
    audio.addEventListener('pause', sync);
    audio.addEventListener('ended', sync);
    audio.addEventListener('loadedmetadata', function () {
      total.textContent = fmt(audio.duration);
      audio.playbackRate = rate;
    });
    audio.addEventListener('timeupdate', function () {
      cur.textContent = fmt(audio.currentTime);
      if (!seeking && audio.duration > 0) { seek.value = Math.round(audio.currentTime / audio.duration * 1000); }
    });
    audio.addEventListener('error', function () {
      title.textContent = 'Не удалось воспроизвести запись';
      title.classList.add('player-error');
    });
    seek.addEventListener('input', function () {
      seeking = true;
      if (audio.duration > 0) { cur.textContent = fmt(seek.value / 1000 * audio.duration); }
    });
    seek.addEventListener('change', function () {
      if (audio.duration > 0) { audio.currentTime = seek.value / 1000 * audio.duration; }
      seeking = false;
    });
    vol.addEventListener('input', function () { audio.volume = parseFloat(vol.value); });
    Array.prototype.forEach.call(speeds, function (b) {
      b.addEventListener('click', function () { setSpeed(parseFloat(b.getAttribute('data-player-speed'))); });
    });
    sync();
  })();

  // Period filter: dates matter only for the "custom" preset; editing a date switches to it.
  var preset = document.querySelector('select[data-preset]');
  if (preset) {
    Array.prototype.forEach.call(document.querySelectorAll('input[data-custom]'), function (d) {
      d.addEventListener('change', function () { preset.value = 'custom'; });
    });
  }
})();

// Chart tooltips: any element with data-tip inside .dash shows it on hover.
(function () {
  var tip = document.getElementById('chart-tip');
  if (!tip) { return; }
  document.addEventListener('mousemove', function (e) {
    var t = e.target.closest ? e.target.closest('[data-tip]') : null;
    if (!t) { tip.hidden = true; return; }
    tip.textContent = t.getAttribute('data-tip');
    tip.hidden = false;
    var x = e.clientX + 12, y = e.clientY + 14, w = tip.offsetWidth;
    if (x + w > window.innerWidth - 8) { x = e.clientX - w - 12; }
    tip.style.left = x + 'px';
    tip.style.top = y + 'px';
  });
}());

// Dashboard auto-refresh (only while the tab is visible).
(function () {
  var el = document.querySelector('[data-autorefresh]');
  if (!el) { return; }
  var ms = (parseInt(el.getAttribute('data-autorefresh'), 10) || 60) * 1000;
  setInterval(function () {
    if (document.visibilityState === 'visible') { window.location.reload(); }
  }, ms);
}());

// Journal: inline row players. One <audio> per row (created on first play); one plays at a time.
(function () {
  var rows = document.querySelectorAll('.rp');
  if (!rows.length) { return; }
  var current = null;
  function fmt(s) {
    if (!isFinite(s)) { return '--:--'; }
    s = Math.floor(s);
    return Math.floor(s / 60) + ':' + ('0' + s % 60).slice(-2);
  }
  function audioOf(rp) {
    if (rp._audio) { return rp._audio; }
    var a = new Audio();
    a.preload = 'metadata';
    a.src = rp.getAttribute('data-src');
    var play = rp.querySelector('.rp-play'), seek = rp.querySelector('.rp-seek');
    var time = rp.querySelector('.rp-time'), total = rp.querySelector('.rp-total');
    a.addEventListener('loadedmetadata', function () { total.textContent = fmt(a.duration); });
    a.addEventListener('timeupdate', function () {
      time.textContent = fmt(a.currentTime);
      if (!rp._seeking && a.duration > 0) { seek.value = Math.round(a.currentTime / a.duration * 1000); }
    });
    a.addEventListener('play', function () { play.textContent = '❚❚'; play.setAttribute('aria-label', 'Пауза'); rp.classList.add('on'); });
    a.addEventListener('pause', function () { play.textContent = '▶'; play.setAttribute('aria-label', 'Слушать'); rp.classList.remove('on'); });
    a.addEventListener('error', function () { rp.classList.add('err'); total.textContent = 'ошибка'; });
    rp._audio = a;
    return a;
  }
  Array.prototype.forEach.call(rows, function (rp) {
    var seek = rp.querySelector('.rp-seek');
    rp.querySelector('.rp-play').addEventListener('click', function () {
      var a = audioOf(rp);
      if (a.paused) {
        if (current && current !== a) { current.pause(); }
        current = a;
        a.play();
      } else {
        a.pause();
      }
    });
    rp.querySelector('.rp-restart').addEventListener('click', function () {
      var a = audioOf(rp);
      a.currentTime = 0;
      if (current && current !== a) { current.pause(); }
      current = a;
      a.play();
    });
    rp.querySelector('.rp-mute').addEventListener('click', function () {
      var a = audioOf(rp);
      a.muted = !a.muted;
      this.textContent = a.muted ? '🔇' : '🔊';
    });
    seek.addEventListener('input', function () { rp._seeking = true; });
    seek.addEventListener('change', function () {
      var a = audioOf(rp);
      var go = function () { a.currentTime = seek.value / 1000 * a.duration; rp._seeking = false; };
      if (a.duration > 0) { go(); } else { a.addEventListener('loadedmetadata', go, { once: true }); }
    });
  });
}());

// Selects marked data-autosubmit submit their form on change (rows per page).
Array.prototype.forEach.call(document.querySelectorAll('select[data-autosubmit]'), function (s) {
  s.addEventListener('change', function () { s.form.submit(); });
});

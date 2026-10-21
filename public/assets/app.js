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

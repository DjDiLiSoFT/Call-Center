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


  // Period filter: dates matter only for the "custom" preset; editing a date switches to it.
  var preset = document.querySelector('select[data-preset]');
  if (preset) {
    Array.prototype.forEach.call(document.querySelectorAll('input[data-custom]'), function (d) {
      d.addEventListener('change', function () { preset.value = 'custom'; preset.form.submit(); });
    });
    // Choosing a preset applies it at once; "Свой период" waits for the dates.
    preset.addEventListener('change', function () { if (preset.value !== 'custom') { preset.form.submit(); } });
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

// Auto-refresh of dashboard / stats (only while the tab is visible).
(function () {
  var el = document.querySelector('[data-autorefresh]');
  if (!el) { return; }
  var ms = (parseInt(el.getAttribute('data-autorefresh'), 10) || 60) * 1000;
  setInterval(function () {
    // not while the client-history drawer is open: the reload would throw away what is being read
    if (document.visibilityState === 'visible' && !document.body.classList.contains('drawer-open')) { window.location.reload(); }
  }, ms);
}());

// Journal: inline row players. One <audio> per row (created on first play); one plays at a time across
// the page and the client-history drawer. initRowPlayers(root) wires every .rp inside root once.
var initRowPlayers = (function () {
  var current = null;
  function fmt(s) {
    if (!isFinite(s)) { return '--:--'; }
    s = Math.floor(s);
    return Math.floor(s / 60) + ':' + ('0' + s % 60).slice(-2);
  }
  function start(a) {
    if (current && current !== a) { current.pause(); }
    current = a;
    a.play();
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
  function wire(rp) {
    if (rp._wired) { return; }
    rp._wired = true;
    var seek = rp.querySelector('.rp-seek');
    rp.querySelector('.rp-play').addEventListener('click', function () {
      var a = audioOf(rp);
      if (a.paused) { start(a); } else { a.pause(); }
    });
    rp.querySelector('.rp-restart').addEventListener('click', function () {
      var a = audioOf(rp);
      a.currentTime = 0;
      start(a);
    });
    rp.querySelector('.rp-speed').addEventListener('click', function () {
      var a = audioOf(rp);
      var next = { 1: 1.5, 1.5: 2, 2: 1 }[a.playbackRate] || 1;
      a.playbackRate = next;
      this.textContent = String(next).replace('.', ',') + '×';
      this.setAttribute('aria-label', 'Скорость ' + this.textContent);
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
  }
  return function (root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('.rp'), wire);
  };
}());
initRowPlayers(document);

// Client history drawer (journal, stats). A number button (.js-client) loads ?p=client&number=… into it.
(function () {
  var drawer = document.querySelector('.drawer');
  var overlay = document.querySelector('.drawer-overlay');
  if (!drawer || !overlay) { return; }
  var body = drawer.querySelector('.drawer-body');
  var closeBtn = drawer.querySelector('.drawer-close');
  var opener = null;
  var seq = 0;
  function close() {
    if (drawer.hidden) { return; }
    seq++;
    Array.prototype.forEach.call(body.querySelectorAll('.rp'), function (rp) {
      if (rp._audio) { rp._audio.pause(); }
    });
    drawer.hidden = true;
    overlay.hidden = true;
    document.body.classList.remove('drawer-open');
    if (opener) { opener.focus(); opener = null; }
  }
  function open(btn) {
    var number = btn.getAttribute('data-number');
    var my = ++seq;
    opener = btn;
    body.innerHTML = '';
    var p = document.createElement('p');
    p.className = 'muted';
    p.textContent = 'Загрузка…';
    body.appendChild(p);
    drawer.hidden = false;
    overlay.hidden = false;
    document.body.classList.add('drawer-open');
    closeBtn.focus();
    fetch('?p=client&number=' + encodeURIComponent(number), { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.text();
      })
      .then(function (html) {
        if (my !== seq) { return; }
        body.innerHTML = html;
        initRowPlayers(body);
      })
      .catch(function () {
        if (my !== seq) { return; }
        p.textContent = 'Не удалось загрузить историю номера.';
        p.className = 'error';
      });
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.js-client') : null;
    if (btn) { e.preventDefault(); open(btn); }
  });
  closeBtn.addEventListener('click', close);
  overlay.addEventListener('click', close);
  document.addEventListener('keydown', function (e) {
    if (drawer.hidden) { return; }
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'Tab') {
      // keep focus inside the open drawer
      var f = drawer.querySelectorAll('button, a[href], input, select, [tabindex]:not([tabindex="-1"])');
      if (!f.length) { return; }
      var first = f[0], last = f[f.length - 1];
      var a = document.activeElement;
      if (!drawer.contains(a) || a === drawer) { e.preventDefault(); (e.shiftKey ? last : first).focus(); }
      else if (e.shiftKey && a === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && a === last) { e.preventDefault(); first.focus(); }
    }
  });
}());

// Journal: remember the chosen operator; opening the journal without ?ext= returns to it.
(function () {
  var page = document.querySelector('[data-journal-page]');
  if (!page) { return; }
  var KEY = 'cs_journal_ext';
  var params = new URLSearchParams(window.location.search);
  try {
    if (!params.has('ext')) {
      var saved = window.localStorage.getItem(KEY);
      if (saved && saved !== '*') {
        params.set('ext', saved);
        window.location.replace(window.location.pathname + '?' + params.toString());
      }
      return;
    }
    var ext = page.getAttribute('data-ext');
    // «Все операторы» is heavy, so only a single operator is remembered
    if (ext && ext !== '*') { window.localStorage.setItem(KEY, ext); } else if (!ext) { window.localStorage.removeItem(KEY); }
  } catch (e) { /* storage unavailable: nothing to remember */ }
}());

// Selects marked data-autosubmit submit their form on change (rows per page).
Array.prototype.forEach.call(document.querySelectorAll('select[data-autosubmit]'), function (s) {
  s.addEventListener('change', function () { s.form.submit(); });
});

// Stats: show / hide operators without calls.
Array.prototype.forEach.call(document.querySelectorAll('[data-toggle-inactive]'), function (b) {
  b.addEventListener('click', function () {
    var body = b.closest('table').querySelector('tbody.inactive');
    var open = body.hidden;
    body.hidden = !open;
    b.setAttribute('aria-expanded', open ? 'true' : 'false');
    b.textContent = (open ? '▾' : '▸') + b.textContent.slice(1);
  });
});

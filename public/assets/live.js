// Live board: polls ?p=live_data and renders it; durations tick every second between polls.
(function () {
  'use strict';
  var root = document.getElementById('live');
  if (!root) { return; }
  var refreshMs = Math.max(2, parseInt(root.getAttribute('data-refresh'), 10) || 3) * 1000;
  var STATE = {
    talking: 'Разговаривает', ringing: 'Звонит', busy: 'Занят', free: 'Свободен',
    paused: 'Пауза', offline: 'Не в сети'
  };
  var tick = [];       // [{el, base, at}] cells whose seconds grow until the next poll

  function dur(sec) {
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60;
    var mm = (h > 0 && m < 10 ? '0' : '') + m, ss = (s < 10 ? '0' : '') + s;
    return (h > 0 ? h + ':' : '') + mm + ':' + ss;
  }
  function fmt(v, kind) {
    if (v === null || v === undefined) { return '—'; }
    if (kind === 'dur') { return dur(v); }
    if (kind === 'pct') { return String(v).replace('.', ',') + '%'; }
    return String(v);
  }
  function cell(tr, text, cls) {
    var td = document.createElement('td');
    td.textContent = text;
    if (cls) { td.className = cls; }
    tr.appendChild(td);
    return td;
  }
  function emptyRow(tbody, cols, text) {
    var tr = document.createElement('tr');
    var td = cell(tr, text, 'muted');
    td.colSpan = cols;
    tbody.appendChild(tr);
  }
  function fill(selector, attr, data) {
    var els = root.querySelectorAll(selector);
    for (var i = 0; i < els.length; i++) {
      var k = els[i].getAttribute(attr);
      els[i].textContent = data ? fmt(data[k], els[i].getAttribute('data-fmt')) : '—';
    }
  }

  function render(d) {
    var now = Date.now();
    tick = [];
    document.getElementById('live-time').textContent = 'Обновлено ' + d.time;
    var err = document.getElementById('live-error');
    err.hidden = !d.error;
    err.textContent = d.error || '';
    fill('[data-k]', 'data-k', d.summary);
    fill('[data-t]', 'data-t', d.today);
    root.classList.toggle('alarm', !!(d.summary && d.summary.waiting > 0 && d.summary.free === 0));

    var w = document.getElementById('live-waiting');
    w.textContent = '';
    var count = 0;
    (d.queues || []).forEach(function (q) {
      q.waiting.forEach(function (c) {
        var tr = document.createElement('tr');
        cell(tr, String(c.position));
        var name = c.name && !/^<?unknown>?$/i.test(c.name) && c.name !== c.number ? c.name : '';
        cell(tr, c.number + (name ? ' (' + name + ')' : ''));
        cell(tr, q.name);
        var td = cell(tr, dur(c.wait_sec), 'num');
        tick.push({ el: td, base: c.wait_sec, at: now });
        if (c.wait_sec >= 60) { tr.className = 'late'; }
        w.appendChild(tr);
        count++;
      });
    });
    if (!count) { emptyRow(w, 4, d.ok ? 'Никто не ждёт' : '—'); }

    var a = document.getElementById('live-agents');
    a.textContent = '';
    var answered = (d.today && d.today.answered_by_agent) || {};
    (d.agents || []).forEach(function (ag) {
      var tr = document.createElement('tr');
      tr.className = 'st-' + ag.state;
      cell(tr, ag.name === ag.ext ? ag.ext : ag.name + ' (' + ag.ext + ')');
      var st = cell(tr, '');
      var badge = document.createElement('span');
      badge.className = 'badge b-' + ag.state;
      badge.textContent = STATE[ag.state] + (ag.state === 'paused' && ag.pause_reason ? ': ' + ag.pause_reason : '');
      st.appendChild(badge);
      cell(tr, ag.peer || '');
      var t = cell(tr, ag.since_sec === null ? '' : dur(ag.since_sec), 'num');
      if (ag.since_sec !== null) { tick.push({ el: t, base: ag.since_sec, at: now }); }
      cell(tr, String(answered[ag.ext] || 0), 'num');
      a.appendChild(tr);
    });
    if (!(d.agents || []).length) { emptyRow(a, 5, d.ok ? 'Нет операторов в сети' : '—'); }
  }

  function poll() {
    fetch('?p=live_data', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        if (r.status === 403) { throw new Error('auth'); }
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
      })
      .then(function (d) { render(d); setTimeout(poll, refreshMs); })
      .catch(function (e) {
        var err = document.getElementById('live-error');
        err.hidden = false;
        if (e.message === 'auth') {
          err.textContent = 'Сессия истекла. ';
          var link = document.createElement('a');
          link.href = '?p=login';
          link.textContent = 'Войти снова';
          err.appendChild(link);
          return; // stop polling until the user logs in again
        }
        err.textContent = 'Нет связи с сервером, повтор через несколько секунд…';
        setTimeout(poll, refreshMs * 2);
      });
  }

  setInterval(function () {
    var now = Date.now();
    tick.forEach(function (x) { x.el.textContent = dur(x.base + (now - x.at) / 1000); });
  }, 1000);

  document.getElementById('live-fullscreen').addEventListener('click', function () {
    if (document.fullscreenElement) { document.exitFullscreen(); } else if (root.requestFullscreen) { root.requestFullscreen(); }
  });

  poll();
}());

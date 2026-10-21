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
      d.addEventListener('change', function () { preset.value = 'custom'; });
    });
  }
})();

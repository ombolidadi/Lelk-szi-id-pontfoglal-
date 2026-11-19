// Foglalható idő: napok be/ki kapcsolása, szünet megjelenítése, hétfő másolása keddtől péntekig.
(function () {
  var form = document.getElementById('schedule-form');
  if (!form) return;
  var list = form.querySelector('.schedule');
  var rows = Array.prototype.slice.call(form.querySelectorAll('.day-row'));
  var byDay = function (d) { return form.querySelector('.day-row[data-day="' + d + '"]'); };

  // A hét első napjának váltásakor a napok azonnal átrendeződnek
  var ws = document.getElementById('week-start');
  if (ws) {
    ws.addEventListener('change', function () {
      var start = parseInt(ws.value, 10);
      for (var i = 0; i < 7; i++) list.appendChild(byDay(((start - 1 + i) % 7) + 1));
    });
  }
  var q = function (row, sel) { return row.querySelector(sel); };

  function sync(row) {
    var on = q(row, '.day-on input').checked;
    var brk = q(row, '.day-brk input').checked;
    row.classList.toggle('is-off', !on);
    Array.prototype.forEach.call(row.querySelectorAll('.day-times input'), function (i) { i.disabled = !on; });
    q(row, '.brk-times').hidden = !brk;
    Array.prototype.forEach.call(q(row, '.brk-times').querySelectorAll('input'), function (i) { i.disabled = !on || !brk; });
  }
  rows.forEach(function (row) {
    row.addEventListener('change', function () { sync(row); });
    sync(row);
  });

  var copy = document.getElementById('copy-monday');
  if (copy) {
    copy.addEventListener('click', function () {
      var mon = byDay(1);
      [2, 3, 4, 5].map(byDay).forEach(function (row) { // kedd–péntek
        ['.day-on input', '.day-brk input'].forEach(function (sel) { q(row, sel).checked = q(mon, sel).checked; });
        ['start', 'end', 'bs', 'be'].forEach(function (name) {
          q(row, 'input[name^="' + name + '["]').value = q(mon, 'input[name^="' + name + '["]').value;
        });
        sync(row);
      });
      copy.textContent = 'Átmásolva ✓ – ne felejtse el menteni';
    });
  }
})();

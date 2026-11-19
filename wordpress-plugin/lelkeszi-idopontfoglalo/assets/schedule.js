// Foglalható idő: napok be/ki kapcsolása, szünet megjelenítése, hétfő másolása keddtől péntekig.
(function () {
  var form = document.getElementById('schedule-form');
  if (!form) return;
  var rows = Array.prototype.slice.call(form.querySelectorAll('.day-row'));
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
      var mon = rows[0];
      rows.slice(1, 5).forEach(function (row) { // kedd–péntek
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

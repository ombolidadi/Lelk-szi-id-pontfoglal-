// Naptárnézet: üres helyre kattintva új foglalás / szünet, bejegyzésre kattintva részletek.
(function () {
  var dlg = document.getElementById('cal-dlg');
  if (!dlg) return;
  var PX_PER_MIN = 1;
  var $ = function (id) { return document.getElementById(id); };
  var pad = function (n) { return String(n).padStart(2, '0'); };
  var hm = function (min) { min = Math.max(0, Math.min(24 * 60, min)); return pad(Math.floor(min / 60)) + ':' + pad(min % 60); };

  function open(panel, title) {
    ['dlg-new', 'dlg-booking', 'dlg-block'].forEach(function (id) { $(id).hidden = id !== panel; });
    $('cal-dlg-title').textContent = title;
    if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
  }
  function close() { if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); }
  dlg.addEventListener('click', function (e) {
    if (e.target.closest('[data-close]') || e.target === dlg) close();
  });

  function showTab(id) {
    ['tab-booking', 'tab-block'].forEach(function (t) { $(t).hidden = t !== id; });
    Array.prototype.forEach.call(dlg.querySelectorAll('[data-tab]'), function (a) {
      a.classList.toggle('active', a.getAttribute('data-tab') === id);
    });
  }
  Array.prototype.forEach.call(dlg.querySelectorAll('[data-tab]'), function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); showTab(a.getAttribute('data-tab')); });
  });

  function row(dl, label, value, href) {
    if (!value) return;
    var dt = document.createElement('dt'); dt.textContent = label;
    var dd = document.createElement('dd');
    if (href) { var a = document.createElement('a'); a.href = href; a.textContent = value; dd.appendChild(a); } else dd.textContent = value;
    dl.appendChild(dt); dl.appendChild(dd);
  }

  // Üres helyre kattintás: idő kiszámítása 15 perces kerekítéssel
  Array.prototype.forEach.call(document.querySelectorAll('.cal-day'), function (day) {
    day.addEventListener('click', function (e) {
      if (e.target.closest('.cal-ev')) return;
      var rect = day.getBoundingClientRect();
      var from = parseInt(day.getAttribute('data-from'), 10) * 60;
      var min = from + Math.floor((e.clientY - rect.top) / PX_PER_MIN / 15) * 15;
      var date = day.getAttribute('data-date');
      var forms = [$('tab-booking'), $('tab-block')];
      forms.forEach(function (f) { f.reset(); f.querySelector('[name=date]').value = date; });
      $('tab-booking').querySelector('[name=time]').value = hm(min);
      $('tab-block').querySelector('[name=start]').value = hm(min);
      $('tab-block').querySelector('[name=end]').value = hm(Math.min(min + 60, 24 * 60 - 5));
      $('dlg-when').textContent = day.getAttribute('aria-label') + ', ' + hm(min);
      showTab('tab-booking');
      open('dlg-new', 'Új bejegyzés');
      $('tab-booking').querySelector('[name=name]').focus();
    });
  });

  // Meglévő bejegyzés
  Array.prototype.forEach.call(document.querySelectorAll('.cal-ev'), function (btn) {
    btn.addEventListener('click', function () {
      var ev = JSON.parse(btn.getAttribute('data-ev'));
      if (ev.kind === 'booking') {
        var dl = $('dlg-booking-details'); dl.innerHTML = '';
        row(dl, 'Foglaló', ev.title);
        row(dl, 'Alkalom', ev.service);
        row(dl, 'Időpont', ev.when);
        row(dl, 'E-mail', ev.email, ev.email ? 'mailto:' + ev.email : null);
        row(dl, 'Telefon', ev.phone, ev.phone ? 'tel:' + ev.phone : null);
        row(dl, 'Megjegyzés', ev.note);
        $('dlg-booking-link').href = ev.link;
        $('dlg-cancel').querySelector('[name=id]').value = ev.id;
        $('dlg-cancel').hidden = ev.past;
        open('dlg-booking', 'Foglalás');
      } else {
        var bl = $('dlg-block-details'); bl.innerHTML = '';
        row(bl, 'Időszak', ev.when);
        row(bl, 'Megjegyzés', ev.note || 'Szünet');
        $('dlg-block').querySelector('[name=id]').value = ev.id;
        open('dlg-block', 'Szünet');
      }
    });
  });
})();

// Lépésenkénti foglalás: 1. kinél  2. milyen alkalomra  3. mikor  4. adatok (bejelentkezés és fiók felajánlásával).
(function () {
  var form = document.getElementById('booking-form');
  if (!form) return;
  var D = JSON.parse(form.getAttribute('data-lif'));
  var $ = function (id) { return document.getElementById(id); };
  var state = { pastor: null, service: null, weekStart: null, slot: null, today: null, maxDay: null };
  var seq = 0;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function serviceById(id) { return D.services.find(function (s) { return s.id === id; }); }
  function pastorById(id) { return D.pastors.find(function (p) { return String(p.id) === String(id); }); }
  function servicesFor(pastor) {
    if (pastor === 'any') {
      return D.services; // mindegyik szolgáltatást nyújtja valaki a csoportban
    }
    var p = pastorById(pastor);
    return D.services.filter(function (s) { return p && p.services.indexOf(s.id) !== -1; });
  }
  function addDays(iso, n) {
    var d = new Date(iso + 'T12:00:00Z');
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  }
  function withQuery(url, params) {
    var q = Object.keys(params).filter(function (k) { return params[k]; })
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
    return q ? url + (url.indexOf('?') === -1 ? '?' : '&') + q : url;
  }

  /* ---------- 1. lépés: lelkész ---------- */
  function renderPastors() {
    var list = $('pastor-list');
    list.innerHTML = '';
    // Ha a csoportban egyetlen lelkész van, csak ő jelenik meg, előre kiválasztva – nincs mit választani.
    if (D.pastors.length === 1) {
      var only = D.pastors[0];
      var t = $('step-pastor').querySelector('h2');
      t.lastChild.textContent = ' Lelkész';
      var card = el('div', 'choice single');
      card.setAttribute('aria-pressed', 'true');
      card.appendChild(el('strong', null, only.name));
      if (only.meta) card.appendChild(el('small', null, only.meta));
      if (only.bio) card.appendChild(el('span', 'bio', only.bio));
      list.appendChild(card);
      return;
    }
    var options = D.pastors.slice();
    if (D.pastors.length > 1) options.unshift({ id: 'any', name: 'Bármelyik lelkész', meta: 'Az első szabad időpont bárkinél a csoportból' });
    options.forEach(function (p) {
      var b = el('button', 'choice');
      b.type = 'button';
      b.setAttribute('aria-pressed', String(state.pastor) === String(p.id));
      b.appendChild(el('strong', null, p.name));
      if (p.meta) b.appendChild(el('small', null, p.meta));
      if (p.bio) b.appendChild(el('span', 'bio', p.bio));
      b.addEventListener('click', function () { choosePastor(p.id); });
      list.appendChild(b);
    });
  }
  function choosePastor(id, keepFlow) {
    state.pastor = String(id);
    $('f-pastor').value = state.pastor;
    var avail = servicesFor(state.pastor);
    if (state.service && !avail.some(function (s) { return s.id === state.service; })) state.service = null;
    if (!state.service && avail.length === 1) state.service = avail[0].id;
    state.slot = null;
    renderPastors();
    renderServices();
    show('step-service', true);
    if (state.service) chooseService(state.service, keepFlow);
    else { show('step-time', false); show('step-details', false); if (!keepFlow) scrollTo('step-service'); }
  }

  /* ---------- 2. lépés: szolgáltatás ---------- */
  function renderServices() {
    var list = $('service-list');
    list.innerHTML = '';
    servicesFor(state.pastor).forEach(function (s) {
      var b = el('button', 'choice service-choice');
      b.type = 'button';
      b.style.setProperty('--accent', s.color);
      b.setAttribute('aria-pressed', state.service === s.id);
      b.appendChild(el('strong', null, s.name));
      b.appendChild(el('small', null, s.duration + ' perc' + (s.location ? ' · ' + s.location : '')));
      if (s.description) b.appendChild(el('span', 'bio', s.description));
      b.addEventListener('click', function () { chooseService(s.id); });
      list.appendChild(b);
    });
  }
  function chooseService(id, keepFlow) {
    state.service = id;
    $('f-service').value = id;
    if (!keepFlow) { state.slot = null; state.weekStart = null; }
    renderServices();
    show('step-time', true);
    show('step-details', Boolean(state.slot));
    loadSlots();
    if (!keepFlow) scrollTo('step-time');
  }

  /* ---------- 3. lépés: időpont ---------- */
  async function loadSlots() {
    var my = ++seq;
    var box = $('slots');
    box.innerHTML = '<p class="muted full">Szabad időpontok keresése…</p>';
    var url = withQuery(D.api, { group: D.group, service: state.service, pastor: state.pastor, days: '7', from: state.weekStart });
    var data;
    try {
      var res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error();
      data = await res.json();
    } catch (e) {
      box.innerHTML = '';
      box.appendChild(el('p', 'alert alert-error full', 'Nem sikerült betölteni az időpontokat. Próbálja újra.'));
      return;
    }
    if (my !== seq) return;
    state.weekStart = data.from; state.today = data.today; state.maxDay = data.maxDay;
    $('week-label').textContent = data.days[0].label + ' – ' + data.days[data.days.length - 1].label;
    $('prev-week').disabled = data.from <= data.today;
    $('next-week').disabled = data.to >= data.maxDay;
    box.innerHTML = '';
    var any = false;
    data.days.forEach(function (day) {
      var col = el('div', 'day' + (day.slots.length ? '' : ' empty'));
      var h = el('div', 'day-head');
      h.appendChild(el('span', null, day.weekday));
      h.appendChild(el('small', null, day.label));
      col.appendChild(h);
      if (!day.slots.length) col.appendChild(el('div', 'none', '–'));
      day.slots.forEach(function (slot) {
        any = true;
        var b = el('button', 'slot', slot.label);
        b.type = 'button';
        var selected = state.slot && state.slot.start === slot.start;
        if (selected) b.classList.add('selected');
        b.addEventListener('click', function () { chooseSlot(slot, day); });
        col.appendChild(b);
      });
      box.appendChild(col);
    });
    if (!any) box.appendChild(el('p', 'muted full', 'Ezen a héten nincs szabad időpont – lapozzon a következő hétre.'));
    // Visszatérés a belépésből: a korábban választott időpont újra kijelölve
    if (D.initial.start && !state.slot) {
      data.days.forEach(function (day) {
        day.slots.forEach(function (slot) {
          if (Date.parse(slot.start) === Date.parse(D.initial.start)) chooseSlot(slot, day, true);
        });
      });
      D.initial.start = '';
    }
  }
  function chooseSlot(slot, day, quiet) {
    state.slot = { start: slot.start, label: day.weekday + ', ' + day.label + ' ' + slot.label };
    $('f-start').value = slot.start;
    Array.prototype.forEach.call(document.querySelectorAll('.slot.selected'), function (b) { b.classList.remove('selected'); });
    Array.prototype.forEach.call(document.querySelectorAll('.slot'), function (b) {
      if (b.textContent === slot.label && b.parentElement.querySelector('.day-head small').textContent === day.label) b.classList.add('selected');
    });
    renderSummary();
    show('step-details', true);
    if (!quiet) scrollTo('step-details');
  }

  /* ---------- 4. összegzés, bejelentkezés felajánlása ---------- */
  function renderSummary() {
    var s = serviceById(state.service);
    var p = state.pastor === 'any' ? { name: 'bármelyik lelkész' } : pastorById(state.pastor);
    var box = $('summary');
    box.innerHTML = '';
    box.appendChild(el('span', 'eyebrow', 'Az Ön választása'));
    box.appendChild(el('p', null, s.name + ' · ' + p.name + ' · ' + state.slot.label));
    var link = $('login-link');
    if (link) {
      var back = withQuery(D.groupUrl, { lelkesz: state.pastor, szolgaltatas: s.slug, idopont: state.slot.start });
      link.href = withQuery(D.loginUrl, { vissza: back });
    }
  }

  function show(id, on) { $(id).hidden = !on; }
  function scrollTo(id) {
    var t = $(id);
    if (t && t.scrollIntoView) t.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
  }

  $('prev-week').addEventListener('click', function () {
    var n = addDays(state.weekStart, -7);
    state.weekStart = n < state.today ? state.today : n;
    loadSlots();
  });
  $('next-week').addEventListener('click', function () { state.weekStart = addDays(state.weekStart, 7); loadSlots(); });

  var reg = $('f-register');
  if (reg) {
    var sync = function () { $('register-fields').hidden = !reg.checked; };
    reg.addEventListener('change', sync);
    sync();
  }

  // Beküldés előtti ellenőrzés (a szerver is ellenőriz)
  form.addEventListener('submit', function (e) {
    var err = null;
    var email = $('f-email').value.trim(), phone = $('f-phone').value.trim();
    if (!state.slot) err = 'Kérjük, válasszon időpontot.';
    else if (!$('f-name').value.trim()) err = 'Kérjük, adja meg a nevét.';
    else if (!email && !phone) err = 'Kérjük, adjon meg legalább egy elérhetőséget: e-mail címet vagy telefonszámot.';
    else if (reg && reg.checked && !email) err = 'A fiók létrehozásához adja meg az e-mail címét (ezzel fog belépni).';
    else if (reg && reg.checked && $('f-password').value.length < 8) err = 'A fiók jelszava legalább 8 karakter legyen.';
    else if (reg && reg.checked && $('f-password').value !== $('f-password2').value) err = 'A két jelszó nem egyezik.';
    else if (!$('f-consent').checked) err = 'A foglaláshoz fogadja el az adatkezelési tájékoztatót.';
    var box = $('client-error');
    if (err) {
      e.preventDefault();
      box.textContent = err;
      box.hidden = false;
      return;
    }
    box.hidden = true;
    $('submit').disabled = true;
    $('submit').textContent = 'Foglalás folyamatban…';
  });

  /* ---------- indulás ---------- */
  renderPastors();
  var init = D.initial;
  if (init.start && !isNaN(Date.parse(init.start))) {
    var d0 = new Date(init.start);
    state.weekStart = d0.getFullYear() + '-' + String(d0.getMonth() + 1).padStart(2, '0') + '-' + String(d0.getDate()).padStart(2, '0');
  }
  var initService = init.service ? D.services.find(function (s) { return s.slug === init.service; }) : null;
  if (init.pastor && (init.pastor === 'any' || pastorById(init.pastor))) {
    if (initService) state.service = initService.id;
    choosePastor(init.pastor, true);
  } else if (D.pastors.length === 1) {
    if (initService) state.service = initService.id;
    choosePastor(D.pastors[0].id, true);
  } else if (initService) {
    // Régi szolgáltatás-link: „bármelyik lelkész” az adott szolgáltatással
    state.service = initService.id;
    choosePastor('any', true);
  }
})();

(function () {
  const form = document.getElementById('booking-form');
  if (!form) return;
  const serviceId = form.dataset.service;
  const slotsEl = document.getElementById('slots');
  const startInput = document.getElementById('start');
  const chosen = document.getElementById('chosen');
  const submit = document.getElementById('submit');
  const label = document.getElementById('week-label');
  const prev = document.getElementById('prev-week');
  const next = document.getElementById('next-week');
  let from = null;
  let today = null;
  let maxDay = null;
  let seq = 0;

  function pastor() {
    const r = form.querySelector('input[name="pastor"]:checked');
    return r ? r.value : 'any';
  }

  function addDays(iso, n) {
    const d = new Date(iso + 'T12:00:00Z');
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  }

  function select(btn, slot, day) {
    slotsEl.querySelectorAll('.slot.selected').forEach((b) => b.classList.remove('selected'));
    btn.classList.add('selected');
    startInput.value = slot.start;
    chosen.hidden = false;
    chosen.textContent = 'Kiválasztott időpont: ' + day.weekday + ', ' + day.label + ' ' + slot.label;
    submit.disabled = false;
  }

  async function load() {
    const my = ++seq;
    slotsEl.innerHTML = '<p class="muted">Szabad időpontok keresése…</p>';
    const q = new URLSearchParams({ service: serviceId, pastor: pastor(), days: '7', group: form.dataset.group });
    if (from) q.set('from', from);
    const api = form.dataset.api;
    let data;
    try {
      const res = await fetch(api + (api.indexOf('?') === -1 ? '?' : '&') + q, { credentials: 'same-origin' });
      if (!res.ok) throw new Error();
      data = await res.json();
    } catch (e) {
      slotsEl.innerHTML = '<p class="alert alert-error">Nem sikerült betölteni az időpontokat. Próbálja újra.</p>';
      return;
    }
    if (my !== seq) return;
    from = data.from; today = data.today; maxDay = data.maxDay;
    label.textContent = data.days[0].label + ' – ' + data.days[data.days.length - 1].label;
    prev.disabled = from <= today;
    next.disabled = data.to >= maxDay;

    slotsEl.innerHTML = '';
    let any = false;
    data.days.forEach((day) => {
      const col = document.createElement('div');
      col.className = 'day';
      const h = document.createElement('div');
      h.className = 'day-head';
      h.innerHTML = '<span></span><small></small>';
      h.firstChild.textContent = day.weekday;
      h.lastChild.textContent = day.label;
      col.appendChild(h);
      if (!day.slots.length) {
        const p = document.createElement('div');
        p.className = 'none';
        p.textContent = '–';
        col.appendChild(p);
      }
      day.slots.forEach((slot) => {
        any = true;
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'slot';
        b.textContent = slot.label;
        if (slot.start === startInput.value) { b.classList.add('selected'); submit.disabled = false; }
        b.addEventListener('click', () => select(b, slot, day));
        col.appendChild(b);
      });
      slotsEl.appendChild(col);
    });
    if (!any) {
      const p = document.createElement('p');
      p.className = 'muted full';
      p.textContent = 'Ezen a héten nincs szabad időpont – lapozzon a következő hétre.';
      slotsEl.appendChild(p);
    }
  }

  prev.addEventListener('click', () => { from = addDays(from, -7); if (from < today) from = today; load(); });
  next.addEventListener('click', () => { from = addDays(from, 7); load(); });
  form.querySelectorAll('input[name="pastor"]').forEach((r) => r.addEventListener('change', () => {
    startInput.value = ''; chosen.hidden = true; submit.disabled = true; load();
  }));
  form.addEventListener('submit', (e) => {
    if (!startInput.value) { e.preventDefault(); alert('Kérjük, válasszon időpontot.'); return; }
    submit.disabled = true;
    submit.textContent = 'Foglalás folyamatban…';
  });
  load();
})();

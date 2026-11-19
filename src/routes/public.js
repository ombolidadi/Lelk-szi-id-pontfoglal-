const express = require('express');
const { DateTime } = require('luxon');
const { db, getSettings } = require('../db');
const { getSlots, pastorsForService } = require('../slots');
const { createBooking, cancelBooking, BookingError, formatRange } = require('../bookings');
const { findActiveGroup, memberIds, KINDS } = require('../groups');

const router = express.Router();

router.get('/robots.txt', (req, res) => res.type('text/plain').send('User-agent: *\nDisallow: /\n'));

// Nincs közös felület: a főoldal semmit nem listáz, foglalni csak csoportlinken lehet.
router.get('/', (req, res) => {
  if (!db.prepare('SELECT 1 FROM users LIMIT 1').get()) return res.redirect('/admin/setup');
  res.render('landing', { title: 'Időpontfoglalás' });
});

function groupNotFound(res) {
  return res.status(404).render('error', { title: 'Nem található', message: 'Ez a link nem létezik, vagy már nem érvényes. Kérjük, kérjen új linket a gyülekezetétől.' });
}

/** A csoport lelkészei, akik az adott szolgáltatást nyújtják – csak csoporttagokhoz lehet foglalni. */
function pastorsFor(serviceId, group) {
  const members = new Set(memberIds(group.id));
  return pastorsForService(serviceId).filter((p) => members.has(p.id));
}

function loadGroup(req, res, next) {
  const group = findActiveGroup(req.params.group || req.query.csoport);
  if (!group) return req.path.startsWith('/api/') ? res.status(404).json({ error: 'Ismeretlen csoport' }) : groupNotFound(res);
  req.group = group;
  res.locals.group = group;
  req.session.lastGroup = group.slug;
  next();
}

function loadService(req, res, next) {
  const service = db.prepare('SELECT * FROM services WHERE slug = ? AND active = 1').get(req.params.service);
  const pastors = service ? pastorsFor(service.id, req.group) : [];
  if (!service || !pastors.length) return res.status(404).render('error', { title: 'Nem található', message: 'Ez a szolgáltatás ebben a csoportban nem foglalható.' });
  req.service = service;
  req.pastors = pastors;
  next();
}

router.get('/csoport/:group', loadGroup, (req, res) => {
  const members = memberIds(req.group.id).map(Number);
  const inList = members.join(',') || '0';
  const services = db.prepare(`SELECT s.* FROM services s WHERE s.active = 1 AND EXISTS (
      SELECT 1 FROM pastor_services ps JOIN users u ON u.id = ps.user_id
      WHERE ps.service_id = s.id AND u.active = 1 AND u.id IN (${inList}))
    ORDER BY s.sort, s.name`).all();
  const pastors = db.prepare(`SELECT id, name, title, bio, congregation FROM users WHERE active = 1 AND id IN (${inList})
    AND EXISTS (SELECT 1 FROM pastor_services ps JOIN services s ON s.id = ps.service_id WHERE ps.user_id = users.id AND s.active = 1)
    ORDER BY name`).all();
  const pastorServices = db.prepare('SELECT user_id, service_id FROM pastor_services').all();
  res.render('index', { title: req.group.name, services, pastors, pastorServices, KINDS });
});

function renderBook(req, res, extra = {}) {
  const c = res.locals.currentClient;
  res.render('book', {
    title: req.service.name,
    service: req.service,
    pastors: req.pastors,
    preselect: null,
    error: null,
    form: c ? { name: c.name, email: c.email, phone: c.phone } : {},
    ...extra,
  });
}

router.get('/csoport/:group/:service', loadGroup, loadService, (req, res) => {
  renderBook(req, res, { preselect: Number(req.query.lelkesz) || null });
});

router.get('/api/slots', loadGroup, async (req, res, next) => {
  try {
    const settings = getSettings();
    const service = db.prepare('SELECT * FROM services WHERE id = ? AND active = 1').get(Number(req.query.service));
    if (!service) return res.status(404).json({ error: 'Ismeretlen szolgáltatás' });
    const offered = pastorsFor(service.id, req.group).map((p) => p.id);
    const pastorIds = req.query.pastor && req.query.pastor !== 'any'
      ? offered.filter((id) => id === Number(req.query.pastor))
      : offered;
    const today = DateTime.now().setZone(settings.timezone).toISODate();
    const from = /^\d{4}-\d{2}-\d{2}$/.test(req.query.from || '') ? req.query.from : today;
    const days = Math.min(Math.max(Number(req.query.days) || 7, 1), 31);
    const to = DateTime.fromISO(from).plus({ days: days - 1 }).toISODate();
    const slots = pastorIds.length ? await getSlots(service, pastorIds, from, to) : {};
    const maxDay = DateTime.now().setZone(settings.timezone).plus({ days: Number(settings.max_days_ahead) || 60 }).toISODate();
    const out = [];
    for (let d = DateTime.fromISO(from).setLocale('hu'); d.toISODate() <= to; d = d.plus({ days: 1 })) {
      const date = d.toISODate();
      out.push({
        date,
        weekday: d.toFormat('cccc'),
        label: d.toFormat('LLL d.'),
        slots: (slots[date] || []).map((s) => ({
          start: s.start,
          label: DateTime.fromISO(s.start, { zone: settings.timezone }).toFormat('HH:mm'),
        })),
      });
    }
    res.json({ from, to, today, maxDay, days: out });
  } catch (err) {
    next(err);
  }
});

router.post('/csoport/:group/:service', loadGroup, loadService, async (req, res, next) => {
  const client = res.locals.currentClient;
  const form = {
    name: String(req.body.name || '').trim().slice(0, 120),
    email: String(req.body.email || '').trim().slice(0, 200),
    phone: String(req.body.phone || '').trim().slice(0, 40),
    note: String(req.body.note || '').trim().slice(0, 2000),
    start: String(req.body.start || ''),
    pastor: String(req.body.pastor || 'any'),
  };
  if (client) form.email = client.email; // bejelentkezett foglaló a saját címével foglal
  const fail = (error) => {
    res.status(400);
    renderBook(req, res, { preselect: Number(form.pastor) || null, error, form });
  };
  if (!form.name) return fail('Kérjük, adja meg a nevét.');
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) return fail('Kérjük, adjon meg érvényes e-mail címet.');
  if (!form.start) return fail('Kérjük, válasszon időpontot.');
  if (req.body.consent !== 'on') return fail('A foglaláshoz el kell fogadnia az adatkezelést.');

  const offered = req.pastors.map((p) => p.id);
  const pastorIds = form.pastor === 'any' ? offered : offered.filter((id) => id === Number(form.pastor));
  if (!pastorIds.length) return fail('A kiválasztott lelkész nem érhető el ebben a csoportban.');
  try {
    const booking = await createBooking({
      service: req.service, pastorIds, start: form.start, client: form,
      clientId: client ? client.id : null, groupId: req.group.id,
    });
    req.session.flash = { type: 'success', text: 'Foglalását rögzítettük!' };
    res.redirect(`/foglalas/${booking.token}`);
  } catch (err) {
    if (err instanceof BookingError) return fail(err.message);
    next(err);
  }
});

function findBooking(token) {
  return db.prepare(`SELECT b.*, s.name AS service_name, s.location, u.name AS pastor_name, u.congregation AS pastor_congregation,
      g.slug AS group_slug, g.name AS group_name
    FROM bookings b JOIN services s ON s.id = b.service_id JOIN users u ON u.id = b.user_id
    LEFT JOIN groups g ON g.id = b.group_id AND g.active = 1 WHERE b.token = ?`).get(token);
}

router.get('/foglalas/:token', (req, res) => {
  const booking = findBooking(req.params.token);
  if (!booking) return res.status(404).render('error', { title: 'Nem található', message: 'Ez a foglalás nem található.' });
  const tz = getSettings().timezone;
  res.render('booking', {
    title: 'Foglalás',
    booking,
    when: formatRange(booking.start_utc, booking.end_utc, tz),
    isPast: Date.parse(booking.start_utc) < Date.now(),
    gcalLink: googleCalendarLink(booking),
  });
});

/** „Hozzáadás a Google Naptárhoz” link egy foglaláshoz (bejelentkezés nélkül is). */
function googleCalendarLink(b) {
  const f = (iso) => new Date(iso).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
  const q = new URLSearchParams({
    action: 'TEMPLATE',
    text: `${b.service_name} – ${b.pastor_name}`,
    dates: `${f(b.start_utc)}/${f(b.end_utc)}`,
    details: `Időpontfoglalás: ${b.service_name}\nLelkész: ${b.pastor_name}`,
    location: b.location || '',
  });
  return `https://calendar.google.com/calendar/render?${q}`;
}

router.post('/foglalas/:token/lemondas', async (req, res, next) => {
  try {
    const booking = findBooking(req.params.token);
    if (!booking) return res.status(404).render('error', { title: 'Nem található', message: 'Ez a foglalás nem található.' });
    if (Date.parse(booking.start_utc) > Date.now()) await cancelBooking(booking, { by: 'client' });
    req.session.flash = { type: 'info', text: 'A foglalást lemondtuk.' };
    res.redirect(`/foglalas/${booking.token}`);
  } catch (err) {
    next(err);
  }
});

router.get('/adatkezeles', (req, res) => res.render('privacy', { title: 'Adatkezelési tájékoztató' }));

module.exports = router;
module.exports.googleCalendarLink = googleCalendarLink;

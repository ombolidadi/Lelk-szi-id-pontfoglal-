const express = require('express');
const { DateTime } = require('luxon');
const { db, getSettings } = require('../db');
const { getSlots, pastorsForService } = require('../slots');
const { createBooking, cancelBooking, BookingError, formatRange } = require('../bookings');
const { findActiveGroup, memberIds, KINDS } = require('../groups');

const router = express.Router();

/**
 * A kérésben megadott csoport (?csoport=... vagy űrlapmező).
 * undefined = nincs csoport megadva; null = megadva, de nem létezik / inaktív.
 */
function requestGroup(req) {
  const slug = (req.body && req.body.csoport) || req.query.csoport;
  if (!slug) return undefined;
  return findActiveGroup(slug);
}

/** A szolgáltatást nyújtó lelkészek, csoport esetén csak a csoport tagjai. */
function pastorsFor(serviceId, group) {
  const all = pastorsForService(serviceId);
  if (!group) return all;
  const members = new Set(memberIds(group.id));
  return all.filter((p) => members.has(p.id));
}

function groupNotFound(res) {
  return res.status(404).render('error', { title: 'Nem található', message: 'Ez a csoport nem létezik, vagy már nem elérhető.' });
}

function renderHome(res, group) {
  const members = group ? memberIds(group.id) : null;
  const inGroup = (alias) => (members ? ` AND ${alias}.id IN (${members.map(Number).join(',') || '0'})` : '');
  const services = db.prepare(`SELECT s.* FROM services s WHERE s.active = 1 AND EXISTS (
      SELECT 1 FROM pastor_services ps JOIN users u ON u.id = ps.user_id
      WHERE ps.service_id = s.id AND u.active = 1${inGroup('u')})
    ORDER BY s.sort, s.name`).all();
  const pastors = db.prepare(`SELECT u.id, u.name, u.title, u.bio, u.congregation FROM users u WHERE u.active = 1${inGroup('u')}
    AND EXISTS (SELECT 1 FROM pastor_services ps JOIN services s ON s.id = ps.service_id WHERE ps.user_id = u.id AND s.active = 1)
    ORDER BY u.name`).all();
  const pastorServices = db.prepare('SELECT user_id, service_id FROM pastor_services').all();
  res.render('index', {
    title: group ? group.name : 'Időpontfoglalás',
    services, pastors, pastorServices, group: group || null, KINDS,
    q: group ? `?csoport=${encodeURIComponent(group.slug)}` : '',
  });
}

router.get('/', (req, res) => {
  if (!db.prepare('SELECT 1 FROM users LIMIT 1').get()) return res.redirect('/admin/setup');
  renderHome(res, null);
});

router.get('/csoport/:slug', (req, res) => {
  const group = findActiveGroup(req.params.slug);
  if (!group) return groupNotFound(res);
  renderHome(res, group);
});

function renderBook(res, service, group, extra = {}) {
  res.render('book', {
    title: service.name,
    service,
    pastors: pastorsFor(service.id, group),
    group: group || null,
    preselect: null,
    error: null,
    form: {},
    ...extra,
  });
}

router.get('/szolgaltatas/:slug', (req, res) => {
  const group = requestGroup(req);
  if (group === null) return groupNotFound(res);
  const service = db.prepare('SELECT * FROM services WHERE slug = ? AND active = 1').get(req.params.slug);
  if (!service) return res.status(404).render('error', { title: 'Nem található', message: 'Ez a szolgáltatás nem elérhető.' });
  renderBook(res, service, group, { preselect: Number(req.query.lelkesz) || null });
});

router.get('/api/slots', async (req, res, next) => {
  try {
    const settings = getSettings();
    const group = requestGroup(req);
    if (group === null) return res.status(404).json({ error: 'Ismeretlen csoport' });
    const service = db.prepare('SELECT * FROM services WHERE id = ? AND active = 1').get(Number(req.query.service));
    if (!service) return res.status(404).json({ error: 'Ismeretlen szolgáltatás' });
    const offered = pastorsFor(service.id, group).map((p) => p.id);
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
          pastors: s.pastors,
        })),
      });
    }
    res.json({ from, to, today, maxDay, days: out });
  } catch (err) {
    next(err);
  }
});

router.post('/szolgaltatas/:slug', async (req, res, next) => {
  const group = requestGroup(req);
  if (group === null) return groupNotFound(res);
  const service = db.prepare('SELECT * FROM services WHERE slug = ? AND active = 1').get(req.params.slug);
  if (!service) return res.status(404).render('error', { title: 'Nem található', message: 'Ez a szolgáltatás nem elérhető.' });
  const pastors = pastorsFor(service.id, group);
  const form = {
    name: String(req.body.name || '').trim().slice(0, 120),
    email: String(req.body.email || '').trim().slice(0, 200),
    phone: String(req.body.phone || '').trim().slice(0, 40),
    note: String(req.body.note || '').trim().slice(0, 2000),
    start: String(req.body.start || ''),
    pastor: String(req.body.pastor || 'any'),
  };
  const fail = (error) => {
    res.status(400);
    renderBook(res, service, group, { preselect: Number(form.pastor) || null, error, form });
  };
  if (!form.name) return fail('Kérjük, adja meg a nevét.');
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) return fail('Kérjük, adjon meg érvényes e-mail címet.');
  if (!form.start) return fail('Kérjük, válasszon időpontot.');
  if (req.body.consent !== 'on') return fail('A foglaláshoz el kell fogadnia az adatkezelést.');

  const offered = pastors.map((p) => p.id);
  const pastorIds = form.pastor === 'any' ? offered : offered.filter((id) => id === Number(form.pastor));
  if (!pastorIds.length) return fail('A kiválasztott lelkész nem érhető el ehhez a szolgáltatáshoz.');
  try {
    const booking = await createBooking({ service, pastorIds, start: form.start, client: form });
    req.session.flash = { type: 'success', text: 'Foglalását rögzítettük!' };
    res.redirect(`/foglalas/${booking.token}`);
  } catch (err) {
    if (err instanceof BookingError) return fail(err.message);
    next(err);
  }
});

function findBooking(token) {
  return db.prepare(`SELECT b.*, s.name AS service_name, s.location, u.name AS pastor_name, u.congregation AS pastor_congregation
    FROM bookings b JOIN services s ON s.id = b.service_id JOIN users u ON u.id = b.user_id WHERE b.token = ?`).get(token);
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
  });
});

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

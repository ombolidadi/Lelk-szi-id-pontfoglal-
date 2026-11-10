const express = require('express');
const { DateTime } = require('luxon');
const { db, getSettings } = require('../db');
const { getSlots, pastorsForService } = require('../slots');
const { createBooking, cancelBooking, BookingError, formatRange } = require('../bookings');

const router = express.Router();

function activeServices() {
  return db.prepare(`SELECT s.* FROM services s WHERE s.active = 1 AND EXISTS (
      SELECT 1 FROM pastor_services ps JOIN users u ON u.id = ps.user_id WHERE ps.service_id = s.id AND u.active = 1)
    ORDER BY s.sort, s.name`).all();
}

router.get('/', (req, res) => {
  if (!db.prepare('SELECT 1 FROM users LIMIT 1').get()) return res.redirect('/admin/setup');
  const services = activeServices();
  const pastors = db.prepare(`SELECT u.id, u.name, u.title, u.bio FROM users u WHERE u.active = 1
    AND EXISTS (SELECT 1 FROM pastor_services ps JOIN services s ON s.id = ps.service_id WHERE ps.user_id = u.id AND s.active = 1)
    ORDER BY u.name`).all();
  const pastorServices = db.prepare('SELECT user_id, service_id FROM pastor_services').all();
  res.render('index', { title: 'Időpontfoglalás', services, pastors, pastorServices });
});

router.get('/szolgaltatas/:slug', (req, res) => {
  const service = db.prepare('SELECT * FROM services WHERE slug = ? AND active = 1').get(req.params.slug);
  if (!service) return res.status(404).render('error', { title: 'Nem található', message: 'Ez a szolgáltatás nem elérhető.' });
  const pastors = pastorsForService(service.id);
  const preselect = Number(req.query.lelkesz) || null;
  res.render('book', { title: service.name, service, pastors, preselect, error: null, form: {} });
});

router.get('/api/slots', async (req, res, next) => {
  try {
    const settings = getSettings();
    const service = db.prepare('SELECT * FROM services WHERE id = ? AND active = 1').get(Number(req.query.service));
    if (!service) return res.status(404).json({ error: 'Ismeretlen szolgáltatás' });
    const offered = pastorsForService(service.id).map((p) => p.id);
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
  const service = db.prepare('SELECT * FROM services WHERE slug = ? AND active = 1').get(req.params.slug);
  if (!service) return res.status(404).render('error', { title: 'Nem található', message: 'Ez a szolgáltatás nem elérhető.' });
  const pastors = pastorsForService(service.id);
  const form = {
    name: String(req.body.name || '').trim().slice(0, 120),
    email: String(req.body.email || '').trim().slice(0, 200),
    phone: String(req.body.phone || '').trim().slice(0, 40),
    note: String(req.body.note || '').trim().slice(0, 2000),
    start: String(req.body.start || ''),
    pastor: String(req.body.pastor || 'any'),
  };
  const fail = (error) => res.status(400).render('book', { title: service.name, service, pastors, preselect: Number(form.pastor) || null, error, form });
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
  return db.prepare(`SELECT b.*, s.name AS service_name, s.location, u.name AS pastor_name
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

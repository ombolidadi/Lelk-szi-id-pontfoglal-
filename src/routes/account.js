// Időpontkérők (foglalók) saját fiókja: regisztráció, belépés, foglalások, naptár-feliratkozás.
const express = require('express');
const { db, getSettings } = require('../db');
const { hashPassword, verifyPassword, randomToken, createLimiter } = require('../security');
const { cancelBooking, formatRange } = require('../bookings');
const mfa = require('./mfa');

const router = express.Router();
const limiter = createLimiter(10, 15 * 60_000);

function baseUrl() {
  return (process.env.BASE_URL || 'http://localhost:3000').replace(/\/$/, '');
}

function requireClient(req, res, next) {
  if (!res.locals.currentClient) return res.redirect('/fiok/belepes');
  next();
}

function backTo(req) {
  return req.session.lastGroup ? `/csoport/${req.session.lastGroup}` : '/fiok';
}

function logIn(req, res, id) {
  req.session.clientId = id;
  req.session.csrf = randomToken(18);
  const to = req.session.afterLogin || '/fiok';
  delete req.session.afterLogin; // a süti az átirányításkor íródik ki, ezért előtte töröljük
  res.redirect(to);
}

// --- Regisztráció ---------------------------------------------------------------
router.get('/fiok/regisztracio', (req, res) => {
  if (res.locals.currentClient) return res.redirect('/fiok');
  if (req.query.vissza && /^\/csoport\/[a-z0-9-]+(\/[a-z0-9-]+)?$/.test(req.query.vissza)) req.session.afterLogin = req.query.vissza;
  res.render('account/register', { title: 'Fiók létrehozása', error: null, form: {} });
});

router.post('/fiok/regisztracio', (req, res) => {
  const form = {
    name: String(req.body.name || '').trim().slice(0, 120),
    email: String(req.body.email || '').trim().slice(0, 200),
    phone: String(req.body.phone || '').trim().slice(0, 40),
  };
  const fail = (error) => res.status(400).render('account/register', { title: 'Fiók létrehozása', error, form });
  if (!form.name) return fail('Kérjük, adja meg a nevét.');
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) return fail('Kérjük, adjon meg érvényes e-mail címet.');
  const pw = String(req.body.password || '');
  if (pw.length < 8) return fail('A jelszó legalább 8 karakter legyen.');
  if (pw !== String(req.body.password2 || '')) return fail('A két jelszó nem egyezik.');
  if (req.body.consent !== 'on') return fail('A fiók létrehozásához el kell fogadnia az adatkezelési tájékoztatót.');
  if (db.prepare('SELECT 1 FROM clients WHERE email = ?').get(form.email)) return fail('Ezzel az e-mail címmel már van fiók. Jelentkezzen be.');
  const id = db.prepare('INSERT INTO clients(email, name, phone, password_hash, calendar_token) VALUES (?, ?, ?, ?, ?)')
    .run(form.email, form.name, form.phone, hashPassword(pw), randomToken(24)).lastInsertRowid;
  req.session.flash = { type: 'success', text: 'Fiókja elkészült. Kétfaktoros hitelesítést a Fiókom oldalon kapcsolhat be.' };
  logIn(req, res, id);
});

// --- Belépés --------------------------------------------------------------------
router.get('/fiok/belepes', (req, res) => {
  if (res.locals.currentClient) return res.redirect('/fiok');
  if (req.query.vissza && /^\/csoport\/[a-z0-9-]+(\/[a-z0-9-]+)?$/.test(req.query.vissza)) req.session.afterLogin = req.query.vissza;
  res.render('account/login', { title: 'Belépés', error: null });
});

router.post('/fiok/belepes', (req, res) => {
  const key = `client:${req.ip}`;
  if (limiter.blocked(key)) return res.status(429).render('account/login', { title: 'Belépés', error: 'Túl sok próbálkozás. Próbálja újra 15 perc múlva.' });
  const c = db.prepare('SELECT * FROM clients WHERE email = ?').get(String(req.body.email || '').trim());
  if (!c || !verifyPassword(String(req.body.password || ''), c.password_hash)) {
    limiter.fail(key);
    return res.status(401).render('account/login', { title: 'Belépés', error: 'Hibás e-mail cím vagy jelszó.' });
  }
  limiter.reset(key);
  mfa.afterPassword(req, res, { kind: 'client', id: c.id, verifyPath: '/fiok/belepes/2fa', done: logIn });
});

mfa.verifyRoutes(router, { kind: 'client', path: '/fiok/belepes/2fa', loginPath: '/fiok/belepes', done: logIn });

router.post('/fiok/kilepes', (req, res) => {
  const back = backTo(req);
  delete req.session.clientId;
  delete req.session.mfa;
  req.session.flash = { type: 'info', text: 'Kijelentkezett.' };
  res.redirect(back);
});

// --- Fiókom ---------------------------------------------------------------------
function calendarUrls(client) {
  const https = `${baseUrl()}/naptar/${client.calendar_token}.ics`;
  const webcal = https.replace(/^https?:/, 'webcal:');
  return { https, webcal, google: `https://calendar.google.com/calendar/render?cid=${encodeURIComponent(webcal)}` };
}

router.get('/fiok', requireClient, (req, res) => {
  const c = db.prepare('SELECT * FROM clients WHERE id = ?').get(res.locals.currentClient.id);
  const tz = getSettings().timezone;
  const rows = db.prepare(`SELECT b.*, s.name AS service_name, s.location, u.name AS pastor_name, g.slug AS group_slug, g.name AS group_name
    FROM bookings b JOIN services s ON s.id = b.service_id JOIN users u ON u.id = b.user_id
    LEFT JOIN groups g ON g.id = b.group_id AND g.active = 1
    WHERE b.client_id = ? ORDER BY b.start_utc DESC`).all(c.id);
  for (const b of rows) b.when = formatRange(b.start_utc, b.end_utc, tz);
  const now = new Date().toISOString();
  res.render('account/dashboard', {
    title: 'Fiókom', client: c,
    upcoming: rows.filter((b) => b.end_utc >= now).reverse(),
    past: rows.filter((b) => b.end_utc < now),
    cal: calendarUrls(c),
    twoFactor: Boolean(c.totp_enabled),
    back: req.session.lastGroup ? `/csoport/${req.session.lastGroup}` : null,
  });
});

router.post('/fiok/adatok', requireClient, (req, res) => {
  const c = db.prepare('SELECT * FROM clients WHERE id = ?').get(res.locals.currentClient.id);
  const name = String(req.body.name || '').trim().slice(0, 120);
  const phone = String(req.body.phone || '').trim().slice(0, 40);
  if (!name) { req.session.flash = { type: 'error', text: 'A név nem lehet üres.' }; return res.redirect('/fiok'); }
  if (req.body.new_password) {
    if (!verifyPassword(String(req.body.current_password || ''), c.password_hash)) { req.session.flash = { type: 'error', text: 'A jelenlegi jelszó hibás.' }; return res.redirect('/fiok'); }
    if (String(req.body.new_password).length < 8) { req.session.flash = { type: 'error', text: 'Az új jelszó legalább 8 karakter legyen.' }; return res.redirect('/fiok'); }
    db.prepare('UPDATE clients SET password_hash = ? WHERE id = ?').run(hashPassword(String(req.body.new_password)), c.id);
  }
  db.prepare('UPDATE clients SET name = ?, phone = ? WHERE id = ?').run(name, phone, c.id);
  req.session.flash = { type: 'success', text: 'Adatai mentve.' };
  res.redirect('/fiok');
});

router.post('/fiok/naptar-uj-link', requireClient, (req, res) => {
  db.prepare('UPDATE clients SET calendar_token = ? WHERE id = ?').run(randomToken(24), res.locals.currentClient.id);
  req.session.flash = { type: 'info', text: 'Új naptárlinket készítettünk. A régi link már nem működik – a naptárában is cserélje le.' };
  res.redirect('/fiok');
});

router.post('/fiok/foglalas/:id/lemondas', requireClient, async (req, res, next) => {
  try {
    const b = db.prepare('SELECT * FROM bookings WHERE id = ? AND client_id = ?').get(Number(req.params.id), res.locals.currentClient.id);
    if (b && Date.parse(b.start_utc) > Date.now()) {
      await cancelBooking(b, { by: 'client' });
      req.session.flash = { type: 'info', text: 'A foglalást lemondtuk.' };
    }
    res.redirect('/fiok');
  } catch (err) { next(err); }
});

router.post('/fiok/torles', requireClient, (req, res) => {
  const c = db.prepare('SELECT * FROM clients WHERE id = ?').get(res.locals.currentClient.id);
  if (!verifyPassword(String(req.body.password || ''), c.password_hash)) {
    req.session.flash = { type: 'error', text: 'A fiók törléséhez adja meg a jelszavát.' };
    return res.redirect('/fiok');
  }
  db.transaction(() => {
    db.prepare('UPDATE bookings SET client_id = NULL WHERE client_id = ?').run(c.id);
    db.prepare('DELETE FROM clients WHERE id = ?').run(c.id);
  })();
  delete req.session.clientId;
  req.session.flash = { type: 'info', text: 'Fiókját töröltük. Meglévő foglalásai megmaradtak.' };
  res.redirect(backTo(req));
});

mfa.settingsRoutes(router, {
  kind: 'client', base: '/fiok', back: '/fiok', guard: requireClient,
  idOf: (req, res) => res.locals.currentClient.id,
});

// --- Naptár-feliratkozás (ICS) ----------------------------------------------------
// A link titkos tokent tartalmaz; bármely naptár (Google, Apple, Outlook) feliratkozhat rá, és magától frissül.
function icsEscape(s) {
  return String(s || '').replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\r?\n/g, '\\n');
}
function icsDate(iso) {
  return new Date(iso).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
}
function fold(line) {
  const out = [];
  let buf = Buffer.from(line, 'utf8');
  while (buf.length > 75) {
    let cut = 75;
    while (cut > 0 && (buf[cut] & 0xc0) === 0x80) cut--; // ne vágjunk ketté UTF-8 karaktert
    out.push(buf.subarray(0, cut).toString('utf8'));
    buf = Buffer.concat([Buffer.from(' '), buf.subarray(cut)]);
  }
  out.push(buf.toString('utf8'));
  return out.join('\r\n');
}

router.get('/naptar/:token.ics', (req, res) => {
  const c = db.prepare('SELECT * FROM clients WHERE calendar_token = ?').get(req.params.token);
  if (!c) return res.status(404).type('text/plain').send('A naptárlink nem érvényes.');
  const settings = getSettings();
  const since = new Date(Date.now() - 90 * 86400e3).toISOString();
  const rows = db.prepare(`SELECT b.*, s.name AS service_name, s.location, u.name AS pastor_name
    FROM bookings b JOIN services s ON s.id = b.service_id JOIN users u ON u.id = b.user_id
    WHERE b.client_id = ? AND b.end_utc >= ? ORDER BY b.start_utc`).all(c.id, since);
  const host = baseUrl().replace(/^https?:\/\//, '');
  const lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Lelkeszi idopontfoglalo//HU', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
    `X-WR-CALNAME:${icsEscape(`${settings.org_name} – időpontjaim`)}`, `X-WR-TIMEZONE:${settings.timezone}`,
    'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H',
  ];
  for (const b of rows) {
    lines.push('BEGIN:VEVENT',
      `UID:${b.token}@${host}`,
      `DTSTAMP:${icsDate(b.created_at.replace(' ', 'T') + 'Z')}`,
      `DTSTART:${icsDate(b.start_utc)}`, `DTEND:${icsDate(b.end_utc)}`,
      `SUMMARY:${icsEscape(`${b.service_name} – ${b.pastor_name}`)}`,
      `DESCRIPTION:${icsEscape(`Lelkész: ${b.pastor_name}\nFoglalás: ${baseUrl()}/foglalas/${b.token}`)}`,
      b.location ? `LOCATION:${icsEscape(b.location)}` : null,
      `STATUS:${b.status === 'confirmed' ? 'CONFIRMED' : 'CANCELLED'}`,
      'END:VEVENT');
  }
  lines.push('END:VCALENDAR');
  res.set('Cache-Control', 'no-cache');
  res.type('text/calendar; charset=utf-8').send(lines.filter(Boolean).map(fold).join('\r\n') + '\r\n');
});

module.exports = router;

const express = require('express');
const { DateTime } = require('luxon');
const { db, getSettings, setSetting } = require('../db');
const { hashPassword, verifyPassword, randomToken, createLimiter, sha256 } = require('../security');
const mfa = require('./mfa');
const mailer = require('../mailer');
const google = require('../google');
const { cancelBooking } = require('../bookings');
const { KINDS, slugify: groupSlug, uniqueSlug, isMember, groupUrl } = require('../groups');

const router = express.Router();
const WEEKDAYS = ['', 'Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat', 'Vasárnap'];

function flash(req, type, text) { req.session.flash = { type, text }; }
const wrap = (fn) => (req, res, next) => Promise.resolve(fn(req, res, next)).catch(next);

function requireLogin(req, res, next) {
  if (!res.locals.currentUser) return res.redirect('/admin/login');
  next();
}
function requireAdmin(req, res, next) {
  if (!res.locals.currentUser || res.locals.currentUser.role !== 'admin') {
    return res.status(403).render('error', { title: 'Hozzáférés megtagadva', message: 'Ehhez az oldalhoz adminisztrátori jog szükséges.' });
  }
  next();
}

function slugify(s) {
  return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'szolgaltatas';
}

// --- Első indítás: adminisztrátor létrehozása ---------------------------------
const hasUsers = () => Boolean(db.prepare('SELECT 1 FROM users LIMIT 1').get());

router.get('/setup', (req, res) => {
  if (hasUsers()) return res.redirect('/admin/login');
  res.render('admin/setup', { title: 'Első beállítás', error: null });
});

router.post('/setup', (req, res) => {
  if (hasUsers()) return res.redirect('/admin/login');
  const { name, email, password, org_name } = req.body;
  if (!name || !email || !password || password.length < 8) {
    return res.render('admin/setup', { title: 'Első beállítás', error: 'Minden mező kötelező, a jelszó legalább 8 karakter.' });
  }
  const id = db.prepare("INSERT INTO users(name, email, password_hash, role) VALUES (?, ?, ?, 'admin')")
    .run(name.trim(), email.trim(), hashPassword(password)).lastInsertRowid;
  if (org_name) setSetting('org_name', org_name.trim());
  // Alapértelmezett heti elérhetőség: H–P 9–12 és 14–17
  const ins = db.prepare('INSERT INTO availability(user_id, weekday, start_time, end_time) VALUES (?, ?, ?, ?)');
  for (let d = 1; d <= 5; d++) { ins.run(id, d, '09:00', '12:00'); ins.run(id, d, '14:00', '17:00'); }
  // Néhány példa szolgáltatás
  const svc = db.prepare('INSERT INTO services(name, slug, description, duration_min, buffer_min, sort) VALUES (?, ?, ?, ?, ?, ?)');
  const examples = [
    ['Lelkigondozói beszélgetés', 'Személyes beszélgetés lelki kérdésekről, nehézségekről.', 60, 15],
    ['Keresztelő előtti beszélgetés', 'Felkészítő beszélgetés a keresztelés előtt.', 60, 15],
    ['Esküvői jegyesoktatás', 'Felkészítő alkalom házasságkötés előtt.', 90, 15],
    ['Temetés egyeztetése', 'A temetési szertartás részleteinek megbeszélése.', 45, 15],
  ];
  examples.forEach(([n, d, dur, buf], i) => {
    const sid = svc.run(n, slugify(n), d, dur, buf, i).lastInsertRowid;
    db.prepare('INSERT INTO pastor_services(user_id, service_id) VALUES (?, ?)').run(id, sid);
  });
  req.session.userId = id;
  flash(req, 'success', 'Az adminisztrátori fiók elkészült. Csatlakoztassa a Google naptárát!');
  res.redirect('/admin');
});

// --- Bejelentkezés -------------------------------------------------------------
const loginLimiter = createLimiter(10, 15 * 60_000);
function logInUser(req, res, id) {
  req.session.userId = id;
  req.session.csrf = randomToken(18);
  res.redirect('/admin');
}

router.get('/login', (req, res) => {
  if (!hasUsers()) return res.redirect('/admin/setup');
  if (res.locals.currentUser) return res.redirect('/admin');
  res.render('admin/login', { title: 'Bejelentkezés', error: null });
});

router.post('/login', (req, res) => {
  const key = `user:${req.ip}`;
  if (loginLimiter.blocked(key)) return res.status(429).render('admin/login', { title: 'Bejelentkezés', error: 'Túl sok próbálkozás. Próbálja újra 15 perc múlva.' });
  const user = db.prepare('SELECT * FROM users WHERE email = ? AND active = 1').get(String(req.body.email || '').trim());
  if (!user || !verifyPassword(String(req.body.password || ''), user.password_hash)) {
    loginLimiter.fail(key);
    return res.status(401).render('admin/login', { title: 'Bejelentkezés', error: 'Hibás e-mail cím vagy jelszó.' });
  }
  loginLimiter.reset(key);
  mfa.afterPassword(req, res, { kind: 'user', id: user.id, verifyPath: '/admin/login/2fa', done: logInUser });
});

mfa.verifyRoutes(router, { kind: 'user', path: '/admin/login/2fa', mount: '/admin', loginPath: '/admin/login', done: logInUser });

router.post('/logout', (req, res) => {
  delete req.session.userId;
  delete req.session.mfa;
  req.session.flash = { type: 'info', text: 'Kijelentkezett.' };
  res.redirect('/admin/login');
});

// --- Meghívó: regisztráció csak meghívólinkkel ------------------------------------
const INVITE_DAYS = 7;
function findInvite(token) {
  const inv = db.prepare('SELECT * FROM invites WHERE token_hash = ?').get(sha256(token));
  if (!inv || inv.used_at || inv.expires_at < Date.now()) return null;
  return inv;
}
function createInvite({ email, role = 'pastor', congregation = '', groupIds = [], userId = null, createdBy }) {
  const token = randomToken(24);
  // Egy címre egyszerre csak egy érvényes meghívó legyen
  db.prepare('UPDATE invites SET expires_at = 0 WHERE email = ? AND used_at IS NULL').run(email);
  db.prepare(`INSERT INTO invites(token_hash, email, role, congregation, group_ids, user_id, created_by, expires_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)`)
    .run(sha256(token), email, role, congregation, JSON.stringify(groupIds), userId, createdBy, Date.now() + INVITE_DAYS * 86400e3);
  const base = (process.env.BASE_URL || 'http://localhost:3000').replace(/\/$/, '');
  return `${base}/admin/meghivo/${token}`;
}

router.get('/meghivo/:token', (req, res) => {
  const inv = findInvite(req.params.token);
  if (!inv) return res.status(404).render('error', { title: 'Érvénytelen meghívó', message: 'Ez a meghívólink lejárt vagy már felhasználták. Kérjen újat a gyülekezet adminisztrátorától.' });
  const existing = inv.user_id ? db.prepare('SELECT name FROM users WHERE id = ?').get(inv.user_id) : null;
  res.render('admin/invite', { title: existing ? 'Új jelszó beállítása' : 'Regisztráció', invite: inv, existing, error: null, form: {} });
});

router.post('/meghivo/:token', (req, res) => {
  const inv = findInvite(req.params.token);
  if (!inv) return res.status(404).render('error', { title: 'Érvénytelen meghívó', message: 'Ez a meghívólink lejárt vagy már felhasználták.' });
  const existing = inv.user_id ? db.prepare('SELECT * FROM users WHERE id = ?').get(inv.user_id) : null;
  const form = { name: String(req.body.name || '').trim().slice(0, 120), title: String(req.body.title || '').trim().slice(0, 120) };
  const fail = (error) => res.status(400).render('admin/invite', { title: existing ? 'Új jelszó beállítása' : 'Regisztráció', invite: inv, existing, error, form });
  const pw = String(req.body.password || '');
  if (!existing && !form.name) return fail('Kérjük, adja meg a nevét.');
  if (pw.length < 10) return fail('A jelszó legalább 10 karakter legyen.');
  if (pw !== String(req.body.password2 || '')) return fail('A két jelszó nem egyezik.');
  let userId;
  try {
    userId = db.transaction(() => {
      db.prepare('UPDATE invites SET used_at = datetime(\'now\') WHERE id = ?').run(inv.id);
      if (existing) {
        db.prepare('UPDATE users SET password_hash = ? WHERE id = ?').run(hashPassword(pw), existing.id);
        return existing.id;
      }
      const id = db.prepare('INSERT INTO users(name, email, password_hash, role, congregation, title) VALUES (?, ?, ?, ?, ?, ?)')
        .run(form.name, inv.email, hashPassword(pw), inv.role === 'admin' ? 'admin' : 'pastor', inv.congregation || '', form.title).lastInsertRowid;
      for (const gid of JSON.parse(inv.group_ids || '[]')) {
        db.prepare('INSERT OR IGNORE INTO group_members(group_id, user_id) SELECT id, ? FROM groups WHERE id = ?').run(id, Number(gid));
      }
      const ins = db.prepare('INSERT INTO availability(user_id, weekday, start_time, end_time) VALUES (?, ?, ?, ?)');
      for (let d = 1; d <= 5; d++) ins.run(id, d, '09:00', '16:00');
      return id;
    })();
  } catch {
    return fail('Ezzel az e-mail címmel már van fiók. Jelentkezzen be, vagy kérjen jelszó-visszaállító linket.');
  }
  req.session.userId = userId;
  req.session.csrf = randomToken(18);
  flash(req, 'success', existing ? 'Új jelszava beállítva.' : 'Üdvözöljük! Javasoljuk, hogy kapcsolja be a kétfaktoros hitelesítést a Profil oldalon.');
  res.redirect('/admin');
});

router.use(requireLogin);

mfa.settingsRoutes(router, {
  kind: 'user', base: '/admin', mount: '/admin', back: '/admin/profile', guard: (req, res, next) => next(),
  idOf: (req, res) => res.locals.currentUser.id,
});

// --- Vezérlőpult: foglalások ----------------------------------------------------
router.get('/', (req, res) => {
  const me = res.locals.currentUser;
  const showAll = me.role === 'admin' && req.query.mind === '1';
  const past = req.query.korabbi === '1';
  const now = new Date().toISOString();
  const bookings = db.prepare(`SELECT b.*, s.name AS service_name, u.name AS pastor_name FROM bookings b
      JOIN services s ON s.id = b.service_id JOIN users u ON u.id = b.user_id
      WHERE ${showAll ? '1=1' : 'b.user_id = @uid'} AND ${past ? 'b.start_utc < @now' : 'b.start_utc >= @now'}
      ORDER BY b.start_utc ${past ? 'DESC' : 'ASC'} LIMIT 200`).all({ uid: me.id, now });
  const accounts = db.prepare('SELECT COUNT(*) n FROM google_accounts WHERE user_id = ?').get(me.id).n;
  const hasAvailability = Boolean(db.prepare('SELECT 1 FROM availability WHERE user_id = ?').get(me.id));
  const hasServices = Boolean(db.prepare('SELECT 1 FROM pastor_services WHERE user_id = ?').get(me.id));
  res.render('admin/dashboard', { title: 'Foglalások', bookings, showAll, past, accounts, hasAvailability, hasServices, googleReady: google.isConfigured() });
});

router.post('/bookings/:id/cancel', wrap(async (req, res) => {
  const me = res.locals.currentUser;
  const b = db.prepare('SELECT * FROM bookings WHERE id = ?').get(Number(req.params.id));
  if (!b || (b.user_id !== me.id && me.role !== 'admin')) return res.redirect('/admin');
  await cancelBooking(b, { by: 'pastor' });
  flash(req, 'info', 'A foglalást lemondta.');
  const back = String(req.body.back || '');
  res.redirect(/^\/admin(\/|\?|$)/.test(back) ? back : '/admin');
}));

// --- Profil ---------------------------------------------------------------------
router.get('/profile', (req, res) => {
  const user = db.prepare('SELECT * FROM users WHERE id = ?').get(res.locals.currentUser.id);
  res.render('admin/profile', { title: 'Profil', user });
});

router.post('/profile', (req, res) => {
  const me = db.prepare('SELECT * FROM users WHERE id = ?').get(res.locals.currentUser.id);
  const { name, title, bio, email, congregation, current_password, new_password } = req.body;
  if (!name || !email) { flash(req, 'error', 'A név és az e-mail cím kötelező.'); return res.redirect('/admin/profile'); }
  try {
    db.prepare('UPDATE users SET name = ?, title = ?, bio = ?, email = ?, congregation = ? WHERE id = ?')
      .run(name.trim(), (title || '').trim(), (bio || '').trim(), email.trim(), String(congregation || '').trim().slice(0, 120), me.id);
  } catch {
    flash(req, 'error', 'Ez az e-mail cím már foglalt.'); return res.redirect('/admin/profile');
  }
  if (new_password) {
    if (!verifyPassword(current_password || '', me.password_hash)) { flash(req, 'error', 'A jelenlegi jelszó hibás.'); return res.redirect('/admin/profile'); }
    if (new_password.length < 8) { flash(req, 'error', 'Az új jelszó legalább 8 karakter legyen.'); return res.redirect('/admin/profile'); }
    db.prepare('UPDATE users SET password_hash = ? WHERE id = ?').run(hashPassword(new_password), me.id);
  }
  flash(req, 'success', 'Profil mentve.');
  res.redirect('/admin/profile');
});

// --- Elérhetőség ----------------------------------------------------------------
function parseRanges(text) {
  const ranges = [];
  for (const part of String(text || '').split(/[,;\n]+/)) {
    const p = part.trim();
    if (!p) continue;
    const m = p.match(/^(\d{1,2})(?::(\d{2}))?\s*[-–]\s*(\d{1,2})(?::(\d{2}))?$/);
    if (!m) throw new Error(`Érvénytelen időszak: „${p}” (helyes formátum: 09:00-12:00)`);
    const s = `${m[1].padStart(2, '0')}:${m[2] || '00'}`;
    const e = `${m[3].padStart(2, '0')}:${m[4] || '00'}`;
    if (s >= e || e > '24:00') throw new Error(`Érvénytelen időszak: „${p}”`);
    ranges.push([s, e]);
  }
  return ranges;
}

function availabilityTarget(req, res) {
  const me = res.locals.currentUser;
  const id = Number(req.query.lelkesz || req.body?.lelkesz) || me.id;
  if (id !== me.id && me.role !== 'admin') return me.id;
  return id;
}

router.get('/availability', (req, res) => {
  const uid = availabilityTarget(req, res);
  const target = db.prepare('SELECT id, name FROM users WHERE id = ?').get(uid);
  const rows = db.prepare('SELECT * FROM availability WHERE user_id = ? ORDER BY weekday, start_time').all(uid);
  const days = {};
  for (let d = 1; d <= 7; d++) days[d] = rows.filter((r) => r.weekday === d).map((r) => `${r.start_time}-${r.end_time}`).join(', ');
  const timeOff = db.prepare('SELECT * FROM time_off WHERE user_id = ? AND end_date >= ? ORDER BY start_date')
    .all(uid, DateTime.now().toISODate());
  res.render('admin/availability', { title: 'Elérhetőség', target, days, WEEKDAYS, timeOff });
});

router.post('/availability', (req, res) => {
  const uid = availabilityTarget(req, res);
  const q = uid !== res.locals.currentUser.id ? `?lelkesz=${uid}` : '';
  try {
    const parsed = {};
    for (let d = 1; d <= 7; d++) parsed[d] = parseRanges(req.body[`day${d}`]);
    db.transaction(() => {
      db.prepare('DELETE FROM availability WHERE user_id = ?').run(uid);
      const ins = db.prepare('INSERT INTO availability(user_id, weekday, start_time, end_time) VALUES (?, ?, ?, ?)');
      for (let d = 1; d <= 7; d++) for (const [s, e] of parsed[d]) ins.run(uid, d, s, e);
    })();
    flash(req, 'success', 'Heti elérhetőség mentve.');
  } catch (err) {
    flash(req, 'error', err.message);
  }
  res.redirect(`/admin/availability${q}`);
});

router.post('/timeoff', (req, res) => {
  const uid = availabilityTarget(req, res);
  const q = uid !== res.locals.currentUser.id ? `?lelkesz=${uid}` : '';
  const { start_date, end_date, note } = req.body;
  const re = /^\d{4}-\d{2}-\d{2}$/;
  if (!re.test(start_date || '') || !re.test(end_date || start_date || '')) {
    flash(req, 'error', 'Adjon meg érvényes dátumot.');
  } else {
    const end = end_date || start_date;
    if (end < start_date) flash(req, 'error', 'A záró dátum nem lehet korábbi a kezdőnél.');
    else {
      db.prepare('INSERT INTO time_off(user_id, start_date, end_date, note) VALUES (?, ?, ?, ?)').run(uid, start_date, end, (note || '').trim());
      flash(req, 'success', 'Távollét rögzítve.');
    }
  }
  res.redirect(`/admin/availability${q}`);
});

router.post('/timeoff/:id/delete', (req, res) => {
  const me = res.locals.currentUser;
  const t = db.prepare('SELECT * FROM time_off WHERE id = ?').get(Number(req.params.id));
  if (t && (t.user_id === me.id || me.role === 'admin')) db.prepare('DELETE FROM time_off WHERE id = ?').run(t.id);
  res.redirect(`/admin/availability${t && t.user_id !== me.id ? `?lelkesz=${t.user_id}` : ''}`);
});

// --- Google Naptár --------------------------------------------------------------
router.get('/google', (req, res) => {
  const me = db.prepare('SELECT * FROM users WHERE id = ?').get(res.locals.currentUser.id);
  const accounts = db.prepare('SELECT * FROM google_accounts WHERE user_id = ? ORDER BY email').all(me.id);
  for (const a of accounts) {
    a.calendars = db.prepare('SELECT * FROM google_calendars WHERE account_id = ? ORDER BY is_primary DESC, summary').all(a.id);
  }
  res.render('admin/google', {
    title: 'Google naptárak', me, accounts, configured: google.isConfigured(), redirectUri: google.redirectUri(),
  });
});

router.get('/google/connect', (req, res) => {
  if (!google.isConfigured()) { flash(req, 'error', 'A Google integráció nincs beállítva a szerveren.'); return res.redirect('/admin/google'); }
  const state = randomToken(18);
  req.session.oauthState = state;
  res.redirect(google.authUrl(state));
});

router.get('/google/callback', wrap(async (req, res) => {
  const { code, state, error } = req.query;
  const expected = req.session.oauthState;
  delete req.session.oauthState;
  if (error) { flash(req, 'error', `A Google hozzáférés megszakadt: ${error}`); return res.redirect('/admin/google'); }
  if (!code || !state || state !== expected) { flash(req, 'error', 'Érvénytelen Google válasz. Próbálja újra.'); return res.redirect('/admin/google'); }
  try {
    await google.connectAccount(res.locals.currentUser.id, String(code));
    flash(req, 'success', 'Google fiók csatlakoztatva. Válassza ki, mely naptárakat vegyük figyelembe!');
  } catch (err) {
    flash(req, 'error', err.message);
  }
  res.redirect('/admin/google');
}));

function ownAccount(req, res) {
  return db.prepare('SELECT * FROM google_accounts WHERE id = ? AND user_id = ?').get(Number(req.params.id), res.locals.currentUser.id);
}

router.post('/google/:id/refresh', wrap(async (req, res) => {
  const a = ownAccount(req, res);
  if (a) {
    try { await google.syncCalendarList(a.id); flash(req, 'success', 'Naptárlista frissítve.'); } catch (err) { flash(req, 'error', err.message); }
  }
  res.redirect('/admin/google');
}));

router.post('/google/:id/delete', wrap(async (req, res) => {
  const a = ownAccount(req, res);
  if (a) {
    await google.revoke(a);
    db.transaction(() => {
      db.prepare('UPDATE users SET target_account_id = NULL, target_calendar_id = NULL WHERE id = ? AND target_account_id = ?').run(a.user_id, a.id);
      db.prepare('DELETE FROM google_accounts WHERE id = ?').run(a.id);
    })();
    flash(req, 'info', `A(z) ${a.email} fiók leválasztva.`);
  }
  res.redirect('/admin/google');
}));

router.post('/google/calendars', (req, res) => {
  const uid = res.locals.currentUser.id;
  const cals = db.prepare(`SELECT c.* FROM google_calendars c JOIN google_accounts a ON a.id = c.account_id WHERE a.user_id = ?`).all(uid);
  const busy = new Set([].concat(req.body.busy || []).map(Number));
  const target = Number(req.body.target);
  db.transaction(() => {
    for (const c of cals) db.prepare('UPDATE google_calendars SET check_busy = ? WHERE id = ?').run(busy.has(c.id) ? 1 : 0, c.id);
    const t = cals.find((c) => c.id === target && c.can_write);
    if (t) db.prepare('UPDATE users SET target_account_id = ?, target_calendar_id = ? WHERE id = ?').run(t.account_id, t.calendar_id, uid);
    else if (req.body.target === 'none') db.prepare('UPDATE users SET target_account_id = NULL, target_calendar_id = NULL WHERE id = ?').run(uid);
  })();
  flash(req, 'success', 'Naptárbeállítások mentve.');
  res.redirect('/admin/google');
});

// --- Szolgáltatások -------------------------------------------------------------
router.get('/services', (req, res) => {
  const services = db.prepare('SELECT * FROM services ORDER BY sort, name').all();
  const links = db.prepare('SELECT ps.*, u.name FROM pastor_services ps JOIN users u ON u.id = ps.user_id WHERE u.active = 1').all();
  const mine = new Set(links.filter((l) => l.user_id === res.locals.currentUser.id).map((l) => l.service_id));
  res.render('admin/services', { title: 'Szolgáltatások', services, links, mine });
});

router.post('/services/mine', (req, res) => {
  const uid = res.locals.currentUser.id;
  const ids = new Set([].concat(req.body.services || []).map(Number));
  db.transaction(() => {
    db.prepare('DELETE FROM pastor_services WHERE user_id = ?').run(uid);
    const ins = db.prepare('INSERT OR IGNORE INTO pastor_services(user_id, service_id) SELECT ?, id FROM services WHERE id = ?');
    for (const id of ids) ins.run(uid, id);
  })();
  flash(req, 'success', 'Az Ön által vállalt szolgáltatások mentve.');
  res.redirect('/admin/services');
});

function serviceForm(res, service, error) {
  const pastors = db.prepare('SELECT id, name FROM users WHERE active = 1 ORDER BY name').all();
  const assigned = new Set(service.id ? db.prepare('SELECT user_id FROM pastor_services WHERE service_id = ?').all(service.id).map((r) => r.user_id) : []);
  res.render('admin/service-edit', { title: service.id ? 'Szolgáltatás szerkesztése' : 'Új szolgáltatás', service, pastors, assigned, error });
}

router.get('/services/new', requireAdmin, (req, res) => serviceForm(res, { duration_min: 60, buffer_min: 15, active: 1, sort: 0, color: '#4f6d7a' }, null));
router.get('/services/:id', requireAdmin, (req, res) => {
  const s = db.prepare('SELECT * FROM services WHERE id = ?').get(Number(req.params.id));
  if (!s) return res.redirect('/admin/services');
  serviceForm(res, s, null);
});

router.post('/services/:id', requireAdmin, (req, res) => {
  const isNew = req.params.id === 'new';
  const b = req.body;
  const data = {
    name: String(b.name || '').trim(),
    slug: slugify(b.slug || b.name),
    description: String(b.description || '').trim(),
    duration_min: Math.max(5, Math.min(600, Number(b.duration_min) || 60)),
    buffer_min: Math.max(0, Math.min(240, Number(b.buffer_min) || 0)),
    location: String(b.location || '').trim(),
    color: /^#[0-9a-f]{6}$/i.test(b.color || '') ? b.color : '#4f6d7a',
    active: b.active === 'on' ? 1 : 0,
    sort: Number(b.sort) || 0,
  };
  if (!data.name) return serviceForm(res, { ...data, id: isNew ? null : Number(req.params.id) }, 'A név kötelező.');
  let id = isNew ? null : Number(req.params.id);
  try {
    db.transaction(() => {
      if (isNew) {
        id = db.prepare(`INSERT INTO services(name, slug, description, duration_min, buffer_min, location, color, active, sort)
          VALUES (@name, @slug, @description, @duration_min, @buffer_min, @location, @color, @active, @sort)`).run(data).lastInsertRowid;
      } else {
        db.prepare(`UPDATE services SET name=@name, slug=@slug, description=@description, duration_min=@duration_min,
          buffer_min=@buffer_min, location=@location, color=@color, active=@active, sort=@sort WHERE id=@id`).run({ ...data, id });
      }
      db.prepare('DELETE FROM pastor_services WHERE service_id = ?').run(id);
      const ins = db.prepare('INSERT OR IGNORE INTO pastor_services(user_id, service_id) VALUES (?, ?)');
      for (const uid of [].concat(b.pastors || []).map(Number)) ins.run(uid, id);
    })();
  } catch (err) {
    if (/UNIQUE/.test(err.message)) return serviceForm(res, { ...data, id }, 'Ez az URL-azonosító már foglalt, válasszon másikat.');
    throw err;
  }
  flash(req, 'success', 'Szolgáltatás mentve.');
  res.redirect('/admin/services');
});

router.post('/services/:id/delete', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  const used = db.prepare('SELECT 1 FROM bookings WHERE service_id = ? LIMIT 1').get(id);
  if (used) {
    db.prepare('UPDATE services SET active = 0 WHERE id = ?').run(id);
    flash(req, 'info', 'A szolgáltatáshoz tartoznak foglalások, ezért csak inaktiváltuk.');
  } else {
    db.prepare('DELETE FROM services WHERE id = ?').run(id);
    flash(req, 'info', 'Szolgáltatás törölve.');
  }
  res.redirect('/admin/services');
});

// --- Lelkészek (admin) ----------------------------------------------------------
router.get('/pastors', requireAdmin, (req, res) => {
  const pastors = db.prepare(`SELECT u.*, (SELECT COUNT(*) FROM google_accounts g WHERE g.user_id = u.id) AS google_count,
    (SELECT COUNT(*) FROM bookings b WHERE b.user_id = u.id AND b.status = 'confirmed' AND b.start_utc >= ?) AS upcoming
    FROM users u ORDER BY u.active DESC, u.name`).all(new Date().toISOString());
  const groups = db.prepare('SELECT * FROM groups ORDER BY name').all();
  const memberships = db.prepare('SELECT gm.user_id, g.name FROM group_members gm JOIN groups g ON g.id = gm.group_id ORDER BY g.name').all();
  const congregations = db.prepare("SELECT DISTINCT congregation FROM users WHERE congregation != '' ORDER BY congregation").all().map((r) => r.congregation);
  const invites = db.prepare(`SELECT i.*, u.name AS user_name FROM invites i LEFT JOIN users u ON u.id = i.user_id
    WHERE i.used_at IS NULL AND i.expires_at > ? ORDER BY i.created_at DESC`).all(Date.now());
  const created = req.session.createdInvite || null;
  delete req.session.createdInvite; // csak egyszer látható
  res.render('admin/pastors', { title: 'Lelkészek', pastors, groups, memberships, congregations, invites, created });
});

router.post('/pastors', requireAdmin, wrap(async (req, res) => {
  const email = String(req.body.email || '').trim().slice(0, 200);
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { flash(req, 'error', 'Adjon meg érvényes e-mail címet.'); return res.redirect('/admin/pastors'); }
  if (db.prepare('SELECT 1 FROM users WHERE email = ?').get(email)) { flash(req, 'error', 'Ezzel az e-mail címmel már van lelkészi fiók.'); return res.redirect('/admin/pastors'); }
  const link = createInvite({
    email,
    role: req.body.role === 'admin' ? 'admin' : 'pastor',
    congregation: String(req.body.congregation || '').trim().slice(0, 120),
    groupIds: [].concat(req.body.groups || []).map(Number),
    createdBy: res.locals.currentUser.id,
  });
  const org = getSettings().org_name;
  await mailer.send(email, `Meghívó – ${org} időpontfoglaló`,
    `Meghívást kapott a(z) ${org} lelkészi időpontfoglaló felületére.\n\nA regisztrációhoz nyissa meg ezt a linket (${INVITE_DAYS} napig érvényes, egyszer használható):\n${link}\n`);
  req.session.createdInvite = { email, link, mailed: mailer.enabled(), reset: false };
  res.redirect('/admin/pastors');
}));

router.post('/invites/:id/revoke', requireAdmin, (req, res) => {
  db.prepare('UPDATE invites SET expires_at = 0 WHERE id = ? AND used_at IS NULL').run(Number(req.params.id));
  flash(req, 'info', 'Meghívó visszavonva.');
  res.redirect('/admin/pastors');
});

router.post('/pastors/:id', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  const me = res.locals.currentUser;
  const u = db.prepare('SELECT * FROM users WHERE id = ?').get(id);
  if (!u) return res.redirect('/admin/pastors');
  const action = req.body.action;
  if (action === 'congregation') {
    db.prepare('UPDATE users SET congregation = ? WHERE id = ?').run(String(req.body.congregation || '').trim().slice(0, 120), id);
    flash(req, 'success', `${u.name} gyülekezete mentve.`);
  } else if (action === 'toggle' && id !== me.id) {
    db.prepare('UPDATE users SET active = 1 - active WHERE id = ?').run(id);
    flash(req, 'info', u.active ? `${u.name} inaktiválva.` : `${u.name} újra aktív.`);
  } else if (action === 'role' && id !== me.id) {
    db.prepare('UPDATE users SET role = ? WHERE id = ?').run(u.role === 'admin' ? 'pastor' : 'admin', id);
    flash(req, 'info', 'Jogosultság módosítva.');
  } else if (action === 'reset') {
    const link = createInvite({ email: u.email, userId: u.id, createdBy: me.id });
    req.session.createdInvite = { email: u.email, link, mailed: false, reset: true };
  } else if (action === 'reset2fa' && id !== me.id) {
    require('../twofactor').disable('user', id);
    flash(req, 'info', `${u.name} kétfaktoros hitelesítése kikapcsolva (pl. elveszett telefon esetén).`);
  }
  res.redirect('/admin/pastors');
});

// --- Csoportok (gyülekezetek, munkacsoportok) -----------------------------------
// Bármely lelkész létrehozhat csoportot; szerkeszteni a csoport tagjai és az adminisztrátorok tudják.
function randomSuffix() {
  return randomToken(8).toLowerCase().replace(/[^a-z0-9]/g, '').slice(0, 6) || 'x' + Date.now().toString(36).slice(-5);
}
// A csoportlink véletlen végződést kap, így nem lehet kitalálni.
const newGroupSlug = (name) => uniqueSlug(`${groupSlug(name)}-${randomSuffix()}`);

function canEditGroup(me, group) {
  return me.role === 'admin' || isMember(group.id, me.id);
}

router.get('/groups', (req, res) => {
  const me = res.locals.currentUser;
  const groups = db.prepare('SELECT * FROM groups ORDER BY active DESC, name').all();
  for (const g of groups) {
    g.members = db.prepare(`SELECT u.id, u.name FROM group_members gm JOIN users u ON u.id = gm.user_id
      WHERE gm.group_id = ? AND u.active = 1 ORDER BY u.name`).all(g.id);
    g.url = groupUrl(g);
    g.canEdit = canEditGroup(me, g);
    g.mine = g.members.some((m) => m.id === me.id);
  }
  groups.sort((a, b) => Number(b.mine) - Number(a.mine));
  // Gyülekezetek, amelyekhez még nincs azonos nevű csoport – egy kattintással létrehozhatók.
  const existing = new Set(groups.map((g) => g.name.toLowerCase()));
  const suggestions = db.prepare(`SELECT congregation, COUNT(*) AS n FROM users WHERE active = 1 AND congregation != ''
    GROUP BY congregation ORDER BY congregation`).all().filter((c) => !existing.has(c.congregation.toLowerCase()));
  res.render('admin/groups', { title: 'Csoportok', groups, suggestions, KINDS });
});

router.post('/groups/from-congregation', (req, res) => {
  const name = String(req.body.congregation || '').trim();
  const members = db.prepare('SELECT id FROM users WHERE active = 1 AND congregation = ?').all(name);
  if (!name || !members.length) return res.redirect('/admin/groups');
  const gid = db.transaction(() => {
    const id = db.prepare("INSERT INTO groups(name, slug, kind, created_by) VALUES (?, ?, 'gyulekezet', ?)")
      .run(name, newGroupSlug(name), res.locals.currentUser.id).lastInsertRowid;
    for (const m of members) db.prepare('INSERT INTO group_members(group_id, user_id) VALUES (?, ?)').run(id, m.id);
    return id;
  })();
  flash(req, 'success', `„${name}” csoport létrehozva ${members.length} lelkésszel.`);
  res.redirect(`/admin/groups/${gid}`);
});

function groupForm(res, group, error) {
  const pastors = db.prepare('SELECT id, name, congregation FROM users WHERE active = 1 ORDER BY name').all();
  const assigned = new Set(group.id ? db.prepare('SELECT user_id FROM group_members WHERE group_id = ?').all(group.id).map((r) => r.user_id)
    : [res.locals.currentUser.id]);
  res.render('admin/group-edit', {
    title: group.id ? 'Csoport szerkesztése' : 'Új csoport', group, pastors, assigned, error, KINDS,
    url: group.slug ? groupUrl(group) : null,
  });
}

router.get('/groups/new', (req, res) => groupForm(res, { kind: 'gyulekezet', active: 1 }, null));

router.get('/groups/:id', (req, res) => {
  const g = db.prepare('SELECT * FROM groups WHERE id = ?').get(Number(req.params.id));
  if (!g) return res.redirect('/admin/groups');
  if (!canEditGroup(res.locals.currentUser, g)) return res.redirect('/admin/groups');
  groupForm(res, g, null);
});

router.post('/groups/:id', (req, res) => {
  const me = res.locals.currentUser;
  const isNew = req.params.id === 'new';
  const id = isNew ? null : Number(req.params.id);
  if (!isNew) {
    const g = db.prepare('SELECT * FROM groups WHERE id = ?').get(id);
    if (!g || !canEditGroup(me, g)) return res.redirect('/admin/groups');
  }
  const b = req.body;
  const data = {
    name: String(b.name || '').trim().slice(0, 120),
    kind: KINDS[b.kind] ? b.kind : 'egyeb',
    description: String(b.description || '').trim().slice(0, 1000),
    active: b.active === 'on' ? 1 : 0,
  };
  data.slug = b.slug ? groupSlug(b.slug) : (isNew ? newGroupSlug(data.name) : groupSlug(data.name));
  let members = [...new Set([].concat(b.members || []).map(Number))];
  // Nem admin lelkész nem zárhatja ki magát véletlenül (különben elveszítené a szerkesztési jogot).
  if (me.role !== 'admin' && !members.includes(me.id)) members.push(me.id);
  if (!data.name) return groupForm(res, { ...data, id }, 'A név kötelező.');
  if (db.prepare('SELECT 1 FROM groups WHERE slug = ? AND id != ?').get(data.slug, id || 0)) {
    return groupForm(res, { ...data, id }, 'Ez a link-azonosító már foglalt, válasszon másikat.');
  }
  let gid = id;
  db.transaction(() => {
    if (isNew) {
      gid = db.prepare('INSERT INTO groups(name, slug, kind, description, active, created_by) VALUES (@name, @slug, @kind, @description, @active, @created_by)')
        .run({ ...data, created_by: me.id }).lastInsertRowid;
    } else {
      db.prepare('UPDATE groups SET name=@name, slug=@slug, kind=@kind, description=@description, active=@active WHERE id=@id').run({ ...data, id });
    }
    db.prepare('DELETE FROM group_members WHERE group_id = ?').run(gid);
    const ins = db.prepare('INSERT OR IGNORE INTO group_members(group_id, user_id) SELECT ?, id FROM users WHERE id = ?');
    for (const uid of members) ins.run(gid, uid);
  })();
  flash(req, 'success', 'Csoport mentve.');
  res.redirect(`/admin/groups/${gid}`);
});

router.post('/groups/:id/delete', (req, res) => {
  const g = db.prepare('SELECT * FROM groups WHERE id = ?').get(Number(req.params.id));
  if (g && canEditGroup(res.locals.currentUser, g)) {
    db.prepare('DELETE FROM groups WHERE id = ?').run(g.id);
    flash(req, 'info', `„${g.name}” csoport törölve. A megosztott link ezután nem működik.`);
  }
  res.redirect('/admin/groups');
});

// --- Beállítások (admin) --------------------------------------------------------
router.get('/settings', requireAdmin, (req, res) => res.render('admin/settings', { title: 'Beállítások' }));

router.post('/settings', requireAdmin, (req, res) => {
  const b = req.body;
  setSetting('org_name', String(b.org_name || '').trim() || 'Gyülekezet');
  setSetting('org_intro', String(b.org_intro || '').trim());
  setSetting('min_notice_hours', Math.max(0, Number(b.min_notice_hours) || 0));
  setSetting('max_days_ahead', Math.max(1, Math.min(365, Number(b.max_days_ahead) || 60)));
  setSetting('slot_step_min', Math.max(5, Math.min(240, Number(b.slot_step_min) || 30)));
  setSetting('invite_client', b.invite_client === 'on' ? '1' : '0');
  if (DateTime.now().setZone(String(b.timezone || '')).isValid) setSetting('timezone', b.timezone);
  flash(req, 'success', 'Beállítások mentve.');
  res.redirect('/admin/settings');
});

module.exports = router;

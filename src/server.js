require('dotenv').config();
const path = require('path');
const express = require('express');
const cookieSession = require('cookie-session');
const { DateTime } = require('luxon');
const { db, getSettings } = require('./db');
const { csrf } = require('./security');

if (!process.env.SESSION_SECRET) {
  console.warn('[figyelem] SESSION_SECRET nincs beállítva – éles használat előtt állítsa be egy hosszú, véletlen értékre!');
}

const app = express();
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, '..', 'views'));
app.set('trust proxy', 1);
app.disable('x-powered-by');

app.use(express.static(path.join(__dirname, '..', 'public'), { maxAge: '1h' }));
app.use(express.urlencoded({ extended: false, limit: '100kb' }));
app.use(express.json({ limit: '100kb' }));
app.use(cookieSession({
  name: 'sess',
  secret: process.env.SESSION_SECRET || 'dev-secret',
  maxAge: 7 * 24 * 3600 * 1000,
  sameSite: 'lax',
  httpOnly: true,
  secure: (process.env.BASE_URL || '').startsWith('https://'),
}));
app.use((req, res, next) => {
  res.set('X-Content-Type-Options', 'nosniff');
  res.set('X-Frame-Options', 'SAMEORIGIN');
  res.set('Referrer-Policy', 'same-origin');
  res.set('X-Robots-Tag', 'noindex, nofollow'); // keresők ne indexeljék
  next();
});
app.use((req, res, next) => {
  const settings = getSettings();
  res.locals.settings = settings;
  res.locals.flash = req.session.flash || null;
  delete req.session.flash;
  res.locals.currentUser = req.session.userId
    ? db.prepare('SELECT id, name, email, role FROM users WHERE id = ? AND active = 1').get(req.session.userId)
    : null;
  res.locals.currentClient = req.session.clientId
    ? db.prepare('SELECT id, name, email, phone FROM clients WHERE id = ?').get(req.session.clientId)
    : null;
  if (req.session.clientId && !res.locals.currentClient) delete req.session.clientId;
  res.locals.group = null;
  res.locals.fmt = (iso, f = 'yyyy. LLL d. HH:mm') => DateTime.fromISO(iso, { zone: settings.timezone }).setLocale('hu').toFormat(f);
  res.locals.path = req.path;
  next();
});

app.use(csrf);

app.use('/admin', require('./routes/admin'));
app.use('/', require('./routes/account'));
app.use('/', require('./routes/public'));

app.use((req, res) => res.status(404).render('error', { title: 'Nem található', message: 'A keresett oldal nem található.' }));
app.use((err, req, res, _next) => {
  console.error(err);
  res.status(500).render('error', { title: 'Hiba', message: 'Váratlan hiba történt. Kérjük, próbálja újra később.' });
});

if (require.main === module) {
  const port = Number(process.env.PORT || 3000);
  app.listen(port, () => console.log(`Időpontfoglaló fut: http://localhost:${port}`));
}

module.exports = app;

const crypto = require('crypto');

function hashPassword(password) {
  const salt = crypto.randomBytes(16).toString('hex');
  const hash = crypto.scryptSync(password, salt, 64).toString('hex');
  return `scrypt:${salt}:${hash}`;
}

function verifyPassword(password, stored) {
  const [algo, salt, hash] = String(stored).split(':');
  if (algo !== 'scrypt' || !salt || !hash) return false;
  const test = crypto.scryptSync(password, salt, 64);
  const expected = Buffer.from(hash, 'hex');
  return expected.length === test.length && crypto.timingSafeEqual(expected, test);
}

function randomToken(bytes = 24) {
  return crypto.randomBytes(bytes).toString('base64url');
}

// A Google tokeneket titkosítva tároljuk (AES-256-GCM, kulcs a SESSION_SECRET-ből).
function key() {
  return crypto.createHash('sha256').update(process.env.SESSION_SECRET || 'dev-secret').digest();
}

function encrypt(text) {
  if (text == null) return null;
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', key(), iv);
  const enc = Buffer.concat([cipher.update(String(text), 'utf8'), cipher.final()]);
  return ['v1', iv.toString('base64'), cipher.getAuthTag().toString('base64'), enc.toString('base64')].join(':');
}

function decrypt(payload) {
  if (!payload) return null;
  const [v, iv, tag, data] = payload.split(':');
  if (v !== 'v1') return null;
  try {
    const decipher = crypto.createDecipheriv('aes-256-gcm', key(), Buffer.from(iv, 'base64'));
    decipher.setAuthTag(Buffer.from(tag, 'base64'));
    return Buffer.concat([decipher.update(Buffer.from(data, 'base64')), decipher.final()]).toString('utf8');
  } catch {
    return null;
  }
}

// Egyszerű CSRF-védelem: munkamenetenként egy token, minden POST-nak tartalmaznia kell.
function csrf(req, res, next) {
  if (!req.session.csrf) req.session.csrf = randomToken(18);
  res.locals.csrf = req.session.csrf;
  if (req.method === 'POST') {
    const sent = (req.body && req.body._csrf) || req.get('x-csrf-token');
    if (!sent || sent !== req.session.csrf) {
      return res.status(403).render('error', { title: 'Hiba', message: 'Lejárt vagy érvénytelen űrlap. Kérjük, töltse újra az oldalt.' });
    }
  }
  next();
}

// Egyszerű, memóriában tartott próbálkozás-korlátozó (belépés, 2FA-kód).
function createLimiter(max = 10, windowMs = 15 * 60_000) {
  const map = new Map();
  const get = (key) => {
    const a = map.get(key);
    if (!a || Date.now() - a.t > windowMs) return { n: 0, t: Date.now() };
    return a;
  };
  return {
    blocked: (key) => get(key).n >= max,
    fail: (key) => { const a = get(key); a.n++; map.set(key, a); },
    reset: (key) => map.delete(key),
  };
}

function sha256(text) {
  return crypto.createHash('sha256').update(String(text)).digest('hex');
}

module.exports = { hashPassword, verifyPassword, randomToken, encrypt, decrypt, csrf, createLimiter, sha256 };

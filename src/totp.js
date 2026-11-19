// Kétfaktoros hitelesítés: TOTP (RFC 6238), a hitelesítő alkalmazások (Google Authenticator, Microsoft Authenticator, Authy…) szabványa.
const crypto = require('crypto');
const QRCode = require('qrcode');
const { hashPassword, verifyPassword } = require('./security');

const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32Encode(buf) {
  let bits = 0, value = 0, out = '';
  for (const byte of buf) {
    value = (value << 8) | byte; bits += 8;
    while (bits >= 5) { out += B32[(value >>> (bits - 5)) & 31]; bits -= 5; }
  }
  if (bits > 0) out += B32[(value << (5 - bits)) & 31];
  return out;
}

function base32Decode(str) {
  const clean = String(str).toUpperCase().replace(/[^A-Z2-7]/g, '');
  let bits = 0, value = 0;
  const out = [];
  for (const ch of clean) {
    value = (value << 5) | B32.indexOf(ch); bits += 5;
    if (bits >= 8) { out.push((value >>> (bits - 8)) & 255); bits -= 8; }
  }
  return Buffer.from(out);
}

function hotp(secretBuf, counter, digits = 6) {
  const msg = Buffer.alloc(8);
  msg.writeBigUInt64BE(BigInt(counter));
  const h = crypto.createHmac('sha1', secretBuf).update(msg).digest();
  const off = h[h.length - 1] & 15;
  const code = ((h[off] & 127) << 24) | (h[off + 1] << 16) | (h[off + 2] << 8) | h[off + 3];
  return String(code % 10 ** digits).padStart(digits, '0');
}

const STEP = 30;
const stepAt = (ms = Date.now()) => Math.floor(ms / 1000 / STEP);

function generateSecret() {
  return base32Encode(crypto.randomBytes(20));
}

/**
 * Ellenőrzi a kódot (±1 lépés tűréssel az óraeltérés miatt).
 * Visszaadja az elfogadott lépést, vagy null-t. A lastStep-nél nem nagyobb lépést elutasítja (újrafelhasználás ellen).
 */
function verify(secret, code, lastStep = 0, now = Date.now()) {
  const c = String(code || '').replace(/\s+/g, '');
  if (!/^\d{6}$/.test(c)) return null;
  const key = base32Decode(secret);
  const cur = stepAt(now);
  for (const s of [cur, cur - 1, cur + 1]) {
    if (s <= (lastStep || 0)) continue;
    const expected = hotp(key, s);
    if (crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(c))) return s;
  }
  return null;
}

function otpauthUri(secret, account, issuer) {
  const label = encodeURIComponent(`${issuer}:${account}`);
  return `otpauth://totp/${label}?secret=${secret}&issuer=${encodeURIComponent(issuer)}&algorithm=SHA1&digits=6&period=${STEP}`;
}

function qrDataUrl(uri) {
  return QRCode.toDataURL(uri, { margin: 1, width: 220 });
}

/** 8 egyszer használható tartalékkód; csak a hash-üket tároljuk. */
function generateBackupCodes() {
  const codes = Array.from({ length: 8 }, () => {
    const raw = crypto.randomBytes(5).toString('hex').toUpperCase();
    return `${raw.slice(0, 5)}-${raw.slice(5)}`;
  });
  return { codes, hashes: codes.map((c) => hashPassword(c)) };
}

/** Tartalékkód ellenőrzése; siker esetén a maradék hash-ek listáját adja vissza. */
function useBackupCode(hashesJson, code) {
  const norm = String(code || '').toUpperCase().replace(/[^A-F0-9]/g, '');
  if (norm.length !== 10) return null;
  const formatted = `${norm.slice(0, 5)}-${norm.slice(5)}`;
  const hashes = JSON.parse(hashesJson || '[]');
  const idx = hashes.findIndex((h) => verifyPassword(formatted, h));
  if (idx === -1) return null;
  hashes.splice(idx, 1);
  return JSON.stringify(hashes);
}

module.exports = { generateSecret, verify, otpauthUri, qrDataUrl, generateBackupCodes, useBackupCode, hotp, base32Encode, base32Decode, stepAt };

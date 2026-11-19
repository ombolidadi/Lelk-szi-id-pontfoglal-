// Kétfaktoros hitelesítés kezelése lelkészeknek (users) és foglalóknak (clients) közösen.
const { db, getSettings } = require('./db');
const totp = require('./totp');
const { encrypt, decrypt } = require('./security');

const TABLES = { user: 'users', client: 'clients' };
const table = (kind) => {
  const t = TABLES[kind];
  if (!t) throw new Error('Ismeretlen fióktípus');
  return t;
};
const row = (kind, id) => db.prepare(`SELECT * FROM ${table(kind)} WHERE id = ?`).get(id);

function isEnabled(kind, id) {
  const r = row(kind, id);
  return Boolean(r && r.totp_enabled);
}

/** Beállítás indítása (vagy a félbehagyott folytatása): titok + QR-kód. */
async function setupData(kind, id, { restart = false } = {}) {
  const r = row(kind, id);
  let secret = !restart && r.totp_secret && !r.totp_enabled ? decrypt(r.totp_secret) : null;
  if (!secret) {
    secret = totp.generateSecret();
    db.prepare(`UPDATE ${table(kind)} SET totp_secret = ? WHERE id = ? AND totp_enabled = 0`).run(encrypt(secret), id);
  }
  const issuer = getSettings().org_name || 'Időpontfoglaló';
  const uri = totp.otpauthUri(secret, r.email, issuer);
  return { secret: secret.replace(/(.{4})/g, '$1 ').trim(), qr: await totp.qrDataUrl(uri) };
}

/** Az első kód megerősítése után bekapcsolja a 2FA-t; visszaadja a tartalékkódokat (csak most láthatók). */
function confirmSetup(kind, id, code) {
  const r = row(kind, id);
  if (!r || r.totp_enabled || !r.totp_secret) return null;
  const step = totp.verify(decrypt(r.totp_secret), code, 0);
  if (!step) return null;
  const { codes, hashes } = totp.generateBackupCodes();
  db.prepare(`UPDATE ${table(kind)} SET totp_enabled = 1, totp_last_step = ?, backup_codes = ? WHERE id = ?`)
    .run(step, JSON.stringify(hashes), id);
  return codes;
}

/** Belépéskor vagy kikapcsoláskor: hitelesítő kód vagy tartalékkód ellenőrzése. */
function check(kind, id, code) {
  const r = row(kind, id);
  if (!r || !r.totp_enabled) return false;
  const step = totp.verify(decrypt(r.totp_secret), code, r.totp_last_step);
  if (step) {
    db.prepare(`UPDATE ${table(kind)} SET totp_last_step = ? WHERE id = ?`).run(step, id);
    return true;
  }
  const left = totp.useBackupCode(r.backup_codes, code);
  if (left) {
    db.prepare(`UPDATE ${table(kind)} SET backup_codes = ? WHERE id = ?`).run(left, id);
    return true;
  }
  return false;
}

function disable(kind, id) {
  db.prepare(`UPDATE ${table(kind)} SET totp_enabled = 0, totp_secret = NULL, totp_last_step = 0, backup_codes = NULL WHERE id = ?`).run(id);
}

function backupCount(kind, id) {
  const r = row(kind, id);
  try { return JSON.parse(r.backup_codes || '[]').length; } catch { return 0; }
}

module.exports = { isEnabled, setupData, confirmSetup, check, disable, backupCount };

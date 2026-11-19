const test = require('node:test');
const assert = require('node:assert');
const totp = require('../src/totp');

test('RFC 6238 tesztvektor (SHA1, T=59s)', () => {
  const key = Buffer.from('12345678901234567890');
  assert.strictEqual(totp.hotp(key, totp.stepAt(59_000), 8), '94287082');
  assert.strictEqual(totp.hotp(key, totp.stepAt(1111111109_000), 8), '07081804');
});

test('base32 oda-vissza', () => {
  const buf = Buffer.from('12345678901234567890');
  assert.strictEqual(totp.base32Encode(buf), 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
  assert.deepStrictEqual(totp.base32Decode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'), buf);
});

test('kód ellenőrzése és újrafelhasználás tiltása', () => {
  const secret = totp.generateSecret();
  const now = Date.now();
  const code = totp.hotp(totp.base32Decode(secret), totp.stepAt(now));
  const step = totp.verify(secret, code, 0, now);
  assert.ok(step);
  assert.strictEqual(totp.verify(secret, code, step, now), null);
  assert.strictEqual(totp.verify(secret, '000000x', 0, now), null);
});

test('tartalékkód egyszer használható', () => {
  const { codes, hashes } = totp.generateBackupCodes();
  const left = totp.useBackupCode(JSON.stringify(hashes), codes[3].toLowerCase());
  assert.ok(left);
  assert.strictEqual(JSON.parse(left).length, 7);
  assert.strictEqual(totp.useBackupCode(left, codes[3]), null);
});

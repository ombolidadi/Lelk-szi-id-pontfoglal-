const test = require('node:test');
const assert = require('node:assert');
const { DateTime } = require('luxon');

process.env.DATABASE_FILE = ':memory:';
const { computeFreeSlots } = require('../src/slots');

const tz = 'Europe/Budapest';
const at = (iso) => DateTime.fromISO(iso, { zone: tz }).toMillis();
const labels = (slots) => slots.map((s) => DateTime.fromMillis(s.start, { zone: tz }).toFormat('HH:mm'));

// 2026-11-16 hétfő
const base = { dates: ['2026-11-16'], timezone: tz, windows: { 1: [{ start: '09:00', end: '12:00' }] }, durationMin: 60, stepMin: 30 };

test('heti ablakból lépésközönként generál időpontokat', () => {
  const r = computeFreeSlots(base);
  assert.deepStrictEqual(labels(r['2026-11-16']), ['09:00', '09:30', '10:00', '10:30', '11:00']);
});

test('foglalt időszak és szünet kiesik', () => {
  const r = computeFreeSlots({ ...base, bufferMin: 15, busy: [{ start: at('2026-11-16T10:00'), end: at('2026-11-16T10:30') }] });
  // 09:00–10:00 + 15 perc szünet ütközik 10:00-val; 10:30+15 = 10:45 előtt nem kezdődhet
  assert.deepStrictEqual(labels(r['2026-11-16']), ['11:00']);
});

test('szabadság alatt nincs időpont', () => {
  const r = computeFreeSlots({ ...base, timeOff: [{ start_date: '2026-11-15', end_date: '2026-11-17' }] });
  assert.deepStrictEqual(r['2026-11-16'], []);
});

test('más napra nem generál, ha nincs ablak', () => {
  const r = computeFreeSlots({ ...base, dates: ['2026-11-17'] });
  assert.deepStrictEqual(r['2026-11-17'], []);
});

test('legkorábbi kezdést figyelembe veszi', () => {
  const r = computeFreeSlots({ ...base, earliest: at('2026-11-16T10:15') });
  assert.deepStrictEqual(labels(r['2026-11-16']), ['10:30', '11:00']);
});

test('nyári időszámítás váltásnál is helyi idő szerint számol', () => {
  // 2027-03-28 vasárnap: óraátállítás
  const r = computeFreeSlots({ ...base, dates: ['2027-03-28'], windows: { 7: [{ start: '09:00', end: '10:00' }] } });
  assert.deepStrictEqual(labels(r['2027-03-28']), ['09:00']);
  assert.strictEqual(new Date(r['2027-03-28'][0].start).toISOString(), '2027-03-28T07:00:00.000Z');
});

// Szabad időpontok számítása: heti elérhetőség − szabadság − meglévő foglalások − Google naptár foglaltság.
const { DateTime } = require('luxon');
const { db, getSettings } = require('./db');
const google = require('./google');

const MIN = 60_000;

/**
 * Tiszta (adatbázis nélküli) számítás, tesztelhető.
 * windows: { [weekday 1-7]: [{ start: 'HH:MM', end: 'HH:MM' }] }
 * busy: [{ start: ms, end: ms }]
 */
function computeFreeSlots({ dates, timezone, windows, timeOff = [], busy = [], durationMin, bufferMin = 0, stepMin, earliest, latest }) {
  const out = {};
  const step = Math.max(5, stepMin || durationMin);
  for (const date of dates) {
    out[date] = [];
    if (timeOff.some((t) => date >= t.start_date && date <= t.end_date)) continue;
    const day = DateTime.fromISO(date, { zone: timezone });
    for (const w of windows[day.weekday] || []) {
      const winStart = DateTime.fromISO(`${date}T${w.start}`, { zone: timezone }).toMillis();
      const winEnd = DateTime.fromISO(`${date}T${w.end}`, { zone: timezone }).toMillis();
      for (let s = winStart; s + durationMin * MIN <= winEnd; s += step * MIN) {
        const e = s + durationMin * MIN;
        if (earliest != null && s < earliest) continue;
        if (latest != null && s > latest) continue;
        const clash = busy.some((b) => s < b.end + bufferMin * MIN && e + bufferMin * MIN > b.start);
        if (!clash) out[date].push({ start: s, end: e });
      }
    }
    out[date].sort((a, b) => a.start - b.start);
  }
  return out;
}

function dateRange(from, to) {
  const dates = [];
  for (let d = DateTime.fromISO(from); d <= DateTime.fromISO(to); d = d.plus({ days: 1 })) dates.push(d.toISODate());
  return dates;
}

function pastorsForService(serviceId) {
  return db.prepare(`SELECT u.* FROM users u JOIN pastor_services ps ON ps.user_id = u.id
    WHERE ps.service_id = ? AND u.active = 1 ORDER BY u.name`).all(serviceId);
}

function loadWindows(userId) {
  const windows = {};
  for (const r of db.prepare('SELECT * FROM availability WHERE user_id = ? ORDER BY weekday, start_time').all(userId)) {
    (windows[r.weekday] ||= []).push({ start: r.start_time, end: r.end_time });
  }
  return windows;
}

function dbBusy(userId, fromMs, toMs, excludeBookingId) {
  return db.prepare(`SELECT start_utc, end_utc FROM bookings WHERE user_id = ? AND status = 'confirmed'
      AND start_utc < ? AND end_utc > ? AND id != ?`)
    .all(userId, new Date(toMs).toISOString(), new Date(fromMs).toISOString(), excludeBookingId || 0)
    .map((b) => ({ start: Date.parse(b.start_utc), end: Date.parse(b.end_utc) }));
}

/**
 * Szabad időpontok egy szolgáltatáshoz, egy vagy több lelkészre, dátumtartományra.
 * Eredmény: { 'YYYY-MM-DD': [{ start: ISO, end: ISO, pastors: [id, ...] }] }
 */
async function getSlots(service, pastorIds, fromDate, toDate, { useGoogle = true } = {}) {
  const settings = getSettings();
  const tz = settings.timezone;
  const now = DateTime.now().setZone(tz);
  const earliest = now.plus({ hours: Number(settings.min_notice_hours) || 0 }).toMillis();
  const lastDay = now.plus({ days: Number(settings.max_days_ahead) || 60 }).toISODate();
  if (toDate > lastDay) toDate = lastDay;
  if (fromDate < now.toISODate()) fromDate = now.toISODate();
  if (fromDate > toDate) return {};

  const dates = dateRange(fromDate, toDate);
  const rangeStart = DateTime.fromISO(fromDate, { zone: tz }).startOf('day').toMillis();
  const rangeEnd = DateTime.fromISO(toDate, { zone: tz }).endOf('day').toMillis();
  const pad = (Number(service.buffer_min) || 0) * MIN;

  const merged = {};
  for (const d of dates) merged[d] = new Map();

  await Promise.all(pastorIds.map(async (pid) => {
    const busy = dbBusy(pid, rangeStart - pad, rangeEnd + pad);
    if (useGoogle) busy.push(...await google.busyIntervals(pid, rangeStart - pad, rangeEnd + pad));
    const res = computeFreeSlots({
      dates,
      timezone: tz,
      windows: loadWindows(pid),
      timeOff: db.prepare('SELECT start_date, end_date FROM time_off WHERE user_id = ?').all(pid),
      busy,
      durationMin: service.duration_min,
      bufferMin: service.buffer_min,
      stepMin: Number(settings.slot_step_min) || service.duration_min,
      earliest,
    });
    for (const [date, slots] of Object.entries(res)) {
      for (const s of slots) {
        const k = s.start;
        if (!merged[date].has(k)) merged[date].set(k, { start: new Date(s.start).toISOString(), end: new Date(s.end).toISOString(), pastors: [] });
        merged[date].get(k).pastors.push(pid);
      }
    }
  }));

  const out = {};
  for (const [date, map] of Object.entries(merged)) {
    out[date] = [...map.values()].sort((a, b) => a.start.localeCompare(b.start));
  }
  return out;
}

/** Ellenőrzi, hogy egy adott időpont még szabad-e az adott lelkésznél. */
async function isSlotFree(service, pastorId, startIso) {
  const tz = getSettings().timezone;
  const date = DateTime.fromISO(startIso, { zone: tz }).toISODate();
  const res = await getSlots(service, [pastorId], date, date);
  return (res[date] || []).some((s) => Date.parse(s.start) === Date.parse(startIso));
}

module.exports = { computeFreeSlots, getSlots, isSlotFree, pastorsForService, dbBusy, dateRange };

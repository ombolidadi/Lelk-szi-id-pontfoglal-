// Foglalás létrehozása és lemondása (adatbázis + Google Naptár + e-mail).
const { DateTime } = require('luxon');
const { db, getSettings } = require('./db');
const { randomToken } = require('./security');
const { isSlotFree, dbBusy } = require('./slots');
const google = require('./google');
const mailer = require('./mailer');

function baseUrl() {
  return (process.env.BASE_URL || 'http://localhost:3000').replace(/\/$/, '');
}

function formatRange(startIso, endIso, tz) {
  const s = DateTime.fromISO(startIso, { zone: tz }).setLocale('hu');
  const e = DateTime.fromISO(endIso, { zone: tz }).setLocale('hu');
  return `${s.toFormat('yyyy. LLLL d. (cccc) HH:mm')}–${e.toFormat('HH:mm')}`;
}

class BookingError extends Error {}

async function createBooking({ service, pastorIds, start, client, clientId = null, groupId = null }) {
  const settings = getSettings();
  const startMs = Date.parse(start);
  if (Number.isNaN(startMs)) throw new BookingError('Érvénytelen időpont.');
  const endMs = startMs + service.duration_min * 60_000;

  // Az első olyan lelkész, akinél az időpont még szabad (a legkevésbé leterheltet előre véve).
  const load = (pid) => db.prepare(`SELECT COUNT(*) n FROM bookings WHERE user_id = ? AND status = 'confirmed' AND start_utc >= ?`)
    .get(pid, new Date().toISOString()).n;
  const ordered = [...pastorIds].sort((a, b) => load(a) - load(b));
  let pastorId = null;
  for (const pid of ordered) {
    if (await isSlotFree(service, pid, new Date(startMs).toISOString())) { pastorId = pid; break; }
  }
  if (!pastorId) throw new BookingError('Ez az időpont időközben betelt. Kérjük, válasszon másikat.');

  const pad = (service.buffer_min || 0) * 60_000;
  const token = randomToken();
  const insert = db.transaction(() => {
    // Utolsó ellenőrzés tranzakción belül a párhuzamos foglalások ellen.
    if (dbBusy(pastorId, startMs - pad, endMs + pad).length) throw new BookingError('Ez az időpont időközben betelt.');
    return db.prepare(`INSERT INTO bookings(service_id, user_id, start_utc, end_utc, client_name, client_email, client_phone, note, token, client_id, group_id)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`)
      .run(service.id, pastorId, new Date(startMs).toISOString(), new Date(endMs).toISOString(),
        client.name, client.email, client.phone || '', client.note || '', token, clientId, groupId).lastInsertRowid;
  });
  const bookingId = insert();
  const booking = db.prepare('SELECT * FROM bookings WHERE id = ?').get(bookingId);
  const pastor = db.prepare('SELECT * FROM users WHERE id = ?').get(pastorId);

  try {
    const ev = await google.createEvent(pastorId, {
      summary: `${service.name} – ${client.name}`,
      description: [
        `Szolgáltatás: ${service.name}`,
        `Név: ${client.name}`,
        `E-mail: ${client.email}`,
        client.phone ? `Telefon: ${client.phone}` : null,
        client.note ? `\nMegjegyzés:\n${client.note}` : null,
        `\nLemondás: ${baseUrl()}/foglalas/${token}`,
      ].filter(Boolean).join('\n'),
      location: service.location,
      start: startMs,
      end: endMs,
      timezone: settings.timezone,
      attendeeEmail: client.email,
      attendeeName: client.name,
      invite: settings.invite_client === '1',
    });
    if (ev) {
      db.prepare('UPDATE bookings SET google_account_id = ?, google_calendar_id = ?, google_event_id = ? WHERE id = ?')
        .run(ev.accountId, ev.calendarId, ev.eventId, bookingId);
    }
  } catch (err) {
    console.error('[google] esemény létrehozása sikertelen:', err.message);
  }

  const when = formatRange(booking.start_utc, booking.end_utc, settings.timezone);
  await mailer.send(client.email, `Időpontfoglalás visszaigazolása – ${service.name}`,
    `Kedves ${client.name}!\n\nIdőpontját rögzítettük.\n\nSzolgáltatás: ${service.name}\nLelkész: ${pastor.name}\nIdőpont: ${when}\n` +
    (service.location ? `Helyszín: ${service.location}\n` : '') +
    `\nHa mégsem tud eljönni, itt lemondhatja: ${baseUrl()}/foglalas/${token}\n\nÁldás és békesség!\n${settings.org_name}`);
  await mailer.send(pastor.email, `Új foglalás: ${service.name} – ${client.name}`,
    `Új időpontfoglalás érkezett.\n\nSzolgáltatás: ${service.name}\nIdőpont: ${when}\nNév: ${client.name}\nE-mail: ${client.email}\n` +
    (client.phone ? `Telefon: ${client.phone}\n` : '') + (client.note ? `Megjegyzés: ${client.note}\n` : ''));

  return db.prepare('SELECT * FROM bookings WHERE id = ?').get(bookingId);
}

async function cancelBooking(booking, { by }) {
  if (booking.status !== 'confirmed') return;
  const settings = getSettings();
  db.prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?").run(booking.id);
  if (booking.google_event_id) {
    try {
      await google.deleteEvent(booking.google_account_id, booking.google_calendar_id, booking.google_event_id, settings.invite_client === '1');
    } catch (err) {
      console.error('[google] esemény törlése sikertelen:', err.message);
    }
  }
  const service = db.prepare('SELECT * FROM services WHERE id = ?').get(booking.service_id);
  const pastor = db.prepare('SELECT * FROM users WHERE id = ?').get(booking.user_id);
  const group = booking.group_id ? db.prepare('SELECT slug FROM groups WHERE id = ? AND active = 1').get(booking.group_id) : null;
  const when = formatRange(booking.start_utc, booking.end_utc, settings.timezone);
  if (by === 'client') {
    await mailer.send(pastor.email, `Lemondott foglalás: ${service.name} – ${booking.client_name}`,
      `${booking.client_name} lemondta a(z) ${when} időpontot (${service.name}).`);
  } else {
    await mailer.send(booking.client_email, `Időpont lemondva – ${service.name}`,
      `Kedves ${booking.client_name}!\n\nSajnos a(z) ${when} időpontot (${service.name}, ${pastor.name}) le kellett mondanunk.\n` +
      (group ? `Új időpontot itt foglalhat: ${baseUrl()}/csoport/${group.slug}\n\n` : '\n') + settings.org_name);
  }
}

module.exports = { createBooking, cancelBooking, BookingError, formatRange };

// Google Naptár integráció közvetlen REST hívásokkal (nincs szükség a nehéz googleapis csomagra).
const { db } = require('./db');
const { encrypt, decrypt } = require('./security');

const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const TOKEN_URL = 'https://oauth2.googleapis.com/token';
const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
const API = 'https://www.googleapis.com/calendar/v3';
const SCOPES = [
  'openid',
  'email',
  'https://www.googleapis.com/auth/calendar.readonly',
  'https://www.googleapis.com/auth/calendar.events',
];

function isConfigured() {
  return Boolean(process.env.GOOGLE_CLIENT_ID && process.env.GOOGLE_CLIENT_SECRET);
}

function redirectUri() {
  const base = (process.env.BASE_URL || 'http://localhost:3000').replace(/\/$/, '');
  return `${base}/admin/google/callback`;
}

function authUrl(state) {
  const params = new URLSearchParams({
    client_id: process.env.GOOGLE_CLIENT_ID,
    redirect_uri: redirectUri(),
    response_type: 'code',
    scope: SCOPES.join(' '),
    access_type: 'offline',
    prompt: 'consent select_account',
    include_granted_scopes: 'true',
    state,
  });
  return `${AUTH_URL}?${params}`;
}

async function postForm(url, data) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(data),
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`Google token hiba: ${json.error_description || json.error || res.status}`);
  return json;
}

async function exchangeCode(code) {
  return postForm(TOKEN_URL, {
    code,
    client_id: process.env.GOOGLE_CLIENT_ID,
    client_secret: process.env.GOOGLE_CLIENT_SECRET,
    redirect_uri: redirectUri(),
    grant_type: 'authorization_code',
  });
}

async function fetchEmail(accessToken) {
  const res = await fetch(USERINFO_URL, { headers: { Authorization: `Bearer ${accessToken}` } });
  if (!res.ok) throw new Error('Nem sikerült lekérni a Google fiók adatait.');
  return (await res.json()).email;
}

// OAuth visszahívás után: fiók mentése/frissítése és naptárlista szinkronizálása.
async function connectAccount(userId, code) {
  const tok = await exchangeCode(code);
  const email = await fetchEmail(tok.access_token);
  const existing = db.prepare('SELECT * FROM google_accounts WHERE user_id = ? AND email = ?').get(userId, email);
  const expiry = Date.now() + (tok.expires_in || 3600) * 1000;
  let accountId;
  if (existing) {
    db.prepare(`UPDATE google_accounts SET access_token = ?, token_expiry = ?, last_error = NULL,
                refresh_token = COALESCE(?, refresh_token) WHERE id = ?`)
      .run(encrypt(tok.access_token), expiry, tok.refresh_token ? encrypt(tok.refresh_token) : null, existing.id);
    accountId = existing.id;
  } else {
    accountId = db.prepare(`INSERT INTO google_accounts(user_id, email, refresh_token, access_token, token_expiry)
                            VALUES (?, ?, ?, ?, ?)`)
      .run(userId, email, encrypt(tok.refresh_token), encrypt(tok.access_token), expiry).lastInsertRowid;
  }
  await syncCalendarList(accountId);
  return accountId;
}

async function getAccessToken(account) {
  const access = decrypt(account.access_token);
  if (access && account.token_expiry && account.token_expiry > Date.now() + 60_000) return access;
  const refresh = decrypt(account.refresh_token);
  if (!refresh) throw new Error('Hiányzó frissítő token – csatlakoztassa újra a Google fiókot.');
  try {
    const tok = await postForm(TOKEN_URL, {
      client_id: process.env.GOOGLE_CLIENT_ID,
      client_secret: process.env.GOOGLE_CLIENT_SECRET,
      refresh_token: refresh,
      grant_type: 'refresh_token',
    });
    const expiry = Date.now() + (tok.expires_in || 3600) * 1000;
    db.prepare('UPDATE google_accounts SET access_token = ?, token_expiry = ?, last_error = NULL WHERE id = ?')
      .run(encrypt(tok.access_token), expiry, account.id);
    return tok.access_token;
  } catch (err) {
    db.prepare('UPDATE google_accounts SET last_error = ? WHERE id = ?').run(err.message, account.id);
    throw err;
  }
}

async function api(account, method, path, body) {
  const token = await getAccessToken(account);
  const res = await fetch(`${API}${path}`, {
    method,
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (res.status === 204) return null;
  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    const msg = (json.error && json.error.message) || res.statusText;
    const err = new Error(`Google Naptár hiba (${res.status}): ${msg}`);
    err.status = res.status;
    throw err;
  }
  return json;
}

function getAccount(accountId) {
  return db.prepare('SELECT * FROM google_accounts WHERE id = ?').get(accountId);
}

async function syncCalendarList(accountId) {
  const account = getAccount(accountId);
  const items = [];
  let pageToken;
  do {
    const q = new URLSearchParams({ minAccessRole: 'freeBusyReader', maxResults: '250' });
    if (pageToken) q.set('pageToken', pageToken);
    const page = await api(account, 'GET', `/users/me/calendarList?${q}`);
    items.push(...(page.items || []));
    pageToken = page.nextPageToken;
  } while (pageToken);

  const upsert = db.prepare(`INSERT INTO google_calendars(account_id, calendar_id, summary, is_primary, can_write, check_busy)
    VALUES (@account_id, @calendar_id, @summary, @is_primary, @can_write, @check_busy)
    ON CONFLICT(account_id, calendar_id) DO UPDATE SET summary = excluded.summary,
      is_primary = excluded.is_primary, can_write = excluded.can_write`);
  const seen = new Set();
  db.transaction(() => {
    for (const c of items) {
      seen.add(c.id);
      upsert.run({
        account_id: accountId,
        calendar_id: c.id,
        summary: c.summaryOverride || c.summary || c.id,
        is_primary: c.primary ? 1 : 0,
        can_write: ['owner', 'writer'].includes(c.accessRole) ? 1 : 0,
        check_busy: c.primary ? 1 : 0, // csak új naptárnál érvényesül
      });
    }
    const existing = db.prepare('SELECT id, calendar_id FROM google_calendars WHERE account_id = ?').all(accountId);
    for (const e of existing) if (!seen.has(e.calendar_id)) db.prepare('DELETE FROM google_calendars WHERE id = ?').run(e.id);

    // Ha a lelkésznek még nincs célnaptára, az elsődleges naptár lesz az.
    const user = db.prepare('SELECT * FROM users WHERE id = ?').get(account.user_id);
    if (!user.target_calendar_id) {
      const primary = items.find((c) => c.primary);
      if (primary) {
        db.prepare('UPDATE users SET target_account_id = ?, target_calendar_id = ? WHERE id = ?')
          .run(accountId, primary.id, user.id);
      }
    }
  })();
}

// Foglalt időszakok lekérése a lelkész összes kiválasztott naptárából (több fiók, több naptár).
async function busyIntervals(userId, timeMin, timeMax) {
  if (!isConfigured()) return [];
  const cals = db.prepare(`SELECT c.calendar_id, c.account_id FROM google_calendars c
    JOIN google_accounts a ON a.id = c.account_id
    WHERE a.user_id = ? AND c.check_busy = 1`).all(userId);
  const byAccount = new Map();
  for (const c of cals) {
    if (!byAccount.has(c.account_id)) byAccount.set(c.account_id, []);
    byAccount.get(c.account_id).push(c.calendar_id);
  }
  const result = [];
  await Promise.all([...byAccount.entries()].map(async ([accountId, ids]) => {
    const account = getAccount(accountId);
    try {
      const fb = await api(account, 'POST', '/freeBusy', {
        timeMin: new Date(timeMin).toISOString(),
        timeMax: new Date(timeMax).toISOString(),
        items: ids.map((id) => ({ id })),
      });
      for (const cal of Object.values(fb.calendars || {})) {
        for (const b of cal.busy || []) result.push({ start: Date.parse(b.start), end: Date.parse(b.end) });
      }
    } catch (err) {
      console.error(`[google] freeBusy hiba (fiók #${accountId}):`, err.message);
      db.prepare('UPDATE google_accounts SET last_error = ? WHERE id = ?').run(err.message, accountId);
    }
  }));
  return result;
}

async function createEvent(userId, { summary, description, location, start, end, timezone, attendeeEmail, attendeeName, invite }) {
  const user = db.prepare('SELECT * FROM users WHERE id = ?').get(userId);
  if (!isConfigured() || !user || !user.target_account_id || !user.target_calendar_id) return null;
  const account = getAccount(user.target_account_id);
  if (!account) return null;
  const body = {
    summary,
    description,
    location: location || undefined,
    start: { dateTime: new Date(start).toISOString(), timeZone: timezone },
    end: { dateTime: new Date(end).toISOString(), timeZone: timezone },
    reminders: { useDefault: true },
  };
  if (invite && attendeeEmail) body.attendees = [{ email: attendeeEmail, displayName: attendeeName }];
  const q = invite ? '?sendUpdates=all' : '';
  const ev = await api(account, 'POST', `/calendars/${encodeURIComponent(user.target_calendar_id)}/events${q}`, body);
  return { accountId: account.id, calendarId: user.target_calendar_id, eventId: ev.id };
}

async function deleteEvent(accountId, calendarId, eventId, notify) {
  const account = getAccount(accountId);
  if (!account || !eventId) return;
  try {
    await api(account, 'DELETE', `/calendars/${encodeURIComponent(calendarId)}/events/${encodeURIComponent(eventId)}${notify ? '?sendUpdates=all' : ''}`);
  } catch (err) {
    if (err.status !== 404 && err.status !== 410) throw err;
  }
}

async function revoke(account) {
  const token = decrypt(account.refresh_token) || decrypt(account.access_token);
  if (!token) return;
  await fetch(`https://oauth2.googleapis.com/revoke?token=${encodeURIComponent(token)}`, { method: 'POST' }).catch(() => {});
}

module.exports = {
  isConfigured, authUrl, redirectUri, connectAccount, syncCalendarList, busyIntervals,
  createEvent, deleteEvent, revoke, getAccount,
};

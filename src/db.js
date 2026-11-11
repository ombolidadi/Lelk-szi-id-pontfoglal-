const path = require('path');
const fs = require('fs');
const Database = require('better-sqlite3');

const dbFile = process.env.DATABASE_FILE || path.join(__dirname, '..', 'data', 'app.db');
fs.mkdirSync(path.dirname(dbFile), { recursive: true });

const db = new Database(dbFile);
db.pragma('journal_mode = WAL');
db.pragma('foreign_keys = ON');

db.exec(`
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE COLLATE NOCASE,
  name TEXT NOT NULL,
  title TEXT DEFAULT '',
  bio TEXT DEFAULT '',
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'pastor',
  active INTEGER NOT NULL DEFAULT 1,
  target_account_id INTEGER,
  target_calendar_id TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS google_accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  email TEXT NOT NULL,
  refresh_token TEXT,
  access_token TEXT,
  token_expiry INTEGER,
  last_error TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(user_id, email)
);

CREATE TABLE IF NOT EXISTS google_calendars (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL REFERENCES google_accounts(id) ON DELETE CASCADE,
  calendar_id TEXT NOT NULL,
  summary TEXT NOT NULL,
  is_primary INTEGER NOT NULL DEFAULT 0,
  can_write INTEGER NOT NULL DEFAULT 0,
  check_busy INTEGER NOT NULL DEFAULT 0,
  UNIQUE(account_id, calendar_id)
);

CREATE TABLE IF NOT EXISTS services (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  description TEXT DEFAULT '',
  duration_min INTEGER NOT NULL DEFAULT 60,
  buffer_min INTEGER NOT NULL DEFAULT 0,
  location TEXT DEFAULT '',
  color TEXT DEFAULT '#4f6d7a',
  active INTEGER NOT NULL DEFAULT 1,
  sort INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS pastor_services (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  service_id INTEGER NOT NULL REFERENCES services(id) ON DELETE CASCADE,
  PRIMARY KEY (user_id, service_id)
);

CREATE TABLE IF NOT EXISTS availability (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  weekday INTEGER NOT NULL,         -- 1 = hétfő ... 7 = vasárnap
  start_time TEXT NOT NULL,         -- 'HH:MM' helyi idő
  end_time TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS time_off (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  start_date TEXT NOT NULL,         -- 'YYYY-MM-DD'
  end_date TEXT NOT NULL,
  note TEXT DEFAULT ''
);

CREATE TABLE IF NOT EXISTS bookings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  service_id INTEGER NOT NULL REFERENCES services(id),
  user_id INTEGER NOT NULL REFERENCES users(id),
  start_utc TEXT NOT NULL,
  end_utc TEXT NOT NULL,
  client_name TEXT NOT NULL,
  client_email TEXT NOT NULL,
  client_phone TEXT DEFAULT '',
  note TEXT DEFAULT '',
  status TEXT NOT NULL DEFAULT 'confirmed',
  token TEXT NOT NULL UNIQUE,
  google_account_id INTEGER,
  google_calendar_id TEXT,
  google_event_id TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_bookings_user_start ON bookings(user_id, start_utc);

CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT
);

-- Lelkészi csoportok (pl. gyülekezet, munkacsoport), saját megosztható foglalási linkkel
CREATE TABLE IF NOT EXISTS groups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  kind TEXT NOT NULL DEFAULT 'gyulekezet',   -- 'gyulekezet' | 'munkacsoport' | 'egyeb'
  description TEXT DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS group_members (
  group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  PRIMARY KEY (group_id, user_id)
);
`);

// Egyszerű migráció meglévő adatbázisokhoz
function addColumn(table, column, def) {
  const cols = db.prepare(`PRAGMA table_info(${table})`).all().map((c) => c.name);
  if (!cols.includes(column)) db.exec(`ALTER TABLE ${table} ADD COLUMN ${column} ${def}`);
}
addColumn('users', 'congregation', "TEXT DEFAULT ''");

// Kétfaktoros hitelesítés (lelkészek)
addColumn('users', 'totp_secret', 'TEXT');          // titkosítva
addColumn('users', 'totp_enabled', 'INTEGER NOT NULL DEFAULT 0');
addColumn('users', 'totp_last_step', 'INTEGER NOT NULL DEFAULT 0');
addColumn('users', 'backup_codes', 'TEXT');         // JSON: scrypt hash-ek

// Foglalások összekötése foglalói fiókkal és csoporttal
addColumn('bookings', 'client_id', 'INTEGER');
addColumn('bookings', 'group_id', 'INTEGER');

db.exec(`
-- Időpontkérők (foglalók) saját fiókja
CREATE TABLE IF NOT EXISTS clients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE COLLATE NOCASE,
  name TEXT NOT NULL,
  phone TEXT DEFAULT '',
  password_hash TEXT NOT NULL,
  totp_secret TEXT,
  totp_enabled INTEGER NOT NULL DEFAULT 0,
  totp_last_step INTEGER NOT NULL DEFAULT 0,
  backup_codes TEXT,
  calendar_token TEXT NOT NULL UNIQUE,          -- naptár-feliratkozási (ICS) link titka
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Meghívók a lelkészi felületre (és jelszó-visszaállító linkek meglévő lelkészeknek)
CREATE TABLE IF NOT EXISTS invites (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token_hash TEXT NOT NULL UNIQUE,
  email TEXT NOT NULL COLLATE NOCASE,
  role TEXT NOT NULL DEFAULT 'pastor',
  congregation TEXT DEFAULT '',
  group_ids TEXT DEFAULT '[]',
  user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,   -- ha meglévő lelkész jelszó-visszaállítása
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  expires_at INTEGER NOT NULL,
  used_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
`);

const DEFAULT_SETTINGS = {
  org_name: 'Gyülekezet',
  org_intro: 'Foglaljon időpontot lelkészeinkhez beszélgetésre, lelkigondozásra vagy egyéb alkalomra.',
  timezone: 'Europe/Budapest',
  min_notice_hours: '12',
  max_days_ahead: '60',
  slot_step_min: '30',
  invite_client: '1',
};

function getSettings() {
  const rows = db.prepare('SELECT key, value FROM settings').all();
  const s = { ...DEFAULT_SETTINGS };
  for (const r of rows) s[r.key] = r.value;
  return s;
}

function setSetting(key, value) {
  db.prepare('INSERT INTO settings(key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
    .run(key, String(value));
}

module.exports = { db, getSettings, setSetting };

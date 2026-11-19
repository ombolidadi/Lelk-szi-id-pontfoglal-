// Lelkészi csoportok segédfüggvényei.
const { db } = require('./db');

const KINDS = { gyulekezet: 'Gyülekezet', munkacsoport: 'Munkacsoport', egyeb: 'Egyéb csoport' };

function slugify(s, fallback = 'csoport') {
  return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || fallback;
}

/** Egyedi URL-azonosító: ha foglalt, számot fűz hozzá. */
function uniqueSlug(base, excludeId = 0) {
  let slug = base;
  for (let i = 2; db.prepare('SELECT 1 FROM groups WHERE slug = ? AND id != ?').get(slug, excludeId); i++) slug = `${base}-${i}`;
  return slug;
}

function findActiveGroup(slug) {
  if (!slug) return null;
  return db.prepare('SELECT * FROM groups WHERE slug = ? AND active = 1').get(String(slug)) || null;
}

function memberIds(groupId) {
  return db.prepare('SELECT user_id FROM group_members WHERE group_id = ?').all(groupId).map((r) => r.user_id);
}

function isMember(groupId, userId) {
  return Boolean(db.prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?').get(groupId, userId));
}

function groupUrl(group) {
  const base = (process.env.BASE_URL || 'http://localhost:3000').replace(/\/$/, '');
  return `${base}/csoport/${group.slug}`;
}

module.exports = { KINDS, slugify, uniqueSlug, findActiveGroup, memberIds, isMember, groupUrl };

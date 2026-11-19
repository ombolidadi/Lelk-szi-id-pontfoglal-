// Bemutató adatok betöltése üres adatbázisba: npm run demo
const { db, setSetting } = require('../src/db');
const { hashPassword } = require('../src/security');
setSetting('org_name', 'Bemutató Egyházközség');
if (db.prepare('SELECT 1 FROM users LIMIT 1').get()) {
  console.log('Az adatbázisban már vannak adatok – a bemutató adatok nem kerültek betöltésre.');
  process.exit(0);
}
const pw = hashPassword('demo1234');
const users = [
  ['Kiss Péter', 'admin@demo.hu', 'admin', 'parókus lelkész', 'Budavári Gyülekezet'],
  ['Nagy Anna', 'anna@demo.hu', 'pastor', 'beosztott lelkész', 'Budavári Gyülekezet'],
  ['Tóth Gábor', 'gabor@demo.hu', 'pastor', 'ifjúsági lelkész', 'Pesti Gyülekezet'],
];
const ids = users.map(([n, e, r, t, c]) => db.prepare('INSERT INTO users(name,email,password_hash,role,title,congregation) VALUES (?,?,?,?,?,?)').run(n, e, pw, r, t, c).lastInsertRowid);
const av = db.prepare('INSERT INTO availability(user_id,weekday,start_time,end_time) VALUES (?,?,?,?)');
ids.forEach((id, i) => { for (let d = 1; d <= 6; d++) { av.run(id, d, i === 2 ? '14:00' : '09:00', i === 2 ? '19:00' : '12:00'); if (i < 2) av.run(id, d, '14:00', '17:00'); } });
const svcs = [
  ['Lelkigondozói beszélgetés', 'lelkigondozoi-beszelgetes', 'Személyes beszélgetés lelki kérdésekről, nehézségekről.', 60, '#4f6d7a', [0, 1, 2]],
  ['Keresztelő előtti beszélgetés', 'kereszteles', 'Felkészítő beszélgetés a keresztelés előtt.', 60, '#7a6a4f', [0, 1]],
  ['Esküvői jegyesoktatás', 'jegyesoktatas', 'Felkészítő alkalom házasságkötés előtt.', 90, '#7a4f63', [0, 2]],
  ['Temetés egyeztetése', 'temetes', 'A temetési szertartás részleteinek megbeszélése.', 45, '#555555', [0, 1, 2]],
];
svcs.forEach(([n, s, d, dur, col, ps], i) => {
  const sid = db.prepare('INSERT INTO services(name,slug,description,duration_min,buffer_min,color,sort,location) VALUES (?,?,?,?,15,?,?,?)').run(n, s, d, dur, col, i, 'Lelkészi hivatal').lastInsertRowid;
  ps.forEach((p) => db.prepare('INSERT INTO pastor_services VALUES (?,?)').run(ids[p], sid));
});
const g = (n, s, k, m) => { const gid = db.prepare('INSERT INTO groups(name,slug,kind,description) VALUES (?,?,?,?)').run(n, s, k, '').lastInsertRowid; m.forEach((i) => db.prepare('INSERT INTO group_members VALUES (?,?)').run(gid, ids[i])); };
g('Budavári Gyülekezet', 'budavari-gyulekezet-x7k2q', 'gyulekezet', [0, 1]);
g('Ifjúsági munkacsoport', 'ifjusagi-munkacsoport-m4p9z', 'munkacsoport', [1, 2]);
db.prepare('INSERT INTO clients(email, name, phone, password_hash, calendar_token) VALUES (?, ?, ?, ?, ?)')
  .run('elek@demo.hu', 'Teszt Elek', '', pw, require('crypto').randomBytes(18).toString('base64url'));
console.log('Csoportlinkek: /csoport/budavari-gyulekezet-x7k2q  és  /csoport/ifjusagi-munkacsoport-m4p9z');
console.log('Foglalói fiók: elek@demo.hu / demo1234');
console.log('Bemutató adatok betöltve. Belépés: admin@demo.hu / demo1234');

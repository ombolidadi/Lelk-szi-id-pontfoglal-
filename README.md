# Lelkészi időpontfoglaló

Online időpontfoglaló alkalmazás gyülekezeteknek, **több lelkész** számára.

## Funkciók

- **Több lelkész** – mindegyiknek saját belépése, heti elérhetősége, távollétei és szolgáltatásai.
- **Google Naptár szinkronizálás lelkészenként** – minden lelkész a *saját* Google fiókját (akár többet is, pl. személyes + gyülekezeti) csatlakoztatja:
  - kiválaszthatja, **mely naptárak** foglalt időszakai zárják ki az időpontokat (több naptár is);
  - kiválaszthatja, **melyik naptárba** kerüljenek az új foglalások;
  - a foglaló opcionálisan Google Naptár-meghívót kap; lemondáskor az esemény törlődik.
- **Több szolgáltatás** (pl. lelkigondozói beszélgetés, keresztelő, jegyesoktatás, temetés) – egyedi időtartammal, szünettel, helyszínnel, színnel; szolgáltatásonként megadható, mely lelkészeknél foglalható.
- **Nyilvános foglalóoldal** – szolgáltatás → lelkész (vagy „bármelyik lelkész”) → heti nézetben szabad időpont → adatok. Mobilbarát.
- **Lemondás** linkkel a foglaló részéről, illetve a lelkészi felületről.
- Opcionális **e-mail értesítés** (SMTP) a foglalónak és a lelkésznek.
- Beállítások: minimális előfoglalási idő, maximum hány nappal előre, időpontok lépésköze, időzóna.

## Gyors indítás (helyben)

```bash
npm install
cp .env.example .env      # töltse ki legalább a SESSION_SECRET-et
npm start
```

Nyissa meg a `http://localhost:3000` címet. Az első indításkor a rendszer az **Első beállítás** oldalra visz, ahol létrehozza az adminisztrátor fiókot (néhány példa szolgáltatással). Ezután a **Lelkészek** menüben vehet fel további lelkészeket – a generált jelszót adja át nekik.

## Google Naptár beállítása (egyszer, az adminisztrátornak)

1. [Google Cloud Console](https://console.cloud.google.com/) → új projekt.
2. **APIs & Services → Library** → engedélyezze a **Google Calendar API**-t.
3. **OAuth consent screen**: típus *External* (vagy *Internal*, ha Google Workspace-en belül maradnak). Scope-ok: `calendar.readonly`, `calendar.events`, `openid`, `email`. Teszt üzemmódban adja hozzá a lelkészek Google címeit *Test users*-ként, vagy publikálja az alkalmazást.
4. **Credentials → Create credentials → OAuth client ID** → *Web application*.
   *Authorized redirect URI*: `https://AZ-ON-DOMAINJE/admin/google/callback`
5. A kapott azonosítót és titkot írja a `.env`-be: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, és állítsa be a `BASE_URL`-t a nyilvános címre.

Ezután minden lelkész a **Google naptár** menüben a saját fiókjával csatlakozik. A tokenek titkosítva (AES-256-GCM, a `SESSION_SECRET`-ből képzett kulccsal) tárolódnak – ezért a `SESSION_SECRET`-et később ne változtassa meg, különben újra kell csatlakoztatni a fiókokat.

## Telepítés online

Bármely Node.js 20+ futtatására képes szolgáltatón működik, amely **tartós lemezt** biztosít az SQLite adatbázisnak (pl. Render + Disk, Railway + Volume, Fly.io + Volume, saját VPS).

Dockerrel:

```bash
docker build -t idopontfoglalo .
docker run -d -p 3000:3000 -v idopont-data:/data --env-file .env idopontfoglalo
```

Fontos környezeti változók: `BASE_URL` (https-sel!), `SESSION_SECRET`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `DATABASE_FILE`. HTTPS mögött a sütik automatikusan `Secure` jelzést kapnak.

## Felépítés

| Fájl | Szerep |
|---|---|
| `src/server.js` | Express alkalmazás, munkamenet, CSRF |
| `src/db.js` | SQLite séma és beállítások |
| `src/google.js` | Google OAuth, naptárlista, freeBusy, esemény létrehozás/törlés |
| `src/slots.js` | Szabad időpontok számítása |
| `src/bookings.js` | Foglalás és lemondás |
| `src/routes/public.js` | Nyilvános oldalak és `/api/slots` |
| `src/routes/admin.js` | Lelkészi / adminisztrátori felület |
| `views/` | EJS sablonok |

Tesztek: `npm test`

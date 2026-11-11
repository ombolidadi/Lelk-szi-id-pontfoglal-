# Lelkészi időpontfoglaló

Online időpontfoglaló alkalmazás gyülekezeteknek, **több lelkész** számára.

## Funkciók

- **Nincs közös felület** – a főoldal semmit nem listáz. Foglalni csak egy **csoport saját linkjén** lehet (`/csoport/<azonosító>`), és csak **a csoport lelkészeihez**. A link véletlen végződést kap, így nem kitalálható.
- **Lelkészi csoportok** (gyülekezet, munkacsoport, egyéb) – egy lelkész több csoportnak is tagja lehet; gyülekezetből egy kattintással csoport készíthető.
- **Rejtett, meghívásos lelkészi felület** – nincs rá gomb vagy link a nyilvános oldalakon, a keresők nem indexelik (`robots.txt`, `noindex`). Regisztrálni csak az adminisztrátor által küldött **meghívólinkkel** lehet (7 napig érvényes, egyszer használható). Elfelejtett jelszóhoz az admin visszaállító linket készít. Van **Kijelentkezés** gomb.
- **Opcionális kétfaktoros hitelesítés** lelkészeknek és foglalóknak is – hitelesítő alkalmazással (Google/Microsoft Authenticator, Authy), 8 egyszer használható tartalékkóddal. Az admin elveszett telefon esetén kikapcsolhatja egy lelkész 2FA-ját.
- **Fiók az időpontkérőknek** (nem kötelező) – belépés után egy helyen látja a foglalásait, lemondhatja őket, és **feliratkozhat rájuk a saját naptárában** (Google, Apple, Outlook): az új foglalások és lemondások maguktól megjelennek. Fiók nélkül is lehet foglalni; ilyenkor a visszaigazoló oldalon „Hozzáadás a Google Naptárhoz” gomb van.
- **Google Naptár lelkészenként** – minden lelkész a saját Google fiókját (akár többet is) köti be: kiválasztja, mely naptárak foglalt időszakait vegye figyelembe a rendszer, és melyik naptárba kerüljenek az új foglalások.
- **Több szolgáltatás** egyedi időtartammal, szünettel, helyszínnel; szolgáltatásonként megadható, mely lelkészeknél foglalható.
- Opcionális **e-mail értesítés** (SMTP) – foglalás, lemondás, meghívó.

## Kipróbálás bemutató adatokkal

```bash
npm install
npm run demo
```

- Csoportlinkek: `http://localhost:3000/csoport/budavari-gyulekezet-x7k2q` és `http://localhost:3000/csoport/ifjusagi-munkacsoport-m4p9z`
- Lelkészi felület: `http://localhost:3000/admin/login` – `admin@demo.hu` / `demo1234` (lelkészek: `anna@demo.hu`, `gabor@demo.hu`, jelszó ugyanaz)
- Foglalói fiók: `elek@demo.hu` / `demo1234`
A bemutató adatok csak üres adatbázisba töltődnek be (a `data/app.db` törlésével újrakezdhető).

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

**Render.com (legegyszerűbb):** a repóban lévő `render.yaml` alapján a Render „New → Blueprint” menüjében a repót kiválasztva magától létrejön a szolgáltatás tartós lemezzel. Utána a Render felületén adja meg a `BASE_URL` (a kapott https-cím) és a Google-azonosítók értékét. (A tartós lemez miatt fizetős csomag kell, kb. havi 7–8 USD.)


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
| `src/groups.js` | Csoportok segédfüggvényei |
| `src/slots.js` | Szabad időpontok számítása |
| `src/bookings.js` | Foglalás és lemondás |
| `src/routes/public.js` | Nyilvános oldalak és `/api/slots` |
| `src/routes/admin.js` | Lelkészi / adminisztrátori felület |
| `views/` | EJS sablonok |

Tesztek: `npm test`

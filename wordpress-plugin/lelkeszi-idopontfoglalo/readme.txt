=== Lelkészi Időpontfoglaló ===
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.3.0
License: MIT

Időpontfoglalás több lelkésznek: csoportlinkek, meghívásos lelkészi regisztráció, kétfaktoros hitelesítés, foglalói fiók, Google Naptár.

== Leírás ==

* Nincs közös felület: foglalni csak egy csoport (gyülekezet, munkacsoport) saját, véletlen végződésű linkjén lehet, és csak a csoport lelkészeihez. A /idopont/ cím maga semmit nem listáz.
* Önálló lelkészi felület a /idopont/lelkeszi/ címen (nem a WordPress admin): saját belépő, menü, kijelentkezés. A lelkészek nem jutnak be a WordPress adminba. Lelkészként regisztrálni csak az adminisztrátor meghívólinkjével lehet.
* Opcionális kétfaktoros hitelesítés (hitelesítő alkalmazás + tartalékkódok) lelkészeknek és foglalóknak.
* Foglalói fiók: saját foglalások, lemondás, naptár-feliratkozás (Google, Apple, Outlook).
* Lelkészenként több Google fiók és naptár: foglaltság figyelése, foglalások beírása.
* A nyilvános oldalak nem kerülnek a keresőkbe (noindex).

== Telepítés ==

1. Bővítmények → Új hozzáadása → Bővítmény feltöltése → a .zip kiválasztása → Telepítés → Bekapcsolás.
2. Beállítások → Közvetlen hivatkozások: válasszon „Bejegyzés neve” szerkezetet (ajánlott, de nélküle is működik).
3. A WordPress admin „Időpontfoglaló” menüpontja a lelkészi felületre visz (/idopont/lelkeszi/). Ott: Profilom, Csoportok (első csoport és a megosztható link), Lelkészek (meghívók), Beállítások (Google Naptár).
4. A lelkészek mindig a /idopont/lelkeszi/ címen lépnek be.

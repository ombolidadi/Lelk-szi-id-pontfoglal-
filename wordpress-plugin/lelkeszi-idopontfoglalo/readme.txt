=== Lelkészi Időpontfoglaló ===
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT

Időpontfoglalás több lelkésznek: csoportlinkek, meghívásos lelkészi regisztráció, kétfaktoros hitelesítés, foglalói fiók, Google Naptár.

== Leírás ==

* Nincs közös felület: foglalni csak egy csoport (gyülekezet, munkacsoport) saját, véletlen végződésű linkjén lehet, és csak a csoport lelkészeihez. A /idopont/ cím maga semmit nem listáz.
* Lelkészi felület a WordPress adminban („Időpontfoglaló” menü). Lelkészként regisztrálni csak az adminisztrátor meghívólinkjével lehet.
* Opcionális kétfaktoros hitelesítés (hitelesítő alkalmazás + tartalékkódok) lelkészeknek és foglalóknak.
* Foglalói fiók: saját foglalások, lemondás, naptár-feliratkozás (Google, Apple, Outlook).
* Lelkészenként több Google fiók és naptár: foglaltság figyelése, foglalások beírása.
* A nyilvános oldalak nem kerülnek a keresőkbe (noindex).

== Telepítés ==

1. Bővítmények → Új hozzáadása → Bővítmény feltöltése → a .zip kiválasztása → Telepítés → Bekapcsolás.
2. Beállítások → Közvetlen hivatkozások: válasszon „Bejegyzés neve” szerkezetet (ajánlott, de nélküle is működik).
3. Időpontfoglaló → Profilom: adja meg az adatait; Időpontfoglaló → Csoportok: hozza létre az első csoportot, és ossza meg a linkjét.
4. Lelkészeket az Időpontfoglaló → Lelkészek oldalon hívhat meg.
5. Google Naptárhoz: Időpontfoglaló → Beállítások (OAuth Client ID és secret, átirányítási URI ott látható).

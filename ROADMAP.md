# Roadmap

Tento dokument shrnuje plánované úpravy aplikace podle aktuálně odsouhlasených požadavků.

## Zásady

- **Bez DB co nejdéle**: vše, co lze, půjde nejdřív udělat tak, aby to šlo testovat bez databáze. DB funkce budou až v závěrečných milnících.
- **Sekvenční tisk bez kolizí**: primární cíl je správnost výpočtu (kolize hlavy/vodících tyčí) + regresní testy.
- **Determinismus**: stejný vstup → stejný výstup (pokud výslovně nezavedeme varianty/průchody).

## Milník A – UI/UX (bez DB) – „použitelné veřejné demo“

### A1. Vizualizace
- **Fullscreen vizualizace na mobilu** *(ANO – 1.d)*:
  - tlačítko „Fullscreen“ u vizualizace (v mobilu primární),
  - možnost zavřít (X / ESC) a zachovat stav (vybraná instance, hlava on/off).
- **Mřížka 10 mm napevno** *(ANO – 1.b)*:
  - vizualizace bude vždy kreslit mřížku po 10 mm (bez přepínače).
- **Debug hlavy – čárkovaná alternativa** *(ANO – 2.c)*:
  - vykreslit „debug“ obdélník hlavy **čárkovaně** v alternativním „kolizním rohu“ podle `smer_X/smer_Y`,
  - bez dalších přepínačů (jen jednoduše vždy při zapnuté hlavě nebo jako malé „Debug“ tlačítko vedle).

### A2. Formulář a validace
- **Inline validace** *(ANO – 3.a)*:
  - chyby přímo u polí (rozsah, prázdné hodnoty, nečíselné),
  - zvýraznění chybného pole + krátká hláška.
- **„Max“ semantics** *(ANO – 3.b)*:
  - sjednotit chování tak, aby „99“ (nebo jiná hodnota) **nebyla** implicitně „max“,
  - explicitní „max“ jako volba (UI) nebo speciální hodnota (`"max"`), aby to bylo jednoznačné.

### A3. Projekty bez účtu (sdílení)
- **Share link v URL hash** *(ANO – 4.a)*:
  - zkrátit sdílení konfigurace tak, aby se necpalo do query stringu,
  - např. `/#<base64-json>` nebo `/#state=<...>`,
  - zachovat kompatibilitu se stávajícím GET (kvůli jednoduchému debugování).

## Milník B – Algoritmus + testy (bez DB) – „správnost + stabilita“

### B1. Zpřesnění kolizí hlavy a tyčí (regresní sada)
- **Regresní testy na reálné scénáře** *(ANO – 8.a, 13.a, 13.b)*:
  - přidat konkrétní případy (včetně těch odhalených vizualizací),
  - testy na různé směry tisku `smer_X/smer_Y`,
  - testy na vodící tyče (zobrazování a posuny).
- **„Bez kolize“ jako garantovaný výsledek** *(ANO – 2.a = NE pro viz. kolizní režim)*:
  - cílem je, aby vizualizace hlavy byla jen kontrolní nástroj,
  - algoritmus má zajistit, že kolize nevznikne (a když hrozí, musí změnit rozmístění nebo snížit počet).

### B2. Více průchodů (optimalizace)
- **Rotace o 90° jako druhý průchod** *(ANO – 6.a)*:
  - povolit per objekt (zap/vyp),
  - vybrat nejlepší výsledek (max počet instancí, sekundárně preference rozprostření).

## Milník C – API (bez DB jako default, DB jen pro auth pokud zapnuto)

### C1. Verze API a stabilní kontrakt
- **`/api/v1/...`** *(ANO – 7.a)*:
  - oddělit stabilní endpoint od experimentálních (`api.php` může zůstat jako legacy),
  - jednotný formát chyb a validačních hlášek.
- **Metadata ve výstupu** *(ANO – 7.b)*:
  - přidat `order/seq`, `row`, případně důvod limitu (proč víc nešlo).

### C2. Rate-limit UX
- **`Retry-After` + standardizace 429** *(ANO – 8.a)*:
  - při 429 vracet doporučený čas do dalšího okna,
  - jednotný error payload.

## Milník D – DB (až nakonec)

### D1. UI pro správu API klíčů
- **Základní stránka v UI** *(ANO – 9.a)*:
  - list/create/revoke přes existující endpointy,
  - bez potřeby externích CLI skriptů.

### D2. Uživatelské účty + projekty
- **Login/registrace + uložení projektů** *(ANO – 10.a, 10.b)*:
  - uložit vstupy (projekty),
  - verze projektu / historie.

### D3. Tiskárny v DB
- **CRUD tiskáren + schvalování** *(ANO – 11.a, 12.a)*:
  - uživatelské návrhy (neschválené),
  - admin schválení,
  - kopírování tiskárny.
- **UI pro `head_steps`** *(ANO – 14.a)*:
  - editace „schodů“ profilu hlavy (z, Xl/Xr/Yl/Yr),
  - validace monotónnosti `z` a rozsahů.

## Neplánované (explicitně zamítnuté)

- Změna legendy/obsahu instancí (gradient pořadí tisku, přepínače) *(NE – 1.a, 1.c)*.
- „Kolizní režim“ vizualizace s červenými průniky *(NE – 2.a)*.
- Další přepínače pro rohy trysky *(NE – 2.b)*.
- Další hromadné akce formuláře mimo „max“ *(NE – 3.c)*.


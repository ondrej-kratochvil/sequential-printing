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

### A2b. Název objektu
- ~~**Volitelný název objektu** (`nazev`, max 60 znaků)~~ hotovo: tabulka objektů, sdílený odkaz, export/import, tabulka pozic, vizualizace, JSON výstup UI/API/CLI (escapovaný v HTML).

### A3. Projekty bez účtu (sdílení)
- **Share link v URL hash** *(ANO – 4.a)*:
  - zkrátit sdílení konfigurace tak, aby se necpalo do query stringu,
  - např. `/#<base64-json>` nebo `/#state=<...>`,
  - zachovat kompatibilitu se stávajícím GET (kvůli jednoduchému debugování).

## Milník B – Algoritmus + testy (bez DB) – „správnost + stabilita“

### B1. Zpřesnění kolizí hlavy a tyčí (regresní sada)
- **Regresní testy na reálné scénáře** *(ANO – 8.a, 13.a, 13.b)*:
  - ~~přidat konkrétní případy (včetně těch odhalených vizualizací)~~ hotovo: `tests/run.php` (organizer, mix rozměrů, vysoké objekty, „max“, dřív kolizní 3× 50×50×100),
  - ~~testy na různé směry tisku `smer_X/smer_Y`~~ hotovo: tiskárny s Xl<Xr i Xl>Xr, Yl<Yr i Yl>Yr, u každého scénáře `kontrola_kolizi.ok === true`,
  - ~~testy na vodící tyče (posuny)~~ hotovo (výpočet i `CollisionChecker`); zbývá: test zobrazování tyčí ve vizualizaci (JS).
- **„Bez kolize“ jako garantovaný výsledek** *(ANO – 2.a = NE pro viz. kolizní režim)*:
  - ~~cílem je, aby vizualizace hlavy byla jen kontrolní nástroj~~ hotovo: nezávislá kontrola `src/CollisionChecker.php`, výsledek v `kontrola_kolizi` (UI, JSON, API, CLI),
  - ~~algoritmus má zajistit, že kolize nevznikne (a když hrozí, musí změnit rozmístění nebo snížit počet)~~ hotovo: při kolizi bezpečnější varianty výpočtu, posun kusu v ose Y, v krajním případě vynechání kusu (postup v README).

### B2. Více průchodů (optimalizace)
- ~~**Rotace o 90° jako druhý průchod** *(ANO – 6.a)*: povolit per objekt (zap/vyp), vybrat nejlepší výsledek (max počet instancí, sekundárně preference rozprostření)~~ hotovo: volba `rotace` u objektu + `povolit_rotaci_vsem`, varianty nic / vše / greedy po objektech, `pozice[].otoceno` a `otocene_objekty`, UI (checkbox, šrafovaný kus ve vizualizaci, sloupec v pozicích, sdílený odkaz, export/import), GET, CLI, API, testy.

### B2a. Více podložek
- ~~**`vice_podlozek` + `max_podlozek`**: co se nevejde na jednu podložku, pokračuje na další (každá podložka samostatně bez kolize)~~ hotovo: `pozice[].podlozka`, `poradi` v rámci podložky + `poradi_celkem`, `pocet_podlozek`, `podlozky`, limit jen nad strop (`max_podlozek`), „max“ jen na 1. podložce, UI záložky podložek, tabulka pozic, GET, CLI, API, testy.

### B2b. Volba rozprostření v ose Y
- ~~**`rozprostreni_y_rezim`**: `po_instancich` (výchozí) nebo `po_radach` (celá řada o stejný krok, místo rozdělené mezi řady)~~ hotovo: UI přepínač, GET, CLI/API `options`, testy bez kolize v obou režimech.

### B3. CLI
- ~~**Výpočet z příkazové řádky** `bin/arrange.php` (JSON ze stdin nebo ze souboru, stejný výstup jako API, exit kódy 0/1/2)~~ hotovo.

## Milník C – API (bez DB jako default, DB jen pro auth pokud zapnuto)

### C1. Verze API a stabilní kontrakt
- ~~**`/api/v1/...`** *(ANO – 7.a)*: oddělit stabilní endpoint od experimentálních (`api.php` může zůstat jako legacy), jednotný formát chyb a validačních hlášek~~ hotovo: `api/v1/arrange.php` (GET i POST), `printers.php`, `keys.php`, rozcestník `index.php`, bez nutnosti mod_rewrite (volitelný `.htaccess`); sdílená vrstva `src/Api.php`, chyby `{"error": {"code", "message", "details"}}` se statusy 400/401/403/404/405/429/500, validace po polích společná s CLI (`src/VstupVypoctu.php`).
- ~~**Úklid API v1** (rozhodnutí 8. 11. 2026): `printers.php` bez API klíče, odstranit legacy `api.php` / `api_keys.php`~~ hotovo: `printers.php` veřejný (bez klíče i DB, bez rate limitu podle IP), rozcestník ukazuje u endpointů `klic`; staré adresy vrací 410 `odstraneno` s hlavičkou `Link` na v1, odstraněn kód jen pro legacy (`odeslatLegacyChybu`, anglický text 429), README „Změny rozhraní“.
- **Metadata ve výstupu** *(ANO – 7.b)*:
  - ~~přidat `order/seq`, `row`~~ hotovo: pole `pozice` (`poradi`, `objekt`, `instance`, `rada`, `X`, `Y`) v UI JSON i API,
  - ~~důvod limitu (proč víc nešlo)~~ hotovo: pole `limit` (objekt, název, požadováno/umístěno, `duvod`, česky `popis`) v UI, JSON UI, API i CLI.

### C2. Rate-limit UX
- ~~**`Retry-After` + standardizace 429** *(ANO – 8.a)*: při 429 vracet doporučený čas do dalšího okna, jednotný error payload~~ hotovo: hlavička `Retry-After` (sekundy do dalšího minutového okna) + `details.retry_after`, u úspěšných odpovědí `X-RateLimit-Limit` / `X-RateLimit-Remaining`, testy čisté funkce i end-to-end proti lokální DB (`TEST_DB_DSN`).

### C3. Import a export 3MF (PR F)
- ~~**Import 3MF/STL**: objekty, rozměry po transformaci, počty instancí a názvy z 3MF (PrusaSlicer i komponenty), STL binární i ASCII~~ hotovo: UI tlačítko „Import 3MF/STL“ (předvyplní tabulku), `api/v1/import.php`, CLI `--import`, limity (`IMPORT_MAX_MB`, limity PHP, zip bomba), chyby ve formátu v1.
- ~~**Export 3MF**: každý kus samostatný objekt v pořadí tisku, střed na X/Y, otočení o 90°, původní sítě nebo kvádry, názvy `01 název`, per-object nastavení ze vstupu~~ hotovo: UI „Stáhnout 3MF“ (sítě přes dočasné úložiště s tokenem), `api/v1/export.php` (import + výpočet + export v jednom požadavku), CLI `--export-3mf`, více podložek = ZIP / `podlozka=N` / soubor na podložku v CLI, ověřeno nařezáním v PrusaSliceru 2.9 (pořadí v G-code).
- Zbývá: přenést per-triangle data z PrusaSliceru (malované podpěry, šev, MMU) a variabilní výšku vrstvy; `complete_objects` zatím zapíná uživatel v profilu (PrusaSlicer částečný `Slic3r_PE.config` v 3MF při řezání z CLI ignoruje).

## Milník D – DB (až nakonec)

### D1. UI pro správu API klíčů
- **Základní stránka v UI** *(ANO – 9.a)*:
  - list/create/revoke přes existující endpointy,
  - bez potřeby externích CLI skriptů.

### D2. Uživatelské účty + projekty
- ~~**Login/registrace + uložení projektů** *(ANO – 10.a, 10.b)*: uložit vstupy (projekty)~~ hotovo: `ucet.php` (registrace, přihlášení, odhlášení; session s HttpOnly/SameSite=Lax/Secure, `session_regenerate_id`, CSRF u všech POST, omezení pokusů v tabulce `login_attempts`, `REGISTRACE=0`), projekty v tabulce `projects` (Uložit projekt / Uložit změny / Uložit jako nový z lišty formuláře, Moje projekty: otevřít, přejmenovat, smazat s potvrzením), `api/v1/projects.php`, migrace `db/migrace/001_ucty_projekty.sql` + `bin/migrace.php`, `bin/admin.php`. Bez DB se účty v UI nezobrazí.
- Zbývá:
  - verze projektu / historie (zatím se projekt jen přepisuje),
  - reset zapomenutého hesla: bez odesílání e-mailů ho nejde udělat bezpečně samoobslužně; zatím admin (nové heslo přes SQL / CLI), později e-mail s jednorázovým odkazem (vyžaduje SMTP),
  - ověření e-mailu při registraci (také vyžaduje e-mail),
  - změna hesla a smazání účtu přihlášeným uživatelem,
  - uložit do projektu i nahraný model 3MF/STL (sítě).

### D0. Předvolby tiskáren bez DB
- ~~**`config/tiskarny.json`**: předvolby se zdrojem každé hodnoty, výchozí MINI jako jediný zdroj výchozí tiskárny~~ hotovo: výběr v UI, GET `tiskarna`, sdílený odkaz, export/import, CLI/API `tiskarna` nebo `printer: "<id>"`, neznámá předvolba = chyba.
- Zbývá: doplnit další tiskárny (MK4, XL, …), až budou ověřené rozměry hlavy a vodicích tyčí (měření nebo dokumentace výrobce), postup v README.

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


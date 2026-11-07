# sequential-printing

## Testy (bez Composeru)

Spuštění lokálně:

```bash
php tests/run.php
```

## Nastavení v UI (GET parametry)

- **Počet instancí**: lze zadat číslem, nebo použít `max` (např. `objekty[0][instances][d]=max`).
- **Rozprostřít instance v ose X**: dorovná zbylé místo v X v rámci řady (rovnoměrně zvětší rozestupy v řadě).
- **Název objektu** (`objekty[0][nazev]`, volitelný): krátký text (max 60 znaků, řídicí znaky se nahradí mezerou, okraje se oříznou). Projde do sdíleného odkazu, exportu/importu vstupu, tabulky pozic, vizualizace (tooltip instance) a do JSON výstupu (`pozice[].nazev`, `objekty[].nazev`, `limit[].nazev`). V HTML je vždy escapovaný. Na výpočet nemá vliv.
- **Rozprostřít instance v ose Y**: dorovná zbylé místo v Y. Způsob určuje `rozprostreni_y_rezim`:
  - `po_instancich` (výchozí, kvůli kompatibilitě) - **po instancích v pořadí tisku**: 1. instance zůstane na startu směru tisku, každá další se posune o rovnoměrný krok dál (přední řada je schodovitá),
  - `po_radach` - celá řada se posune o stejný krok, zbylé místo se rozdělí mezi řady (1. řada na startu, poslední u protějšího okraje), kusy v řadě mají stejné Y (u stejně hlubokých kusů bez posunu kvůli vodicím tyčím). Mezery mezi řadami se jen zvětší, takže kolize nepřibudou; výsledek stejně ověří kontrola kolizí.
  V UI přepínač pod volbou „Rozprostřít instance v ose Y“, v GET `rozprostreni_y_rezim=po_radach`, v CLI a API `options.rozprostreni_y_rezim`. Neznámá hodnota = `po_instancich` (CLI ji odmítne jako chybný vstup).
- **Rozprostřít po celé podložce**: zkouší měnit počet řad tak, aby se instance využily po celé ploše.
- **Tiskárna** (`tiskarna=prusa_mini`, v UI výběr „Tiskárna“ v Nastavení): předvolba z `config/tiskarny.json`, bez parametru výchozí Prusa MINI. Projde do sdíleného odkazu (jen když není výchozí) i exportu/importu. Neznámá předvolba = hláška místo výpočtu (JSON: `{"error": ...}`). Viz [Předvolby tiskáren](#předvolby-tiskáren).
- **Více podložek** (`vice_podlozek=1`, `max_podlozek=N`, v UI „Více podložek“ a „Nejvýš podložek“ v Nastavení): co se nevejde na jednu podložku, pokračuje na další. Výchozí vypnuto. Viz [Více podložek](#více-podložek).
- **Rotace o 90°** (`objekty[0][rotace]=1`, v UI checkbox „Rotace 90°“ v tabulce objektů; globálně `povolit_rotaci_vsem=1`, v UI „Povolit rotaci o 90° všem objektům“ v Nastavení): výpočet smí objekt otočit kolem osy Z o 90° (záměna rozměrů x a y), když se tak vejde víc kusů. Výchozí vypnuto (kompatibilita: bez volby je výsledek stejný jako dřív). Viz [Rotace o 90°](#rotace-o-90).

## Rotace o 90°

Objekty s povolenou rotací (`rotace: true` u objektu nebo `povolit_rotaci_vsem: true` v nastavení; čtvercový půdorys se přeskočí) výpočet zkusí i otočené o 90° kolem Z (x a y zaměněné). Všechny kombinace by u více objektů rostly exponenciálně, proto se počítá nejvýš `2 + počet povolených objektů` variant:

1. nic neotočeno (stejný výpočet jako bez rotace),
2. všechny povolené objekty otočené,
3. greedy po objektech: od lepší z variant 1 a 2 se u každého povoleného objektu (v pořadí vstupu) zkusí opačná orientace a nechá se, když je výsledek lepší.

Když se už v 1. variantě vešly všechny požadované kusy (bez `max`) bez úprav kontrolou kolizí, další varianty se nepočítají. Každá varianta prochází celým výpočtem včetně kontroly kolizí (viz níže), takže i otočený výsledek je bez kolize.

Lepší varianta = bez kolize, pak víc umístěných instancí, pak bez úprav kontrolou kolizí (méně kroků v `kontrola_kolizi.postup`), pak méně otočených objektů; při úplné shodě zůstane dřívější varianta, tedy objekt neotočený. Nastavení rozprostření (X, Y, po celé podložce) je ve všech variantách stejné jako u uživatele.

Výstup: `pozice[].otoceno` (`true` = kus je otočený, **v sliceru ho otoč o 90° kolem Z**, X/Y je pořád jeho střed), `otocene_objekty` (klíče otočených objektů) a v `objekty` u otočeného objektu `otoceno: true` a rozměry `x`/`y` tak, jak leží na podložce (už zaměněné). Výjimka: JSON z `index.php?format=json` vrací v `objekty` vstup formuláře (původní rozměry) s `rotace` a `otoceno`. UI otočený kus ve vizualizaci kreslí otočeným obdélníkem se šrafováním a značkou ↻, v tabulce pozic je sloupec „Otočeno“ a nad výsledkem řádek „Otočeno o 90°: objekt …“. U limitu je objekt označený „otočený o 90°“.

Kde se volba zadává: UI (checkbox u objektu, přepínač v Nastavení; projde do sdíleného odkazu i exportu/importu), GET (`objekty[0][rotace]=1`, `povolit_rotaci_vsem=1`), CLI a API (`objekty[].rotace`, `options.povolit_rotaci_vsem`). CLI s povolenou rotací přijme i objekt, který se na podložku vejde jen otočený.

## Více podložek

Volba `vice_podlozek` (výchozí vypnuto = jedna podložka jako dřív) a `max_podlozek` (celé číslo 1 až 20, výchozí 20; hodnota mimo rozsah se v UI a API omezí, CLI ji odmítne). Když se požadované instance nevejdou na první podložku, výpočet pokračuje další podložkou jen se zbylými kusy. Každá podložka se počítá stejnými pravidly (řady, rozprostření, rotace, kontrola kolizí) a samostatně, takže každá je bez kolize sama o sobě (tiskne se zvlášť). Končí, když se vešlo vše, když se na další podložku nevešel žádný kus, nebo na stropu `max_podlozek`.

- **„max“ jen na první podložce:** objekt s počtem `max` se umisťuje jen na 1. podložku (jinak by zaplnil všechny podložky až do stropu). Další podložky dostanou jen objekty s přesným počtem. Na 1. podložce se o místo dělí stejně jako bez volby (přednost prvního objektu, poměrné rozdělení), takže `max` objekt tam může dostat i 0 kusů.
- **1. podložka je vždy stejná jako bez volby.** Když se vše vejde na jednu podložku, je výstup s volbou i bez ní stejný.
- **Rotace** se volí pro každou podložku zvlášť: rozhoduje `pozice[].otoceno` a `podlozky[].otocene_objekty`; `otocene_objekty` je sjednocení přes podložky a `objekty` (rozměry, `otoceno`) jsou podle 1. podložky.
- **Číslování:** `pozice[].podlozka` (1..N), `poradi` = pořadí tisku v rámci podložky (na každé podložce od 1), `poradi_celkem` = pořadí přes všechny podložky (1..počet kusů). `instance` se čísluje průběžně přes podložky (na 2. podložce pokračuje za 1. podložkou). Bez volby je `podlozka` vždy 1 a `poradi_celkem = poradi`.
- **Výstup:** `pocet_podlozek`, `pocet_instanci` celkem, `podlozky` (souhrn každé podložky: `podlozka`, `pocet_instanci`, `pocet_rad`, `otocene_objekty`, `kontrola_kolizi`), `kontrola_kolizi` souhrnně (`ok` jen když jsou bez kolize všechny podložky, konflikty mají navíc `podlozka`, kroky v `postup` předponu „Podložka N:“). `positions` (`[instance, X, Y]`) jsou za sebou přes všechny podložky a podložku neříkají, u více podložek ber `pozice`.
- **`limit`** řeší jen to, co se nevešlo ani na všechny podložky: na stropu `duvod: "max_podlozek"` („umístěno 12 z 20 na 2 podložkách - další podložka by překročila strop max_podlozek (2)“), objekty vyřazené kvůli rozměru a objekty `max` bez kusu zůstávají z 1. podložky. S volbou vypnutou je `limit` jako dřív.
- **UI:** text „Na N podložky se vleze celkem … (podložka 1: …, podložka 2: …)“, ve vizualizaci záložky „Podložka 1 (6) / Podložka 2 (6) / …“ (tisková hlava a šipky ←/→ chodí po kusech vybrané podložky), v tabulce pozic sloupce Podložka a Pořadí (v rámci podložky), v poli JSON pod vizualizací `pozice` místo `positions`. Tabulky „Zbývá místa“ jsou pro 1. podložku.
- Kde se zadává: UI (Nastavení, sdílený odkaz, export/import), GET (`vice_podlozek=1`, `max_podlozek=5`), CLI a API (`options.vice_podlozek`, `options.max_podlozek`).

## Předvolby tiskáren

Předvolby jsou bez DB v souboru `config/tiskarny.json` (čte ho `src/PredvolbyTiskaren.php`). Výchozí předvolba (`"vychozi"`) je Prusa MINI a je to jediný zdroj výchozí tiskárny: `SequentialPrintCalculator::vychoziTiskarna()` ji čte z tohoto souboru (dřív konstanta `VYCHOZI_TISKARNA`). Hodnoty MINI jsou dosavadní změřené hodnoty aplikace.

Zatím je v souboru jen Prusa MINI. Pro další tiskárny (MK4, XL, …) nemám ověřené rozměry hlavy v tom tvaru, který výpočet potřebuje (asymetrická hlava Xl/Xr/Yl/Yr a výška a vzdálenost vodicích tyčí / portálu), proto tam nejsou. PrusaSlicer v profilech uvádí `extruder_clearance_radius` a `extruder_clearance_height`, to je ale válec kolem trysky a výška portálu pro kontrolu sekvenčního tisku, ne rozměry, které tu výpočet používá. Dají se z nich vzít jen jako hrubá kontrola, ne jako náhrada měření.

Jak přidat tiskárnu:

1. Do `config/tiskarny.json` do `tiskarny` přidej objekt s id (ASCII, např. `prusa_mk4s`): `nazev` (text pro výběr v UI), `parametry` a `zdroje`.
2. `parametry` mají stejné klíče jako `printer`: `x`, `y`, `z` (tisková plocha v mm), `posun_zprava` (korekce pravého okraje, jinak 0), `Xl`, `Xr`, `Yl`, `Yr` (vzdálenost trysky od levé, pravé, přední a zadní hrany hlavy v mm), `vodici_tyce_Z` (výška vodicích tyčí / portálu nad tryskou), `vodici_tyce_Y` (vzdálenost tyčí od trysky v ose Y); volitelně `head_steps` (schody profilu hlavy podle výšky). Všechny povinné hodnoty musí být čísla, jinak soubor nejde načíst.
3. Do `zdroje` napiš u každé hodnoty, odkud je (vlastní měření, odkaz na dokumentaci výrobce, profil PrusaSliceru). Test `php tests/run.php` hlídá, že žádná hodnota nemá prázdný zdroj.
4. Ověř výsledek (`php tests/run.php`, vizualizace s tiskovou hlavou) a nejlépe zkušební tisk.

Kde se volí: UI (výběr „Tiskárna“ v Nastavení, GET `tiskarna=<id>`, sdílený odkaz, export/import), CLI a API (`"tiskarna": "<id>"` v kořeni vstupu, nebo `"printer": "<id>"` místo objektu). Vlastní hodnoty v objektu `printer` přepíšou hodnoty předvolby (bez `tiskarna` přepisují výchozí MINI, jako dřív). Neznámá předvolba je chyba: CLI exit code 1, `api.php` HTTP 400 s `{"error": ...}`, `index.php?format=json` `{"error": ...}`, UI hláška místo výpočtu.

## CLI (bez DB a bez webového serveru)

```bash
php bin/arrange.php vstup.json
php bin/arrange.php < vstup.json
echo '{"objekty":[{"x":50,"y":50,"z":100,"instances":{"d":3},"nazev":"krabička"}]}' | php bin/arrange.php
```

Vstup:

```json
{
  "objekty": [{"x": 50, "y": 50, "z": 100, "instances": {"d": 3}, "nazev": "krabička"},
              {"x": 15, "y": 100, "z": 50, "instances": {"d": "max"}, "rotace": true}],
  "options": {"rozprostrit_instance_v_ose_x": false, "rozprostreni_y_rezim": "po_radach", "povolit_rotaci_vsem": false,
              "vice_podlozek": true, "max_podlozek": 5},
  "tiskarna": "prusa_mini",
  "printer": {"Xl": 36.5, "Xr": 12}
}
```

- `objekty` je povinné pole; `instances.d` je celé číslo ≥ 1 nebo `"max"` (bez `instances` = 1 kus); `nazev` je volitelný text a propíše se do `pozice`, `objekty` a `limit` ve výstupu (oříznutý na 60 znaků); `rotace` (`true`/`false`) povolí otočení o 90° (viz [Rotace o 90°](#rotace-o-90)).
- `options`, `tiskarna` a `printer` jsou volitelné, chybějící hodnoty = výchozí jako v UI (předvolba Prusa MINI z `config/tiskarny.json`: x/y/z 180, posun_zprava 1, Xr 12, Xl 36.5, Yr 15.5, Yl 15.5, vodici_tyce_Z 21, vodici_tyce_Y 17.4). `tiskarna` je id předvolby (viz [Předvolby tiskáren](#předvolby-tiskáren)); `printer` je objekt s vlastními hodnotami (přepíšou předvolbu, může mít i `head_steps`), nebo místo objektu id předvolby. `options.vice_podlozek` (`true`/`false`) a `options.max_podlozek` (1 až 20) viz [Více podložek](#více-podložek).
- Výstup (stdout, JSON): stejné klíče jako `api.php` - `positions`, `pozice`, `pocet_instanci`, `pocet_podlozek`, `objekty`, `printer`, `kontrola_kolizi`, `limit`, `otocene_objekty`, `podlozky`.
- Exit code: `0` = OK, `1` = chybný vstup, včetně neznámé předvolby tiskárny (na stdout `{"error": "..."}`, hláška i na stderr), `2` = výsledek obsahuje kolizi (`kontrola_kolizi.ok = false`; výpočet se tomu snaží zabránit, viz níže).

## Kontrola kolizí

Třída `src/CollisionChecker.php` ověřuje hotové rozmístění nezávisle na výpočtu: bere jen výsledné pozice (`pozice`), rozměry objektů a parametry tiskárny. Pro každý kus v pořadí tisku (`poradi`) kontroluje všechny dříve vytištěné kusy (ne jen předchozí řadu):

- **Hlava** - obdélník hlavy kolem trysky: `Xl` vlevo, `Xr` vpravo, `Yl` dopředu, `Yr` dozadu (při `head_steps` schod podle výšky dřívějšího kusu, stejné pravidlo „nejbližší nižší schod“ jako kalkulátor). Tryska během tisku projede celý půdorys kusu, takže hlava zasáhne obdélník `[levá hrana − Xl, pravá hrana + Xr] × [přední hrana − Yl, zadní hrana + Yr]`. Do něj nesmí zasahovat žádný dříve vytištěný kus.
- **Vodicí tyče** - jsou na pohyblivé části `vodici_tyce_Y` od trysky (za tryskou při tisku zepředu dozadu, před tryskou při tisku zezadu dopředu, stejně jako ve vizualizaci) ve výšce `vodici_tyce_Z` a vedou přes celou šířku podložky. Při tisku kusu tyč projede pás `[přední hrana ± vodici_tyce_Y, zadní hrana ± vodici_tyce_Y]`; dřívější kus vyšší než `vodici_tyce_Z` do něj nesmí zasahovat.
- **Podložka** - kus musí ležet celý na podložce (šířka už po korekci `posun_zprava`).

`presah_mm` je u hlavy menší z průniků v X a Y (o tolik by stačilo kus posunout), u tyčí průnik v ose Y. Tolerance je 0,02 mm (pozice jsou zaokrouhlené na setiny).

### Co udělá výpočet, když kontrola najde kolizi

`SequentialPrintCalculator::calculate()` kontrolu volá vždy na konci. Když je výsledek bez kolize, vrátí ho beze změny (`upraveno: false`). Jinak zkouší postupně bezpečnější varianty:

1. stejné nastavení jako uživatel,
2. stejné nastavení, ale posun řad počítaný s hlavou přes celou šířku kusu (původní výpočet bere trysku jen v „kolizním“ rohu, což bývá hustší a po rozprostření v X obvykle vyjde, ale ne vždy),
3. navíc vypnuté rozprostření v ose X,
4. vypnuté rozprostření v X i Y,
5. vypnuté i rozprostření po celé podložce.

U každé varianty projde kusy v pořadí tisku a kus, který koliduje s dříve vytištěným, **posune ve směru tisku v ose Y** přesně o potřebnou vzdálenost (hlava/tyče musí celé minout dřívější kus). Kus, který by se pak nevešel na podložku, **vynechá**. Vezme první variantu bez kolize, která udrží původní počet instancí bez vynechání; když žádná, tu bez kolize s nejvíce instancemi (při shodě tu, která nemusela nic vynechat). Co se změnilo, je v `kontrola_kolizi.postup`, původní nálezy v `puvodni_konflikty`. UI to ukáže u výsledku jedním řádkem „Kontrola kolizí: OK“ (případně s výpisem úprav nebo konfliktů).

Při zavedení kontroly se našly a opravily i chyby výpočtu: při tisku zezadu dopředu (`Yl > Yr`) se nehlídal okraj podložky (kusy vycházely mimo ni), pravidlo vodicích tyčí v řadě platilo jen mezi instancemi stejného objektu, posun řady kvůli hlavě mohl vystrčit už umístěné kusy z podložky a `instances[r]` mohl ve výstupu zůstat z dřívějšího průchodu.

## API (JSON)

> **Pozor:** UI (`index.php`) funguje i bez DB. API endpointy (`api.php`, `api_keys.php`) vyžadují DB (API klíče + rate limit), pokud explicitně nenastavíš `API_REQUIRE_KEY=0` pro lokální demo.

Lokálně (GET, stejné parametry jako UI):

```bash
curl 'http://localhost/api.php?objekty[0][x]=50&objekty[0][y]=50&objekty[0][z]=100&objekty[0][instances][d]=3'
```

Alternativně přímo `index.php`:

```bash
curl 'http://localhost/index.php?format=json&objekty[0][x]=50&objekty[0][y]=50&objekty[0][z]=100&objekty[0][instances][d]=3'
```

### Výstup

- `positions`: `[instance, X, Y]` středů instancí v pořadí tisku (zpětně kompatibilní).
- `pozice`: totéž s metadaty, aby integrace věděla, který kus je který:

```json
{"poradi": 1, "poradi_celkem": 1, "podlozka": 1, "objekt": 0, "instance": 1, "rada": 1, "X": 154.11, "Y": 22.98, "otoceno": false}
```

  `poradi` = pořadí tisku na podložce (1, 2, …), `poradi_celkem` = pořadí přes všechny podložky, `podlozka` = číslo podložky (1..`pocet_podlozek`; bez volby `vice_podlozek` vždy 1 a `poradi_celkem = poradi`, viz [Více podložek](#více-podložek)), `objekt` = klíč objektu ve vstupu `objekty`, `instance` = pořadové číslo instance objektu, `rada` = řada na podložce, `X`/`Y` = střed v mm, `nazev` = název objektu (jen když je zadaný), `otoceno` = kus je otočený o 90° kolem Z (v sliceru ho otoč, viz [Rotace o 90°](#rotace-o-90)).
  Při tisku po objektech dej v sliceru každý kus jako samostatný objekt v pořadí `poradi` (PrusaSlicer pořadí seznamu dodrží), u více podložek každou podložku jako samostatný projekt.
- `pocet_podlozek` a `podlozky`: počet podložek a souhrn každé z nich (`podlozka`, `pocet_instanci`, `pocet_rad`, `otocene_objekty`, `kontrola_kolizi`). Bez volby `vice_podlozek` je podložka jedna.
- `kontrola_kolizi`: výsledek nezávislé kontroly kolizí (viz [Kontrola kolizí](#kontrola-kolizí)):

```json
{"ok": true, "pocet_kusu": 3, "konflikty": [], "upraveno": true,
 "postup": ["Kus (objekt 1, instance 3) posunut v ose Y o 32.6 mm."],
 "puvodni_konflikty": [{"typ": "hlava", "poradi": 3, "objekt": 0, "instance": 3, "s_poradi": 2, "s_objekt": 0, "s_instance": 2, "presah_mm": 24.5, "popis": "Hlava při tisku kusu #3 … zasáhne do kusu #2 … o 24.5 mm."}]}
```

  `konflikty` / `puvodni_konflikty`: `typ` = `hlava` | `tyce` | `podlozka`, `poradi` = kolidující kus, `s_poradi` = dříve vytištěný kus, do kterého zasahuje, `presah_mm` = o kolik mm. `puvodni_konflikty` je jen u `upraveno: true` (co kontrola našla před úpravou rozmístění).

- `limit`: proč se nevešly všechny požadované instance (prázdné pole = vešlo se vše). Jedna položka na objekt; objekt s počtem `"max"` jen když se nevešel ani jeden kus:

```json
{"objekt": 0, "nazev": "dlaždice", "pozadovano": 20, "umisteno": 6, "duvod": "misto_y",
 "popis": "Objekt 1 (dlaždice): umístěno 6 z 20 - další řada se nevejde v ose Y (řady jsou v ose X plné)."}
```

  `duvod`:
  - `misto_y` - řady jsou v ose X plné a další řada se nevejde v ose Y (čistě v ose X se výpočet nezastaví, nový kus vždy začne další řadu),
  - `hlava` - tisková hlava by zasáhla už vytištěné kusy a po odsunutí by se kus nevešel na podložku,
  - `tyce` - totéž kvůli vodicím tyčím nad vyšším kusem,
  - `kolize` - kus vynechala kontrola kolizí (viz [Kontrola kolizí](#kontrola-kolizí)),
  - `rozmer` - rozměr objektu je větší než tisková plocha (objekt se vůbec nepočítá),
  - `sdilene_misto` - zbylé místo dostaly ostatní objekty (přednost prvního objektu, když se nevešel jeho přesný počet, nebo poměrné rozdělení podle požadovaných počtů),
  - `max_podlozek` - jen s volbou `vice_podlozek`: zbylé kusy by potřebovaly další podložku nad strop `max_podlozek`.

  UI ukáže totéž pod počtem instancí jako seznam „Nevešlo se vše“.

Výchozí tiskárna (Prusa MINI) a výchozí nastavení jsou na jediném místě: předvolba v `config/tiskarny.json` (čte ji `SequentialPrintCalculator::vychoziTiskarna()`) a `SequentialPrintCalculator::VYCHOZI_NASTAVENI`. Berou je UI, API i CLI; chybějící hodnoty v `printer`/`options` se doplní z nich. Jinou předvolbu vybere `"tiskarna": "<id>"` (POST) nebo `tiskarna=<id>` (GET).

POST `application/json`:

```bash
curl -X POST 'http://localhost/api.php' \
  -H 'Content-Type: application/json' \
  -d '{"objekty":[{"x":50,"y":50,"z":100,"instances":{"d":3}}]}'
```

### Autentizace (API klíče) + rate limit

`api.php` vyžaduje API klíč:
- `Authorization: Bearer <api_key>` (doporučeno), nebo
- `X-Api-Key: <api_key>`

Konfigurace DB přes env proměnné:
- `DB_DSN` (např. `mysql:host=127.0.0.1;dbname=sekvencni_tisk;charset=utf8mb4`)
- `DB_USER`
- `DB_PASS`

Vypnutí požadavku na klíč (jen pro lokální demo): `API_REQUIRE_KEY=0`

Vytvoření uživatele a klíče (CLI):

```bash
php bin/create_user.php user@example.com 'heslo'
php bin/create_api_key.php 1 'muj klic' 60
```

### Endpointy pro správu API klíčů (JSON)

Seznam klíčů (bez plaintext):

```bash
curl 'http://localhost/api_keys.php' \
  -H 'Authorization: Bearer <api_key>'
```

Vytvoření nového klíče (plaintext se vrátí jen jednou):

```bash
curl -X POST 'http://localhost/api_keys.php' \
  -H 'Authorization: Bearer <api_key>' \
  -H 'Content-Type: application/json' \
  -d '{"name":"integrace A","rate_limit_per_min":60}'
```

Revokace klíče:

```bash
curl -X DELETE 'http://localhost/api_keys.php?id=123' \
  -H 'Authorization: Bearer <api_key>'
```

## DB schéma (MariaDB)

SQL je v `db/schema.sql` (uživatelé, API klíče, tiskárny + schody profilu hlavy).

Volitelný seed je v `db/seed.sql`.
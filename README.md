# sequential-printing

## Testy (bez Composeru)

Spuštění lokálně:

```bash
php tests/run.php
```

Testy nepotřebují DB (API v1 běží s `API_REQUIRE_KEY=0`). Testy 3MF potřebují rozšíření zip; když ho výchozí php.ini nenačítá (WAMP), spusť `php -d extension=zip tests/run.php` (bez něj se 3MF přeskočí a ověří se jen STL a chyba `chybi_zip`). Je-li nainstalovaný PrusaSlicer (`C:\Program Files\Prusa3D\PrusaSlicer\prusa-slicer-console.exe`, jinak env `PRUSASLICER_CLI`) s profily MINI, test export nařeže a zkontroluje pořadí a počet objektů v G-code; `TEST_BEZ_PRUSASLICERU=1` to vypne. Klíč, `X-RateLimit-*` a 429 s `Retry-After` end-to-end se otestují jen proti **lokální** testovací DB ze `db/schema.sql` (test si založí uživatele a klíče a po sobě je smaže):

```bash
TEST_DB_DSN='mysql:host=127.0.0.1;port=3307;dbname=sekvencni_tisk_test;charset=utf8mb4' TEST_DB_USER=root TEST_DB_PASS= php tests/run.php
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
- **Import 3MF/STL a Stáhnout 3MF**: tlačítko „Import 3MF/STL“ pod tabulkou objektů předvyplní objekty ze souboru, tlačítko „Stáhnout 3MF“ u výsledku stáhne projekt pro PrusaSlicer. Viz [Import a export 3MF](#import-a-export-3mf).

## Import a export 3MF

Aplikace umí načíst objekty z 3MF (PrusaSlicer i jiné slicery) nebo STL a výsledek rozmístění vrátit jako 3MF, ve kterém je **každý kus samostatný objekt v pořadí tisku**. PrusaSlicer při tisku po objektech tiskne v pořadí seznamu objektů (ověřeno v G-code), takže stačí 3MF otevřít a nařezat.

**V PrusaSliceru zapni tisk po objektech:** Print Settings → Output options → Sequential printing → „Complete individual objects“ (`complete_objects = 1`), z příkazové řádky `--complete-objects`; s `--dont-arrange` (v GUI nespouštěj Uspořádat) zůstanou kusy tam, kam je dal výpočet. Do 3MF se tahle volba nezapisuje: PrusaSlicer částečný `Metadata/Slic3r_PE.config` při řezání z CLI ignoruje (ověřeno, `complete_objects` zůstalo 0) a v GUI by projekt s neúplnou konfigurací přepsal tiskárnu a profil. PrusaSlicer navíc sám kontroluje kolize (vlastním, hrubším modelem hlavy) a kus, který byť o 0,001 mm vyčnívá z podložky, tiše vynechá; export proto kus vyčnívající jen o zaokrouhlení pozic (nejvýš 0,05 mm) posune dovnitř.

### Import

- **3MF**: každý `<object>`, na který odkazuje položka `<build><item>`, je jeden objekt. Počet instancí = počet položek se stejným objektem. Rozměry x/y/z = ohraničení sítě po použití rotace a měřítka z `transform` první položky (posun se nepočítá), jednotky modelu (`unit`) se převedou na mm. Komponenty (`<components>`, i vnořené a s `p:path` do jiného souboru balíčku jako u Bambu/Orca) se spojí do jedné sítě. Název z `Metadata/Slic3r_PE_model.config` (`<metadata type="object" key="name">`), jinak z atributu `name`, jinak „název souboru + pořadí“. Mají-li instance různé natočení, rozměry jsou podle první a import vrátí varování.
- **STL** (binární i ASCII, typ se pozná podle obsahu): jeden objekt, rozměry z ohraničení, název z názvu souboru, 1 instance.
- Rozměry se zaokrouhlí na 2 desetinná místa **nahoru** (zaokrouhlení dolů by kus zmenšilo a mohlo vést ke kolizi), `sit` = index objektu v modelu (odkaz na síť pro export).
- **Limity**: soubor nejvýš `IMPORT_MAX_MB` (env, výchozí 50 MB) a zároveň `upload_max_filesize` / `post_max_size` v PHP (WAMP má výchozí 2 MB / 8 MB, překročení je srozumitelná chyba 413). Soubor uvnitř 3MF po rozbalení nejvýš `IMPORT_MAX_ROZBALENO_MB` (výchozí 500 MB, ochrana proti zip bombě). XML se čte proudově (XMLReader, bez externích entit), síť je v paměti jako binární řetězec (12 B na vrchol a trojúhelník).
- **3MF potřebuje PHP rozšíření zip** (`ZipArchive`, v php.ini `extension=zip`). Bez něj import 3MF i export vrátí chybu 500 `chybi_zip` s vysvětlením; import STL funguje i bez něj.

### Export

- Každý kus = samostatný `<object>` (síť se u každého kusu opakuje) v pořadí `poradi`; položka `<item transform>` posune kus tak, aby **střed ohraničení sítě** byl na `X`/`Y` z `pozice` (síť nemusí mít počátek ve středu) a spodek na z = 0. Otočený kus (`otoceno`) se otočí o 90° kolem Z. Rotace a měřítko z importovaného 3MF zůstanou v transformu položky.
- Síť: importovaná (podle `sit` u objektu), jinak kvádr zadaných rozměrů. Síť větší než zadané rozměry (o víc než zaokrouhlení) je chyba vstupu u daného rozměru, protože výpočet by s ní nebyl bez kolize.
- `Metadata/Slic3r_PE_model.config`: název objektu `01 název` (pořadí doplněné nulami podle počtu kusů; název z formuláře, jinak z modelu, jinak „objekt N“) a per-object metadata ze vstupu (např. `bottom_solid_layers`, `extruder`), takže per-object nastavení zůstanou. Části objektu (volume: modifikátory, `matrix`, `volume_type`) se přenesou, když vstup je 3MF z PrusaSliceru bez komponent. Nepřenáší se: malované podpěry, šev a MMU (atributy trojúhelníků), variabilní výška vrstvy, rozsahy výšek a celé nastavení tisku (`Slic3r_PE.config`).
- **Více podložek**: jeden 3MF na podložku (pořadí na každé od 01). V UI a API bez volby podložky ZIP `sekvencni-tisk.zip` se soubory `sekvencni-tisk_podlozka_N.3mf`, s `podlozka=N` jen 3MF podložky N; v CLI rovnou soubory `vystup_podlozka_N.3mf` (s `--podlozka N` jen jeden). Zvolil jsem ZIP místo jednoho 3MF s více podložkami, protože formát podložek v 3MF má každý slicer jiný a PrusaSlicer ho nemá.

### Kde se používá

- **UI**: „Import 3MF/STL“ nahraje soubor (`index.php?akce=import_modelu`, POST multipart) a předvyplní tabulku objektů (rozměry, název, počet). Soubor se uloží do dočasné složky pod náhodný token (`sit_token` ve formuláři, 32 hex znaků z `random_bytes`), řádky nesou skrytý odkaz `objekty[i][sit]`. „Stáhnout 3MF“ (`index.php?…&format=3mf`) pak použije sítě nahraného modelu; řádek bez sítě (přidaný ručně) je kvádr, „Bez sítí“ odkaz na model zahodí. Složka `IMPORT_TMP_DIR` (výchozí `<temp>/sekvencni_tisk_modely`), soubory starší než `IMPORT_TTL_HODIN` (výchozí 24) se mažou při každém importu; prošlý token = chyba 410 `model_vyprsel`. Token v URL je zároveň oprávnění ke staženému modelu (uhodnout nejde), do sdíleného odkazu (`#s=`) ani exportu vstupu se nedává.
- **API v1** (stejné klíče a rate limit jako ostatní endpointy):

```bash
curl -F 'soubor=@model.3mf' 'http://localhost/api/v1/import.php' -H 'X-Api-Key: <api_key>'
curl --data-binary @model.stl 'http://localhost/api/v1/import.php?nazev=model.stl' -H 'X-Api-Key: <api_key>'
# import + výpočet + export v jednom požadavku (nic se na serveru neukládá)
curl -F 'soubor=@model.3mf' -F 'vstup={"options":{"vice_podlozek":true}}' 'http://localhost/api/v1/export.php' -H 'X-Api-Key: <api_key>' -o vystup.zip
curl -X POST 'http://localhost/api/v1/export.php?podlozka=1' -H 'Content-Type: application/json' -d '{"objekty":[{"x":30,"y":20,"z":10,"instances":{"d":3}}]}' -H 'X-Api-Key: <api_key>' -o vystup.3mf
```

  `import.php` vrací `{"objekty": [{x, y, z, instances, nazev, sit}], "soubor": {nazev, typ}, "modely": [{sit, nazev, pocet_instanci, rozmery (přesně), pocet_vrcholu}], "varovani": []}`; `objekty` jdou rovnou do `arrange.php` / `export.php`. `export.php` přijme JSON jako `arrange.php` (kvádry), GET se stejnými parametry, nebo multipart `soubor` + volitelný `vstup` (JSON; bez `objekty` se vezmou z modelu, s `objekty` na sítě odkazuje `sit`). Odpověď `model/3mf` nebo `application/zip` s hlavičkami `X-Pocet-Kusu`, `X-Pocet-Podlozek`, `X-Kontrola-Kolizi` (`ok` / `kolize`).
- **CLI**: `php bin/arrange.php --import model.3mf` vypíše výsledek importu (vstup k úpravě), `php bin/arrange.php [vstup.json] --import model.3mf --export-3mf vystup.3mf [--podlozka N]` spočítá a zapíše 3MF (na stdout jde výsledek výpočtu jako bez exportu, zapsané soubory na stderr). Bez `--import` exportuje kvádry.

Chyby (formát v1, viz [Formát chyb](#formát-chyb)): 400 `chybi_soubor`, `neplatny_soubor` (`details.duvod`), `chyba_nahrani`, `nic_k_exportu`, `neplatny_vstup` (`objekty[i].sit`, rozměr menší než síť, `podlozka`); 413 `soubor_prilis_velky`; 415 `nepodporovany_soubor`; 410 `model_vyprsel` (jen UI); 500 `chybi_zip`.

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

Kde se volí: UI (výběr „Tiskárna“ v Nastavení, GET `tiskarna=<id>`, sdílený odkaz, export/import), CLI a API (`"tiskarna": "<id>"` v kořeni vstupu, nebo `"printer": "<id>"` místo objektu). Vlastní hodnoty v objektu `printer` přepíšou hodnoty předvolby (bez `tiskarna` přepisují výchozí MINI, jako dřív). Neznámá předvolba je chyba: CLI exit code 1, API v1 HTTP 404 `neznama_tiskarna` (viz [Formát chyb](#formát-chyb)), legacy `api.php` HTTP 400 s `{"error": ...}`, `index.php?format=json` `{"error": ...}`, UI hláška místo výpočtu. Seznam předvoleb vrací `GET /api/v1/printers.php`.

Rozměry objektu v tabulce UI mají mez (`max`) podle vybrané tiskárny: x = šířka plochy po korekci `posun_zprava`, y a z = plocha (u Prusa MINI 179 / 180 / 180 mm), s povolenou rotací (u objektu nebo všem) x i y do většího z rozměrů. Mez se přepočítá při změně tiskárny nebo rotace a hlídá ji inline validace (JS). Na serveru stejné meze bere validace vstupu CLI a API v1 (`src/VstupVypoctu.php`, rozměr nad mez = chyba 400 u pole); UI a legacy `api.php` objekt nad mez nespočítají a ukážou ho v `limit` s důvodem `rozmer`.

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
- Exit code: `0` = OK, `1` = chybný vstup, včetně neznámé předvolby tiskárny (na stdout `{"error": "...", "chyby": [...]}`, hlášky i na stderr), `2` = výsledek obsahuje kolizi (`kontrola_kolizi.ok = false`; výpočet se tomu snaží zabránit, viz níže).
- Validace vstupu je stejná jako v API v1 (`src/VstupVypoctu.php`): najde všechny chyby najednou. `error` je text se všemi zprávami oddělenými `; ` (jako dřív, jen může být zpráv víc), `chyby` je seznam `{"pole": "objekty[0].x", "zprava": "..."}` stejný jako `details.chyby` v API v1 (u neznámé předvolby chybí).

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

> **Pozor:** UI (`index.php`) funguje i bez DB. API endpointy (`api/v1/*.php`, legacy `api.php`, `api_keys.php`) vyžadují DB (API klíče + rate limit), pokud explicitně nenastavíš `API_REQUIRE_KEY=0` pro lokální demo. Správa klíčů vyžaduje DB a platný klíč vždy.

### API v1 (stabilní)

Stabilní kontrakt jsou fyzické soubory v `api/v1/`, takže fungují i bez mod_rewrite (WAMP, Český hosting). Volitelný `api/v1/.htaccess` přidá hezké URL (`/api/v1/arrange` místo `/api/v1/arrange.php`) a předá hlavičku `Authorization` do PHP i u CGI; podmínkou není.

| Endpoint | Metody | Co dělá |
|---|---|---|
| `api/v1/arrange.php` | GET, POST | výpočet rozmístění; POST = JSON tělo jako CLI, GET = stejné parametry jako UI a `api.php` |
| `api/v1/import.php` | POST | import 3MF/STL (multipart `soubor` nebo tělo): objekty pro vstup, viz [Import a export 3MF](#import-a-export-3mf) |
| `api/v1/export.php` | GET, POST | výpočet + 3MF ke stažení (JSON jako `arrange`, nebo multipart s modelem), `?podlozka=N` |
| `api/v1/printers.php` | GET | předvolby tiskáren (`id`, `nazev`, `vychozi`, `parametry`, `plocha`, `zdroje`); `?id=<id>` jedna předvolba |
| `api/v1/keys.php` | GET, POST, DELETE | správa API klíčů (seznam, nový klíč, `?id=` zneplatnění) |
| `api/v1/` (`index.php`) | GET | verze a seznam endpointů |

```bash
curl -X POST 'http://localhost/api/v1/arrange.php' \
  -H 'Authorization: Bearer <api_key>' \
  -H 'Content-Type: application/json' \
  -d '{"objekty":[{"x":50,"y":50,"z":100,"instances":{"d":3}}],"tiskarna":"prusa_mini","options":{"vice_podlozek":true}}'
curl -g 'http://localhost/api/v1/arrange.php?objekty[0][x]=50&objekty[0][y]=50&objekty[0][z]=100&objekty[0][instances][d]=3&options[vice_podlozek]=1' \
  -H 'X-Api-Key: <api_key>'
curl 'http://localhost/api/v1/printers.php' -H 'X-Api-Key: <api_key>'
```

- **Výstup `arrange` (200)** má stejné klíče jako legacy `api.php` a CLI (`SequentialPrintCalculator::vystupApi()`, viz [Výstup](#výstup)).
- **Validace** je společná s CLI (`src/VstupVypoctu.php`): u POST přísné typy (pravdivostní volby `true`/`false` nebo `0`/`1`, `max_podlozek` celé číslo 1 až 20, `instances.d` celé číslo ≥ 1 nebo `"max"`, rozměry kladné a nejvýš tisková plocha tiskárny, s rotací stačí vejít se otočený). U GET jsou hodnoty texty, takže volby přijmou i `"1"`/`"0"`/`"true"`/`"false"`/prázdné a čísla jako text; prázdné `tiskarna` = výchozí. Na rozdíl od UI a legacy `api.php` v1 objekt nad tiskovou plochu odmítne (400), místo aby ho dal do `limit`.
- **Správa klíčů** (`keys.php`) má stejné operace a výstup jako legacy `api_keys.php` (viz níže), chyby ve formátu v1: 403 `vyzaduje_klic` (bez klíče, i při `API_REQUIRE_KEY=0`), 400 `neplatny_vstup` (pole `name`, `rate_limit_per_min`, `id`), 404 `klic_nenalezen`.

Kód je na jednom místě: `src/Api.php` (vstup, ověření klíče, odpověď), `src/ApiChyba.php` (formát chyb), `src/VstupVypoctu.php` (validace pro v1 i CLI), `src/SpravaKlicu.php` (klíče pro v1 i legacy). Endpointy jsou jen tenké obaly.

### Formát chyb

API v1 vrací každou chybu stejně, s odpovídajícím HTTP statusem:

```json
{"error": {"code": "neplatny_vstup", "message": "Vstup obsahuje chyby (2).",
           "details": {"chyby": [{"pole": "objekty[0].x", "zprava": "objekt 1: 'x' musí být kladné číslo."},
                                 {"pole": "options.max_podlozek", "zprava": "'options.max_podlozek' musí být celé číslo 1 až 20."}]}}}
```

`code` je strojový kód (na ten se má integrace vázat), `message` česky pro člověka, `details` je vždy objekt (může být `{}`).

| Status | `code` | Kdy | `details` |
|---|---|---|---|
| 400 | `neplatny_json` | POST tělo není JSON objekt | `json_chyba` |
| 400 | `neplatny_vstup` | chyby vstupu (všechny najednou) | `chyby`: `[{pole, zprava}]`, pole jako `objekty[1].instances.d` (index od 0) |
| 401 | `chybi_klic`, `neplatny_klic`, `zneplatneny_klic` | chybí / neznámý / zneplatněný API klíč | |
| 403 | `vyzaduje_klic` | správa klíčů bez platného klíče | |
| 404 | `neznama_tiskarna` | neznámá předvolba (`tiskarna`, `printer`, `printers.php?id=`) | `tiskarna`, `dostupne` |
| 404 | `neznamy_endpoint` | neznámá cesta pod `/api/v1/` (jen s `.htaccess`) | `endpoint`, `dostupne` |
| 404 | `klic_nenalezen` | zneplatnění neexistujícího nebo už zneplatněného klíče | `id` |
| 405 | `nepovolena_metoda` | metoda, kterou endpoint nepodporuje (+ hlavička `Allow`) | `metoda`, `povolene` |
| 429 | `prekrocen_limit` | překročen limit klíče (+ hlavička `Retry-After`) | `retry_after`, `limit` |
| 500 | `interni_chyba`, `chyba_overeni` | chyba serveru / DB při ověření klíče (bez podrobností) | |

Neznámá předvolba má přednost před ostatními chybami vstupu (bez tiskárny nejde ověřit rozměry).

### Rate limit

Každý klíč má `rate_limit_per_min` (0 = bez limitu). Počítá se v minutových oknech v tabulce `api_key_rate_limits` (okno = unix čas zaokrouhlený dolů na minutu, `ApiAuth::zacatekOkna()`), požadavek nad limit vrátí 429.

- **429**: hlavička `Retry-After` = sekundy do začátku dalšího okna (1 až 60, `ApiAuth::sekundDoDalsihoOkna()`), stejná hodnota v `details.retry_after`.
- **Úspěšné odpovědi** klíče s limitem nesou `X-RateLimit-Limit` (limit za minutu) a `X-RateLimit-Remaining` (kolik požadavků v aktuálním okně zbývá). Bez klíče (`API_REQUIRE_KEY=0`) nebo s limitem 0 tyto hlavičky nejsou.
- Hlavičky `Retry-After` a `X-RateLimit-*` posílají i legacy endpointy (tělo mají beze změny).

### Legacy endpointy (zastaralé)

> **Zastaralé:** `api.php` a `api_keys.php` zůstávají kvůli stávajícím klientům se stejným chováním a výstupem jako dřív, nové integrace mají používat `/api/v1/`. Jsou to tenké obaly nad stejným kódem (`src/Api.php`, `src/SpravaKlicu.php`).

Formát chyb legacy endpointů se **nemění**: dál vrací `{"error": "<text>"}` (klient, který čte `error` jako text, by se s objektem rozbil) se stejnými texty i statusy (429 dál „Rate limit exceeded.“). Beze změny zůstává i volnější chování `api.php`: neplatný JSON v POST = použije se GET, objekt nad tiskovou plochu = `limit` s důvodem `rozmer`, neznámá předvolba = 400. Přibyly jen hlavičky `Retry-After` a `X-RateLimit-*`.

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
  Při tisku po objektech dej v sliceru každý kus jako samostatný objekt v pořadí `poradi` (PrusaSlicer pořadí seznamu dodrží), u více podložek každou podložku jako samostatný projekt. Hotový projekt takhle vyrobí export 3MF, viz [Import a export 3MF](#import-a-export-3mf).
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

API v1 i `api.php` vyžadují API klíč:
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
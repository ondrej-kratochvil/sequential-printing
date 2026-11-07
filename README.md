# sequential-printing

## Testy (bez Composeru)

Spuštění lokálně:

```bash
php tests/run.php
```

## Nastavení v UI (GET parametry)

- **Počet instancí**: lze zadat číslem, nebo použít `max` (např. `objekty[0][instances][d]=max`).
- **Rozprostřít instance v ose X**: dorovná zbylé místo v X v rámci řady (rovnoměrně zvětší rozestupy v řadě).
- **Rozprostřít instance v ose Y**: dorovná zbylé místo v Y **po instancích v pořadí tisku** – 1. instance zůstane na startu směru tisku, další instance se posouvají o rovnoměrný krok.
- **Rozprostřít po celé podložce**: zkouší měnit počet řad tak, aby se instance využily po celé ploše.

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
              {"x": 20, "y": 20, "z": 10, "instances": {"d": "max"}}],
  "options": {"rozprostrit_instance_v_ose_x": false},
  "printer": {"Xl": 36.5, "Xr": 12}
}
```

- `objekty` je povinné pole; `instances.d` je celé číslo ≥ 1 nebo `"max"` (bez `instances` = 1 kus); `nazev` je volitelný a propíše se do `pozice` a `objekty` ve výstupu.
- `options` a `printer` jsou volitelné, chybějící hodnoty = výchozí jako v UI (Prusa MINI: x/y/z 180, posun_zprava 1, Xr 12, Xl 36.5, Yr 15.5, Yl 15.5, vodici_tyce_Z 21, vodici_tyce_Y 17.4). `printer` může mít i `head_steps`.
- Výstup (stdout, JSON): stejné klíče jako `api.php` - `positions`, `pozice`, `pocet_instanci`, `pocet_podlozek`, `objekty`, `printer`, `kontrola_kolizi`.
- Exit code: `0` = OK, `1` = chybný vstup (na stdout `{"error": "..."}`, hláška i na stderr), `2` = výsledek obsahuje kolizi (`kontrola_kolizi.ok = false`; výpočet se tomu snaží zabránit, viz níže).

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
{"poradi": 1, "objekt": 0, "instance": 1, "rada": 1, "X": 154.11, "Y": 22.98}
```

  `poradi` = pořadí tisku (1, 2, …), `objekt` = klíč objektu ve vstupu `objekty`, `instance` = pořadové číslo instance objektu, `rada` = řada na podložce, `X`/`Y` = střed v mm.
  Při tisku po objektech dej v sliceru každý kus jako samostatný objekt v pořadí `poradi` (PrusaSlicer pořadí seznamu dodrží).
- `kontrola_kolizi`: výsledek nezávislé kontroly kolizí (viz [Kontrola kolizí](#kontrola-kolizí)):

```json
{"ok": true, "pocet_kusu": 3, "konflikty": [], "upraveno": true,
 "postup": ["Kus (objekt 1, instance 3) posunut v ose Y o 32.6 mm."],
 "puvodni_konflikty": [{"typ": "hlava", "poradi": 3, "objekt": 0, "instance": 3, "s_poradi": 2, "s_objekt": 0, "s_instance": 2, "presah_mm": 24.5, "popis": "Hlava při tisku kusu #3 … zasáhne do kusu #2 … o 24.5 mm."}]}
```

  `konflikty` / `puvodni_konflikty`: `typ` = `hlava` | `tyce` | `podlozka`, `poradi` = kolidující kus, `s_poradi` = dříve vytištěný kus, do kterého zasahuje, `presah_mm` = o kolik mm. `puvodni_konflikty` je jen u `upraveno: true` (co kontrola našla před úpravou rozmístění).

Výchozí tiskárna (Prusa MINI) a výchozí nastavení jsou na jediném místě: `SequentialPrintCalculator::VYCHOZI_TISKARNA` a `VYCHOZI_NASTAVENI`. Berou je UI, API i CLI; chybějící hodnoty v `printer`/`options` se doplní z nich.

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
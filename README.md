# sequential-printing

## Testy (bez Composeru)

Spuštění lokálně:

```bash
php tests/run.php
```

## Nastavení v UI (GET parametry)

- **Rozprostřít instance v ose X**: dorovná zbylé místo v X v rámci řady (rovnoměrně zvětší rozestupy v řadě).
- **Rozprostřít instance v ose Y**: dorovná zbylé místo v Y **po instancích v pořadí tisku** – 1. instance zůstane na startu směru tisku, další instance se posouvají o rovnoměrný krok.
- **Rozprostřít po celé podložce**: zkouší měnit počet řad tak, aby se instance využily po celé ploše.

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
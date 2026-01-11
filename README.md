# sequential-printing

## Testy (bez Composeru)

Spuštění lokálně:

```bash
php tests/run.php
```

## API (JSON)

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

## DB schéma (MariaDB)

SQL je v `db/schema.sql` (uživatelé, API klíče, tiskárny + schody profilu hlavy).

Volitelný seed je v `db/seed.sql`.
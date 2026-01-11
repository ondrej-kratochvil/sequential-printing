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

POST `application/json`:

```bash
curl -X POST 'http://localhost/api.php' \
  -H 'Content-Type: application/json' \
  -d '{"objekty":[{"x":50,"y":50,"z":100,"instances":{"d":3}}]}'
```
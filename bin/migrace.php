<?php
/**
 * CLI: spustí migrace DB (db/migrace/*.sql popořadě podle názvu).
 *
 * Použití:
 *   php bin/migrace.php            # spustí všechny migrace (jsou idempotentní, jde pouštět opakovaně)
 *   php bin/migrace.php --vypis    # jen vypíše soubory, nic nespustí
 *
 * DB podle env DB_DSN, DB_USER, DB_PASS (stejně jako aplikace). Nová instalace: db/schema.sql obsahuje
 * i tabulky z migrací, migrace pak nic nezmění. Existující DB: stačí spustit tenhle skript.
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

require_once __DIR__ . '/../src/Db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Rozdělí SQL soubor na příkazy (středník na konci řádku). Řádkové komentáře `--` se zahodí;
 * středník uvnitř řetězce na konci řádku migrace nepoužívají.
 *
 * @return string[]
 */
function prikazyMigrace(string $sql): array
{
    $prikazy = [];
    $aktualni = '';
    foreach (preg_split('/\R/', $sql) as $radek) {
        $bezKomentare = preg_replace('/\s--\s.*$|^--.*$/', '', $radek);
        if (trim($bezKomentare) === '') continue;
        $aktualni .= $bezKomentare . "\n";
        if (preg_match('/;\s*$/', $bezKomentare)) {
            $prikazy[] = trim(preg_replace('/;\s*$/', '', trim($aktualni)));
            $aktualni = '';
        }
    }
    if (trim($aktualni) !== '') $prikazy[] = trim($aktualni);
    return $prikazy;
}

$soubory = glob(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrace' . DIRECTORY_SEPARATOR . '*.sql') ?: [];
sort($soubory, SORT_STRING);

if (in_array('--vypis', $argv, true)) {
    foreach ($soubory as $soubor) fwrite(STDOUT, basename($soubor) . "\n");
    exit(0);
}

try {
    $pdo = Db::pdo();
} catch (Throwable $e) {
    fwrite(STDERR, 'Nelze se připojit k DB: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($soubory as $soubor) {
    $prikazy = prikazyMigrace((string)file_get_contents($soubor));
    try {
        foreach ($prikazy as $prikaz) $pdo->exec($prikaz);
    } catch (Throwable $e) {
        fwrite(STDERR, basename($soubor) . ': chyba: ' . $e->getMessage() . "\n");
        exit(1);
    }
    fwrite(STDOUT, basename($soubor) . ': OK (' . count($prikazy) . ' ' . (count($prikazy) === 1 ? 'příkaz' : (count($prikazy) < 5 ? 'příkazy' : 'příkazů')) . ")\n");
}
fwrite(STDOUT, 'Hotovo, migrací: ' . count($soubory) . "\n");

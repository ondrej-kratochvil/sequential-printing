<?php
// CLI: vytvoří API klíč pro uživatele (uloží jen hash, vypíše plaintext jednou).
// Použití:
//   php bin/create_api_key.php <user_id> <name> [rate_limit_per_min]
//
// Výstup:
//   api_key: sk_live_<...>
//
// Pozn.: klíč si ulož; později už ho z DB nedostaneš. Přihlášený uživatel si klíče spravuje i v UI (ucet.php?stranka=klice).
// Logika je sdílená s UI a api/v1/keys.php (src/SpravaKlicu.php).

require_once __DIR__ . '/../src/SpravaKlicu.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$userId = isset($argv[1]) ? (int)$argv[1] : 0;
$name = $argv[2] ?? '';

if ($userId <= 0 || $name === '') {
    fwrite(STDERR, "Usage: php bin/create_api_key.php <user_id> <name> [rate_limit_per_min]\n");
    exit(1);
}

$overeno = SpravaKlicu::overVstup(['name' => $name, 'rate_limit_per_min' => $argv[3] ?? SpravaKlicu::VYCHOZI_LIMIT]);
if (!empty($overeno['chyby'])) {
    foreach ($overeno['chyby'] as $chyba) fwrite(STDERR, $chyba['zprava'] . "\n");
    exit(1);
}

$klic = SpravaKlicu::vytvor(Db::pdo(), $userId, $overeno['name'], $overeno['rate_limit_per_min']);
fwrite(STDOUT, "Created api_key_id={$klic['id']} user_id={$userId} name=\"{$klic['name']}\" rate_limit_per_min={$klic['rate_limit_per_min']}\n");
fwrite(STDOUT, "api_key: {$klic['api_key']}\n");

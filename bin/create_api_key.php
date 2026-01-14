<?php
// CLI: vytvoří API klíč pro uživatele (uloží jen hash, vypíše plaintext jednou).
// Použití:
//   php bin/create_api_key.php <user_id> <name> [rate_limit_per_min]
//
// Výstup:
//   api_key: sk_live_<...>
//
// Pozn.: klíč si ulož; později už ho z DB nedostaneš.

require_once __DIR__ . '/../src/Db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$userId = isset($argv[1]) ? (int)$argv[1] : 0;
$name = $argv[2] ?? '';
$rate = isset($argv[3]) ? (int)$argv[3] : 60;

if ($userId <= 0 || $name === '') {
    fwrite(STDERR, "Usage: php bin/create_api_key.php <user_id> <name> [rate_limit_per_min]\n");
    exit(1);
}
if ($rate < 0) $rate = 0;

// 32 bytes => 64 hex chars. Prefix pro identifikaci v UI/logu.
$random = bin2hex(random_bytes(32));
$apiKey = 'sk_live_' . $random;
$hash = hash('sha256', $apiKey);
$prefix = substr($apiKey, 0, 12);

$pdo = Db::pdo();
$stmt = $pdo->prepare('
  INSERT INTO api_keys (user_id, name, key_prefix, key_hash, rate_limit_per_min)
  VALUES (?, ?, ?, ?, ?)
');
$stmt->execute([$userId, $name, $prefix, $hash, $rate]);

$id = (int)$pdo->lastInsertId();
fwrite(STDOUT, "Created api_key_id={$id} user_id={$userId} name=\"{$name}\" rate_limit_per_min={$rate}\n");
fwrite(STDOUT, "api_key: {$apiKey}\n");


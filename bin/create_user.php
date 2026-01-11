<?php
// CLI: vytvoří uživatele (email + password).
// Použití:
//   php bin/create_user.php user@example.com 'heslo' [--admin]

require_once __DIR__ . '/../src/Db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$email = $argv[1] ?? null;
$password = $argv[2] ?? null;
$isAdmin = in_array('--admin', $argv, true);

if (!$email || !$password) {
    fwrite(STDERR, "Usage: php bin/create_user.php <email> <password> [--admin]\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo = Db::pdo();

$stmt = $pdo->prepare('INSERT INTO users (email, password_hash, is_admin) VALUES (?, ?, ?)');
$stmt->execute([$email, $hash, $isAdmin ? 1 : 0]);

$id = (int)$pdo->lastInsertId();
fwrite(STDOUT, "Created user id={$id} email={$email}\n");


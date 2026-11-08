<?php
/**
 * CLI: nastaví nebo odebere uživateli roli admina (users.is_admin), případně vypíše adminy.
 *
 * Použití:
 *   php bin/admin.php jan@example.cz            # udělá z uživatele admina
 *   php bin/admin.php jan@example.cz --odebrat  # odebere roli admina
 *   php bin/admin.php --vypis                   # vypíše adminy
 *
 * Nového uživatele rovnou jako admina založí: php bin/create_user.php <email> <heslo> --admin
 * DB podle env DB_DSN, DB_USER, DB_PASS.
 */

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Ucty.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = Db::pdo();
if (in_array('--vypis', $argv, true)) {
    foreach ($pdo->query('SELECT id, email FROM users WHERE is_admin = 1 ORDER BY id')->fetchAll() as $radek) {
        fwrite(STDOUT, "{$radek['id']}\t{$radek['email']}\n");
    }
    exit(0);
}

$email = Ucty::normalizujEmail($argv[1] ?? '');
if ($email === '' || strpos($email, '--') === 0) {
    fwrite(STDERR, "Použití: php bin/admin.php <email> [--odebrat] | --vypis\n");
    exit(1);
}
$admin = in_array('--odebrat', $argv, true) ? 0 : 1;
$stmt = $pdo->prepare('UPDATE users SET is_admin = ? WHERE email = ?');
$stmt->execute([$admin, $email]);
if (Ucty::najdiPodleEmailu($pdo, $email) === null) {
    fwrite(STDERR, "Uživatel {$email} neexistuje.\n");
    exit(1);
}
fwrite(STDOUT, $admin ? "{$email} je admin.\n" : "{$email} už není admin.\n");

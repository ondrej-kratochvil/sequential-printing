<?php
/**
 * Jednoduchá DB utilita bez Composeru (PDO).
 *
 * Konfigurace přes env:
 *   DB_DSN   (např. "mysql:host=127.0.0.1;dbname=sekvencni_tisk;charset=utf8mb4")
 *   DB_USER
 *   DB_PASS
 */

class Db
{
    /** @var ?PDO */
    private static $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $dsn = getenv('DB_DSN') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS') ?: '';

        if ($dsn === '') {
            throw new RuntimeException('Chybí DB_DSN (env).');
        }

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        self::$pdo = $pdo;
        return $pdo;
    }
}


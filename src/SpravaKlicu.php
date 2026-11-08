<?php
/**
 * Správa API klíčů (DB) - logika pro api/v1/keys.php.
 *
 * Klíč se ukládá jen jako sha256 hash, plaintext se vrátí jen jednou při vytvoření.
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Db.php';

class SpravaKlicu
{
    /** Nejvyšší povolený limit požadavků za minutu. */
    public const MAXIMALNI_LIMIT = 100000;

    /** Výchozí limit požadavků za minutu, když ho vstup neuvádí. */
    public const VYCHOZI_LIMIT = 60;

    /** Klíče uživatele (bez plaintext klíče), nejnovější první. */
    public static function seznam(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('
            SELECT id, name, key_prefix, rate_limit_per_min, last_used_at, revoked_at, created_at
            FROM api_keys
            WHERE user_id = ?
            ORDER BY created_at DESC, id DESC
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Ověří tělo požadavku na nový klíč. Pořadí chyb = pořadí kontrol (name, rate_limit_per_min).
     *
     * @return array ['name' => string, 'rate_limit_per_min' => int, 'chyby' => [{pole, zprava}]]
     */
    public static function overVstup(array $body): array
    {
        $chyby = [];
        $name = isset($body['name']) && is_scalar($body['name']) ? trim((string)$body['name']) : '';
        $rate = isset($body['rate_limit_per_min']) && is_scalar($body['rate_limit_per_min']) ? (int)$body['rate_limit_per_min'] : self::VYCHOZI_LIMIT;
        if ($name === '' || strlen($name) > 100) $chyby[] = ['pole' => 'name', 'zprava' => "Pole 'name' je povinné (max 100 znaků)."];
        if ($rate < 0) $rate = 0;
        if ($rate > self::MAXIMALNI_LIMIT) $chyby[] = ['pole' => 'rate_limit_per_min', 'zprava' => "Pole 'rate_limit_per_min' je příliš vysoké."];
        return ['name' => $name, 'rate_limit_per_min' => $rate, 'chyby' => $chyby];
    }

    /** Vytvoří klíč a vrátí jeho údaje včetně plaintext `api_key` (jen tentokrát). */
    public static function vytvor(PDO $pdo, int $userId, string $name, int $rate): array
    {
        $apiKey = 'sk_live_' . bin2hex(random_bytes(32));
        $prefix = substr($apiKey, 0, 12);

        $stmt = $pdo->prepare('
            INSERT INTO api_keys (user_id, name, key_prefix, key_hash, rate_limit_per_min)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$userId, $name, $prefix, hash('sha256', $apiKey), $rate]);

        return [
            'id' => (int)$pdo->lastInsertId(),
            'name' => $name,
            'key_prefix' => $prefix,
            'rate_limit_per_min' => $rate,
            'api_key' => $apiKey,
        ];
    }

    /** Zneplatní klíč uživatele. Vrací false, když klíč neexistuje, patří jinému uživateli nebo už je zneplatněn. */
    public static function zneplatni(PDO $pdo, int $userId, int $id): bool
    {
        $stmt = $pdo->prepare('
            UPDATE api_keys
            SET revoked_at = CURRENT_TIMESTAMP
            WHERE id = ? AND user_id = ? AND revoked_at IS NULL
        ');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }
}

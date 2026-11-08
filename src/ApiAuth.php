<?php
require_once __DIR__ . '/Db.php';

class ApiAuth
{
    /** Délka okna rate limitu v sekundách (minutová okna v tabulce api_key_rate_limits). */
    public const DELKA_OKNA = 60;

    /**
     * Najde API klíč a provede rate-limit.
     *
     * Vrací asociativní pole z tabulky api_keys (včetně rate_limit_per_min) a navíc `rate_limit`
     * (stav okna: limit, zbyva, reset_za; null když klíč limit nemá), nebo vyhodí výjimku s HTTP kódem.
     */
    public static function requireApiKey(): array
    {
        $require = getenv('API_REQUIRE_KEY');
        if ($require !== false && (string)$require === '0') {
            return ['id' => null, 'user_id' => null, 'rate_limit_per_min' => null, 'rate_limit' => null];
        }

        $rawKey = self::extractKey();
        if ($rawKey === null || $rawKey === '') {
            throw new ApiAuthHttpException(401, 'Chybí API klíč. Použij Authorization: Bearer <key> nebo X-Api-Key.', 'chybi_klic');
        }

        $hash = hash('sha256', $rawKey);

        $pdo = Db::pdo();
        $stmt = $pdo->prepare('
            SELECT id, user_id, name, key_prefix, rate_limit_per_min, revoked_at
            FROM api_keys
            WHERE key_hash = ?
            LIMIT 1
        ');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new ApiAuthHttpException(401, 'Neplatný API klíč.', 'neplatny_klic');
        }
        if ($row['revoked_at'] !== null) {
            throw new ApiAuthHttpException(401, 'API klíč je zneplatněn.', 'zneplatneny_klic');
        }

        $row['rate_limit'] = self::enforceRateLimit($pdo, (int)$row['id'], (int)$row['rate_limit_per_min']);

        $pdo->prepare('UPDATE api_keys SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$row['id']]);

        return $row;
    }

    private static function extractKey(): ?string
    {
        // Preferuj header, query param jen pro debugging.
        $headers = self::getAllHeadersLower();

        if (isset($headers['authorization'])) {
            $auth = trim($headers['authorization']);
            if (stripos($auth, 'bearer ') === 0) {
                return trim(substr($auth, 7));
            }
        }

        if (isset($headers['x-api-key'])) {
            return trim($headers['x-api-key']);
        }

        if (isset($_GET['api_key'])) {
            return trim((string)$_GET['api_key']);
        }

        return null;
    }

    private static function getAllHeadersLower(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $out[$name] = $v;
            }
        }
        // Některé SAPIs nepropagují Authorization do HTTP_AUTHORIZATION
        if (isset($_SERVER['AUTHORIZATION']) && !isset($out['authorization'])) {
            $out['authorization'] = $_SERVER['AUTHORIZATION'];
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION']) && !isset($out['authorization'])) {
            $out['authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        }
        return $out;
    }

    /** Začátek minutového okna, do kterého patří čas $ted (unix timestamp zaokrouhlený dolů na minutu). */
    public static function zacatekOkna(int $ted): int
    {
        return intdiv($ted, self::DELKA_OKNA) * self::DELKA_OKNA;
    }

    /** Za kolik sekund začne další okno (1..60) - hodnota hlavičky Retry-After u 429. */
    public static function sekundDoDalsihoOkna(int $ted): int
    {
        return self::zacatekOkna($ted) + self::DELKA_OKNA - $ted;
    }

    /**
     * Stav okna pro hlavičky X-RateLimit-*: limit za minutu, kolik požadavků v okně zbývá
     * (po započítání aktuálního) a za kolik sekund začne další okno.
     */
    public static function stavLimitu(int $perMin, int $pocetVOkne, int $ted): array
    {
        return [
            'limit' => $perMin,
            'zbyva' => max(0, $perMin - $pocetVOkne),
            'reset_za' => self::sekundDoDalsihoOkna($ted),
        ];
    }

    /**
     * Započítá požadavek do aktuálního okna. Vrací stav okna (stavLimitu()), null když klíč limit nemá.
     *
     * @throws ApiAuthHttpException 429 (s retryAfter a limitem) při překročení limitu.
     */
    private static function enforceRateLimit(PDO $pdo, int $apiKeyId, int $perMin): ?array
    {
        if ($perMin <= 0) return null;

        $ted = time();
        $windowStart = self::zacatekOkna($ted);

        // Atomicky inkrementuj čítač pro aktuální okno.
        $stmt = $pdo->prepare('
            INSERT INTO api_key_rate_limits (api_key_id, window_start_ts, cnt)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE cnt = cnt + 1
        ');
        $stmt->execute([$apiKeyId, $windowStart]);

        $stmt = $pdo->prepare('
            SELECT cnt
            FROM api_key_rate_limits
            WHERE api_key_id = ? AND window_start_ts = ?
            LIMIT 1
        ');
        $stmt->execute([$apiKeyId, $windowStart]);
        $row = $stmt->fetch();
        $cnt = $row ? (int)$row['cnt'] : 1;

        if ($cnt > $perMin) {
            throw new ApiAuthHttpException(429, 'Rate limit exceeded.', 'prekrocen_limit', self::sekundDoDalsihoOkna($ted), $perMin);
        }
        return self::stavLimitu($perMin, $cnt, $ted);
    }
}

class ApiAuthHttpException extends RuntimeException
{
    /** @var int */
    public $statusCode;

    /** @var string Strojový kód pro API v1 (chybi_klic, neplatny_klic, zneplatneny_klic, prekrocen_limit). */
    public $kod;

    /** @var int|null U 429: sekundy do dalšího okna (Retry-After). */
    public $retryAfter;

    /** @var int|null U 429: limit požadavků za minutu. */
    public $limit;

    public function __construct(int $statusCode, string $message, string $kod = 'chyba_overeni', ?int $retryAfter = null, ?int $limit = null)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->kod = $kod;
        $this->retryAfter = $retryAfter;
        $this->limit = $limit;
    }
}


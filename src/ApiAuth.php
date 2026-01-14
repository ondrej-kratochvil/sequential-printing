<?php
require_once __DIR__ . '/Db.php';

class ApiAuth
{
    /**
     * Najde API klíč a provede rate-limit.
     *
     * Vrací asociativní pole z tabulky api_keys (včetně rate_limit_per_min),
     * nebo vyhodí výjimku s HTTP kódem.
     */
    public static function requireApiKey(): array
    {
        $require = getenv('API_REQUIRE_KEY');
        if ($require !== false && (string)$require === '0') {
            return ['id' => null, 'user_id' => null, 'rate_limit_per_min' => null];
        }

        $rawKey = self::extractKey();
        if ($rawKey === null || $rawKey === '') {
            throw new ApiAuthHttpException(401, 'Chybí API klíč. Použij Authorization: Bearer <key> nebo X-Api-Key.');
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
            throw new ApiAuthHttpException(401, 'Neplatný API klíč.');
        }
        if ($row['revoked_at'] !== null) {
            throw new ApiAuthHttpException(401, 'API klíč je zneplatněn.');
        }

        self::enforceRateLimit($pdo, (int)$row['id'], (int)$row['rate_limit_per_min']);

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

    private static function enforceRateLimit(PDO $pdo, int $apiKeyId, int $perMin): void
    {
        if ($perMin <= 0) return;

        $windowStart = (int)(floor(time() / 60) * 60);

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
            throw new ApiAuthHttpException(429, 'Rate limit exceeded.');
        }
    }
}

class ApiAuthHttpException extends RuntimeException
{
    /** @var int */
    public $statusCode;

    public function __construct(int $statusCode, string $message)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }
}


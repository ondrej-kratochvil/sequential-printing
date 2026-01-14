<?php
/**
 * Endpointy pro správu API klíčů (JSON).
 *
 * Autentizace: API klíčem (Bearer / X-Api-Key).
 *
 * GET    /api_keys.php
 *   => seznam klíčů uživatele (bez plaintext klíče)
 *
 * POST   /api_keys.php
 *   JSON: { "name": "...", "rate_limit_per_min": 60 }
 *   => vytvoří nový klíč a vrátí plaintext jen jednou
 *
 * DELETE /api_keys.php?id=123
 *   => zneplatní klíč
 */

require_once __DIR__ . "/src/ApiAuth.php";

header("Content-Type: application/json; charset=UTF-8");

$json = function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

try {
    $auth = ApiAuth::requireApiKey();
} catch (ApiAuthHttpException $e) {
    $json($e->statusCode, ["error" => $e->getMessage()]);
} catch (Throwable $e) {
    $json(500, ["error" => "Chyba při ověření API klíče."]);
}

if (empty($auth["user_id"])) {
    // I když je globálně vypnutý požadavek na klíč (API_REQUIRE_KEY=0),
    // správa klíčů musí být vždy chráněná.
    $json(403, ["error" => "Správa API klíčů vyžaduje autentizaci."]);
}

$pdo = Db::pdo();
$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

if ($method === "GET") {
    $stmt = $pdo->prepare('
        SELECT id, name, key_prefix, rate_limit_per_min, last_used_at, revoked_at, created_at
        FROM api_keys
        WHERE user_id = ?
        ORDER BY created_at DESC, id DESC
    ');
    $stmt->execute([(int)$auth["user_id"]]);
    $keys = $stmt->fetchAll();
    $json(200, ["keys" => $keys]);
}

if ($method === "POST") {
    $raw = file_get_contents("php://input");
    $body = json_decode($raw, true);
    if (!is_array($body)) $json(400, ["error" => "Nevalidní JSON."]);

    $name = isset($body["name"]) ? trim((string)$body["name"]) : "";
    $rate = isset($body["rate_limit_per_min"]) ? (int)$body["rate_limit_per_min"] : 60;
    if ($name === "" || strlen($name) > 100) $json(400, ["error" => "Pole 'name' je povinné (max 100 znaků)."]);
    if ($rate < 0) $rate = 0;
    if ($rate > 100000) $json(400, ["error" => "Pole 'rate_limit_per_min' je příliš vysoké."]);

    $random = bin2hex(random_bytes(32));
    $apiKey = "sk_live_" . $random;
    $hash = hash("sha256", $apiKey);
    $prefix = substr($apiKey, 0, 12);

    $stmt = $pdo->prepare('
        INSERT INTO api_keys (user_id, name, key_prefix, key_hash, rate_limit_per_min)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([(int)$auth["user_id"], $name, $prefix, $hash, $rate]);

    $id = (int)$pdo->lastInsertId();
    $json(201, [
        "id" => $id,
        "name" => $name,
        "key_prefix" => $prefix,
        "rate_limit_per_min" => $rate,
        "api_key" => $apiKey,
    ]);
}

if ($method === "DELETE") {
    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    if ($id <= 0) $json(400, ["error" => "Chybí parametr 'id'."]);

    $stmt = $pdo->prepare('
        UPDATE api_keys
        SET revoked_at = CURRENT_TIMESTAMP
        WHERE id = ? AND user_id = ? AND revoked_at IS NULL
    ');
    $stmt->execute([$id, (int)$auth["user_id"]]);

    if ($stmt->rowCount() === 0) {
        $json(404, ["error" => "Klíč nenalezen nebo už je zneplatněn."]);
    }

    $json(200, ["ok" => true]);
}

$json(405, ["error" => "Method not allowed."]);


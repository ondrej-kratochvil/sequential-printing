<?php
/**
 * ZASTARALÉ (legacy) endpointy pro správu API klíčů (JSON). Nové integrace: /api/v1/keys.php
 * (stejné operace, jednotný formát chyb). Chování a výstup zůstávají jako dřív ({"error": "<text>"}).
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

require_once __DIR__ . "/src/Api.php";
require_once __DIR__ . "/src/SpravaKlicu.php";

$json = function (int $status, array $payload): void {
    Api::odeslat($status, $payload);
};

try {
    $auth = Api::overKlic(true);
} catch (ApiChyba $e) {
    Api::odeslatLegacyChybu($e);
}

if (empty($auth["user_id"])) {
    // I když je globálně vypnutý požadavek na klíč (API_REQUIRE_KEY=0),
    // správa klíčů musí být vždy chráněná.
    $json(403, ["error" => "Správa API klíčů vyžaduje autentizaci."]);
}

$pdo = Db::pdo();
$method = Api::metoda();

if ($method === "GET") {
    $json(200, ["keys" => SpravaKlicu::seznam($pdo, (int)$auth["user_id"])]);
}

if ($method === "POST") {
    $body = json_decode(Api::surovyVstup(), true);
    if (!is_array($body)) $json(400, ["error" => "Nevalidní JSON."]);

    $overeno = SpravaKlicu::overVstup($body);
    if (!empty($overeno["chyby"])) $json(400, ["error" => $overeno["chyby"][0]["zprava"]]);

    $json(201, SpravaKlicu::vytvor($pdo, (int)$auth["user_id"], $overeno["name"], $overeno["rate_limit_per_min"]));
}

if ($method === "DELETE") {
    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    if ($id <= 0) $json(400, ["error" => "Chybí parametr 'id'."]);

    if (!SpravaKlicu::zneplatni($pdo, (int)$auth["user_id"], $id)) {
        $json(404, ["error" => "Klíč nenalezen nebo už je zneplatněn."]);
    }

    $json(200, ["ok" => true]);
}

$json(405, ["error" => "Method not allowed."]);

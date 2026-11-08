<?php
/**
 * API v1: správa API klíčů (vyžaduje DB a platný klíč i při API_REQUIRE_KEY=0).
 *
 * GET    /api/v1/keys.php          => {"keys": [...]} (bez plaintext klíče)
 * POST   /api/v1/keys.php          JSON {"name": "...", "rate_limit_per_min": 60} => 201, plaintext `api_key` jen jednou
 * DELETE /api/v1/keys.php?id=123   => {"ok": true}, neznámý / už zneplatněný klíč = 404 klic_nenalezen
 *
 * Chyby ve formátu API v1 (viz src/ApiChyba.php). Logika je společná s legacy api_keys.php (src/SpravaKlicu.php).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'SpravaKlicu.php';

Api::obsluz(function (): void {
    $metoda = Api::povolMetody(['GET', 'POST', 'DELETE']);
    $auth = Api::overKlic();
    if (empty($auth['user_id'])) {
        // I když je globálně vypnutý požadavek na klíč (API_REQUIRE_KEY=0), správa klíčů musí být vždy chráněná.
        throw new ApiChyba(403, 'vyzaduje_klic', 'Správa API klíčů vyžaduje autentizaci platným API klíčem.');
    }
    $userId = (int)$auth['user_id'];

    if ($metoda === 'GET') {
        Api::odeslat(200, ['keys' => SpravaKlicu::seznam(Db::pdo(), $userId)]);
    }

    if ($metoda === 'POST') {
        $overeno = SpravaKlicu::overVstup(Api::teloJson());
        if (!empty($overeno['chyby'])) {
            throw new ApiChyba(400, 'neplatny_vstup', 'Vstup obsahuje chyby (' . count($overeno['chyby']) . ').', ['chyby' => $overeno['chyby']]);
        }
        Api::odeslat(201, SpravaKlicu::vytvor(Db::pdo(), $userId, $overeno['name'], $overeno['rate_limit_per_min']));
    }

    // DELETE
    $id = isset($_GET['id']) && is_scalar($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        throw new ApiChyba(400, 'neplatny_vstup', 'Vstup obsahuje chyby (1).', ['chyby' => [['pole' => 'id', 'zprava' => "Parametr 'id' musí být kladné celé číslo."]]]);
    }
    if (!SpravaKlicu::zneplatni(Db::pdo(), $userId, $id)) {
        throw new ApiChyba(404, 'klic_nenalezen', 'Klíč nenalezen nebo už je zneplatněn.', ['id' => $id]);
    }
    Api::odeslat(200, ['ok' => true]);
});

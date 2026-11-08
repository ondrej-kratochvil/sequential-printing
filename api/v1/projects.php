<?php
/**
 * API v1: projekty uživatele (vyžaduje DB a platný klíč i při API_REQUIRE_KEY=0, stejně jako keys.php).
 *
 * GET    /api/v1/projects.php           => {"projects": [{id, name, pocet_objektu, created_at, updated_at}]}
 * GET    /api/v1/projects.php?id=12     => {"project": {id, name, data, created_at, updated_at}}
 * POST   /api/v1/projects.php           JSON {"name": "...", "data": {...}} => 201 {"project": {id, name}}
 * PUT    /api/v1/projects.php?id=12     JSON {"name"?: "...", "data"?: {...}} => 200 {"project": {id, name}}
 * DELETE /api/v1/projects.php?id=12     => {"ok": true}
 *
 * `data` = vstup formuláře ve stejném tvaru jako export vstupu z UI ({"version": 1, "objekty": [...], "nastaveni": {...}}),
 * očistí ho Projekty::overData. Projekt patří vlastníkovi klíče; cizí projekt = 404 projekt_nenalezen.
 * Chyby ve formátu v1: 400 neplatny_vstup (details.chyby), 403 vyzaduje_klic, 404 projekt_nenalezen.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Projekty.php';

Api::obsluz(function (): void {
    $metoda = Api::povolMetody(['GET', 'POST', 'PUT', 'DELETE']);
    $auth = Api::overKlic();
    if (empty($auth['user_id'])) {
        throw new ApiChyba(403, 'vyzaduje_klic', 'Projekty vyžadují autentizaci platným API klíčem (projekt patří vlastníkovi klíče).');
    }
    $userId = (int)$auth['user_id'];
    $pdo = Db::pdo();
    $id = isset($_GET['id']) && is_scalar($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
    $chybaId = function (): ApiChyba {
        return new ApiChyba(400, 'neplatny_vstup', 'Vstup obsahuje chyby (1).', ['chyby' => [['pole' => 'id', 'zprava' => "Parametr 'id' musí být kladné celé číslo."]]]);
    };
    $nenalezen = function (int $id): ApiChyba {
        return new ApiChyba(404, 'projekt_nenalezen', 'Projekt nenalezen.', ['id' => $id]);
    };

    if ($metoda === 'GET') {
        if (isset($_GET['id'])) {
            if ($id <= 0) throw $chybaId();
            $projekt = Projekty::nacti($pdo, $userId, $id);
            if ($projekt === null) throw $nenalezen($id);
            Api::odeslat(200, ['project' => $projekt]);
        }
        Api::odeslat(200, ['projects' => Projekty::seznam($pdo, $userId)]);
    }

    if ($metoda === 'DELETE') {
        if ($id <= 0) throw $chybaId();
        if (!Projekty::smaz($pdo, $userId, $id)) throw $nenalezen($id);
        Api::odeslat(200, ['ok' => true]);
    }

    // POST a PUT: název a data.
    $telo = Api::teloJson();
    $chyby = [];
    $nazev = null;
    if ($metoda === 'POST' || array_key_exists('name', $telo)) {
        $nazev = Projekty::upravNazev($telo['name'] ?? null);
        if ($nazev === null) $chyby[] = ['pole' => 'name', 'zprava' => "Pole 'name' je povinné (max " . Projekty::MAX_DELKA_NAZVU . ' znaků).'];
    }
    $data = null;
    if ($metoda === 'POST' || array_key_exists('data', $telo)) {
        $overeno = Projekty::overData($telo['data'] ?? null);
        $chyby = array_merge($chyby, $overeno['chyby']);
        $data = $overeno['data'];
    }
    if ($metoda === 'PUT' && $id <= 0) array_unshift($chyby, ['pole' => 'id', 'zprava' => "Parametr 'id' musí být kladné celé číslo."]);
    if ($metoda === 'PUT' && $nazev === null && $data === null && empty($chyby)) $chyby[] = ['pole' => 'name', 'zprava' => "Pošli aspoň 'name' nebo 'data'."];
    if (!empty($chyby)) throw new ApiChyba(400, 'neplatny_vstup', 'Vstup obsahuje chyby (' . count($chyby) . ').', ['chyby' => $chyby]);

    if ($metoda === 'POST') {
        $noveId = Projekty::vytvor($pdo, $userId, $nazev, $data);
        Api::odeslat(201, ['project' => ['id' => $noveId, 'name' => $nazev]]);
    }
    $stary = Projekty::nacti($pdo, $userId, $id);
    if ($stary === null) throw $nenalezen($id);
    $nazev = $nazev ?? $stary['name'];
    if ($data !== null) Projekty::prepis($pdo, $userId, $id, $nazev, $data);
    else Projekty::prejmenuj($pdo, $userId, $id, $nazev);
    Api::odeslat(200, ['project' => ['id' => $id, 'name' => $nazev]]);
});

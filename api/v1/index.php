<?php
/**
 * API v1: rozcestník. /api/v1/ vrátí seznam endpointů (`klic`: `vyzaduje` = klíč, pokud ho konfigurace
 * vyžaduje (API_REQUIRE_KEY), `bez_klice` = veřejný, `vzdy` = platný klíč i při API_REQUIRE_KEY=0); neznámý endpoint (přes volitelný .htaccess,
 * parametr `endpoint`) = 404 neznamy_endpoint. Bez mod_rewrite se volají přímo soubory *.php.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';

Api::obsluz(function (): void {
    $endpointy = [
        'arrange' => ['soubor' => 'arrange.php', 'metody' => ['GET', 'POST'], 'klic' => 'vyzaduje', 'popis' => 'Výpočet rozmístění objektů pro sekvenční tisk.'],
        'import' => ['soubor' => 'import.php', 'metody' => ['POST'], 'klic' => 'vyzaduje', 'popis' => 'Import modelu 3MF/STL: rozměry, názvy a počty objektů pro vstup výpočtu.'],
        'export' => ['soubor' => 'export.php', 'metody' => ['GET', 'POST'], 'klic' => 'vyzaduje', 'popis' => 'Výpočet a export do 3MF (každý kus samostatný objekt v pořadí tisku).'],
        'printers' => ['soubor' => 'printers.php', 'metody' => ['GET'], 'klic' => 'bez_klice', 'popis' => 'Předvolby tiskáren (?id=<id> jedna předvolba), bez klíče.'],
        'keys' => ['soubor' => 'keys.php', 'metody' => ['GET', 'POST', 'DELETE'], 'klic' => 'vzdy', 'popis' => 'Správa API klíčů (vyžaduje DB a platný klíč vždy).'],
        'projects' => ['soubor' => 'projects.php', 'metody' => ['GET', 'POST', 'PUT', 'DELETE'], 'klic' => 'vzdy', 'popis' => 'Projekty vlastníka klíče: uložený vstup formuláře (vyžaduje DB a platný klíč vždy).'],
    ];
    if (isset($_GET['endpoint']) && is_scalar($_GET['endpoint']) && trim((string)$_GET['endpoint'], '/') !== '') {
        $endpoint = trim((string)$_GET['endpoint'], '/');
        throw new ApiChyba(404, 'neznamy_endpoint', "Neznámý endpoint '{$endpoint}'. Dostupné: " . implode(', ', array_keys($endpointy)) . '.', [
            'endpoint' => $endpoint,
            'dostupne' => array_keys($endpointy),
        ]);
    }
    Api::povolMetody(['GET']);
    Api::odeslat(200, ['verze' => Api::VERZE, 'endpointy' => $endpointy]);
});

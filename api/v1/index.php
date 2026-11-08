<?php
/**
 * API v1: rozcestník. /api/v1/ vrátí seznam endpointů; neznámý endpoint (přes volitelný .htaccess,
 * parametr `endpoint`) = 404 neznamy_endpoint. Bez mod_rewrite se volají přímo soubory *.php.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';

Api::obsluz(function (): void {
    $endpointy = [
        'arrange' => ['soubor' => 'arrange.php', 'metody' => ['GET', 'POST'], 'popis' => 'Výpočet rozmístění objektů pro sekvenční tisk.'],
        'import' => ['soubor' => 'import.php', 'metody' => ['POST'], 'popis' => 'Import modelu 3MF/STL: rozměry, názvy a počty objektů pro vstup výpočtu.'],
        'export' => ['soubor' => 'export.php', 'metody' => ['GET', 'POST'], 'popis' => 'Výpočet a export do 3MF (každý kus samostatný objekt v pořadí tisku).'],
        'printers' =>['soubor' => 'printers.php', 'metody' => ['GET'], 'popis' => 'Předvolby tiskáren (?id=<id> jedna předvolba).'],
        'keys' => ['soubor' => 'keys.php', 'metody' => ['GET', 'POST', 'DELETE'], 'popis' => 'Správa API klíčů (vyžaduje DB).'],
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

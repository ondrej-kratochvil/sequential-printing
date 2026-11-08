<?php
/**
 * API v1: výpočet rozmístění (stabilní kontrakt).
 *
 * POST application/json:  {"objekty": [...], "options": {...}, "tiskarna": "<id>", "printer": {...}}
 * GET  se stejnými parametry jako UI a api.php (objekty[0][x]=50&…&options[vice_podlozek]=1&tiskarna=prusa_mini)
 *
 * Úspěch 200: stejné klíče jako api.php a CLI (SequentialPrintCalculator::vystupApi()).
 * Chyby: {"error": {"code", "message", "details"}} - 400 neplatny_json / neplatny_vstup (details.chyby po polích),
 * 401 chybí / neplatný klíč, 404 neznama_tiskarna, 405 nepovolena_metoda, 429 prekrocen_limit (Retry-After), 500.
 * Validace vstupu je stejná jako v CLI (src/VstupVypoctu.php).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';

Api::obsluz(function (): void {
    Api::povolMetody(['GET', 'POST']);
    Api::overKlic();
    [$vstup, $zDotazu] = Api::vstupVypoctu();
    Api::odeslat(200, Api::vypocet(VstupVypoctu::over($vstup, $zDotazu)));
});

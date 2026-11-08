<?php
/**
 * API v1: předvolby tiskáren (config/tiskarny.json).
 *
 * GET /api/v1/printers.php          => {"vychozi": "<id>", "tiskarny": [{id, nazev, vychozi, parametry, plocha, zdroje}]}
 * GET /api/v1/printers.php?id=<id>  => {"tiskarna": {...}}, neznámé id = 404 neznama_tiskarna
 *
 * `plocha` = rozměry, do kterých se musí objekt vejít (x po korekci posun_zprava), stejné meze jako validace vstupu.
 *
 * Bez API klíče (rozhodnutí 8. 11. 2026): seznam předvoleb je veřejný a statický (config/tiskarny.json),
 * endpoint nepotřebuje DB ani klíč. Rate limit podle IP tu není: bez DB není kde počítat a čtení
 * jednoho JSON souboru je levné; případné omezení patří webovému serveru.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';

Api::obsluz(function (): void {
    Api::povolMetody(['GET']);
    if (isset($_GET['id']) && $_GET['id'] !== '') {
        if (!PredvolbyTiskaren::existuje($_GET['id'])) throw VstupVypoctu::neznamaTiskarna($_GET['id']);
        Api::odeslat(200, ['tiskarna' => PredvolbyTiskaren::detail($_GET['id'])]);
    }
    $tiskarny = [];
    foreach (array_keys(PredvolbyTiskaren::seznam()) as $id) $tiskarny[] = PredvolbyTiskaren::detail($id);
    Api::odeslat(200, ['vychozi' => PredvolbyTiskaren::vychoziId(), 'tiskarny' => $tiskarny]);
});

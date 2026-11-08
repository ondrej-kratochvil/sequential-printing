<?php
/**
 * API v1: import modelu 3MF (PrusaSlicer i jiné slicery) nebo STL (binární i ASCII).
 *
 * POST multipart/form-data, pole `soubor`:   curl -F 'soubor=@model.3mf' …/api/v1/import.php
 * POST surové tělo (název v ?nazev=):         curl --data-binary @model.stl '…/api/v1/import.php?nazev=model.stl'
 *
 * Úspěch 200: {"objekty": [{x, y, z, instances: {d}, nazev, sit}], "soubor": {nazev, typ}, "modely": [...], "varovani": [...]}
 * `objekty` jdou rovnou do vstupu arrange.php / export.php (rozměry zaokrouhlené na setiny nahoru, `sit` = index objektu
 * v modelu pro export se sítěmi). Typ souboru se pozná podle obsahu (3MF = ZIP).
 * Chyby ve formátu v1: 400 chybi_soubor / neplatny_soubor / chyba_nahrani, 413 soubor_prilis_velky (IMPORT_MAX_MB, limity PHP),
 * 415 nepodporovany_soubor, 500 chybi_zip (PHP bez rozšíření zip), plus 401/405/429 jako ostatní endpointy.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';

Api::obsluz(function (): void {
    Api::povolMetody(['POST']);
    Api::overKlic();
    $soubor = Api::nahranySoubor('soubor', true);
    if ($soubor === null) {
        throw new ApiChyba(400, 'chybi_soubor', "Chybí soubor: pošli multipart pole 'soubor', nebo soubor jako tělo požadavku (název v ?nazev=).");
    }
    Api::odeslat(200, ImportModelu::souhrn(ImportModelu::nacti($soubor['cesta'], $soubor['nazev'], false)));
});

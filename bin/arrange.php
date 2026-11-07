<?php
// CLI: výpočet rozmístění pro sekvenční tisk (bez DB, bez webového serveru).
// Použití:
//   php bin/arrange.php vstup.json
//   php bin/arrange.php < vstup.json
//   echo '{"objekty":[{"x":50,"y":50,"z":100,"instances":{"d":3}}]}' | php bin/arrange.php
//
// Vstup (JSON):
//   {"objekty": [{"x": 50, "y": 50, "z": 100, "instances": {"d": 3 | "max"}, "nazev": "volitelné", "rotace": true}],
//    "options": {...}, "printer": {...}, "tiskarna": "prusa_mini"}
//   options, printer a tiskarna jsou volitelné (výchozí = Prusa MINI jako v UI); printer může být i id předvolby
//   (config/tiskarny.json), vlastní hodnoty v objektu printer přepíšou předvolbu. options.vice_podlozek = více podložek.
//
// Výstup: JSON se stejnými klíči jako api.php (positions, pozice, pocet_instanci, pocet_podlozek,
// objekty, printer, kontrola_kolizi, limit, otocene_objekty, podlozky). Exit code: 0 = OK, 1 = chybný vstup
// (i neznámá předvolba tiskárny), 2 = výsledek obsahuje kolizi.

require_once __DIR__ . '/../src/Api.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const EXIT_OK = 0;
const EXIT_CHYBNY_VSTUP = 1;
const EXIT_KOLIZE = 2;

/**
 * Chybný vstup: zprávy na stderr, na stdout {"error": "<zprávy oddělené '; '>", "chyby": [{pole, zprava}]}
 * (`chyby` jen u chyb jednotlivých polí, stejné jako details.chyby v API v1), exit 1.
 *
 * @param string|string[] $zpravy
 */
function chybnyVstup($zpravy, array $chyby = []): void
{
    $zpravy = (array)$zpravy;
    foreach ($zpravy as $zprava) fwrite(STDERR, "Chybný vstup: {$zprava}\n");
    $vystup = ['error' => implode('; ', $zpravy)];
    if (!empty($chyby)) $vystup['chyby'] = $chyby;
    fwrite(STDOUT, json_encode($vystup, JSON_UNESCAPED_UNICODE) . "\n");
    exit(EXIT_CHYBNY_VSTUP);
}

$argumenty = array_slice($argv, 1);
if (in_array('-h', $argumenty, true) || in_array('--help', $argumenty, true)) {
    fwrite(STDOUT, "Použití: php bin/arrange.php [vstup.json | -]\n(bez argumentu nebo s '-' čte JSON ze stdin)\n");
    exit(EXIT_OK);
}

$soubor = $argumenty[0] ?? '-';
if ($soubor === '-') {
    $raw = stream_get_contents(STDIN);
} else {
    if (!is_file($soubor) || !is_readable($soubor)) chybnyVstup("soubor '{$soubor}' neexistuje nebo nejde číst.");
    $raw = file_get_contents($soubor);
}
if ($raw === false || trim($raw) === '') chybnyVstup('prázdný vstup.');

$vstup = json_decode($raw, true);
if (!is_array($vstup)) chybnyVstup('vstup není platný JSON objekt (' . json_last_error_msg() . ').');

// Validace je společná s API v1 (src/VstupVypoctu.php): všechny chyby po polích najednou, neznámá předvolba
// tiskárny je chyba vstupu (exit 1). Tiskárna a nastavení: chybějící hodnoty doplní předvolba (výchozí Prusa MINI)
// a VYCHOZI_NASTAVENI; "tiskarna": "<id>", nebo "printer": "<id>" místo objektu, objekt "printer" přepíše předvolbu.
try {
    $overeno = VstupVypoctu::over($vstup);
} catch (ApiChyba $e) {
    chybnyVstup($e->zpravy(), $e->status === 400 && isset($e->details['chyby']) ? $e->details['chyby'] : []);
}

$vystup = Api::vypocet($overeno);

fwrite(STDOUT, json_encode($vystup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
exit((is_array($vystup['kontrola_kolizi']) && $vystup['kontrola_kolizi']['ok']) ? EXIT_OK : EXIT_KOLIZE);

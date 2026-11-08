<?php
// CLI: výpočet rozmístění pro sekvenční tisk (bez DB, bez webového serveru).
// Použití:
//   php bin/arrange.php vstup.json
//   php bin/arrange.php < vstup.json
//   echo '{"objekty":[{"x":50,"y":50,"z":100,"instances":{"d":3}}]}' | php bin/arrange.php
//   php bin/arrange.php --import model.3mf                           (jen import: objekty pro vstup jako JSON)
//   php bin/arrange.php --import model.3mf --export-3mf vystup.3mf   (import + výpočet + export 3MF)
//   php bin/arrange.php vstup.json --import model.3mf --export-3mf vystup.3mf [--podlozka N]
//
// Vstup (JSON):
//   {"objekty": [{"x": 50, "y": 50, "z": 100, "instances": {"d": 3 | "max"}, "nazev": "volitelné", "rotace": true, "sit": 0}],
//    "options": {...}, "printer": {...}, "tiskarna": "prusa_mini"}
//   options, printer a tiskarna jsou volitelné (výchozí = Prusa MINI jako v UI); printer může být i id předvolby
//   (config/tiskarny.json), vlastní hodnoty v objektu printer přepíšou předvolbu. options.vice_podlozek = více podložek.
//   S --import: bez "objekty" ve vstupu se objekty vezmou z modelu, jinak "sit" = index objektu v modelu (síť pro export).
//
// Výstup: JSON se stejnými klíči jako api.php (positions, pozice, pocet_instanci, pocet_podlozek,
// objekty, printer, kontrola_kolizi, limit, otocene_objekty, podlozky); jen s --import bez vstupu a bez exportu
// výsledek importu (objekty, soubor, modely, varovani). --export-3mf zapíše 3MF (u více podložek bez --podlozka
// soubor na podložku: vystup_podlozka_1.3mf, …), zapsané soubory vypíše na stderr.
// Exit code: 0 = OK, 1 = chybný vstup (i neznámá předvolba tiskárny, chyba importu/exportu), 2 = výsledek obsahuje kolizi.

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

/** ApiChyba jako chybný vstup (u chyb polí i seznam `chyby`). */
function chybaApi(ApiChyba $e): void
{
    chybnyVstup($e->zpravy(), $e->status === 400 && isset($e->details['chyby']) ? $e->details['chyby'] : []);
}

// --- Argumenty: jeden poziční (vstup.json nebo '-'), volby --import, --export-3mf, --podlozka (i ve tvaru --volba=hodnota).
$argumenty = array_slice($argv, 1);
if (in_array('-h', $argumenty, true) || in_array('--help', $argumenty, true)) {
    fwrite(STDOUT, "Použití: php bin/arrange.php [vstup.json | -] [--import model.3mf|model.stl] [--export-3mf vystup.3mf] [--podlozka N]\n"
        . "(bez argumentu nebo s '-' čte JSON ze stdin; --import bez vstupu a bez --export-3mf vypíše jen výsledek importu)\n");
    exit(EXIT_OK);
}
$volby = ['--import' => null, '--export-3mf' => null, '--podlozka' => null];
$pozicni = [];
for ($i = 0; $i < count($argumenty); $i++) {
    $arg = $argumenty[$i];
    $nazev = strpos($arg, '=') !== false ? substr($arg, 0, strpos($arg, '=')) : $arg;
    if (array_key_exists($nazev, $volby)) {
        if ($nazev !== $arg) {
            $volby[$nazev] = substr($arg, strlen($nazev) + 1);
        } else {
            if (!isset($argumenty[$i + 1])) chybnyVstup("volba {$nazev} potřebuje hodnotu.");
            $volby[$nazev] = $argumenty[++$i];
        }
    } elseif ($arg !== '-' && strpos($arg, '--') === 0) {
        chybnyVstup("neznámá volba {$arg} (viz --help).");
    } else {
        $pozicni[] = $arg;
    }
}
if (count($pozicni) > 1) chybnyVstup('zadej nejvýš jeden vstupní soubor.');

$model = null;
$soubor = null;
if ($volby['--import'] !== null) {
    $cesta = $volby['--import'];
    if (!is_file($cesta) || !is_readable($cesta)) chybnyVstup("soubor modelu '{$cesta}' neexistuje nebo nejde číst.");
    $soubor = ['cesta' => $cesta, 'nazev' => basename($cesta)];
}

// Jen import: objekty z modelu (pro úpravu a další běh), nic se nepočítá.
if ($soubor !== null && empty($pozicni) && $volby['--export-3mf'] === null) {
    try {
        $vysledekImportu = ImportModelu::souhrn(ImportModelu::nacti($soubor['cesta'], $soubor['nazev'], false));
    } catch (ApiChyba $e) {
        chybaApi($e);
    }
    foreach ($vysledekImportu['varovani'] as $varovani) fwrite(STDERR, "Varování: {$varovani}\n");
    fwrite(STDOUT, json_encode($vysledekImportu, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(EXIT_OK);
}

// --- Vstup JSON (s --import bez vstupního souboru prázdný: objekty z modelu, nastavení výchozí).
if (empty($pozicni) && $soubor !== null) {
    $vstup = [];
} else {
    $vstupniSoubor = $pozicni[0] ?? '-';
    if ($vstupniSoubor === '-') {
        $raw = stream_get_contents(STDIN);
    } else {
        if (!is_file($vstupniSoubor) || !is_readable($vstupniSoubor)) chybnyVstup("soubor '{$vstupniSoubor}' neexistuje nebo nejde číst.");
        $raw = file_get_contents($vstupniSoubor);
    }
    if ($raw === false || trim($raw) === '') chybnyVstup('prázdný vstup.');
    $vstup = json_decode($raw, true);
    if (!is_array($vstup)) chybnyVstup('vstup není platný JSON objekt (' . json_last_error_msg() . ').');
}

// Validace je společná s API v1 (src/VstupVypoctu.php): všechny chyby po polích najednou, neznámá předvolba
// tiskárny je chyba vstupu (exit 1). Tiskárna a nastavení: chybějící hodnoty doplní předvolba (výchozí Prusa MINI)
// a VYCHOZI_NASTAVENI; "tiskarna": "<id>", nebo "printer": "<id>" místo objektu, objekt "printer" přepíše předvolbu.
try {
    if ($volby['--export-3mf'] !== null) {
        // Import + výpočet + export společně s API v1 export.php (Api::export()).
        $export = Api::export($vstup, false, $soubor, Api::cisloPodlozky($volby['--podlozka']));
        $vystup = $export['vysledek'];
        $cil = $volby['--export-3mf'];
        $zaklad = preg_replace('/\.3mf$/i', '', $cil);
        foreach ($export['soubory'] as $nazev => $obsah) {
            $cesta = preg_match('/_podlozka_(\d+)\.3mf$/', $nazev, $m) && count($export['soubory']) > 1 ? "{$zaklad}_podlozka_{$m[1]}.3mf" : $cil;
            if ($volby['--podlozka'] !== null) $cesta = $cil;
            if (@file_put_contents($cesta, $obsah) === false) chybnyVstup("soubor '{$cesta}' nejde zapsat.");
            fwrite(STDERR, "Zapsáno: {$cesta}\n");
        }
    } else {
        if ($soubor !== null) {
            $model = ImportModelu::nacti($soubor['cesta'], $soubor['nazev'], false);
            if (!isset($vstup['objekty'])) $vstup['objekty'] = ImportModelu::objektyProVstup($model);
        } elseif ($volby['--podlozka'] !== null) {
            chybnyVstup('volba --podlozka platí jen s --export-3mf.');
        }
        $vystup = Api::vypocet(VstupVypoctu::over($vstup));
    }
} catch (ApiChyba $e) {
    chybaApi($e);
}

fwrite(STDOUT, json_encode($vystup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
exit((is_array($vystup['kontrola_kolizi']) && $vystup['kontrola_kolizi']['ok']) ? EXIT_OK : EXIT_KOLIZE);

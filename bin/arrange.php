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

require_once __DIR__ . '/../src/SequentialPrintCalculator.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const EXIT_OK = 0;
const EXIT_CHYBNY_VSTUP = 1;
const EXIT_KOLIZE = 2;

function chybnyVstup(string $zprava): void
{
    fwrite(STDERR, "Chybný vstup: {$zprava}\n");
    fwrite(STDOUT, json_encode(['error' => $zprava], JSON_UNESCAPED_UNICODE) . "\n");
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

// Tiskárna a nastavení: chybějící hodnoty doplní předvolba (výchozí Prusa MINI) a VYCHOZI_NASTAVENI.
// Předvolba tiskárny (config/tiskarny.json): "tiskarna": "<id>", nebo "printer": "<id>" místo objektu.
// Vlastní hodnoty v objektu "printer" přepíšou hodnoty předvolby.
$printer = $vstup['printer'] ?? [];
$options = $vstup['options'] ?? [];
$idTiskarny = $vstup['tiskarna'] ?? null;
if (is_string($printer)) {
    if ($idTiskarny !== null && $idTiskarny !== $printer) chybnyVstup("'tiskarna' a 'printer' určují různé předvolby.");
    $idTiskarny = $printer;
    $printer = [];
}
if ($idTiskarny !== null && !is_string($idTiskarny)) chybnyVstup("'tiskarna' musí být id předvolby (text).");
if ($idTiskarny !== null && !PredvolbyTiskaren::existuje($idTiskarny)) {
    chybnyVstup("neznámá tiskárna '{$idTiskarny}'. Dostupné předvolby: " . implode(', ', array_keys(PredvolbyTiskaren::seznam())) . '.');
}
if (!is_array($printer)) chybnyVstup("'printer' musí být objekt nebo id předvolby.");
if (!is_array($options)) chybnyVstup("'options' musí být objekt.");
if (isset($options['rozprostreni_y_rezim']) && !in_array($options['rozprostreni_y_rezim'], SequentialPrintCalculator::ROZPROSTRENI_Y_REZIMY, true)) {
    chybnyVstup("'options.rozprostreni_y_rezim' musí být " . implode(' nebo ', SequentialPrintCalculator::ROZPROSTRENI_Y_REZIMY) . '.');
}
if (isset($options['povolit_rotaci_vsem']) && !is_bool($options['povolit_rotaci_vsem']) && !in_array($options['povolit_rotaci_vsem'], [0, 1], true)) {
    chybnyVstup("'options.povolit_rotaci_vsem' musí být true/false.");
}
if (isset($options['vice_podlozek']) && !is_bool($options['vice_podlozek']) && !in_array($options['vice_podlozek'], [0, 1], true)) {
    chybnyVstup("'options.vice_podlozek' musí být true/false.");
}
if (isset($options['max_podlozek']) && !(is_int($options['max_podlozek']) && $options['max_podlozek'] >= 1 && $options['max_podlozek'] <= SequentialPrintCalculator::MAXIMALNI_POCET_PODLOZEK)) {
    chybnyVstup("'options.max_podlozek' musí být celé číslo 1 až " . SequentialPrintCalculator::MAXIMALNI_POCET_PODLOZEK . '.');
}
$rotaceVsem = !empty($options['povolit_rotaci_vsem']);
foreach ($printer as $klic => $hodnota) {
    if ($klic === 'head_steps') {
        if (!is_array($hodnota)) chybnyVstup("'printer.head_steps' musí být pole.");
        continue;
    }
    if (!is_numeric($hodnota)) chybnyVstup("'printer.{$klic}' musí být číslo.");
}
$tiskarna = PredvolbyTiskaren::sestav($idTiskarny, $printer);

$objekty = $vstup['objekty'] ?? null;
if (!is_array($objekty) || empty($objekty)) chybnyVstup("chybí neprázdné pole 'objekty'.");
if (array_keys($objekty) !== range(0, count($objekty) - 1)) chybnyVstup("'objekty' musí být pole (seznam), ne objekt.");

foreach ($objekty as $i => $objekt) {
    $cislo = $i + 1;
    if (!is_array($objekt)) chybnyVstup("objekt {$cislo} musí být objekt s x, y, z.");
    if (isset($objekt['rotace']) && !is_bool($objekt['rotace']) && !in_array($objekt['rotace'], [0, 1], true)) {
        chybnyVstup("objekt {$cislo}: 'rotace' musí být true/false.");
    }
    foreach (['x', 'y', 'z'] as $osa) {
        if (!isset($objekt[$osa]) || !is_numeric($objekt[$osa]) || (float)$objekt[$osa] <= 0) {
            chybnyVstup("objekt {$cislo}: '{$osa}' musí být kladné číslo.");
        }
    }
    $limity = [];
    foreach (['x', 'y', 'z'] as $osa) {
        $limity[$osa] = (float)$tiskarna[$osa] - ($osa === 'x' ? (float)$tiskarna['posun_zprava'] : 0.0);
    }
    // S povolenou rotací stačí, když se objekt vejde aspoň otočený o 90° (x a y zaměněné).
    $vejdeSeOtoceny = ($rotaceVsem || !empty($objekt['rotace']))
        && (float)$objekt['y'] <= $limity['x'] && (float)$objekt['x'] <= $limity['y'];
    foreach (['x', 'y', 'z'] as $osa) {
        if ($osa !== 'z' && $vejdeSeOtoceny) continue;
        if ((float)$objekt[$osa] > $limity[$osa]) {
            chybnyVstup("objekt {$cislo}: '{$osa}' = {$objekt[$osa]} mm je větší než tisková plocha ({$limity[$osa]} mm).");
        }
    }
    if (isset($objekt['instances'])) {
        if (!is_array($objekt['instances']) || !array_key_exists('d', $objekt['instances'])) {
            chybnyVstup("objekt {$cislo}: 'instances' musí být objekt s klíčem 'd'.");
        }
        $d = $objekt['instances']['d'];
        if ($d !== 'max' && !(is_numeric($d) && (int)$d == $d && (int)$d >= 1)) {
            chybnyVstup("objekt {$cislo}: 'instances.d' musí být celé číslo >= 1 nebo \"max\".");
        }
    }
    if (isset($objekt['nazev'])) {
        // Název propíše do výstupu kalkulátor (pozice, objekty, limit), oříznutý na MAXIMALNI_DELKA_NAZVU znaků.
        if (!is_string($objekt['nazev'])) chybnyVstup("objekt {$cislo}: 'nazev' musí být text.");
    }
}

$calculator = new SequentialPrintCalculator($tiskarna, $options);
$vystup = SequentialPrintCalculator::vystupApi($calculator->calculate($objekty));


fwrite(STDOUT, json_encode($vystup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
exit((is_array($vystup['kontrola_kolizi']) && $vystup['kontrola_kolizi']['ok']) ? EXIT_OK : EXIT_KOLIZE);

<?php
// CLI: výpočet rozmístění pro sekvenční tisk (bez DB, bez webového serveru).
// Použití:
//   php bin/arrange.php vstup.json
//   php bin/arrange.php < vstup.json
//   echo '{"objekty":[{"x":50,"y":50,"z":100,"instances":{"d":3}}]}' | php bin/arrange.php
//
// Vstup (JSON):
//   {"objekty": [{"x": 50, "y": 50, "z": 100, "instances": {"d": 3 | "max"}, "nazev": "volitelné"}],
//    "options": {...}, "printer": {...}}      // options a printer jsou volitelné (výchozí = Prusa MINI jako v UI)
//
// Výstup: JSON se stejnými klíči jako api.php (positions, pozice, pocet_instanci, pocet_podlozek,
// objekty, printer, kontrola_kolizi, limit). Exit code: 0 = OK, 1 = chybný vstup, 2 = výsledek obsahuje kolizi.

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

// Tiskárna a nastavení: chybějící hodnoty doplní kalkulátor (VYCHOZI_TISKARNA / VYCHOZI_NASTAVENI).
$printer = $vstup['printer'] ?? [];
$options = $vstup['options'] ?? [];
if (!is_array($printer)) chybnyVstup("'printer' musí být objekt.");
if (!is_array($options)) chybnyVstup("'options' musí být objekt.");
if (isset($options['rozprostreni_y_rezim']) && !in_array($options['rozprostreni_y_rezim'], SequentialPrintCalculator::ROZPROSTRENI_Y_REZIMY, true)) {
    chybnyVstup("'options.rozprostreni_y_rezim' musí být " . implode(' nebo ', SequentialPrintCalculator::ROZPROSTRENI_Y_REZIMY) . '.');
}
foreach ($printer as $klic => $hodnota) {
    if ($klic === 'head_steps') {
        if (!is_array($hodnota)) chybnyVstup("'printer.head_steps' musí být pole.");
        continue;
    }
    if (!is_numeric($hodnota)) chybnyVstup("'printer.{$klic}' musí být číslo.");
}
$tiskarna = array_merge(SequentialPrintCalculator::VYCHOZI_TISKARNA, $printer);

$objekty = $vstup['objekty'] ?? null;
if (!is_array($objekty) || empty($objekty)) chybnyVstup("chybí neprázdné pole 'objekty'.");
if (array_keys($objekty) !== range(0, count($objekty) - 1)) chybnyVstup("'objekty' musí být pole (seznam), ne objekt.");

foreach ($objekty as $i => $objekt) {
    $cislo = $i + 1;
    if (!is_array($objekt)) chybnyVstup("objekt {$cislo} musí být objekt s x, y, z.");
    foreach (['x', 'y', 'z'] as $osa) {
        if (!isset($objekt[$osa]) || !is_numeric($objekt[$osa]) || (float)$objekt[$osa] <= 0) {
            chybnyVstup("objekt {$cislo}: '{$osa}' musí být kladné číslo.");
        }
        $limit = (float)$tiskarna[$osa] - ($osa === 'x' ? (float)$tiskarna['posun_zprava'] : 0.0);
        if ((float)$objekt[$osa] > $limit) {
            chybnyVstup("objekt {$cislo}: '{$osa}' = {$objekt[$osa]} mm je větší než tisková plocha ({$limit} mm).");
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

$calculator = new SequentialPrintCalculator($printer, $options);
$vystup = SequentialPrintCalculator::vystupApi($calculator->calculate($objekty));


fwrite(STDOUT, json_encode($vystup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
exit((is_array($vystup['kontrola_kolizi']) && $vystup['kontrola_kolizi']['ok']) ? EXIT_OK : EXIT_KOLIZE);

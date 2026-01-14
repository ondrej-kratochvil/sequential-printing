<?php
/**
 * Minimal test runner (bez Composeru).
 *
 * Spuštění:
 *   php tests/run.php
 *
 * Web (omezeně):
 *   /tests/run.php?scenario=basic3
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=UTF-8');

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'SequentialPrintCalculator.php';

function fail(string $message): void {
    echo "FAIL: {$message}\n";
    if (PHP_SAPI !== 'cli') http_response_code(500);
    exit(1);
}

function ok(string $message): void {
    echo "OK: {$message}\n";
}

function runScenarioCli(array $get): array {
    // CLI i web poběží stejně (bez shell_exec), jen CLI má plný balík testů.
    $calc = new SequentialPrintCalculator(
        [
            "x" => 180, "y" => 180, "z" => 180,
            "posun_zprava" => 1,
            "Xr" => 12, "Xl" => 36.5,
            "Yr" => 15.5, "Yl" => 15.5,
            "vodici_tyce_Z" => 21,
            "vodici_tyce_Y" => 17.4,
        ],
        [
            "rozprostrit_instance_po_cele_podlozce" => true,
            "rozprostrit_instance_v_ose_x" => true,
            "rozprostrit_instance_v_ose_y" => true,
        ]
    );
    $res = $calc->calculate(isset($get["objekty"]) ? $get["objekty"] : []);
    return [
        "count" => $res["pocet_instanci"],
        "positions" => $res["datova_veta_pole"],
    ];
}

function assertSame($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        fail("{$label}: očekáváno " . var_export($expected, true) . ", ale bylo " . var_export($actual, true));
    }
}

function assertTrue(bool $cond, string $label): void {
    if (!$cond) {
        fail($label);
    }
}

function assertNear(float $expected, float $actual, float $eps, string $label): void {
    if (abs($expected - $actual) > $eps) {
        fail("{$label}: očekáváno ~" . $expected . ", ale bylo " . $actual);
    }
}

if (PHP_SAPI === 'cli') {
    // 1) Regrese: základní scénář nesmí spadnout a musí vrátit požadovaný počet instancí.
    $res = runScenarioCli([
        "objekty" => [
            ["x" => 50, "y" => 50, "z" => 100, "instances" => ["d" => 3]],
        ],
    ]);
    assertSame(3, $res["count"], "Počet instancí pro 50×50×100 (d=3)");
    assertTrue(is_array($res["positions"]) && count($res["positions"]) === 3, "Pozice musí být pole o délce 3");
    ok("Základní scénář (3 instance) vrací 3 pozice.");

    // 2) Regrese: „max“ (zde 99) nesmí spadnout a musí vrátit aspoň jednu pozici.
    $res = runScenarioCli([
        "objekty" => [
            ["x" => 50, "y" => 50, "z" => 100, "instances" => ["d" => 99]],
        ],
    ]);
    assertTrue(is_int($res["count"]) && $res["count"] > 0, "Max scénář: count musí být > 0");
    assertTrue(is_array($res["positions"]) && count($res["positions"]) === $res["count"], "Max scénář: počet pozic musí odpovídat count");
    ok("Max scénář (99) nespadl a vrátil nenulový výsledek.");

    // 3) Regrese: směr tisku se určuje podle profilu hlavy pro nejvyšší objekt.
    $calc = new SequentialPrintCalculator(
        [
            "x" => 180, "y" => 180, "z" => 300,
            "posun_zprava" => 0,
            "vodici_tyce_Z" => 0,
            "vodici_tyce_Y" => 0,
            "head_steps" => [
                ["z" => 0, "xl" => 100, "xr" => 1, "yl" => 1, "yr" => 1],   // => smer_X zprava_doleva
                ["z" => 200, "xl" => 1, "xr" => 100, "yl" => 1, "yr" => 1], // => smer_X zleva_doprava
            ],
        ],
        [
            "rozprostrit_instance_po_cele_podlozce" => false,
            "rozprostrit_instance_v_ose_x" => false,
            "rozprostrit_instance_v_ose_y" => false,
        ]
    );
    $res = $calc->calculate([
        ["x" => 10, "y" => 10, "z" => 150, "instances" => ["d" => 1]],
        ["x" => 10, "y" => 10, "z" => 250, "instances" => ["d" => 1]],
    ]);
    assertSame("zleva_doprava", $res["printer"]["smer_X"], "Směr X podle nejvyššího objektu");
    ok("Směr tisku podle nejvyššího objektu funguje.");

    // 4) Regrese: rozprostření v ose Y je po instancích:
    // ověř posuny jako rozdíl mezi výpočtem s Y=off a Y=on (abychom nepletli přirozené rozestupy).
    $printer = [
        "x" => 180, "y" => 180, "z" => 180,
        "posun_zprava" => 1,
        "Xr" => 12, "Xl" => 36.5,
        "Yr" => 15.5, "Yl" => 15.5,
        "vodici_tyce_Z" => 21,
        "vodici_tyce_Y" => 17.4,
    ];
    $obj = [["x" => 50, "y" => 40, "z" => 100, "instances" => ["d" => 99]]];

    $calcOff = new SequentialPrintCalculator($printer, [
        "rozprostrit_instance_po_cele_podlozce" => true,
        "rozprostrit_instance_v_ose_x" => true,
        "rozprostrit_instance_v_ose_y" => false,
    ]);
    $off = $calcOff->calculate($obj);

    $calcOn = new SequentialPrintCalculator($printer, [
        "rozprostrit_instance_po_cele_podlozce" => true,
        "rozprostrit_instance_v_ose_x" => true,
        "rozprostrit_instance_v_ose_y" => true,
    ]);
    $on = $calcOn->calculate($obj);

    assertSame(6, $on["pocet_instanci"], "Scénář 50×40×100 má dát 6 instancí");
    assertSame($off["pocet_instanci"], $on["pocet_instanci"], "Stejný počet instancí pro Y on/off");

    $zbyvaY = (float)$on["zbyva_v_ose_Y"];
    $step = $zbyvaY / (6 - 1);

    $ysOff = array_map(function ($p) { return (float)$p[2]; }, $off["datova_veta_pole"]);
    $ysOn = array_map(function ($p) { return (float)$p[2]; }, $on["datova_veta_pole"]);

    // 1. instance se neposouvá; n-tá instance se posune o (n-1)*step
    assertNear(0.0, $ysOn[0] - $ysOff[0], 0.2, "Y posun instance 1");
    assertNear($step, $ysOn[1] - $ysOff[1], 0.25, "Y posun instance 2");
    assertNear($step * 5, $ysOn[5] - $ysOff[5], 0.25, "Y posun instance 6");
    ok("Rozprostření v ose Y po instancích funguje.");

    ok("ALL OK");
    exit(0);
}

// Web režim: 1 scénář na request.
$scenario = $_GET['scenario'] ?? 'basic3';
$scenarioGet = null;
if ($scenario === 'basic3') {
    $scenarioGet = [
        "objekty" => [
            ["x" => 50, "y" => 50, "z" => 100, "instances" => ["d" => 3]],
        ],
    ];
}
if (!is_array($scenarioGet)) fail("Neznámý scenario. Použij ?scenario=basic3");

$res = runScenarioCli($scenarioGet);
assertSame(3, $res["count"], "Počet instancí pro 50×50×100 (d=3)");
assertTrue(is_array($res["positions"]) && count($res["positions"]) === 3, "Pozice musí být pole o délce 3");
ok("Základní scénář (3 instance) vrací 3 pozice.");
ok("ALL OK");


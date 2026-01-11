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

function fail(string $message): void {
    echo "FAIL: {$message}\n";
    if (PHP_SAPI !== 'cli') http_response_code(500);
    exit(1);
}

function ok(string $message): void {
    echo "OK: {$message}\n";
}

function runScenarioCli(array $get): array {
    $workspaceRoot = dirname(__DIR__);
    $indexPath = $workspaceRoot . DIRECTORY_SEPARATOR . 'index.php';

    $payload = var_export($get, true);
    $code = <<<PHP
        \$_GET = {$payload};
        ob_start();
        include "{$indexPath}";
        ob_end_clean();
        echo json_encode([
            "count" => \$pocet_instanci1 ?? null,
            "positions" => \$datova_veta_pole ?? null,
        ]);
        PHP;

    $cmd = 'php -r ' . escapeshellarg($code);
    $out = shell_exec($cmd);
    if ($out === null || $out === '') fail("Scénář selhal: prázdný výstup z `{$cmd}`");

    $decoded = json_decode($out, true);
    if (!is_array($decoded)) fail("Scénář selhal: nevalidní JSON výstup: {$out}");
    return $decoded;
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

    ok("ALL OK");
    exit(0);
}

// Web režim: bez refaktoru výpočtu nejde v jednom requestu bezpečně spustit více scénářů
// (index.php není knihovna, je to skript s globály + HTML výstupem).
$scenario = $_GET['scenario'] ?? 'basic3';
$scenarioGet = match ($scenario) {
    'basic3' => [
        "objekty" => [
            ["x" => 50, "y" => 50, "z" => 100, "instances" => ["d" => 3]],
        ],
    ],
    default => null,
};
if (!is_array($scenarioGet)) fail("Neznámý scenario. Použij ?scenario=basic3");

$oldGet = $_GET;
$_GET = $scenarioGet;
ob_start();
include dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.php';
ob_end_clean();
$_GET = $oldGet;

assertSame(3, $pocet_instanci1 ?? null, "Počet instancí pro 50×50×100 (d=3)");
assertTrue(is_array($datova_veta_pole ?? null) && count($datova_veta_pole) === 3, "Pozice musí být pole o délce 3");
ok("Základní scénář (3 instance) vrací 3 pozice.");
ok("ALL OK");


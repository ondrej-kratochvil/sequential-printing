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
        SequentialPrintCalculator::VYCHOZI_TISKARNA,
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
        "pozice" => $res["pozice"],
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

    // Pozice s metadaty: ktery objekt, poradi tisku a rada (integrace potrebuji vedet, ktery kus je ktery).
    // Scenar: organizer gelu 7. 11. 2026 (2 + 2 bunky ruznych rozmeru + 1 vyssi blok).
    $vstup = [
        ["x" => 49.79, "y" => 45.95, "z" => 10, "instances" => ["d" => 2]],
        ["x" => 51.00, "y" => 46.97, "z" => 10, "instances" => ["d" => 2]],
        ["x" => 80.55, "y" => 40.25, "z" => 11, "instances" => ["d" => 1]],
    ];
    $res = runScenarioCli(["objekty" => $vstup]);
    assertSame(5, $res["count"], "Mix 3 objektů: počet instancí");
    assertSame(count($res["positions"]), count($res["pozice"]), "pozice má stejně položek jako positions");
    $poctyObjektu = [];
    $predchoziRada = 0;
    foreach ($res["pozice"] as $k => $p) {
        assertSame($k + 1, $p["poradi"], "poradi jde po jedné v pořadí tisku");
        assertTrue(isset($vstup[$p["objekt"]]), "objekt je klíč ze vstupu");
        assertSame($res["positions"][$k], [$p["instance"], $p["X"], $p["Y"]], "pozice odpovídá positions");
        assertTrue($p["rada"] >= $predchoziRada, "řady v pořadí tisku neklesají");
        $predchoziRada = $p["rada"];
        $poctyObjektu[$p["objekt"]] = ($poctyObjektu[$p["objekt"]] ?? 0) + 1;
    }
    ksort($poctyObjektu);
    assertSame([0 => 2, 1 => 2, 2 => 1], $poctyObjektu, "počet instancí každého objektu");
    assertSame(2, $res["pozice"][count($res["pozice"]) - 1]["objekt"], "nejvyšší objekt (blok) se tiskne poslední");
    ok("Pozice s metadaty (objekt, pořadí, řada) odpovídají positions.");

    // --- Kontrola kolizí (ROADMAP B1) ---

    // Výchozí tiskárna: prázdné printer = VYCHOZI_TISKARNA (jediné místo s výchozími hodnotami).
    $sVychozi = (new SequentialPrintCalculator([], []))->calculate([["x" => 50, "y" => 50, "z" => 100, "instances" => ["d" => 3]]]);
    $sExplicitni = (new SequentialPrintCalculator(SequentialPrintCalculator::VYCHOZI_TISKARNA, SequentialPrintCalculator::VYCHOZI_NASTAVENI))
        ->calculate([["x" => 50, "y" => 50, "z" => 100, "instances" => ["d" => 3]]]);
    assertSame($sExplicitni["pozice"], $sVychozi["pozice"], "Prázdné printer/options = výchozí tiskárna a nastavení");
    ok("Výchozí tiskárna a nastavení se berou z jednoho místa.");

    // Regresní scénáře: oba směry tisku v X i Y, vysoké objekty nad vodicími tyčemi, mix rozměrů, „max“.
    $tiskarny = [
        "MINI (Xl>Xr, Yl=Yr)" => [SequentialPrintCalculator::VYCHOZI_TISKARNA, "zprava_doleva", "zepredu_dozadu"],
        "Xl<Xr" => [array_merge(SequentialPrintCalculator::VYCHOZI_TISKARNA, ["Xl" => 12, "Xr" => 36.5]), "zleva_doprava", "zepredu_dozadu"],
        "Yl<Yr" => [array_merge(SequentialPrintCalculator::VYCHOZI_TISKARNA, ["Yl" => 10, "Yr" => 30]), "zprava_doleva", "zepredu_dozadu"],
        "Yl>Yr" => [array_merge(SequentialPrintCalculator::VYCHOZI_TISKARNA, ["Yl" => 30, "Yr" => 10]), "zprava_doleva", "zezadu_dopredu"],
        "Xl<Xr, Yl>Yr" => [array_merge(SequentialPrintCalculator::VYCHOZI_TISKARNA, ["Xl" => 12, "Xr" => 36.5, "Yl" => 30, "Yr" => 10]), "zleva_doprava", "zezadu_dopredu"],
    ];
    $sadyObjektu = [
        "vysoké nad tyčemi" => [["x" => 50, "y" => 50, "z" => 100, "instances" => ["d" => 3]]],
        "vysoké max" => [["x" => 20, "y" => 30, "z" => 40, "instances" => ["d" => "max"]]],
        "nízké max" => [["x" => 20, "y" => 20, "z" => 10, "instances" => ["d" => "max"]]],
        "mix rozměrů" => [
            ["x" => 30, "y" => 60, "z" => 5, "instances" => ["d" => 4]],
            ["x" => 15, "y" => 15, "z" => 30, "instances" => ["d" => 6]],
            ["x" => 40, "y" => 25, "z" => 50, "instances" => ["d" => 3]],
        ],
        "organizer (mix nízkých)" => [
            ["x" => 49.79, "y" => 45.95, "z" => 10, "instances" => ["d" => 2]],
            ["x" => 51.00, "y" => 46.97, "z" => 10, "instances" => ["d" => 2]],
            ["x" => 80.55, "y" => 40.25, "z" => 11, "instances" => ["d" => 1]],
        ],
        "mix vysokých + max" => [
            ["x" => 70.8, "y" => 66.7, "z" => 61.4, "instances" => ["d" => 4]],
            ["x" => 51.7, "y" => 29.8, "z" => 38.6, "instances" => ["d" => "max"]],
        ],
    ];
    $nastaveni = [
        "vše zap." => ["rozprostrit_instance_po_cele_podlozce" => true, "rozprostrit_instance_v_ose_x" => true, "rozprostrit_instance_v_ose_y" => true],
        "vše vyp." => ["rozprostrit_instance_po_cele_podlozce" => false, "rozprostrit_instance_v_ose_x" => false, "rozprostrit_instance_v_ose_y" => false],
        "jen po celé podložce" => ["rozprostrit_instance_po_cele_podlozce" => true, "rozprostrit_instance_v_ose_x" => false, "rozprostrit_instance_v_ose_y" => false],
    ];
    $pocetScenaru = 0;
    foreach ($tiskarny as $nazevTiskarny => [$tiskarna, $smerX, $smerY]) {
        foreach ($sadyObjektu as $nazevSady => $sada) {
            foreach ($nastaveni as $nazevNastaveni => $opt) {
                $label = "{$nazevTiskarny} / {$nazevSady} / {$nazevNastaveni}";
                $res = (new SequentialPrintCalculator($tiskarna, $opt))->calculate($sada);
                assertSame($smerX, $res["printer"]["smer_X"], "{$label}: směr X");
                assertSame($smerY, $res["printer"]["smer_Y"], "{$label}: směr Y");
                assertTrue($res["pocet_instanci"] > 0, "{$label}: aspoň jedna instance");
                assertSame(true, $res["kontrola_kolizi"]["ok"], "{$label}: kontrola_kolizi.ok");
                // Nespoléhám jen na příznak z kalkulátoru: výstup znovu ověří samostatný checker.
                $nezavisle = (new CollisionChecker($res["printer"]))->check(CollisionChecker::kusyZVysledku($res["pozice"], $res["objekty"]));
                assertSame(true, $nezavisle["ok"], "{$label}: nezávislá kontrola výstupu");
                assertSame($res["pocet_instanci"], $nezavisle["pocet_kusu"], "{$label}: počet kusů v kontrole");
                $soucetR = 0;
                foreach ($res["objekty"] as $o) $soucetR += $o["instances"]["r"];
                assertSame($res["pocet_instanci"], $soucetR, "{$label}: součet instances[r] = počet instancí");
                $pocetScenaru++;
            }
        }
    }
    ok("Regresní scénáře bez kolize ({$pocetScenaru}× tiskárna/objekty/nastavení).");

    // Dřív kolizní případ (odhalený kontrolou): 3× 50×50×100 jen s rozprostřením po celé podložce.
    // Hlava při tisku 3. kusu (od jeho levé hrany − Xl) zasahovala do 2. kusu o 24,5 mm; kus se teď posune v Y.
    $res = (new SequentialPrintCalculator([], ["rozprostrit_instance_po_cele_podlozce" => true, "rozprostrit_instance_v_ose_x" => false, "rozprostrit_instance_v_ose_y" => false]))
        ->calculate([["x" => 50, "y" => 50, "z" => 100, "instances" => ["d" => 3]]]);
    assertSame(3, $res["pocet_instanci"], "Opravený případ: počet instancí zůstane");
    assertSame(true, $res["kontrola_kolizi"]["ok"], "Opravený případ: bez kolize");
    assertSame(true, $res["kontrola_kolizi"]["upraveno"], "Opravený případ: výpočet rozmístění upravil");
    assertSame("hlava", $res["kontrola_kolizi"]["puvodni_konflikty"][0]["typ"], "Opravený případ: původní konflikt hlavy");
    assertNear(24.5, $res["kontrola_kolizi"]["puvodni_konflikty"][0]["presah_mm"], 0.01, "Opravený případ: původní přesah");
    ok("Kolizní rozmístění kalkulátor opraví (posun v ose Y) a nahlásí původní konflikt.");

    // Checker sám: ručně sestavené rozmístění (MINI: tisk zprava doleva, zepředu dozadu).
    $mini = (new SequentialPrintCalculator([], []))->calculate([["x" => 10, "y" => 10, "z" => 10]])["printer"];
    $checker = new CollisionChecker($mini);
    $kus = function (int $poradi, float $levy, float $predni, float $x, float $y, float $z): array {
        return ["poradi" => $poradi, "objekt" => 0, "instance" => $poradi, "X" => $levy + $x / 2, "Y" => $predni + $y / 2, "x" => $x, "y" => $y, "z" => $z];
    };

    // a) hlava: 2. kus jen 5 mm vlevo od 1. (potřeba Xr = 12) => přesah 7 mm
    $k = $checker->check([$kus(1, 129, 0, 50, 30, 10), $kus(2, 74, 0, 50, 30, 10)]);
    assertSame(false, $k["ok"], "Checker: malá mezera v X je kolize");
    assertSame("hlava", $k["konflikty"][0]["typ"], "Checker: typ hlava");
    assertSame(2, $k["konflikty"][0]["poradi"], "Checker: kolidující kus");
    assertSame(1, $k["konflikty"][0]["s_poradi"], "Checker: s dřívějším kusem");
    assertNear(7.0, $k["konflikty"][0]["presah_mm"], 0.01, "Checker: přesah hlavy");

    // b) stejné rozmístění s mezerou přesně Xr je OK
    $k = $checker->check([$kus(1, 129, 0, 50, 30, 10), $kus(2, 67, 0, 50, 30, 10)]);
    assertSame(true, $k["ok"], "Checker: mezera Xr je bez kolize");

    // c) kolize s kusem, který není v předchozí řadě ani předchozí v pořadí (#3 do #1):
    //    #3 je v 2. řadě, ale hlava (přední hrana − Yl) sahá do #1 v 1. řadě.
    $k = $checker->check([
        $kus(1, 129, 0, 50, 40, 10),
        $kus(2, 10, 0, 20, 20, 10),
        $kus(3, 100, 50, 50, 30, 10),
    ]);
    assertSame(false, $k["ok"], "Checker: kolize s ne-předchozím kusem");
    assertSame([3, 1], [$k["konflikty"][0]["poradi"], $k["konflikty"][0]["s_poradi"]], "Checker: #3 koliduje s #1");
    assertNear(5.5, $k["konflikty"][0]["presah_mm"], 0.01, "Checker: přesah 40 + 15,5 − 50");

    // d) vodicí tyče: vysoký kus (z 50 > 21) a další kus ve stejné řadě; tyč (17,4 mm za tryskou) přejede přes 1. kus
    $k = $checker->check([$kus(1, 129, 0, 50, 40, 50), $kus(2, 67, 0, 50, 40, 50)]);
    assertSame(false, $k["ok"], "Checker: vodicí tyče přes vysoký kus");
    assertSame("tyce", $k["konflikty"][0]["typ"], "Checker: typ tyce");
    assertNear(22.6, $k["konflikty"][0]["presah_mm"], 0.01, "Checker: přesah tyčí 40 − 17,4");
    // ...nízký 1. kus (z 20 <= 21) tyčím nevadí
    $k = $checker->check([$kus(1, 129, 0, 50, 40, 20), $kus(2, 67, 0, 50, 40, 50)]);
    assertSame(true, $k["ok"], "Checker: nízký kus pod tyčemi je OK");

    // e) tisk zezadu dopředu (Yl > Yr): tyč je před tryskou, kontrola v opačném směru
    $checkerZezadu = new CollisionChecker(array_merge($mini, ["Yl" => 30, "Yr" => 10, "smer_Y" => "zezadu_dopredu"]));
    $k = $checkerZezadu->check([$kus(1, 129, 140, 50, 40, 50), $kus(2, 67, 140, 50, 40, 50)]);
    assertSame("tyce", $k["konflikty"][0]["typ"] ?? null, "Checker zezadu: vodicí tyče přes vysoký kus");
    $k = $checkerZezadu->check([$kus(1, 129, 140, 50, 40, 50), $kus(2, 67, 140 - 40 + 17.4 - 40, 50, 40, 50)]);
    assertSame(true, $k["ok"], "Checker zezadu: kus posunutý dopředu o (40 − 17,4) je OK");

    // f) kus mimo podložku
    $k = $checker->check([$kus(1, 150, 0, 50, 30, 10)]);
    assertSame("podlozka", $k["konflikty"][0]["typ"] ?? null, "Checker: kus mimo podložku");
    ok("CollisionChecker najde kolizi hlavy, vodicích tyčí i přesah z podložky.");

    // --- CLI bin/arrange.php ---
    $spustCli = function (string $stdin, array $args = []): array {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'arrange.php');
        foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
        $proc = proc_open($cmd, [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
        if (!is_resource($proc)) fail("CLI: nelze spustit {$cmd}");
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ["code" => $code, "out" => $out, "err" => $err, "json" => json_decode($out, true)];
    };

    $cli = $spustCli(json_encode(["objekty" => [
        ["x" => 50, "y" => 50, "z" => 100, "instances" => ["d" => 3], "nazev" => "krabička"],
        ["x" => 20, "y" => 20, "z" => 10, "instances" => ["d" => "max"]],
    ]]));
    assertSame(0, $cli["code"], "CLI: exit code 0 (stderr: {$cli["err"]})");
    assertTrue(is_array($cli["json"]), "CLI: výstup je JSON");
    foreach (["positions", "pozice", "pocet_instanci", "pocet_podlozek", "objekty", "printer", "kontrola_kolizi"] as $klic) {
        assertTrue(array_key_exists($klic, $cli["json"]), "CLI: výstup obsahuje '{$klic}'");
    }
    assertSame(true, $cli["json"]["kontrola_kolizi"]["ok"], "CLI: kontrola_kolizi.ok");
    assertSame(count($cli["json"]["pozice"]), $cli["json"]["pocet_instanci"], "CLI: počet pozic");
    $sNazvem = array_values(array_filter($cli["json"]["pozice"], function ($p) { return $p["objekt"] === 0; }));
    assertSame("krabička", $sNazvem[0]["nazev"] ?? null, "CLI: nazev objektu se propíše do pozice");
    assertSame(179.0, (float)$cli["json"]["printer"]["x"], "CLI: výchozí tiskárna (180 − posun_zprava)");

    // Stejný výsledek jako přímé volání kalkulátoru (= api.php).
    $primo = SequentialPrintCalculator::vystupApi((new SequentialPrintCalculator([], []))->calculate([["x" => 50, "y" => 50, "z" => 100, "instances" => ["d" => 3]]]));
    $cli2 = $spustCli(json_encode(["objekty" => [["x" => 50, "y" => 50, "z" => 100, "instances" => ["d" => 3]]]]));
    assertSame(json_decode(json_encode($primo), true), $cli2["json"], "CLI: stejný výstup jako vystupApi()");

    // Vstup ze souboru (argument) + vlastní tiskárna a nastavení.
    $tmp = tempnam(sys_get_temp_dir(), "arrange");
    file_put_contents($tmp, json_encode([
        "objekty" => [["x" => 30, "y" => 30, "z" => 30, "instances" => ["d" => "max"]]],
        "printer" => ["Xl" => 12, "Xr" => 36.5],
        "options" => ["rozprostrit_instance_v_ose_x" => false],
    ]));
    $cli3 = $spustCli("", [$tmp]);
    unlink($tmp);
    assertSame(0, $cli3["code"], "CLI soubor: exit code 0 (stderr: {$cli3["err"]})");
    assertSame("zleva_doprava", $cli3["json"]["printer"]["smer_X"], "CLI soubor: vlastní tiskárna");

    // Chybný vstup => exit 1 + {"error": ...}
    foreach ([
        "není JSON" => "tohle není json",
        "chybí objekty" => '{"options":{}}',
        "záporný rozměr" => '{"objekty":[{"x":-5,"y":10,"z":10}]}',
        "větší než podložka" => '{"objekty":[{"x":500,"y":10,"z":10}]}',
        "špatné instances" => '{"objekty":[{"x":10,"y":10,"z":10,"instances":{"d":"hodne"}}]}',
        "printer není objekt" => '{"objekty":[{"x":10,"y":10,"z":10}],"printer":5}',
    ] as $popis => $vstup) {
        $cli = $spustCli($vstup);
        assertSame(1, $cli["code"], "CLI chybný vstup ({$popis}): exit code 1");
        assertTrue(isset($cli["json"]["error"]), "CLI chybný vstup ({$popis}): JSON s 'error'");
    }
    ok("CLI bin/arrange.php: výstup, exit kódy 0/1, vstup ze stdin i ze souboru.");

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


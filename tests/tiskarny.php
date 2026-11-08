<?php
/**
 * Testy tiskáren v DB (Milník D3). Načítá je tests/run.php (sdílí fail/ok/assert*, $spustEndpoint, $chybaV1, $spustStranku …).
 *
 * Bez DB: validace formuláře tiskárny (head_steps vzestupně, rozsahy), id db_<číslo>, převod na parametry kalkulátoru,
 * předvolby jen ze souboru, ucet.php tiskárny bez DB.
 * S DB (TEST_DB_DSN): vlastní tiskárna, návrh, schválení, návrh úpravy schválené tiskárny, schválené ve výběru
 * (PredvolbyTiskaren, api/v1/printers.php, CLI), vlastní neschválené jen pro vlastníka. Data se po sobě smažou.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'TiskarnyDb.php';

$formular = [
    "name" => "  Test tiskárna ", "x" => "180", "y" => "180", "z" => "180", "posun_zprava" => "1",
    "Xl" => "36,5", "Xr" => "12", "Yl" => "15.5", "Yr" => "15.5", "vodici_tyce_Z" => "21", "vodici_tyce_Y" => "17.4", "poznamka" => "měřeno",
    "head_steps" => [["z" => "30", "Xl" => "20", "Xr" => "12", "Yl" => "15.5", "Yr" => "15.5"], ["z" => "", "Xl" => "", "Xr" => "", "Yl" => "", "Yr" => ""], ["z" => "60", "Xl" => "10", "Xr" => "10", "Yl" => "10", "Yr" => "10"]],
];
$o = TiskarnyDb::overVstup($formular);
assertSame([], $o["chyby"], "Tiskárna: platný formulář");
assertSame(["Test tiskárna", 36.5, 2], [$o["tiskarna"]["name"], $o["tiskarna"]["Xl"], count($o["tiskarna"]["head_steps"])], "Tiskárna: název oříznutý, desetinná čárka, prázdný schod přeskočen");
$spatny = $formular;
$spatny["head_steps"][2]["z"] = "20";
assertSame(["head_steps.1"], array_keys(TiskarnyDb::overVstup($spatny)["chyby"]), "Tiskárna: výšky schodů musí jít vzestupně (chyba u 2. uloženého schodu)");
$spatny = $formular;
$spatny["head_steps"][0]["z"] = "0";
assertTrue(isset(TiskarnyDb::overVstup($spatny)["chyby"]["head_steps.0"]), "Tiskárna: schod z = 0 nejde (základ jsou rozměry hlavy)");
$spatny = $formular;
$spatny["head_steps"][2]["z"] = "200";
assertTrue(strpos(TiskarnyDb::overVstup($spatny)["chyby"]["head_steps.1"] ?? "", "výška tisku") !== false, "Tiskárna: schod nad výškou tisku Z");
$spatny = $formular;
$spatny["head_steps"][0]["Xr"] = "";
assertTrue(isset(TiskarnyDb::overVstup($spatny)["chyby"]["head_steps.0"]), "Tiskárna: neúplný schod");
$spatny = $formular;
$spatny["x"] = "abc";
$spatny["Yr"] = "-1";
$spatny["posun_zprava"] = "600";
$spatny["name"] = "";
assertSame(["name", "x", "posun_zprava", "Yr"], array_keys(TiskarnyDb::overVstup($spatny)["chyby"]), "Tiskárna: chyby po polích");
$spatny = $formular;
$spatny["posun_zprava"] = "180";
assertTrue(isset(TiskarnyDb::overVstup($spatny)["chyby"]["posun_zprava"]), "Tiskárna: korekce zprava menší než šířka");
assertSame(2, count(TiskarnyDb::varovaniProfilu($o["tiskarna"])), "Tiskárna: varování u schodů užších než hlava pod nimi");
$obalka = $o["tiskarna"];
$obalka["head_steps"] = [["z" => 30.0, "Xl" => 40.0, "Xr" => 12.0, "Yl" => 15.5, "Yr" => 16.0]];
assertSame([], TiskarnyDb::varovaniProfilu($obalka), "Tiskárna: obálka (nezužuje se) bez varování");
assertSame([12, null, null], [TiskarnyDb::cisloZId("db_12"), TiskarnyDb::cisloZId("db_0"), TiskarnyDb::cisloZId("prusa_mini")], "Tiskárna: id db_<číslo>");
$param = TiskarnyDb::naParametry($o["tiskarna"]);
assertSame([0.0, 36.5, 30.0, 60.0], [$param["head_steps"][0]["z"], $param["head_steps"][0]["Xl"], $param["head_steps"][1]["z"], $param["head_steps"][2]["z"]], "Tiskárna: kalkulátor dostane základ jako schod z = 0");
$bezSchodu = $o["tiskarna"];
$bezSchodu["head_steps"] = [];
assertTrue(!isset(TiskarnyDb::naParametry($bezSchodu)["head_steps"]), "Tiskárna bez schodů: bez head_steps");
// Kalkulátor se schody: vysoký kus (z 70) bere schod od 60 (užší hlava), výsledek bez kolize.
$res = (new SequentialPrintCalculator($param, []))->calculate([["x" => 30, "y" => 30, "z" => 70, "instances" => ["d" => "max"]]]);
assertTrue($res["kontrola_kolizi"]["ok"] && $res["pocet_instanci"] > 0, "Tiskárna se schody: výpočet bez kolize");
assertSame(["prusa_mini"], array_keys(PredvolbyTiskaren::seznam()), "Bez DB: předvolby jen ze souboru");
assertSame("soubor", PredvolbyTiskaren::detail("prusa_mini")["zdroj"], "Předvolba ze souboru má zdroj soubor");
$html = $spustStranku("ucet.php", ["stranka" => "tiskarny"]);
assertTrue(strpos($html, "Účty nejsou zapnuté") !== false, "ucet.php tiskárny bez DB: hláška");
ok("Tiskárny bez DB: validace (schody vzestupně, rozsahy), id db_<číslo>, parametry pro kalkulátor, předvolby jen ze souboru.");

$dsn = getenv("TEST_DB_DSN");
if ($dsn === false || $dsn === "") {
    ok("Tiskárny s DB přeskočeno (nastav TEST_DB_DSN).");
    return;
}
$dbEnv = ["API_REQUIRE_KEY" => "0", "DB_DSN" => $dsn, "DB_USER" => (string)getenv("TEST_DB_USER"), "DB_PASS" => (string)getenv("TEST_DB_PASS")];
$pdo = new PDO($dsn, $dbEnv["DB_USER"], $dbEnv["DB_PASS"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$znacka = bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO users (email, password_hash) VALUES (?, ?), (?, ?)")->execute(["tisk-a-{$znacka}@example.com", "x", "tisk-b-{$znacka}@example.com", "x"]);
$idA = (int)$pdo->query("SELECT id FROM users WHERE email = 'tisk-a-{$znacka}@example.com'")->fetchColumn();
$idB = (int)$pdo->query("SELECT id FROM users WHERE email = 'tisk-b-{$znacka}@example.com'")->fetchColumn();
$puvodniDsn = getenv("DB_DSN");
try {
    $t = $o["tiskarna"];
    $t["name"] = "Test tiskárna {$znacka}";
    $idT = TiskarnyDb::vytvor($pdo, $idA, $t);
    $nactena = TiskarnyDb::jedna($pdo, $idT);
    assertSame(["koncept", 36.5, [30.0, 60.0], "měřeno"], [TiskarnyDb::stav($nactena), $nactena["Xl"], array_column($nactena["head_steps"], "z"), $nactena["poznamka"]], "DB tiskárna: uložená jako koncept se schody");
    assertSame(false, TiskarnyDb::uprav($pdo, $idB, $idT, $t), "DB tiskárna: cizí uživatel neupraví");
    assertSame(false, TiskarnyDb::smaz($pdo, $idB, $idT), "DB tiskárna: cizí uživatel nesmaže");
    assertSame(false, TiskarnyDb::navrhni($pdo, $idB, $idT), "DB tiskárna: cizí uživatel nenavrhne");
    $t2 = $t;
    $t2["head_steps"] = [["z" => 40.0, "Xl" => 15.0, "Xr" => 12.0, "Yl" => 15.5, "Yr" => 15.5]];
    assertSame(true, TiskarnyDb::uprav($pdo, $idA, $idT, $t2), "DB tiskárna: vlastník upraví koncept");
    assertSame([40.0], array_column(TiskarnyDb::jedna($pdo, $idT)["head_steps"], "z"), "DB tiskárna: schody přepsané");

    // Předvolby s DB: koncept vidí jen vlastník (zahrnoutVlastni), ostatní ne.
    putenv("DB_DSN={$dsn}");
    putenv("DB_USER={$dbEnv["DB_USER"]}");
    putenv("DB_PASS={$dbEnv["DB_PASS"]}");
    $idPredvolby = TiskarnyDb::idPredvolby($idT);
    PredvolbyTiskaren::zahrnoutVlastni(null);
    assertSame(false, PredvolbyTiskaren::existuje($idPredvolby), "Předvolby s DB: koncept nevidí nikdo cizí");
    PredvolbyTiskaren::zahrnoutVlastni($idA);
    assertSame(true, PredvolbyTiskaren::existuje($idPredvolby), "Předvolby s DB: koncept vidí vlastník");
    assertTrue(strpos(PredvolbyTiskaren::seznam()[$idPredvolby], "neschválená") !== false, "Předvolby s DB: koncept označený jako neschválený");
    PredvolbyTiskaren::zahrnoutVlastni($idB);
    assertSame(false, PredvolbyTiskaren::existuje($idPredvolby), "Předvolby s DB: koncept nevidí jiný přihlášený");

    assertSame(true, TiskarnyDb::navrhni($pdo, $idA, $idT), "DB tiskárna: návrh ke zveřejnění");
    assertSame("navrzeno", TiskarnyDb::stav(TiskarnyDb::jedna($pdo, $idT)), "DB tiskárna: stav navrženo");
    assertTrue(in_array($idT, array_column(TiskarnyDb::navrhy($pdo), "id"), true), "DB tiskárna: v seznamu návrhů pro admina");
    assertSame(true, TiskarnyDb::vratNavrh($pdo, $idT), "Admin: vrátit návrh");
    TiskarnyDb::navrhni($pdo, $idA, $idT);
    assertSame($idT, TiskarnyDb::schval($pdo, $idT), "Admin: schválit");
    assertSame([false, false], [TiskarnyDb::uprav($pdo, $idA, $idT, $t), TiskarnyDb::smaz($pdo, $idA, $idT)], "Schválenou tiskárnu vlastník neupraví ani nesmaže");
    PredvolbyTiskaren::zahrnoutVlastni(null);
    assertSame(true, PredvolbyTiskaren::existuje($idPredvolby), "Předvolby s DB: schválenou vidí všichni");
    assertSame([40.0], array_column(array_slice(PredvolbyTiskaren::parametry($idPredvolby)["head_steps"], 1), "z"), "Předvolby s DB: parametry se schody");
    $detail = PredvolbyTiskaren::detail($idPredvolby);
    assertSame(["db", false, ["poznamka" => "měřeno"]], [$detail["zdroj"], $detail["vychozi"], $detail["zdroje"]], "Předvolby s DB: detail (zdroj db, poznámka jako zdroj)");
    assertSame("prusa_mini", PredvolbyTiskaren::vychoziId(), "Předvolby s DB: výchozí zůstává ze souboru");

    // Návrh úpravy schválené tiskárny: po schválení přepíše původní (id zůstane), kopie zmizí.
    $t3 = $t2;
    $t3["name"] = "Test tiskárna {$znacka} v2";
    $t3["Xl"] = 30.0;
    $idKopie = TiskarnyDb::vytvor($pdo, $idB, $t3, $idT);
    assertSame($idT, TiskarnyDb::jedna($pdo, $idKopie)["replaces_printer_id"], "Návrh úpravy: odkaz na původní tiskárnu");
    assertSame(null, TiskarnyDb::schval($pdo, $idKopie), "Admin: neschválí koncept, který nebyl navržený");
    TiskarnyDb::navrhni($pdo, $idB, $idKopie);
    assertSame($idT, TiskarnyDb::schval($pdo, $idKopie), "Admin: schválení úpravy vrátí id původní tiskárny");
    $po = TiskarnyDb::jedna($pdo, $idT);
    assertSame([true, "Test tiskárna {$znacka} v2", 30.0, null], [$po["approved"], $po["name"], $po["Xl"], TiskarnyDb::jedna($pdo, $idKopie)], "Návrh úpravy: hodnoty přepsané, kopie smazaná");

    // API printers (veřejné, bez klíče) a CLI se schválenou tiskárnou z DB.
    $r = $spustEndpoint("api/v1/printers.php", "GET", [], null, $dbEnv);
    assertTrue($r["status"] === 200 && in_array($idPredvolby, array_column($r["json"]["tiskarny"] ?? [], "id"), true), "API printers s DB: schválená tiskárna v seznamu");
    $r = $spustEndpoint("api/v1/printers.php", "GET", ["id" => $idPredvolby], null, $dbEnv);
    assertSame([200, "db", 179.0], [$r["status"], $r["json"]["tiskarna"]["zdroj"] ?? null, (float)($r["json"]["tiskarna"]["plocha"]["x"] ?? 0)], "API printers?id=db_N");
    $r = $spustEndpoint("api/v1/arrange.php", "POST", [], json_encode(["objekty" => [["x" => 30, "y" => 30, "z" => 50, "instances" => ["d" => 4]]], "tiskarna" => $idPredvolby]), $dbEnv);
    assertSame([200, 4, true], [$r["status"], $r["json"]["pocet_instanci"] ?? null, $r["json"]["kontrola_kolizi"]["ok"] ?? null], "API arrange s tiskárnou z DB");
    assertSame(30.0, (float)($r["json"]["printer"]["Xl"] ?? 0), "API arrange: parametry tiskárny z DB");
    $proc = proc_open(phpPrikaz() . ' ' . escapeshellarg(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'arrange.php'), [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, null, array_merge(getenv(), $dbEnv));
    fwrite($pipes[0], json_encode(["objekty" => [["x" => 30, "y" => 30, "z" => 50, "instances" => ["d" => 4]]], "tiskarna" => $idPredvolby]));
    fclose($pipes[0]);
    $cli = json_decode(stream_get_contents($pipes[1]), true);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $kod = proc_close($proc);
    assertSame([0, 4], [$kod, $cli["pocet_instanci"] ?? null], "CLI s DB: tiskárna db_N");
    // Bez DB_DSN stejné id neexistuje (CLI bez DB = jen předvolby ze souboru).
    $r = $spustEndpoint("api/v1/arrange.php", "POST", [], json_encode(["objekty" => [["x" => 30, "y" => 30, "z" => 50]], "tiskarna" => $idPredvolby]), ["API_REQUIRE_KEY" => "0", "DB_DSN" => ""]);
    $chybaV1($r, 404, "neznama_tiskarna", "API bez DB: db_N neznámá");
    // index.php s DB, nepřihlášený: schválená tiskárna ve výběru.
    $html = $spustStranku("index.php", ["tiskarna" => $idPredvolby, "objekty" => [["x" => "30", "y" => "30", "z" => "50", "instances" => ["d" => "4"]]]], $dbEnv);
    assertTrue(preg_match('/<option value="' . $idPredvolby . '" selected="selected">Test tiskárna/', $html) === 1 && strpos($html, 'id="tiskova_podlozka"') !== false, "index.php s DB: schválená tiskárna ve výběru a výpočet");
    assertTrue(!preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $html), "index.php s DB: bez warningů");

    assertSame(true, TiskarnyDb::zrusZverejneni($pdo, $idT), "Admin: zrušit zveřejnění");
    PredvolbyTiskaren::zahrnoutVlastni(null);
    assertSame(false, PredvolbyTiskaren::existuje($idPredvolby), "Po zrušení zveřejnění tiskárna z výběru zmizí");
    assertSame(true, TiskarnyDb::smaz($pdo, $idA, $idT), "Vlastník smaže koncept");
} finally {
    putenv($puvodniDsn === false ? "DB_DSN" : "DB_DSN={$puvodniDsn}");
    PredvolbyTiskaren::zahrnoutVlastni(null);
    PredvolbyTiskaren::obnov();
    $pdo->prepare("DELETE FROM printers WHERE created_by_user_id IN (?, ?)")->execute([$idA, $idB]);
    $pdo->prepare("DELETE FROM users WHERE id IN (?, ?)")->execute([$idA, $idB]);
}
assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM printers WHERE name LIKE 'Test tiskárna {$znacka}%'")->fetchColumn(), "Úklid: testovací tiskárny smazané");
ok("Tiskárny s DB: koncept, návrh, schválení, návrh úpravy, viditelnost ve výběru, API printers/arrange, CLI, index.php, zrušení zveřejnění.");

<?php
/**
 * Testy účtů a projektů (Milník D2). Načítá je tests/run.php (sdílí fail/ok/assert* a $spustEndpoint, $chybaV1, $dbEnv …).
 *
 * Bez DB: validace e-mailu a hesla, očištění dat projektu, převod na parametry formuláře, index.php a ucet.php bez DB_DSN
 * (účty se nezobrazí, výpočet jede), API projects bez klíče.
 * S DB (TEST_DB_DSN): registrace, přihlášení, omezení pokusů, projekty a vlastnictví, API projects s klíčem.
 * Testovací data (uživatelé, pokusy) se po sobě smažou.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Ucty.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Projekty.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Relace.php';

// Stránka (index.php, ucet.php) jako samostatný proces: env, $_GET, $_POST, $_SERVER; vrací výstup včetně warningů.
$spustStranku = function (string $soubor, array $get = [], array $env = [], array $post = [], array $server = []): string {
    $koren = dirname(__DIR__);
    $skript = tempnam(sys_get_temp_dir(), "str");
    $php = '<?php ';
    foreach ($env as $k => $v) $php .= 'putenv(' . var_export($k . '=' . $v, true) . '); ';
    $php .= '$_GET = ' . var_export($get, true) . '; $_POST = ' . var_export($post, true) . '; ';
    $php .= '$_SERVER["REQUEST_METHOD"] = ' . var_export(empty($post) ? 'GET' : 'POST', true) . '; ';
    foreach ($server as $k => $v) $php .= '$_SERVER[' . var_export($k, true) . '] = ' . var_export($v, true) . '; ';
    $php .= 'include ' . var_export($koren . DIRECTORY_SEPARATOR . $soubor, true) . ';';
    file_put_contents($skript, $php);
    $cmd = phpPrikaz() . ' -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg($skript);
    $proc = proc_open($cmd, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
    if (!is_resource($proc)) fail("{$soubor}: nelze spustit {$cmd}");
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    unlink($skript);
    return $out;
};
$bezWarningu = function (string $html, string $label): void {
    assertTrue(!preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error):/', $html), "{$label}: bez warningů (" . substr($html, 0, 300) . ")");
};

// --- Bez DB: validace ---
assertSame(null, Ucty::overEmail("jan.novak@example.cz"), "E-mail: platný");
foreach (["", "bez-zavinace", "a@", "@example.cz", "a b@example.cz", str_repeat("a", 250) . "@example.cz"] as $spatny) {
    assertTrue(Ucty::overEmail($spatny) !== null, "E-mail: neplatný '" . substr($spatny, 0, 30) . "'");
}
assertSame("jan@example.cz", Ucty::normalizujEmail("  Jan@Example.CZ "), "E-mail: normalizace (trim, malá písmena)");
assertSame(null, Ucty::overHeslo("dlouhe-heslo", "dlouhe-heslo"), "Heslo: platné");
assertTrue(Ucty::overHeslo("kratke", "kratke") !== null, "Heslo: krátké");
assertTrue(Ucty::overHeslo("dlouhe-heslo", "jine-heslo1") !== null, "Heslo: neshoda");
assertTrue(Ucty::overHeslo(str_repeat("x", 73), str_repeat("x", 73)) !== null, "Heslo: nad 72 bajtů (bcrypt by ho zkrátil)");
assertTrue(Ucty::overHeslo(null, null) !== null, "Heslo: chybí");
putenv("REGISTRACE=0");
assertSame(false, Ucty::registraceZapnuta(), "REGISTRACE=0 registraci vypne");
putenv("REGISTRACE");
assertSame(true, Ucty::registraceZapnuta(), "Registrace výchozí zapnutá");
$puvodniDsn = getenv("DB_DSN");
putenv("DB_DSN=mysql:host=127.0.0.1");
assertSame(true, Relace::dbZapnuta(), "Relace::dbZapnuta s DB_DSN");
putenv("DB_DSN=");
assertSame(false, Relace::dbZapnuta(), "Relace::dbZapnuta bez DB_DSN");
putenv($puvodniDsn === false ? "DB_DSN" : "DB_DSN=" . $puvodniDsn);

// --- Bez DB: data projektu ---
$stav = [
    "version" => 1,
    "objekty" => [
        ["x" => "50,5", "y" => 50, "z" => "100", "instances" => ["d" => "3"], "nazev" => "  krabička <b> ", "rotace" => true, "navic" => "x"],
        ["x" => "abc", "y" => "", "z" => "10", "instances" => ["d" => "max"]],
        ["x" => "20", "y" => "20", "z" => "20", "instances" => ["d" => "-1"], "rotace" => false],
    ],
    "nastaveni" => ["rozprostrit_instance_v_ose_x" => 1, "rozprostreni_y_rezim" => "po_radach", "tiskarna" => "prusa_mini", "vice_podlozek" => "1",
        "max_podlozek" => 99, "neznamy" => "x", "povolit_rotaci_vsem" => 0],
];
$over = Projekty::overData($stav);
assertSame([], $over["chyby"], "Projekt: platná data bez chyb");
$d = $over["data"];
assertSame(["x" => "50.5", "y" => 50, "z" => "100", "instances" => ["d" => "3"], "nazev" => "krabička <b>", "rotace" => true], $d["objekty"][0], "Projekt: objekt očištěný (čárka, název, bez neznámých klíčů)");
assertSame(["x" => "", "y" => "", "z" => "10", "instances" => ["d" => "max"]], $d["objekty"][1], "Projekt: nečíselný rozměr prázdný, max zůstane");
assertSame("", $d["objekty"][2]["instances"]["d"], "Projekt: záporný počet zahozen");
assertTrue(!isset($d["objekty"][2]["rotace"]), "Projekt: rotace false se neukládá");
assertSame(20, $d["nastaveni"]["max_podlozek"], "Projekt: max_podlozek omezené na 20");
assertSame(["rozprostrit_instance_v_ose_x" => 1, "rozprostrit_instance_v_ose_y" => 0, "povolit_rotaci_vsem" => 0, "vice_podlozek" => 1, "rozprostrit_instance_po_cele_podlozce" => 0,
    "umistit_na_stred_v_ose_x" => 0, "umistit_na_stred_v_ose_y" => 0, "rozprostreni_y_rezim" => "po_radach", "tiskarna" => "prusa_mini", "max_podlozek" => 20], $d["nastaveni"], "Projekt: nastavení jen známé klíče");
assertSame("data.objekty", Projekty::overData(["objekty" => []])["chyby"][0]["pole"] ?? null, "Projekt: bez objektů = chyba");
assertSame("data", Projekty::overData("text")["chyby"][0]["pole"] ?? null, "Projekt: data nejsou objekt");
assertSame("data.objekty", Projekty::overData(["objekty" => array_fill(0, Projekty::MAX_OBJEKTU + 1, ["x" => 1])])["chyby"][0]["pole"] ?? null, "Projekt: příliš mnoho objektů");
assertSame("", Projekty::overData(["objekty" => [["x" => 1]], "nastaveni" => ["tiskarna" => "../etc"]])["data"]["nastaveni"]["tiskarna"], "Projekt: tiskárna jen bezpečné id");
assertSame(null, Projekty::upravNazev("   "), "Název projektu: prázdný");
assertSame(null, Projekty::upravNazev(str_repeat("ž", Projekty::MAX_DELKA_NAZVU + 1)), "Název projektu: příliš dlouhý");
assertSame("a b", Projekty::upravNazev(" a\tb "), "Název projektu: řídicí znaky = mezera");
$dotaz = Projekty::naDotaz($d);
assertSame(["x" => "50.5", "y" => "50", "z" => "100", "instances" => ["d" => "3"], "nazev" => "krabička <b>", "rotace" => "1"], $dotaz["objekty"][0], "Projekt na dotaz: objekt");
assertSame(["1", "1", "po_radach", "prusa_mini", "20"], [$dotaz["rozprostrit_instance_v_ose_x"], $dotaz["vice_podlozek"], $dotaz["rozprostreni_y_rezim"], $dotaz["tiskarna"], $dotaz["max_podlozek"]], "Projekt na dotaz: nastavení");
assertTrue(!isset($dotaz["rozprostrit_instance_v_ose_y"]) && !isset($dotaz["povolit_rotaci_vsem"]), "Projekt na dotaz: vypnuté volby chybí (jako neodeslaný checkbox)");
// Otevřený projekt dá stejný výpočet jako stejný formulář (index.php format=json).
$jsonProjekt = json_decode($spustStranku("index.php", Projekty::naDotaz(["objekty" => [["x" => "50", "y" => "50", "z" => "100", "instances" => ["d" => "3"]]], "nastaveni" => Projekty::overData(["objekty" => [["x" => 1]], "nastaveni" => ["rozprostrit_instance_v_ose_x" => 1, "rozprostrit_instance_v_ose_y" => 1, "rozprostrit_instance_po_cele_podlozce" => 1]])["data"]["nastaveni"]]) + ["format" => "json"]), true);
assertSame(3, $jsonProjekt["pocet_instanci"] ?? null, "Projekt na dotaz: index.php spočítá 3 kusy");

// --- Bez DB: UI bez účtů ---
$html = $spustStranku("index.php", ["objekty" => [["x" => "50", "y" => "50", "z" => "100", "instances" => ["d" => "3"]]]]);
$bezWarningu($html, "index.php bez DB");
assertTrue(strpos($html, 'id="ucet_panel"') === false && strpos($html, 'id="ulozit_projekt"') === false && strpos($html, 'ucet.php?stranka=prihlaseni') === false, "index.php bez DB: žádné prvky účtu");
assertTrue(strpos($html, 'id="tiskova_podlozka"') !== false, "index.php bez DB: výpočet a vizualizace fungují");
$html = $spustStranku("ucet.php");
$bezWarningu($html, "ucet.php bez DB");
assertTrue(strpos($html, "Účty nejsou zapnuté") !== false && strpos($html, 'href="index.php"') !== false, "ucet.php bez DB: srozumitelná hláška a odkaz na výpočet");
$r = $spustEndpoint("api/v1/projects.php", "GET");
$chybaV1($r, 403, "vyzaduje_klic", "v1 projects bez klíče (API_REQUIRE_KEY=0)");
$vypis = shell_exec(phpPrikaz() . ' ' . escapeshellarg(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'migrace.php') . ' --vypis');
assertTrue(strpos((string)$vypis, "001_ucty_projekty.sql") !== false, "bin/migrace.php --vypis zná migraci 001");
ok("Účty bez DB: validace e-mailu a hesla, data projektu, převod na formulář, UI a ucet.php bez DB_DSN, projects bez klíče.");

// --- S DB (TEST_DB_DSN) ---
$dsn = getenv("TEST_DB_DSN");
if ($dsn === false || $dsn === "") {
    ok("Účty a projekty s DB přeskočeno (nastav TEST_DB_DSN).");
    return;
}
$dbEnv = ["API_REQUIRE_KEY" => "1", "DB_DSN" => $dsn, "DB_USER" => (string)getenv("TEST_DB_USER"), "DB_PASS" => (string)getenv("TEST_DB_PASS")];
$pdo = new PDO($dsn, $dbEnv["DB_USER"], $dbEnv["DB_PASS"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
// Tabulky z migrací (idempotentní).
$proc = proc_open(phpPrikaz() . ' ' . escapeshellarg(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'migrace.php'), [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, null,
    array_merge(getenv(), ["DB_DSN" => $dsn, "DB_USER" => $dbEnv["DB_USER"], "DB_PASS" => $dbEnv["DB_PASS"]]));
$migrace = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
assertTrue(strpos((string)$migrace, "Hotovo") !== false, "Migrace na testovací DB: {$migrace}");

$znacka = bin2hex(random_bytes(4));
$ip = "203.0.113." . random_int(1, 254) . "-" . $znacka; // jedinečná „IP“ jen pro tenhle běh testu
$emailA = "ucet-a-{$znacka}@example.com";
$emailB = "ucet-b-{$znacka}@example.com";
try {
    $r = Ucty::registruj($pdo, " Ucet-A-{$znacka}@Example.com ", "heslo-123456", "heslo-123456", $ip);
    assertTrue($r["ok"] && $r["uzivatel"]["email"] === $emailA, "Registrace: OK, e-mail normalizovaný");
    $idA = (int)$r["uzivatel"]["id"];
    $hash = $pdo->query("SELECT password_hash FROM users WHERE id = {$idA}")->fetchColumn();
    assertTrue(password_verify("heslo-123456", $hash) && strpos($hash, "heslo") === false, "Registrace: heslo jen jako hash");
    $r = Ucty::registruj($pdo, $emailA, "heslo-123456", "heslo-123456", $ip);
    assertTrue(!$r["ok"] && isset($r["chyby"]["email"]), "Registrace: stejný e-mail podruhé = chyba");
    $r = Ucty::registruj($pdo, "spatny", "kratke", "jine", $ip);
    assertSame(["email", "heslo"], array_keys($r["chyby"]), "Registrace: chyby e-mailu i hesla najednou");
    putenv("REGISTRACE=0");
    assertTrue(!Ucty::registruj($pdo, $emailB, "heslo-123456", "heslo-123456", $ip)["ok"], "Registrace vypnutá (REGISTRACE=0)");
    putenv("REGISTRACE");
    $r = Ucty::registruj($pdo, $emailB, "heslo-123456", "heslo-123456", $ip);
    $idB = (int)$r["uzivatel"]["id"];

    $r = Ucty::prihlas($pdo, strtoupper($emailA), "heslo-123456", $ip);
    assertTrue($r["ok"] && $r["uzivatel"]["id"] === $idA && $r["uzivatel"]["is_admin"] === false, "Přihlášení: OK (e-mail bez ohledu na velikost písmen)");
    $r = Ucty::prihlas($pdo, $emailA, "spatne-heslo", $ip);
    assertTrue(!$r["ok"] && $r["chyba"] === "Nesprávný e-mail nebo heslo.", "Přihlášení: špatné heslo");
    $r = Ucty::prihlas($pdo, "neexistuje-{$znacka}@example.com", "heslo-123456", $ip);
    assertTrue(!$r["ok"] && $r["chyba"] === "Nesprávný e-mail nebo heslo.", "Přihlášení: neznámý účet = stejná hláška");
    // Omezení pokusů: 5 neúspěchů na e-mail (A má už 1) = blokace, i se správným heslem.
    for ($i = 0; $i < Ucty::MAX_CHYB_EMAIL - 1; $i++) Ucty::prihlas($pdo, $emailA, "spatne-heslo", $ip . "-x{$i}");
    $r = Ucty::prihlas($pdo, $emailA, "heslo-123456", $ip . "-jina");
    assertTrue(!$r["ok"] && $r["blokovano_s"] > 0 && $r["blokovano_s"] <= Ucty::OKNO_S && strpos($r["chyba"], "Příliš mnoho") === 0, "Přihlášení: po " . Ucty::MAX_CHYB_EMAIL . " neúspěších blokace e-mailu ({$r["blokovano_s"]} s)");
    assertTrue(Ucty::prihlas($pdo, $emailB, "heslo-123456", $ip . "-jina")["ok"], "Přihlášení: blokace e-mailu A neblokuje B");
    // Úspěch vynuluje neúspěchy: B 4× špatně, pak dobře, pak znovu 4× špatně = pořád jde.
    for ($i = 0; $i < 4; $i++) Ucty::prihlas($pdo, $emailB, "spatne", $ip . "-b");
    assertTrue(Ucty::prihlas($pdo, $emailB, "heslo-123456", $ip . "-b")["ok"], "Přihlášení: 4 neúspěchy ještě neblokují");
    for ($i = 0; $i < 4; $i++) Ucty::prihlas($pdo, $emailB, "spatne", $ip . "-b");
    assertTrue(Ucty::prihlas($pdo, $emailB, "heslo-123456", $ip . "-b")["ok"], "Přihlášení: úspěch vynuloval neúspěchy");
    // Blokace IP: MAX_CHYB_IP neúspěchů z jedné IP na různé e-maily.
    $ipIp = $ip . "-ip";
    for ($i = 0; $i < Ucty::MAX_CHYB_IP; $i++) Ucty::prihlas($pdo, "nikdo{$i}-{$znacka}@example.com", "x", $ipIp);
    assertTrue(Ucty::blokovanoPrihlaseni($pdo, $emailB, $ipIp) > 0 && !Ucty::prihlas($pdo, $emailB, "heslo-123456", $ipIp)["ok"], "Přihlášení: blokace IP po " . Ucty::MAX_CHYB_IP . " neúspěších");
    // Registrace z jedné IP: nejvýš MAX_REGISTRACI_IP za hodinu.
    $ipReg = $ip . "-reg";
    for ($i = 0; $i < Ucty::MAX_REGISTRACI_IP; $i++) assertTrue(Ucty::registruj($pdo, "reg{$i}-{$znacka}@example.com", "heslo-123456", "heslo-123456", $ipReg)["ok"], "Registrace {$i} z jedné IP");
    $r = Ucty::registruj($pdo, "reg-navic-{$znacka}@example.com", "heslo-123456", "heslo-123456", $ipReg);
    assertTrue(!$r["ok"] && strpos($r["chyby"]["email"] ?? "", "příliš mnoho") !== false, "Registrace: limit na IP");

    // Projekty a vlastnictví.
    $idP = Projekty::vytvor($pdo, $idA, "Krabičky", $d);
    $p = Projekty::nacti($pdo, $idA, $idP);
    assertSame(["Krabičky", $d], [$p["name"], $p["data"]], "Projekt: uložit a načíst (data beze změny)");
    assertSame(null, Projekty::nacti($pdo, $idB, $idP), "Projekt: cizí uživatel ho nenačte");
    assertSame(false, Projekty::prejmenuj($pdo, $idB, $idP, "Cizí"), "Projekt: cizí uživatel nepřejmenuje");
    assertSame(false, Projekty::prepis($pdo, $idB, $idP, "Cizí", $d), "Projekt: cizí uživatel nepřepíše");
    assertSame(false, Projekty::smaz($pdo, $idB, $idP), "Projekt: cizí uživatel nesmaže");
    assertSame(true, Projekty::prejmenuj($pdo, $idA, $idP, "Krabičky 2"), "Projekt: přejmenovat");
    assertSame(true, Projekty::prejmenuj($pdo, $idA, $idP, "Krabičky 2"), "Projekt: přejmenovat na stejný název");
    $d2 = $d;
    $d2["objekty"][] = ["x" => "10", "y" => "10", "z" => "10", "instances" => ["d" => "1"]];
    assertSame(true, Projekty::prepis($pdo, $idA, $idP, "Krabičky 3", $d2), "Projekt: přepsat data");
    $seznam = Projekty::seznam($pdo, $idA);
    assertSame([[$idP, "Krabičky 3", 4]], array_map(function ($p) { return [$p["id"], $p["name"], $p["pocet_objektu"]]; }, $seznam), "Projekt: seznam (bez dat, počet objektů)");
    assertSame([], Projekty::seznam($pdo, $idB), "Projekt: seznam jen vlastní");

    // index.php s DB, nepřihlášený: odkaz Přihlásit s návratem na stejný výpočet.
    $html = $spustStranku("index.php", ["objekty" => [["x" => "50", "y" => "50", "z" => "100", "instances" => ["d" => "3"]]]], $dbEnv, [], ["QUERY_STRING" => "objekty%5B0%5D%5Bx%5D=50"]);
    $bezWarningu($html, "index.php s DB");
    assertTrue(strpos($html, 'id="ucet_panel"') !== false && strpos($html, 'ucet.php?stranka=prihlaseni&amp;zpet=index.php%3Fobjekty%255B0%255D%255Bx%255D%3D50') !== false, "index.php s DB: Přihlásit s návratem");
    assertTrue(strpos($html, 'id="ulozit_projekt"') === false && strpos($html, 'id="prihlasit_projekty"') !== false, "index.php s DB nepřihlášený: Uložit projekt jen po přihlášení");
    $html = $spustStranku("ucet.php", ["stranka" => "registrace"], $dbEnv);
    $bezWarningu($html, "ucet.php registrace");
    assertTrue(strpos($html, 'name="heslo_znovu"') !== false && strpos($html, 'name="csrf"') !== false, "ucet.php: registrace s CSRF polem");
    $html = $spustStranku("ucet.php", ["stranka" => "projekty"], $dbEnv);
    assertTrue(strpos($html, "Moje projekty") === false, "ucet.php: projekty bez přihlášení se neukážou");

    // API v1 projects s klíčem uživatele A.
    $klic = "sk_test_" . bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO api_keys (user_id, name, key_prefix, key_hash, rate_limit_per_min) VALUES (?, ?, ?, ?, 0)")->execute([$idA, "test", substr($klic, 0, 12), hash("sha256", $klic)]);
    $sKlicem = ["HTTP_AUTHORIZATION" => "Bearer " . $klic];
    $r = $spustEndpoint("api/v1/projects.php", "GET", [], null, $dbEnv, $sKlicem);
    assertSame([200, 1, "Krabičky 3"], [$r["status"], count($r["json"]["projects"] ?? []), $r["json"]["projects"][0]["name"] ?? null], "v1 projects GET seznam");
    $r = $spustEndpoint("api/v1/projects.php", "POST", [], json_encode(["name" => "Z API", "data" => $stav]), $dbEnv, $sKlicem);
    assertSame([201, "Z API"], [$r["status"], $r["json"]["project"]["name"] ?? null], "v1 projects POST");
    $idApi = (int)($r["json"]["project"]["id"] ?? 0);
    $r = $spustEndpoint("api/v1/projects.php", "GET", ["id" => (string)$idApi], null, $dbEnv, $sKlicem);
    assertSame([200, $d], [$r["status"], $r["json"]["project"]["data"] ?? null], "v1 projects GET ?id (data očištěná)");
    $r = $spustEndpoint("api/v1/projects.php", "PUT", ["id" => (string)$idApi], '{"name":"Přejmenováno"}', $dbEnv, $sKlicem);
    assertSame([200, "Přejmenováno"], [$r["status"], $r["json"]["project"]["name"] ?? null], "v1 projects PUT název");
    $r = $spustEndpoint("api/v1/projects.php", "POST", [], '{"name":"","data":{"objekty":[]}}', $dbEnv, $sKlicem);
    $chybaV1($r, 400, "neplatny_vstup", "v1 projects POST chybný vstup");
    assertSame(["name", "data.objekty"], array_column($r["json"]["error"]["details"]["chyby"], "pole"), "v1 projects: chyby po polích");
    // Projekt uživatele B přes klíč A = 404.
    $idPB = Projekty::vytvor($pdo, $idB, "Béčko", $d);
    $chybaV1($spustEndpoint("api/v1/projects.php", "GET", ["id" => (string)$idPB], null, $dbEnv, $sKlicem), 404, "projekt_nenalezen", "v1 projects cizí projekt");
    $chybaV1($spustEndpoint("api/v1/projects.php", "DELETE", ["id" => (string)$idPB], null, $dbEnv, $sKlicem), 404, "projekt_nenalezen", "v1 projects DELETE cizí");
    $r = $spustEndpoint("api/v1/projects.php", "DELETE", ["id" => (string)$idApi], null, $dbEnv, $sKlicem);
    assertSame([200, true], [$r["status"], $r["json"]["ok"] ?? null], "v1 projects DELETE");
    $chybaV1($spustEndpoint("api/v1/projects.php", "PUT", [], '{"name":"x"}', $dbEnv, $sKlicem), 400, "neplatny_vstup", "v1 projects PUT bez id");
} finally {
    $pdo->prepare("DELETE FROM users WHERE email LIKE ?")->execute(["%-{$znacka}@example.com"]);
    $pdo->prepare("DELETE FROM login_attempts WHERE ip LIKE ? OR email LIKE ?")->execute([$ip . "%", "%-{$znacka}@example.com"]);
}
assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE '%-{$znacka}@example.com'")->fetchColumn(), "Úklid: testovací uživatelé smazaní");
ok("Účty a projekty s DB: registrace, přihlášení, omezení pokusů (e-mail, IP, registrace), projekty a vlastnictví, index.php/ucet.php s DB, API projects.");

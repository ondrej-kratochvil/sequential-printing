<?php
/**
 * Účet (Milník D): přihlášení, registrace, odhlášení a správa projektů.
 *
 * Stránky (GET ?stranka=): prihlaseni, registrace, projekty.
 * Formuláře (POST, pole `akce` + `csrf`): prihlasit, registrovat, odhlasit, prejmenovat_projekt, smazat_projekt.
 * GET ?akce=otevrit&id=N: otevře projekt = přesměruje na index.php s parametry formuláře (výpočet hned proběhne).
 * POST ?akce=ulozit_projekt (JSON, hlavička X-CSRF-Token): uloží vstup formuláře z index.php, chyby ve formátu API v1.
 *
 * Bez DB (chybí DB_DSN) stránka jen řekne, že účty nejsou zapnuté; výpočet v index.php funguje dál.
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Relace.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Ucty.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Projekty.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'StrankaUctu.php';

$akce_get = isset($_GET['akce']) && is_string($_GET['akce']) ? $_GET['akce'] : '';

/** Přesměrování (303 = po POST vždy GET) a konec. */
function presmeruj(string $url): void
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Návrat po přihlášení: jen index.php s parametry (žádná cizí adresa = žádný open redirect). */
function bezpecny_navrat($zpet): string
{
    if (!is_string($zpet) || strlen($zpet) > 4000) return '';
    return preg_match('/^index\.php(\?[^\r\n\\\\#]*)?$/', $zpet) ? $zpet : '';
}

/** Přihlášený uživatel, jinak přesměrování na přihlášení (s návratem). */
function vyzaduj_prihlaseni(?array $uzivatel): array
{
    if ($uzivatel) return $uzivatel;
    Relace::nastavZpravu('Nejdřív se přihlas.', 'chyba');
    presmeruj('ucet.php?stranka=prihlaseni');
    return [];
}

// --- Bez DB: účty nejsou zapnuté ---
if (!Relace::dbZapnuta()) {
    if ($akce_get === 'ulozit_projekt') {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';
        Api::obsluz(function (): void {
            throw new ApiChyba(503, 'ucty_vypnute', 'Účty nejsou zapnuté (aplikace běží bez databáze).');
        });
    }
    http_response_code(503);
    StrankaUctu::zacatek('Účet', null);
    echo '<div class="card uzka"><h2>Účty nejsou zapnuté</h2><p>Aplikace běží bez databáze (není nastavené <code>DB_DSN</code>), takže přihlášení, projekty a další funkce účtu nejsou k dispozici.</p>';
    echo '<p class="muted">Výpočet, import a export, sdílený odkaz i ukládání do prohlížeče fungují dál.</p>';
    echo '<div class="toolbar"><a class="button primary" href="index.php">' . StrankaUctu::ikona('calculator') . 'Zpět na výpočet</a></div></div>';
    StrankaUctu::konec();
    exit;
}

Relace::start();
try {
    $pdo = Db::pdo();
} catch (Throwable $e) {
    error_log('Sekvenční tisk: nelze se připojit k DB: ' . $e->getMessage());
    $pdo = null;
}
if ($pdo === null) {
    if ($akce_get === 'ulozit_projekt') {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';
        Api::obsluz(function (): void {
            throw new ApiChyba(503, 'db_nedostupna', 'Databáze je teď nedostupná, projekt se neuložil.');
        });
    }
    http_response_code(503);
    StrankaUctu::zacatek('Účet', null);
    echo '<div class="card uzka"><h2>Databáze je nedostupná</h2><p>Účet teď nejde použít. Výpočet funguje dál.</p><div class="toolbar"><a class="button primary" href="index.php">' . StrankaUctu::ikona('calculator') . 'Zpět na výpočet</a></div></div>';
    StrankaUctu::konec();
    exit;
}
$uzivatel = Relace::uzivatel();

// --- JSON: uložení projektu z index.php (Uložit projekt) ---
if ($akce_get === 'ulozit_projekt') {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';
    Api::obsluz(function () use ($uzivatel, $pdo): void {
        Api::povolMetody(['POST']);
        if (!$uzivatel) throw new ApiChyba(401, 'neprihlasen', 'Přihlas se, projekty se ukládají k účtu.');
        if (!Relace::overCsrf(Relace::csrfZPozadavku())) throw new ApiChyba(403, 'neplatny_csrf', 'Platnost stránky vypršela. Obnov stránku a ulož projekt znovu.');
        $telo = Api::teloJson();
        $chyby = [];
        $nazev = Projekty::upravNazev($telo['nazev'] ?? null);
        if ($nazev === null) $chyby[] = ['pole' => 'nazev', 'zprava' => 'Název projektu je povinný (nejvýš ' . Projekty::MAX_DELKA_NAZVU . ' znaků).'];
        $overeno = Projekty::overData($telo['data'] ?? null);
        $chyby = array_merge($chyby, $overeno['chyby']);
        $id = isset($telo['id']) && (is_int($telo['id']) || (is_string($telo['id']) && ctype_digit($telo['id']))) ? (int)$telo['id'] : 0;
        if (!empty($chyby)) throw new ApiChyba(400, 'neplatny_vstup', 'Projekt nejde uložit: ' . implode(' ', array_column($chyby, 'zprava')), ['chyby' => $chyby]);
        if ($id > 0) {
            if (!Projekty::prepis($pdo, $uzivatel['id'], $id, $nazev, $overeno['data'])) throw new ApiChyba(404, 'projekt_nenalezen', 'Projekt nenalezen (možná byl smazán). Ulož ho jako nový.', ['id' => $id]);
            Api::odeslat(200, ['projekt' => ['id' => $id, 'nazev' => $nazev]]);
        }
        $noveId = Projekty::vytvor($pdo, $uzivatel['id'], $nazev, $overeno['data']);
        Api::odeslat(201, ['projekt' => ['id' => $noveId, 'nazev' => $nazev]]);
    });
}

// --- Otevření projektu: přesměrování na výpočet s parametry formuláře ---
if ($akce_get === 'otevrit') {
    $uzivatel = vyzaduj_prihlaseni($uzivatel);
    $id = isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
    $projekt = $id > 0 ? Projekty::nacti($pdo, $uzivatel['id'], $id) : null;
    if ($projekt === null) {
        Relace::nastavZpravu('Projekt nenalezen.', 'chyba');
        presmeruj('ucet.php?stranka=projekty');
    }
    presmeruj('index.php?' . http_build_query(Projekty::naDotaz($projekt['data']) + ['projekt' => $projekt['id']]));
}

// --- Formuláře (POST) ---
$chyby = [];
$hodnoty = [];
$stranka = isset($_GET['stranka']) && is_string($_GET['stranka']) ? $_GET['stranka'] : '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $akce = isset($_POST['akce']) && is_string($_POST['akce']) ? $_POST['akce'] : '';
    $zpet = bezpecny_navrat($_POST['zpet'] ?? '');
    if (!Relace::overCsrf(Relace::csrfZPozadavku())) {
        Relace::nastavZpravu('Platnost formuláře vypršela, zkus to prosím znovu.', 'chyba');
        presmeruj('ucet.php' . ($stranka !== '' ? '?stranka=' . rawurlencode($stranka) : ''));
    }
    if ($akce === 'prihlasit') {
        $vysledek = Ucty::prihlas($pdo, $_POST['email'] ?? '', $_POST['heslo'] ?? '', Ucty::ipKlienta());
        if ($vysledek['ok']) {
            Relace::prihlas($vysledek['uzivatel']);
            presmeruj($zpet !== '' ? $zpet : 'index.php');
        }
        $stranka = 'prihlaseni';
        $http_status = $vysledek['blokovano_s'] > 0 ? 429 : 400;
        $chyby['formular'] = $vysledek['chyba'];
        $hodnoty['email'] = Ucty::normalizujEmail($_POST['email'] ?? '');
    } elseif ($akce === 'registrovat') {
        $vysledek = Ucty::registruj($pdo, $_POST['email'] ?? '', $_POST['heslo'] ?? '', $_POST['heslo_znovu'] ?? '', Ucty::ipKlienta());
        if ($vysledek['ok']) {
            Relace::prihlas($vysledek['uzivatel']);
            Relace::nastavZpravu('Účet je založený, jsi přihlášený.');
            presmeruj($zpet !== '' ? $zpet : 'index.php');
        }
        $stranka = 'registrace';
        $chyby = $vysledek['chyby'];
        $hodnoty['email'] = Ucty::normalizujEmail($_POST['email'] ?? '');
    } elseif ($akce === 'odhlasit') {
        Relace::odhlas();
        presmeruj('index.php');
    } elseif ($akce === 'prejmenovat_projekt' || $akce === 'smazat_projekt') {
        $uzivatel = vyzaduj_prihlaseni($uzivatel);
        $id = isset($_POST['id']) && is_string($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($akce === 'smazat_projekt') {
            $ok = Projekty::smaz($pdo, $uzivatel['id'], $id);
            Relace::nastavZpravu($ok ? 'Projekt je smazaný.' : 'Projekt nenalezen.', $ok ? 'ok' : 'chyba');
        } else {
            $nazev = Projekty::upravNazev($_POST['nazev'] ?? null);
            if ($nazev === null) Relace::nastavZpravu('Název projektu je povinný (nejvýš ' . Projekty::MAX_DELKA_NAZVU . ' znaků).', 'chyba');
            else {
                $ok = Projekty::prejmenuj($pdo, $uzivatel['id'], $id, $nazev);
                Relace::nastavZpravu($ok ? 'Projekt je přejmenovaný.' : 'Projekt nenalezen.', $ok ? 'ok' : 'chyba');
            }
        }
        presmeruj('ucet.php?stranka=projekty');
    } else {
        presmeruj('ucet.php');
    }
}

// --- Stránky (GET) ---
if ($stranka === '') $stranka = $uzivatel ? 'projekty' : 'prihlaseni';
if ($uzivatel && in_array($stranka, ['prihlaseni', 'registrace'], true)) presmeruj('ucet.php?stranka=projekty');
if (!in_array($stranka, ['prihlaseni', 'registrace', 'projekty'], true)) {
    http_response_code(404);
    StrankaUctu::zacatek('Nenalezeno', $uzivatel);
    echo '<div class="card uzka"><h2>Stránka nenalezena</h2><div class="toolbar"><a class="button" href="ucet.php">Účet</a></div></div>';
    StrankaUctu::konec();
    exit;
}
if (!$uzivatel && $stranka === 'projekty') vyzaduj_prihlaseni(null);

$h = function ($text): string { return StrankaUctu::h($text); };
$zpet_get = bezpecny_navrat($_GET['zpet'] ?? ($_POST['zpet'] ?? ''));

if ($stranka === 'prihlaseni' || $stranka === 'registrace') {
    $registrace = $stranka === 'registrace';
    if (!empty($chyby)) http_response_code($http_status ?? 400);
    StrankaUctu::zacatek($registrace ? 'Registrace' : 'Přihlášení', null);
    $odkaz_zpet = $zpet_get !== '' ? '&zpet=' . rawurlencode($zpet_get) : '';
    echo '<div class="card uzka"><h2>' . ($registrace ? 'Registrace' : 'Přihlášení') . '</h2>';
    if ($registrace && !Ucty::registraceZapnuta()) {
        echo '<p>Registrace je vypnutá, účet založí správce.</p><div class="toolbar"><a class="button" href="ucet.php?stranka=prihlaseni' . $h($odkaz_zpet) . '">' . StrankaUctu::ikona('log-in') . 'Přihlásit se</a></div></div>';
        StrankaUctu::konec();
        exit;
    }
    if (!empty($chyby['formular'])) echo '<div class="zprava chyba" role="alert">' . $h($chyby['formular']) . '</div>';
    echo '<form method="post" action="ucet.php?stranka=' . $h($stranka) . '" novalidate="novalidate">' . StrankaUctu::csrfPole();
    echo '<input type="hidden" name="akce" value="' . ($registrace ? 'registrovat' : 'prihlasit') . '" />';
    if ($zpet_get !== '') echo '<input type="hidden" name="zpet" value="' . $h($zpet_get) . '" />';
    $pole = function (string $nazev, string $popis, string $typ, string $autocomplete, string $hint = '') use ($chyby, $hodnoty, $h): void {
        $chyba = $chyby[$nazev === 'heslo_znovu' ? '-' : $nazev] ?? '';
        echo '<div class="pole"><label for="' . $nazev . '">' . $h($popis) . '</label>';
        echo '<input id="' . $nazev . '" name="' . $nazev . '" type="' . $typ . '" autocomplete="' . $autocomplete . '" required="required"'
            . ($typ === 'email' ? ' maxlength="' . Ucty::MAX_DELKA_EMAILU . '" value="' . $h($hodnoty[$nazev] ?? '') . '"' : ' maxlength="' . Ucty::MAX_DELKA_HESLA . '"')
            . ($chyba !== '' ? ' aria-invalid="true" aria-describedby="' . $nazev . '_chyba"' : '') . ' />';
        if ($hint !== '') echo '<span class="hint">' . $h($hint) . '</span>';
        if ($chyba !== '') echo '<span class="chyba" id="' . $nazev . '_chyba">' . $h($chyba) . '</span>';
        echo '</div>';
    };
    $pole('email', 'E-mail', 'email', 'email');
    $pole('heslo', 'Heslo', 'password', $registrace ? 'new-password' : 'current-password', $registrace ? 'Aspoň ' . Ucty::MIN_DELKA_HESLA . ' znaků.' : '');
    if ($registrace) $pole('heslo_znovu', 'Heslo znovu', 'password', 'new-password');
    echo '<div class="toolbar"><button class="primary" type="submit">' . StrankaUctu::ikona($registrace ? 'user-plus' : 'log-in') . ($registrace ? 'Založit účet' : 'Přihlásit') . '</button>';
    if ($registrace) echo '<a href="ucet.php?stranka=prihlaseni' . $h($odkaz_zpet) . '">Už mám účet</a>';
    elseif (Ucty::registraceZapnuta()) echo '<a href="ucet.php?stranka=registrace' . $h($odkaz_zpet) . '">Nemám účet, zaregistrovat</a>';
    echo '</div></form>';
    if (!$registrace) echo '<p class="muted" style="margin-top: 12px;">Zapomenuté heslo zatím obnoví jen správce (aplikace neposílá e-maily).</p>';
    echo '</div>';
    echo '<p class="muted"><a href="index.php">Zpět na výpočet</a> (funguje i bez přihlášení).</p>';
    StrankaUctu::konec();
    exit;
}

// Moje projekty
$projekty = Projekty::seznam($pdo, $uzivatel['id']);
StrankaUctu::zacatek('Moje projekty', $uzivatel, 'projekty');
echo '<div class="card"><h2>Moje projekty</h2>';
if (empty($projekty)) {
    echo '<p class="muted">Zatím nemáš žádný projekt. Na stránce výpočtu vyplň objekty a klikni na <strong>Uložit projekt</strong>.</p>';
} else {
    echo '<div class="table-wrap"><table><tr><th>Název</th><th class="cislo">Objektů</th><th>Upraveno</th><th>Akce</th></tr>';
    foreach ($projekty as $p) {
        $id = (int)$p['id'];
        echo '<tr><td><a href="ucet.php?akce=otevrit&amp;id=' . $id . '">' . $h($p['name']) . '</a></td><td class="cislo">' . (int)$p['pocet_objektu'] . '</td><td>' . $h(StrankaUctu::datum($p['updated_at'])) . '</td><td><div class="toolbar" style="margin:0;">';
        echo '<a class="button small primary" href="ucet.php?akce=otevrit&amp;id=' . $id . '">' . StrankaUctu::ikona('folder-open') . 'Otevřít</a>';
        echo '<details><summary class="button small">' . StrankaUctu::ikona('pencil') . 'Přejmenovat</summary><form method="post" action="ucet.php?stranka=projekty" class="inline" style="margin-top:6px;">' . StrankaUctu::csrfPole()
            . '<input type="hidden" name="akce" value="prejmenovat_projekt" /><input type="hidden" name="id" value="' . $id . '" />'
            . '<input type="text" name="nazev" value="' . $h($p['name']) . '" maxlength="' . Projekty::MAX_DELKA_NAZVU . '" required="required" aria-label="Nový název" />'
            . '<button class="small" type="submit">' . StrankaUctu::ikona('check') . 'Uložit</button></form></details>';
        echo '<details><summary class="button small">' . StrankaUctu::ikona('trash') . 'Smazat</summary><form method="post" action="ucet.php?stranka=projekty" class="inline" style="margin-top:6px;">' . StrankaUctu::csrfPole()
            . '<input type="hidden" name="akce" value="smazat_projekt" /><input type="hidden" name="id" value="' . $id . '" />'
            . '<span class="muted">Opravdu smazat „' . $h($p['name']) . '“? Nejde to vrátit.</span><button class="small danger" type="submit">' . StrankaUctu::ikona('trash') . 'Ano, smazat</button></form></details>';
        echo '</div></td></tr>';
    }
    echo '</table></div>';
}
echo '<p class="muted" style="margin-top: 10px;">Projekt ukládá objekty a nastavení formuláře (stejně jako export vstupu do JSON). Otevření spustí výpočet. Nahraný model 3MF/STL se do projektu neukládá.</p></div>';
StrankaUctu::konec();

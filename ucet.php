<?php
/**
 * Účet (Milník D): přihlášení, registrace, odhlášení a správa projektů.
 *
 * Stránky (GET ?stranka=): prihlaseni, registrace, projekty, klice (API klíče), tiskarny (moje tiskárny),
 * tiskarna (formulář: nová, ?id= úprava, ?kopie=<id předvolby>), schvalovani (jen admin).
 * Formuláře (POST, pole `akce` + `csrf`): prihlasit, registrovat, odhlasit, prejmenovat_projekt, smazat_projekt,
 * vytvorit_klic, zneplatnit_klic, ulozit_tiskarnu, smazat_tiskarnu, navrhnout_tiskarnu, stahnout_navrh,
 * schvalit_tiskarnu, vratit_tiskarnu, zrusit_zverejneni (poslední tři jen admin).
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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'SpravaKlicu.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'TiskarnyDb.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'PredvolbyTiskaren.php';

/** Stránky jen pro přihlášeného uživatele. */
const STRANKY_UCTU = ['projekty', 'klice', 'tiskarny', 'tiskarna', 'schvalovani'];

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
if ($uzivatel) PredvolbyTiskaren::zahrnoutVlastni($uzivatel['id']);

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
    } elseif ($akce === 'vytvorit_klic' || $akce === 'zneplatnit_klic') {
        // API klíče přes sdílenou logiku SpravaKlicu (stejná jako api/v1/keys.php).
        $uzivatel = vyzaduj_prihlaseni($uzivatel);
        if ($akce === 'vytvorit_klic') {
            $limit = isset($_POST['rate_limit_per_min']) && $_POST['rate_limit_per_min'] !== '' ? $_POST['rate_limit_per_min'] : SpravaKlicu::VYCHOZI_LIMIT;
            $overeno = SpravaKlicu::overVstup(['name' => $_POST['name'] ?? '', 'rate_limit_per_min' => $limit]);
            if (!empty($overeno['chyby'])) {
                Relace::nastavZpravu(implode(' ', array_column($overeno['chyby'], 'zprava')), 'chyba');
            } else {
                $novy = SpravaKlicu::vytvor($pdo, $uzivatel['id'], $overeno['name'], $overeno['rate_limit_per_min']);
                // Plaintext klíče jen do session na jedno zobrazení (po přesměrování se ukáže a hned smaže).
                $_SESSION['novy_klic'] = ['id' => $novy['id'], 'name' => $novy['name'], 'api_key' => $novy['api_key']];
            }
        } else {
            $id = isset($_POST['id']) && is_string($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
            $ok = SpravaKlicu::zneplatni($pdo, $uzivatel['id'], $id);
            Relace::nastavZpravu($ok ? 'Klíč je zneplatněný, API ho už nepřijme.' : 'Klíč nenalezen nebo už je zneplatněný.', $ok ? 'ok' : 'chyba');
        }
        presmeruj('ucet.php?stranka=klice');
    } elseif (in_array($akce, ['ulozit_tiskarnu', 'smazat_tiskarnu', 'navrhnout_tiskarnu', 'stahnout_navrh'], true)) {
        // Vlastní tiskárny (D3): jen neschválené jdou upravit a smazat.
        $uzivatel = vyzaduj_prihlaseni($uzivatel);
        $id = isset($_POST['id']) && is_string($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($akce === 'ulozit_tiskarnu') {
            $overeno = TiskarnyDb::overVstup($_POST);
            if (!empty($overeno['chyby'])) {
                // Formulář znovu s hodnotami a chybami (bez přesměrování).
                $stranka = 'tiskarna';
                $chyby = $overeno['chyby'];
                $formular_tiskarny = $overeno['tiskarna'] + ['id' => $id, 'nahrazuje' => $_POST['nahrazuje'] ?? ''];
                $http_status = 400;
            } elseif ($id > 0) {
                $ok = TiskarnyDb::uprav($pdo, $uzivatel['id'], $id, $overeno['tiskarna']);
                Relace::nastavZpravu($ok ? 'Tiskárna je uložená.' . (empty($varovani = TiskarnyDb::varovaniProfilu($overeno['tiskarna'])) ? '' : ' Pozor: ' . implode(' ', $varovani) . ' Kontrola kolizí bere jen schod pro výšku dřívějšího kusu, zadej raději obálku (každý schod aspoň tak široký jako hlava pod ním), viz README.') : 'Tiskárnu nejde upravit (není tvoje nebo je už schválená).', $ok ? 'ok' : 'chyba');
                presmeruj('ucet.php?stranka=tiskarny');
            } else {
                $nahrazuje = isset($_POST['nahrazuje']) && is_string($_POST['nahrazuje']) && ctype_digit($_POST['nahrazuje']) && !empty($_POST['navrh_upravy']) ? (int)$_POST['nahrazuje'] : null;
                TiskarnyDb::vytvor($pdo, $uzivatel['id'], $overeno['tiskarna'], $nahrazuje);
                Relace::nastavZpravu('Tiskárna je uložená. Ve výběru tiskárny ji vidíš jen ty, dokud ji nenavrhneš ke zveřejnění a admin ji neschválí.' . (empty($varovani = TiskarnyDb::varovaniProfilu($overeno['tiskarna'])) ? '' : ' Pozor: ' . implode(' ', $varovani) . ' Kontrola kolizí bere jen schod pro výšku dřívějšího kusu, zadej raději obálku (každý schod aspoň tak široký jako hlava pod ním), viz README.'));
                presmeruj('ucet.php?stranka=tiskarny');
            }
        } else {
            if ($akce === 'smazat_tiskarnu') $ok = TiskarnyDb::smaz($pdo, $uzivatel['id'], $id);
            else $ok = TiskarnyDb::navrhni($pdo, $uzivatel['id'], $id, $akce === 'navrhnout_tiskarnu');
            $zpravy = [
                'smazat_tiskarnu' => 'Tiskárna je smazaná.',
                'navrhnout_tiskarnu' => 'Tiskárna je navržená ke zveřejnění, čeká na schválení adminem.',
                'stahnout_navrh' => 'Návrh je stažený, tiskárna je zase jen tvoje.',
            ];
            Relace::nastavZpravu($ok ? $zpravy[$akce] : 'Akci nejde provést (tiskárna není tvoje, je schválená nebo už je v tomto stavu).', $ok ? 'ok' : 'chyba');
            presmeruj('ucet.php?stranka=tiskarny');
        }
    } elseif (in_array($akce, ['schvalit_tiskarnu', 'vratit_tiskarnu', 'zrusit_zverejneni'], true)) {
        // Schvalování (jen admin, users.is_admin).
        $uzivatel = vyzaduj_prihlaseni($uzivatel);
        if (!$uzivatel['is_admin']) {
            Relace::nastavZpravu('Schvalovat tiskárny může jen admin.', 'chyba');
            presmeruj('ucet.php?stranka=tiskarny');
        }
        $id = isset($_POST['id']) && is_string($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($akce === 'schvalit_tiskarnu') {
            $schvalena = TiskarnyDb::schval($pdo, $id);
            Relace::nastavZpravu($schvalena !== null ? 'Tiskárna je schválená (id ve výběru: ' . TiskarnyDb::idPredvolby($schvalena) . ').' : 'Návrh nenalezen.', $schvalena !== null ? 'ok' : 'chyba');
        } elseif ($akce === 'vratit_tiskarnu') {
            $ok = TiskarnyDb::vratNavrh($pdo, $id);
            Relace::nastavZpravu($ok ? 'Návrh je vrácený autorovi k úpravám.' : 'Návrh nenalezen.', $ok ? 'ok' : 'chyba');
        } else {
            $ok = TiskarnyDb::zrusZverejneni($pdo, $id);
            Relace::nastavZpravu($ok ? 'Zveřejnění je zrušené, tiskárna je zase koncept vlastníka.' : 'Tiskárna nenalezena.', $ok ? 'ok' : 'chyba');
        }
        presmeruj('ucet.php?stranka=schvalovani');
    } else {
        presmeruj('ucet.php');
    }
}

// --- Stránky (GET) ---
if ($stranka === '') $stranka = $uzivatel ? 'projekty' : 'prihlaseni';
if ($uzivatel && in_array($stranka, ['prihlaseni', 'registrace'], true)) presmeruj('ucet.php?stranka=projekty');
if (!in_array($stranka, array_merge(['prihlaseni', 'registrace'], STRANKY_UCTU), true)) {
    http_response_code(404);
    StrankaUctu::zacatek('Nenalezeno', $uzivatel);
    echo '<div class="card uzka"><h2>Stránka nenalezena</h2><div class="toolbar"><a class="button" href="ucet.php">Účet</a></div></div>';
    StrankaUctu::konec();
    exit;
}
if (!$uzivatel && in_array($stranka, STRANKY_UCTU, true)) vyzaduj_prihlaseni(null);

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

// Tiskárny (D3): přehled vlastních tiskáren.
$popis_stavu = function (array $t) use ($h): string {
    $stav = TiskarnyDb::stav($t);
    if ($stav === 'schvaleno') return '<span class="stitek ok">schválená</span>';
    if ($stav === 'navrzeno') return '<span class="stitek ceka">čeká na schválení</span>';
    return '<span class="stitek">koncept (jen pro tebe)</span>';
};
if ($stranka === 'tiskarny') {
    $tiskarny = TiskarnyDb::vlastni($pdo, $uzivatel['id']);
    StrankaUctu::zacatek('Moje tiskárny', $uzivatel, 'tiskarny');
    echo '<div class="card"><h2>Moje tiskárny</h2>';
    echo '<p class="muted">Vlastní tiskárnu vidíš ve výběru „Tiskárna“ v Nastavení hned po uložení (jen ty). Když ji navrhneš ke zveřejnění a admin ji schválí, objeví se všem vedle předvoleb ze souboru, v API (<code>api/v1/printers.php</code>) i v CLI jako <code>db_&lt;číslo&gt;</code>. Schválenou tiskárnu už neupravíš; úpravu navrhneš kopií.</p>';
    if (empty($tiskarny)) {
        echo '<p>Zatím nemáš žádnou tiskárnu.</p>';
    } else {
        echo '<div class="table-wrap"><table><tr><th>Název</th><th>Id ve výběru</th><th class="cislo">Podložka (mm)</th><th class="cislo">Hlava Xl / Xr / Yl / Yr</th><th class="cislo">Schodů</th><th>Stav</th><th>Akce</th></tr>';
        foreach ($tiskarny as $t) {
            $id = (int)$t['id'];
            $stav = TiskarnyDb::stav($t);
            echo '<tr><td>' . $h($t['name']) . ($t['replaces_printer_id'] ? '<br /><span class="muted">návrh úpravy tiskárny db_' . (int)$t['replaces_printer_id'] . '</span>' : '') . '</td>';
            echo '<td><code>' . $h(TiskarnyDb::idPredvolby($id)) . '</code></td>';
            echo '<td class="cislo">' . $h($t['x'] . ' × ' . $t['y'] . ' × ' . $t['z']) . '</td><td class="cislo">' . $h($t['Xl'] . ' / ' . $t['Xr'] . ' / ' . $t['Yl'] . ' / ' . $t['Yr']) . '</td>';
            echo '<td class="cislo">' . count($t['head_steps']) . '</td><td>' . $popis_stavu($t) . '</td><td><div class="toolbar" style="margin:0;">';
            echo '<a class="button small primary" href="index.php?tiskarna=' . $h(TiskarnyDb::idPredvolby($id)) . '">' . StrankaUctu::ikona('calculator') . 'Použít</a>';
            if ($stav !== 'schvaleno') echo '<a class="button small" href="ucet.php?stranka=tiskarna&amp;id=' . $id . '">' . StrankaUctu::ikona('pencil') . 'Upravit</a>';
            echo '<a class="button small" href="ucet.php?stranka=tiskarna&amp;kopie=' . $h(TiskarnyDb::idPredvolby($id)) . '">' . StrankaUctu::ikona('copy') . 'Kopírovat</a>';
            $formular = function (string $akce, string $popis, string $ikona, string $trida = 'small') use ($id): string {
                return '<form method="post" action="ucet.php?stranka=tiskarny" class="inline">' . StrankaUctu::csrfPole() . '<input type="hidden" name="akce" value="' . $akce . '" /><input type="hidden" name="id" value="' . $id . '" /><button class="' . $trida . '" type="submit">' . StrankaUctu::ikona($ikona) . $popis . '</button></form>';
            };
            if ($stav === 'koncept') echo $formular('navrhnout_tiskarnu', 'Navrhnout ke zveřejnění', 'send');
            if ($stav === 'navrzeno') echo $formular('stahnout_navrh', 'Stáhnout návrh', 'undo');
            if ($stav !== 'schvaleno') {
                echo '<details><summary class="button small">' . StrankaUctu::ikona('trash') . 'Smazat</summary><form method="post" action="ucet.php?stranka=tiskarny" class="inline" style="margin-top:6px;">' . StrankaUctu::csrfPole()
                    . '<input type="hidden" name="akce" value="smazat_tiskarnu" /><input type="hidden" name="id" value="' . $id . '" /><span class="muted">Opravdu smazat „' . $h($t['name']) . '“?</span><button class="small danger" type="submit">' . StrankaUctu::ikona('trash') . 'Ano, smazat</button></form></details>';
            }
            echo '</div></td></tr>';
        }
        echo '</table></div>';
    }
    // Nová tiskárna: prázdná, nebo kopie předvolby (soubor, schválené z DB, vlastní).
    echo '<div class="toolbar"><a class="button primary" href="ucet.php?stranka=tiskarna">' . StrankaUctu::ikona('plus') . 'Nová tiskárna</a>';
    echo '<form method="get" action="ucet.php" class="inline"><input type="hidden" name="stranka" value="tiskarna" /><label class="muted" for="kopie">nebo kopie předvolby</label><select id="kopie" name="kopie">';
    foreach (PredvolbyTiskaren::seznam() as $id_predvolby => $nazev_predvolby) echo '<option value="' . $h($id_predvolby) . '">' . $h($nazev_predvolby) . '</option>';
    echo '</select><button type="submit">' . StrankaUctu::ikona('copy') . 'Kopírovat</button></form></div></div>';
    StrankaUctu::konec();
    exit;
}

// Formulář tiskárny: nová, úprava vlastní (?id=), kopie předvolby (?kopie=<id předvolby>).
if ($stranka === 'tiskarna') {
    $kopie_z = null;
    if (!isset($formular_tiskarny)) {
        $formular_tiskarny = ['id' => 0, 'name' => '', 'poznamka' => '', 'head_steps' => [], 'nahrazuje' => ''];
        foreach (TiskarnyDb::PARAMETRY as $klic => $_) $formular_tiskarny[$klic] = '';
        $id_get = isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id_get > 0) {
            $t = TiskarnyDb::jedna($pdo, $id_get);
            if ($t === null || $t['created_by_user_id'] !== $uzivatel['id'] || $t['approved']) {
                Relace::nastavZpravu('Tiskárnu nejde upravit (není tvoje nebo je už schválená, pak ji zkopíruj).', 'chyba');
                presmeruj('ucet.php?stranka=tiskarny');
            }
            $formular_tiskarny = $t + ['nahrazuje' => ''];
        } elseif (isset($_GET['kopie']) && is_string($_GET['kopie']) && PredvolbyTiskaren::existuje($_GET['kopie'])) {
            $kopie_z = $_GET['kopie'];
            $parametry = PredvolbyTiskaren::parametry($kopie_z);
            foreach (TiskarnyDb::PARAMETRY as $klic => $_) $formular_tiskarny[$klic] = $parametry[$klic] ?? '';
            // Schody bez základu (z = 0), klíče z, Xl … (předvolby ze souboru smí mít i xl, z_mm …).
            foreach (($parametry['head_steps'] ?? []) as $s) {
                if (!is_array($s)) continue;
                $schod = ['z' => $s['z'] ?? ($s['z_mm'] ?? 0)];
                foreach (TiskarnyDb::KLICE_HLAVY as $klic) $schod[$klic] = $s[$klic] ?? ($s[strtolower($klic)] ?? ($s[strtolower($klic) . '_mm'] ?? ''));
                if ((float)$schod['z'] > 0) $formular_tiskarny['head_steps'][] = $schod;
            }
            $formular_tiskarny['name'] = 'Kopie: ' . PredvolbyTiskaren::seznam()[$kopie_z];
            $zdroje = PredvolbyTiskaren::detail($kopie_z)['zdroje'];
            $formular_tiskarny['poznamka'] = 'Zkopírováno z předvolby ' . $kopie_z . '.' . (is_array($zdroje) && isset($zdroje['poznamka']) ? "\n" . $zdroje['poznamka'] : '');
            $cislo = TiskarnyDb::cisloZId($kopie_z);
            $zdrojova = $cislo !== null ? TiskarnyDb::jedna($pdo, $cislo) : null;
            if ($zdrojova !== null && $zdrojova['approved']) $formular_tiskarny['nahrazuje'] = (string)$cislo;
        }
    }
    if (!empty($chyby)) http_response_code($http_status ?? 400);
    $f = $formular_tiskarny;
    $uprava = (int)$f['id'] > 0;
    StrankaUctu::zacatek($uprava ? 'Upravit tiskárnu' : 'Nová tiskárna', $uzivatel, 'tiskarny');
    echo '<div class="card"><h2>' . ($uprava ? 'Upravit tiskárnu' : 'Nová tiskárna') . '</h2>';
    if (!empty($chyby)) echo '<div class="zprava chyba" role="alert">Formulář obsahuje chyby (' . count($chyby) . '), oprav je prosím níže.</div>';
    echo '<form method="post" action="ucet.php?stranka=tiskarna" id="formular_tiskarny" novalidate="novalidate">' . StrankaUctu::csrfPole() . '<input type="hidden" name="akce" value="ulozit_tiskarnu" />';
    if ($uprava) echo '<input type="hidden" name="id" value="' . (int)$f['id'] . '" />';
    $pole = function (string $klic, string $popis, string $hint = '') use ($f, $chyby, $h): void {
        $chyba = $chyby[$klic] ?? '';
        echo '<div class="pole"><label for="t_' . $klic . '">' . $h($popis) . '</label><input id="t_' . $klic . '" name="' . $klic . '" type="number" step="0.01" min="0" value="' . $h($f[$klic] ?? '') . '" style="width: 9rem;"' . ($chyba !== '' ? ' aria-invalid="true"' : '') . ' />';
        if ($hint !== '') echo '<span class="hint">' . $h($hint) . '</span>';
        if ($chyba !== '') echo '<span class="chyba">' . $h($chyba) . '</span>';
        echo '</div>';
    };
    echo '<div class="pole"><label for="t_name">Název</label><input id="t_name" name="name" type="text" maxlength="' . TiskarnyDb::MAX_DELKA_NAZVU . '" value="' . $h($f['name']) . '" required="required"' . (isset($chyby['name']) ? ' aria-invalid="true"' : '') . ' />' . (isset($chyby['name']) ? '<span class="chyba">' . $h($chyby['name']) . '</span>' : '') . '</div>';
    echo '<h3>Podložka</h3><div class="toolbar" style="align-items: start;">';
    $pole('x', 'Šířka X (mm)');
    $pole('y', 'Hloubka Y (mm)');
    $pole('z', 'Výška tisku Z (mm)');
    $pole('posun_zprava', 'Korekce zprava (mm)', 'O kolik zúžit použitelnou šířku vpravo, jinak 0.');
    echo '</div><h3>Hlava (od trysky k hraně hlavy, platí od výšky 0)</h3><div class="toolbar" style="align-items: start;">';
    $pole('Xl', 'Vlevo Xl (mm)');
    $pole('Xr', 'Vpravo Xr (mm)');
    $pole('Yl', 'Vpředu Yl (mm)');
    $pole('Yr', 'Vzadu Yr (mm)');
    echo '</div><h3>Vodicí tyče / portál</h3><div class="toolbar" style="align-items: start;">';
    $pole('vodici_tyce_Z', 'Výška nad tryskou Z (mm)', 'Kus vyšší než tohle zasáhnou tyče.');
    $pole('vodici_tyce_Y', 'Vzdálenost od trysky Y (mm)');
    echo '</div>';

    // Profil hlavy po výškách (head_steps).
    echo '<h3>Profil hlavy po výškách (schody, volitelné)</h3>';
    echo '<p class="muted">Řádek „od výšky z platí rozměry hlavy Xl / Xr / Yl / Yr“. Výšky z se měří od špičky trysky nahoru, musí být větší než 0 a jít vzestupně; pod prvním schodem platí základní rozměry hlavy výše. Jak to kalkulátor použije, popisuje README (Profil hlavy po výškách).</p>';
    if (isset($chyby['head_steps'])) echo '<p class="chyba-text">' . $h($chyby['head_steps']) . '</p>';
    echo '<div class="table-wrap"><table id="schody"><thead><tr><th>Od výšky z (mm)</th><th>Xl</th><th>Xr</th><th>Yl</th><th>Yr</th><th></th></tr></thead><tbody>';
    $schody = $f['head_steps'];
    $radek_schodu = function ($i, array $s) use ($h, $chyby): string {
        $html = '<tr data-schod="1">';
        foreach (array_merge(['z'], TiskarnyDb::KLICE_HLAVY) as $klic) {
            $html .= '<td><input type="number" step="0.01" min="0" name="head_steps[' . $i . '][' . $klic . ']" value="' . $h($s[$klic] ?? '') . '" aria-label="' . ($klic === 'z' ? 'od výšky z' : $klic) . '" style="width: 6.5rem;" /></td>';
        }
        $html .= '<td><button class="small" type="button" data-smazat-schod="1" title="Odebrat schod">' . StrankaUctu::ikona('trash') . 'Odebrat</button></td></tr>';
        if (isset($chyby['head_steps.' . $i])) $html .= '<tr class="chyba-radek"><td colspan="6" class="chyba-text">' . $h($chyby['head_steps.' . $i]) . '</td></tr>';
        return $html;
    };
    foreach (array_values($schody) as $i => $s) echo $radek_schodu($i, $s);
    echo '</tbody></table></div>';
    echo '<template id="sablona_schodu">' . $radek_schodu('__I__', []) . '</template>';
    echo '<div class="toolbar"><button type="button" id="pridat_schod">' . StrankaUctu::ikona('plus') . 'Přidat schod</button><span id="kontrola_schodu" class="chyba-text" role="status"></span></div><p id="varovani_profilu" class="chyba-text" role="status"></p>';

    echo '<div class="pole" style="margin-top: 14px;"><label for="t_poznamka">Odkud hodnoty jsou (měření, dokumentace výrobce)</label><textarea id="t_poznamka" name="poznamka" rows="3" maxlength="' . TiskarnyDb::MAX_DELKA_POZNAMKY . '" style="width: 100%;">' . $h($f['poznamka']) . '</textarea>' . (isset($chyby['poznamka']) ? '<span class="chyba">' . $h($chyby['poznamka']) . '</span>' : '') . '<span class="hint">Admin podle toho posoudí návrh na zveřejnění.</span></div>';
    if (!$uprava && (string)($f['nahrazuje'] ?? '') !== '') {
        $puvodni = TiskarnyDb::jedna($pdo, (int)$f['nahrazuje']);
        if ($puvodni !== null && $puvodni['approved']) {
            echo '<input type="hidden" name="nahrazuje" value="' . (int)$puvodni['id'] . '" />';
            echo '<label style="display:flex; gap:8px; align-items:center; margin: 6px 0;"><input type="checkbox" name="navrh_upravy" value="1"' . (!empty($_POST['navrh_upravy']) ? ' checked="checked"' : '') . ' /> Je to návrh úpravy veřejné tiskárny „' . $h($puvodni['name']) . '“ (po schválení přepíše její hodnoty, id ' . $h(TiskarnyDb::idPredvolby($puvodni['id'])) . ' zůstane)</label>';
        }
    }
    echo '<div class="toolbar"><button class="primary" type="submit">' . StrankaUctu::ikona('check') . 'Uložit tiskárnu</button><a class="button" href="ucet.php?stranka=tiskarny">Zpět</a></div></form></div>';
    $skript = <<<'JS'
(function () {
  var tbody = document.querySelector('#schody tbody');
  var sablona = document.getElementById('sablona_schodu');
  var dalsi = tbody.querySelectorAll('tr[data-schod]').length;
  function kontrola() {
    // Výšky z musí jít vzestupně a být větší než 0 (stejně jako kontrola na serveru).
    var predchozi = 0, chyba = '', vyskaZ = parseFloat(document.getElementById('t_z').value);
    tbody.querySelectorAll('tr[data-schod]').forEach(function (tr, i) {
      var vstup = tr.querySelector('input[name$="[z]"]');
      var z = parseFloat(vstup.value);
      var spatne = false;
      if (vstup.value !== '') {
        if (!(z > 0)) { spatne = true; chyba = chyba || ('Schod ' + (i + 1) + ': z musí být větší než 0.'); }
        else if (z <= predchozi) { spatne = true; chyba = chyba || ('Schod ' + (i + 1) + ': výšky z musí jít vzestupně.'); }
        else if (isFinite(vyskaZ) && z > vyskaZ) { spatne = true; chyba = chyba || ('Schod ' + (i + 1) + ': z je větší než výška tisku Z.'); }
        if (z > predchozi) predchozi = z;
      }
      vstup.setAttribute('aria-invalid', spatne ? 'true' : 'false');
    });
    document.getElementById('kontrola_schodu').textContent = chyba;
    // Varování (neblokuje): schod užší než hlava pod ním, viz TiskarnyDb::varovaniProfilu.
    var pod = {}, uzsi = [];
    ['Xl', 'Xr', 'Yl', 'Yr'].forEach(function (k) { pod[k] = parseFloat(document.getElementById('t_' + k).value) || 0; });
    tbody.querySelectorAll('tr[data-schod]').forEach(function (tr, i) {
      ['Xl', 'Xr', 'Yl', 'Yr'].forEach(function (k) {
        var v = parseFloat(tr.querySelector('input[name$="[' + k + ']"]').value);
        if (!isFinite(v)) return;
        if (v < pod[k] && uzsi.indexOf(i + 1) < 0) uzsi.push(i + 1);
        pod[k] = Math.max(pod[k], v);
      });
    });
    document.getElementById('varovani_profilu').textContent = uzsi.length ? ('Pozor: schod ' + uzsi.join(', ') + ' je užší než hlava pod ním. Kontrola kolizí bere pro dřívější kus jen schod pro jeho výšku, takže širší spodní část hlavy může přehlédnout. Bezpečné je zadat obálku (každý schod aspoň tak široký jako vše pod ním).') : '';
    return chyba === '';
  }
  document.getElementById('pridat_schod').addEventListener('click', function () {
    tbody.insertAdjacentHTML('beforeend', sablona.innerHTML.replace(/__I__/g, String(dalsi++)));
    var radky = tbody.querySelectorAll('tr[data-schod]');
    radky[radky.length - 1].querySelector('input').focus();
  });
  tbody.addEventListener('click', function (e) {
    var tl = e.target.closest('[data-smazat-schod]');
    if (!tl) return;
    var tr = tl.closest('tr');
    var dalsiRadek = tr.nextElementSibling;
    if (dalsiRadek && dalsiRadek.classList.contains('chyba-radek')) dalsiRadek.remove();
    tr.remove();
    kontrola();
  });
  document.getElementById('formular_tiskarny').addEventListener('input', kontrola);
  document.getElementById('formular_tiskarny').addEventListener('submit', function (e) {
    if (!kontrola()) { e.preventDefault(); document.getElementById('kontrola_schodu').scrollIntoView({ block: 'center' }); }
  });
  kontrola();
})();
JS;
    StrankaUctu::konec($skript);
    exit;
}

// Schvalování tiskáren (jen admin).
if ($stranka === 'schvalovani') {
    if (!$uzivatel['is_admin']) {
        http_response_code(403);
        StrankaUctu::zacatek('Schvalování tiskáren', $uzivatel);
        echo '<div class="card uzka"><h2>Jen pro admina</h2><p>Schvalovat tiskárny může jen admin (<code>users.is_admin</code>, viz README).</p></div>';
        StrankaUctu::konec();
        exit;
    }
    $navrhy = TiskarnyDb::navrhy($pdo);
    $schvalene = TiskarnyDb::schvalene($pdo);
    $hodnoty_tiskarny = function (array $t) use ($h): string {
        $schody = empty($t['head_steps']) ? 'bez schodů' : implode('; ', array_map(function ($s) { return 'od ' . $s['z'] . ' mm: ' . $s['Xl'] . ' / ' . $s['Xr'] . ' / ' . $s['Yl'] . ' / ' . $s['Yr']; }, $t['head_steps']));
        return 'Podložka ' . $h($t['x'] . ' × ' . $t['y'] . ' × ' . $t['z']) . ' mm, korekce zprava ' . $h($t['posun_zprava']) . '<br />Hlava Xl / Xr / Yl / Yr: ' . $h($t['Xl'] . ' / ' . $t['Xr'] . ' / ' . $t['Yl'] . ' / ' . $t['Yr'])
            . '<br />Schody: ' . $h($schody) . (empty($v = TiskarnyDb::varovaniProfilu($t)) ? '' : '<br /><span class="chyba-text">Pozor, profil se nahoru zužuje: ' . $h(implode(' ', $v)) . '</span>') . '<br />Vodicí tyče Z / Y: ' . $h($t['vodici_tyce_Z'] . ' / ' . $t['vodici_tyce_Y']) . ($t['poznamka'] !== '' ? '<br /><span class="muted">Zdroj: ' . nl2br($h($t['poznamka'])) . '</span>' : '');
    };
    $tlacitko = function (string $akce, int $id, string $popis, string $ikona, string $trida = 'small'): string {
        return '<form method="post" action="ucet.php?stranka=schvalovani" class="inline">' . StrankaUctu::csrfPole() . '<input type="hidden" name="akce" value="' . $akce . '" /><input type="hidden" name="id" value="' . $id . '" /><button class="' . $trida . '" type="submit">' . StrankaUctu::ikona($ikona) . $popis . '</button></form>';
    };
    StrankaUctu::zacatek('Schvalování tiskáren', $uzivatel, 'schvalovani');
    echo '<div class="card"><h2>Návrhy ke zveřejnění</h2>';
    if (empty($navrhy)) echo '<p class="muted">Žádný návrh nečeká.</p>';
    else {
        echo '<div class="table-wrap"><table><tr><th>Tiskárna</th><th>Hodnoty</th><th>Autor</th><th>Navrženo</th><th>Akce</th></tr>';
        foreach ($navrhy as $t) {
            $nahrazuje = $t['replaces_printer_id'] !== null ? TiskarnyDb::jedna($pdo, $t['replaces_printer_id']) : null;
            echo '<tr><td>' . $h($t['name']) . ($nahrazuje ? '<br /><span class="stitek ceka">úprava „' . $h($nahrazuje['name']) . '“ (' . $h(TiskarnyDb::idPredvolby($nahrazuje['id'])) . ')</span>' : '') . '</td><td>' . $hodnoty_tiskarny($t) . '</td>';
            echo '<td>' . $h($t['vlastnik_email'] ?? '') . '</td><td>' . $h(StrankaUctu::datum($t['navrzeno_at'])) . '</td><td><div class="toolbar" style="margin:0;">';
            echo $tlacitko('schvalit_tiskarnu', (int)$t['id'], $nahrazuje ? 'Schválit úpravu' : 'Schválit', 'shield-check', 'small primary');
            echo $tlacitko('vratit_tiskarnu', (int)$t['id'], 'Vrátit k úpravám', 'undo');
            echo '</div></td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div><div class="card"><h2>Schválené tiskárny z DB</h2><p class="muted">Ve výběru tiskárny jsou vedle předvoleb z <code>config/tiskarny.json</code>. Zrušení zveřejnění je vrátí vlastníkovi jako koncept (odkazy <code>tiskarna=db_…</code> pak ostatním přestanou fungovat).</p>';
    if (empty($schvalene)) echo '<p class="muted">Zatím žádné.</p>';
    else {
        echo '<div class="table-wrap"><table><tr><th>Tiskárna</th><th>Id</th><th>Hodnoty</th><th>Vlastník</th><th>Akce</th></tr>';
        foreach ($schvalene as $t) {
            echo '<tr><td>' . $h($t['name']) . '</td><td><code>' . $h(TiskarnyDb::idPredvolby($t['id'])) . '</code></td><td>' . $hodnoty_tiskarny($t) . '</td><td>' . $h($t['vlastnik_email'] ?? 'systém') . '</td><td>';
            echo '<details><summary class="button small">' . StrankaUctu::ikona('x') . 'Zrušit zveřejnění</summary><div style="margin-top:6px;">' . $tlacitko('zrusit_zverejneni', (int)$t['id'], 'Ano, zrušit zveřejnění', 'x', 'small danger') . '</div></details></td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';
    StrankaUctu::konec();
    exit;
}

// API klíče (D1): seznam, nový klíč (ukáže se jen jednou), zneplatnění.
if ($stranka === 'klice') {
    $novy_klic = isset($_SESSION['novy_klic']) && is_array($_SESSION['novy_klic']) ? $_SESSION['novy_klic'] : null;
    unset($_SESSION['novy_klic']);
    $klice = SpravaKlicu::seznam($pdo, $uzivatel['id']);
    StrankaUctu::zacatek('API klíče', $uzivatel, 'klice');
    if ($novy_klic) {
        echo '<div class="card" id="novy_klic"><h2>Nový klíč „' . $h($novy_klic['name']) . '“</h2>';
        echo '<p><strong>Zkopíruj si ho teď, znovu ho už neuvidíš</strong> (v databázi je jen jeho otisk).</p>';
        echo '<div class="tajne" id="novy_klic_hodnota">' . $h($novy_klic['api_key']) . '</div>';
        echo '<div class="toolbar"><button class="small" type="button" id="kopirovat_klic">' . StrankaUctu::ikona('copy') . '<span class="lbl">Kopírovat</span></button>';
        echo '<span class="muted">Použití: hlavička <code>Authorization: Bearer &lt;klíč&gt;</code> nebo <code>X-Api-Key</code>.</span></div></div>';
    }
    echo '<div class="card"><h2>API klíče</h2>';
    echo '<p class="muted">Klíčem se přihlašuje integrace k API v1 (<code>arrange</code>, <code>import</code>, <code>export</code>, <code>keys</code>, <code>projects</code>). Limit = nejvýš požadavků za minutu, 0 = bez limitu.</p>';
    if (empty($klice)) {
        echo '<p>Zatím nemáš žádný klíč.</p>';
    } else {
        echo '<div class="table-wrap"><table><tr><th>Název</th><th>Prefix</th><th class="cislo">Limit / min</th><th>Vytvořen</th><th>Naposledy použit</th><th>Stav</th><th>Akce</th></tr>';
        foreach ($klice as $k) {
            $platny = $k['revoked_at'] === null;
            echo '<tr><td>' . $h($k['name']) . '</td><td><code>' . $h($k['key_prefix']) . '…</code></td><td class="cislo">' . ((int)$k['rate_limit_per_min'] === 0 ? 'bez limitu' : (int)$k['rate_limit_per_min']) . '</td>';
            echo '<td>' . $h(StrankaUctu::datum($k['created_at'])) . '</td><td>' . ($k['last_used_at'] ? $h(StrankaUctu::datum($k['last_used_at'])) : '<span class="muted">nikdy</span>') . '</td>';
            echo '<td>' . ($platny ? '<span class="stitek ok">platný</span>' : '<span class="stitek">zneplatněn ' . $h(StrankaUctu::datum($k['revoked_at'])) . '</span>') . '</td><td>';
            if ($platny) {
                echo '<details><summary class="button small">' . StrankaUctu::ikona('x') . 'Zneplatnit</summary><form method="post" action="ucet.php?stranka=klice" class="inline" style="margin-top:6px;">' . StrankaUctu::csrfPole()
                    . '<input type="hidden" name="akce" value="zneplatnit_klic" /><input type="hidden" name="id" value="' . (int)$k['id'] . '" />'
                    . '<span class="muted">Integrace s tímto klíčem přestane fungovat. Nejde to vrátit.</span><button class="small danger" type="submit">' . StrankaUctu::ikona('x') . 'Ano, zneplatnit</button></form></details>';
            }
            echo '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '<h3>Nový klíč</h3><form method="post" action="ucet.php?stranka=klice" class="inline">' . StrankaUctu::csrfPole() . '<input type="hidden" name="akce" value="vytvorit_klic" />';
    echo '<label class="pole" style="margin:0;">Název <input type="text" name="name" maxlength="100" required="required" placeholder="např. skript doma" /></label>';
    echo '<label class="pole" style="margin:0;">Limit / min <input type="number" name="rate_limit_per_min" min="0" max="' . SpravaKlicu::MAXIMALNI_LIMIT . '" step="1" value="' . SpravaKlicu::VYCHOZI_LIMIT . '" style="width: 8rem;" /></label>';
    echo '<button class="primary" type="submit" style="align-self: end;">' . StrankaUctu::ikona('plus') . 'Vytvořit klíč</button></form></div>';
    StrankaUctu::konec($novy_klic ? 'document.getElementById("kopirovat_klic").addEventListener("click", async function () { var t = document.getElementById("novy_klic_hodnota").textContent; var l = this.querySelector(".lbl"); try { await navigator.clipboard.writeText(t); l.textContent = "Zkopírováno"; } catch (e) { var r = document.createRange(); r.selectNodeContents(document.getElementById("novy_klic_hodnota")); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); l.textContent = "Označeno, zkopíruj Ctrl+C"; } });' : '');
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

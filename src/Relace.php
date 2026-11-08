<?php
/**
 * Přihlášení v UI: PHP session, přihlášený uživatel a CSRF token.
 *
 * Aplikace funguje i bez DB: bez DB_DSN se session vůbec nespouští a účty se v UI nezobrazí (Relace::dbZapnuta()).
 * Session se zakládá jen na stránkách účtu (ucet.php); index.php ji jen obnoví, když už cookie existuje,
 * aby anonymní návštěvník nedostal cookie zbytečně.
 *
 * Cookie: HttpOnly, SameSite=Lax, Secure na HTTPS, path = složka aplikace; session.use_strict_mode (neznámé id
 * se nepřevezme), po přihlášení session_regenerate_id(true).
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Db.php';

class Relace
{
    /** Název session cookie. */
    public const NAZEV = 'sekvencni_tisk';

    /** @var array|null|false Přihlášený uživatel (cache pro jeden požadavek), false = ještě nenačteno. */
    private static $uzivatel = false;

    /** Jsou účty zapnuté (nastavené DB_DSN)? Bez DB aplikace funguje dál, jen bez účtů. */
    public static function dbZapnuta(): bool
    {
        return (string)(getenv('DB_DSN') ?: '') !== '';
    }

    /** Běží požadavek přes HTTPS (i za proxy, která nastaví HTTPS nebo port 443)? */
    public static function jeHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
        return isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443';
    }

    /** Cesta cookie: složka, ve které leží index.php (aplikace může běžet v podsložce). */
    public static function cestaCookie(): string
    {
        $skript = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '/';
        $slozka = str_replace('\\', '/', dirname($skript));
        if ($slozka === '' || $slozka === '.') return '/';
        return rtrim($slozka, '/') . '/';
    }

    /** Spustí session (idempotentní). Mimo web (CLI) nic nedělá. */
    public static function start(): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) return;
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name(self::NAZEV);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => self::cestaCookie(),
            'secure' => self::jeHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Obnoví session jen tehdy, když prohlížeč už cookie poslal (index.php: anonymní návštěva bez cookie). */
    public static function startPokudExistuje(): void
    {
        if (!self::dbZapnuta() || !isset($_COOKIE[self::NAZEV])) return;
        self::start();
    }

    /**
     * Přihlášený uživatel: ['id' => int, 'email' => string, 'is_admin' => bool], jinak null.
     * Čte se z DB při každém požadavku (smazaný uživatel nebo odebraný admin se projeví hned).
     * Chyba DB = nepřihlášen (aplikace jede dál bez účtů).
     */
    public static function uzivatel(): ?array
    {
        if (self::$uzivatel !== false) return self::$uzivatel;
        self::$uzivatel = null;
        if (!self::dbZapnuta() || session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) return null;
        try {
            $stmt = Db::pdo()->prepare('SELECT id, email, is_admin FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$_SESSION['user_id']]);
            $radek = $stmt->fetch();
        } catch (Throwable $e) {
            error_log('Sekvenční tisk: nelze načíst přihlášeného uživatele: ' . $e->getMessage());
            return null;
        }
        if (!$radek) {
            unset($_SESSION['user_id']);
            return null;
        }
        self::$uzivatel = ['id' => (int)$radek['id'], 'email' => (string)$radek['email'], 'is_admin' => (bool)$radek['is_admin']];
        return self::$uzivatel;
    }

    /** Po úspěšném přihlášení: nové id session (ochrana proti fixaci session) a nový CSRF token. */
    public static function prihlas(array $uzivatel): void
    {
        self::start();
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION = ['user_id' => (int)$uzivatel['id'], 'csrf' => bin2hex(random_bytes(32))];
        self::$uzivatel = false;
    }

    /** Odhlášení: smaže data session, session i cookie. */
    public static function odhlas(): void
    {
        self::start();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(self::NAZEV, '', [
                'expires' => time() - 3600,
                'path' => $p['path'],
                'secure' => $p['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_destroy();
        }
        self::$uzivatel = false;
    }

    /** CSRF token této session (založí ho, když chybí). Bez aktivní session prázdný řetězec. */
    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return '';
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    /** Ověří CSRF token z formuláře (pole `csrf`) nebo z hlavičky X-CSRF-Token. */
    public static function overCsrf($token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) return false;
        return is_string($token) && $token !== '' && hash_equals($_SESSION['csrf'], $token);
    }

    /** CSRF token z požadavku: POST pole `csrf`, jinak hlavička X-CSRF-Token. */
    public static function csrfZPozadavku(): ?string
    {
        if (isset($_POST['csrf']) && is_string($_POST['csrf'])) return $_POST['csrf'];
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) return $_SERVER['HTTP_X_CSRF_TOKEN'];
        return null;
    }

    /** Jednorázová zpráva pro další stránku (po přesměrování). */
    public static function nastavZpravu(string $text, string $typ = 'ok'): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['zprava'] = ['text' => $text, 'typ' => $typ];
    }

    /** Vyzvedne jednorázovou zprávu (a smaže ji). */
    public static function vezmiZpravu(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['zprava']) || !is_array($_SESSION['zprava'])) return null;
        $zprava = $_SESSION['zprava'];
        unset($_SESSION['zprava']);
        return $zprava;
    }
}

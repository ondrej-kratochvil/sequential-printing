<?php
/**
 * Uživatelské účty (DB): registrace, přihlášení, omezení počtu pokusů.
 *
 * Hesla: password_hash(PASSWORD_DEFAULT), při přihlášení password_needs_rehash. Délka hesla 8 až 72 bajtů
 * (bcrypt delší heslo tiše zkrátí, proto ho rovnou odmítneme). Neznámý e-mail se ověřuje proti
 * fiktivnímu hashi, aby se odpověď nelišila časem.
 *
 * Omezení pokusů (tabulka login_attempts, čas podle DB):
 *   - přihlášení: nejvýš MAX_CHYB_EMAIL neúspěchů na e-mail a MAX_CHYB_IP na IP za OKNO_S sekund,
 *   - registrace: nejvýš MAX_REGISTRACI_IP registrací z jedné IP za OKNO_REGISTRACE_S.
 * IP je REMOTE_ADDR (hlavičkám typu X-Forwarded-For se nevěří, dají se podvrhnout).
 *
 * E-maily se neposílají (bez ověření adresy a bez resetu hesla e-mailem, viz ROADMAP).
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Db.php';

class Ucty
{
    public const MIN_DELKA_HESLA = 8;
    public const MAX_DELKA_HESLA = 72;
    public const MAX_DELKA_EMAILU = 255;

    /** Okno pro neúspěšná přihlášení (s). */
    public const OKNO_S = 900;
    public const MAX_CHYB_EMAIL = 5;
    public const MAX_CHYB_IP = 20;

    /** Okno pro registrace z jedné IP (s). */
    public const OKNO_REGISTRACE_S = 3600;
    public const MAX_REGISTRACI_IP = 10;

    /** Záznamy pokusů starší než tohle (s) se mažou. */
    public const UCHOVAT_POKUSY_S = 86400;

    /** Je registrace zapnutá? Vypíná ji env REGISTRACE=0 (účty pak zakládá jen admin přes CLI). */
    public static function registraceZapnuta(): bool
    {
        $hodnota = getenv('REGISTRACE');
        return $hodnota === false || (string)$hodnota !== '0';
    }

    /** E-mail pro uložení a porovnání: oříznutý, malými písmeny. */
    public static function normalizujEmail($email): string
    {
        return is_string($email) ? strtolower(trim($email)) : '';
    }

    /** Chyba e-mailu (česky), null = v pořádku. */
    public static function overEmail(string $email): ?string
    {
        if ($email === '') return 'Vyplň e-mail.';
        if (strlen($email) > self::MAX_DELKA_EMAILU) return 'E-mail je příliš dlouhý (nejvýš ' . self::MAX_DELKA_EMAILU . ' znaků).';
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) return 'E-mail nemá platný tvar (např. jmeno@example.cz).';
        return null;
    }

    /** Chyba hesla (česky), null = v pořádku. */
    public static function overHeslo($heslo, $znovu): ?string
    {
        if (!is_string($heslo) || $heslo === '') return 'Vyplň heslo.';
        if (strlen($heslo) < self::MIN_DELKA_HESLA) return 'Heslo musí mít aspoň ' . self::MIN_DELKA_HESLA . ' znaků.';
        if (strlen($heslo) > self::MAX_DELKA_HESLA) return 'Heslo může mít nejvýš ' . self::MAX_DELKA_HESLA . ' bajtů (znak s diakritikou zabere 2).';
        if (!is_string($znovu) || !hash_equals($heslo, $znovu)) return 'Hesla se neshodují.';
        return null;
    }

    /**
     * Registrace. Vrací ['ok' => bool, 'chyby' => [pole => zpráva], 'uzivatel' => ?array].
     */
    public static function registruj(PDO $pdo, $email, $heslo, $znovu, string $ip): array
    {
        $email = self::normalizujEmail($email);
        $chyby = [];
        if (!self::registraceZapnuta()) return ['ok' => false, 'chyby' => ['email' => 'Registrace je vypnutá. Účet založí správce.'], 'uzivatel' => null];
        $zbyva = self::blokovanoRegistrace($pdo, $ip);
        if ($zbyva > 0) {
            return ['ok' => false, 'chyby' => ['email' => 'Z této adresy bylo založeno příliš mnoho účtů. Zkus to za ' . self::lidskyCas($zbyva) . '.'], 'uzivatel' => null];
        }
        $chybaEmailu = self::overEmail($email);
        if ($chybaEmailu !== null) $chyby['email'] = $chybaEmailu;
        $chybaHesla = self::overHeslo($heslo, $znovu);
        if ($chybaHesla !== null) $chyby['heslo'] = $chybaHesla;
        if (!empty($chyby)) return ['ok' => false, 'chyby' => $chyby, 'uzivatel' => null];

        if (self::najdiPodleEmailu($pdo, $email) !== null) {
            return ['ok' => false, 'chyby' => ['email' => 'Účet s tímto e-mailem už existuje. Přihlas se.'], 'uzivatel' => null];
        }
        try {
            $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute([$email, password_hash($heslo, PASSWORD_DEFAULT)]);
        } catch (PDOException $e) {
            // Souběh dvou registrací stejného e-mailu: unikátní klíč uq_users_email.
            if ($e->getCode() === '23000') return ['ok' => false, 'chyby' => ['email' => 'Účet s tímto e-mailem už existuje. Přihlas se.'], 'uzivatel' => null];
            throw $e;
        }
        $id = (int)$pdo->lastInsertId();
        self::zaznamenejPokus($pdo, 'registrace', $email, $ip, true);
        return ['ok' => true, 'chyby' => [], 'uzivatel' => ['id' => $id, 'email' => $email, 'is_admin' => false]];
    }

    /**
     * Přihlášení. Vrací ['ok' => bool, 'chyba' => ?string, 'uzivatel' => ?array, 'blokovano_s' => int].
     * Zpráva při chybě neříká, jestli účet existuje.
     */
    public static function prihlas(PDO $pdo, $email, $heslo, string $ip): array
    {
        $email = self::normalizujEmail($email);
        $heslo = is_string($heslo) ? $heslo : '';
        $zbyva = self::blokovanoPrihlaseni($pdo, $email, $ip);
        if ($zbyva > 0) {
            return ['ok' => false, 'chyba' => 'Příliš mnoho neúspěšných pokusů. Zkus to znovu za ' . self::lidskyCas($zbyva) . '.', 'uzivatel' => null, 'blokovano_s' => $zbyva];
        }
        $radek = ($email !== '' && strlen($email) <= self::MAX_DELKA_EMAILU) ? self::najdiPodleEmailu($pdo, $email) : null;
        // Neznámý účet: ověřit proti fiktivnímu hashi, ať odpověď trvá stejně dlouho.
        $hash = $radek ? (string)$radek['password_hash'] : password_hash('neexistujici-ucet', PASSWORD_DEFAULT);
        $platne = password_verify($heslo, $hash) && $radek !== null;
        self::zaznamenejPokus($pdo, 'prihlaseni', $email, $ip, $platne);
        if (!$platne) {
            return ['ok' => false, 'chyba' => 'Nesprávný e-mail nebo heslo.', 'uzivatel' => null, 'blokovano_s' => 0];
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($heslo, PASSWORD_DEFAULT), (int)$radek['id']]);
        }
        // Úspěch vynuluje neúspěšné pokusy na tenhle e-mail.
        $pdo->prepare("DELETE FROM login_attempts WHERE druh = 'prihlaseni' AND email = ? AND uspech = 0")->execute([$email]);
        return ['ok' => true, 'chyba' => null, 'uzivatel' => ['id' => (int)$radek['id'], 'email' => (string)$radek['email'], 'is_admin' => (bool)$radek['is_admin']], 'blokovano_s' => 0];
    }

    /** Za kolik sekund se odblokuje přihlášení (0 = neblokováno). */
    public static function blokovanoPrihlaseni(PDO $pdo, string $email, string $ip): int
    {
        $zbyva = 0;
        if ($email !== '') $zbyva = max($zbyva, self::zbyvaVOkne($pdo, 'prihlaseni', 'email', $email, false, self::OKNO_S, self::MAX_CHYB_EMAIL));
        if ($ip !== '') $zbyva = max($zbyva, self::zbyvaVOkne($pdo, 'prihlaseni', 'ip', $ip, false, self::OKNO_S, self::MAX_CHYB_IP));
        return $zbyva;
    }

    /** Za kolik sekund půjde z této IP založit další účet (0 = hned). */
    public static function blokovanoRegistrace(PDO $pdo, string $ip): int
    {
        if ($ip === '') return 0;
        return self::zbyvaVOkne($pdo, 'registrace', 'ip', $ip, true, self::OKNO_REGISTRACE_S, self::MAX_REGISTRACI_IP);
    }

    /** Zapíše pokus a smaže staré záznamy. */
    public static function zaznamenejPokus(PDO $pdo, string $druh, string $email, string $ip, bool $uspech): void
    {
        $pdo->prepare('INSERT INTO login_attempts (druh, email, ip, uspech) VALUES (?, ?, ?, ?)')
            ->execute([$druh, substr($email, 0, self::MAX_DELKA_EMAILU), substr($ip, 0, 45), $uspech ? 1 : 0]);
        $pdo->prepare('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL ? SECOND')->execute([self::UCHOVAT_POKUSY_S]);
    }

    /** Uživatel podle e-mailu (id, email, password_hash, is_admin), null = není. */
    public static function najdiPodleEmailu(PDO $pdo, string $email): ?array
    {
        $stmt = $pdo->prepare('SELECT id, email, password_hash, is_admin FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $radek = $stmt->fetch(PDO::FETCH_ASSOC);
        return $radek ?: null;
    }

    /** Kolik zbývá do konce blokace (s) pro záznamy `druh` + sloupec = hodnota v okně. */
    private static function zbyvaVOkne(PDO $pdo, string $druh, string $sloupec, string $hodnota, bool $uspech, int $okno, int $max): int
    {
        $sloupec = $sloupec === 'ip' ? 'ip' : 'email';
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS pocet, TIMESTAMPDIFF(SECOND, NOW(), MIN(created_at) + INTERVAL ? SECOND) AS zbyva
            FROM login_attempts
            WHERE druh = ? AND {$sloupec} = ? AND uspech = ? AND created_at > NOW() - INTERVAL ? SECOND
        ");
        $stmt->execute([$okno, $druh, $hodnota, $uspech ? 1 : 0, $okno]);
        $radek = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$radek || (int)$radek['pocet'] < $max) return 0;
        return max(1, (int)$radek['zbyva']);
    }

    /** „5 min“ / „40 s“. */
    public static function lidskyCas(int $sekundy): string
    {
        if ($sekundy >= 60) return (int)ceil($sekundy / 60) . ' min';
        return $sekundy . ' s';
    }

    /** IP klienta pro omezení pokusů (REMOTE_ADDR). */
    public static function ipKlienta(): string
    {
        return isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : '';
    }
}

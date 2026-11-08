<?php
/**
 * Dočasné úložiště nahraných modelů pro UI (import v index.php, pozdější export 3MF se stejnými sítěmi).
 *
 * UI je formulář GET bez DB a bez session, proto se nahraný soubor uloží pod náhodný token (32 hex znaků,
 * random_bytes) do dočasné složky a formulář nese jen token (`sit_token`). Token je zároveň oprávnění:
 * bez něj se k souboru nikdo nedostane a uhodnout ho nejde. Soubory starší než IMPORT_TTL_HODIN (výchozí 24)
 * se mažou při každém dalším importu.
 *
 * Složka: env IMPORT_TMP_DIR, jinak <sys_get_temp_dir()>/sekvencni_tisk_modely.
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ApiChyba.php';

class UlozisteModelu
{
    public const VYCHOZI_TTL_HODIN = 24;

    /** Složka úložiště (vytvoří se při prvním uložení). */
    public static function slozka(): string
    {
        $slozka = getenv('IMPORT_TMP_DIR');
        if ($slozka === false || trim($slozka) === '') $slozka = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sekvencni_tisk_modely';
        return rtrim($slozka, '/\\');
    }

    /** Jak dlouho soubory drží (sekundy). */
    public static function ttl(): int
    {
        $hodnota = getenv('IMPORT_TTL_HODIN');
        $hodin = ($hodnota !== false && is_numeric($hodnota) && (float)$hodnota > 0) ? (float)$hodnota : self::VYCHOZI_TTL_HODIN;
        return (int)round($hodin * 3600);
    }

    /** Platný tvar tokenu (32 hex znaků) - nic jiného se do cesty k souboru nedostane. */
    public static function jePlatnyToken($token): bool
    {
        return is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token) === 1;
    }

    /**
     * Zkopíruje soubor do úložiště, vrátí token. Při té příležitosti smaže prošlé soubory.
     *
     * @throws ApiChyba 500 při chybě zápisu.
     */
    public static function uloz(string $cesta, string $nazevSouboru): string
    {
        $slozka = self::slozka();
        if (!is_dir($slozka) && !@mkdir($slozka, 0700, true) && !is_dir($slozka)) {
            throw new ApiChyba(500, 'interni_chyba', 'Nepodařilo se připravit dočasnou složku pro model.');
        }
        self::uklid();
        $token = bin2hex(random_bytes(16));
        if (!@copy($cesta, $slozka . DIRECTORY_SEPARATOR . $token . '.model')
            || @file_put_contents($slozka . DIRECTORY_SEPARATOR . $token . '.json', json_encode(['nazev' => $nazevSouboru, 'vytvoreno' => time()], JSON_UNESCAPED_UNICODE)) === false) {
            throw new ApiChyba(500, 'interni_chyba', 'Nepodařilo se uložit model do dočasné složky.');
        }
        return $token;
    }

    /**
     * Uložený model podle tokenu: ['cesta' => soubor, 'nazev' => původní název], nebo null (neplatný token, prošlý, smazaný).
     */
    public static function najdi($token): ?array
    {
        if (!self::jePlatnyToken($token)) return null;
        $zaklad = self::slozka() . DIRECTORY_SEPARATOR . $token;
        if (!is_file($zaklad . '.model') || !is_file($zaklad . '.json')) return null;
        if (filemtime($zaklad . '.model') < time() - self::ttl()) return null;
        $meta = json_decode((string)file_get_contents($zaklad . '.json'), true);
        return ['cesta' => $zaklad . '.model', 'nazev' => is_array($meta) && isset($meta['nazev']) ? (string)$meta['nazev'] : 'model'];
    }

    /** Smaže soubory úložiště starší než TTL (jen soubory s tvarem tokenu). */
    public static function uklid(): int
    {
        $slozka = self::slozka();
        if (!is_dir($slozka)) return 0;
        $smazano = 0;
        $hranice = time() - self::ttl();
        foreach ((array)glob($slozka . DIRECTORY_SEPARATOR . '*') as $soubor) {
            if (!preg_match('/^[a-f0-9]{32}\.(model|json)$/', basename($soubor))) continue;
            if (@filemtime($soubor) < $hranice && @unlink($soubor)) $smazano++;
        }
        return $smazano;
    }
}

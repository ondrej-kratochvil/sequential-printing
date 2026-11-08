<?php
/**
 * Sdílená vrstva JSON API: vstup, autentizace (API klíč + rate limit) a odpověď.
 *
 * Používají ji stabilní endpointy api/v1/*.php i legacy api.php a api_keys.php, aby se
 * čtení vstupu, ověření klíče a odesílání odpovědi neopakovalo.
 *
 * API v1 vrací chyby jednotně (viz ApiChyba):
 *   {"error": {"code": "...", "message": "...", "details": {...}}}
 * Legacy endpointy si nechávají dosavadní tvar {"error": "<text>"} (viz odeslatLegacyChybu()).
 *
 * Testovatelnost bez webového serveru: vstup jde podstrčit přes Api::$surovyVstup (místo
 * php://input) a poslední odpověď (status, hlavičky, tělo) je v Api::$odeslano.
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ApiChyba.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ApiAuth.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'VstupVypoctu.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SequentialPrintCalculator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ImportModelu.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'Export3mf.php';

class Api
{
    /** Verze stabilního API. */
    public const VERZE = 'v1';

    /** Příznaky json_encode pro odpovědi. */
    private const JSON_PRIZNAKY = JSON_UNESCAPED_UNICODE;

    /** @var string|null Tělo požadavku pro testy (jinak php://input). */
    public static $surovyVstup = null;

    /** @var array|null Poslední odeslaná odpověď: ['status' => int, 'hlavicky' => [název => hodnota], 'telo' => array]. */
    public static $odeslano = null;

    /** @var array Hlavičky, které se přidají k odpovědi (např. X-RateLimit-* po ověření klíče). */
    private static $hlavicky = [];

    /** HTTP metoda požadavku (velkými písmeny, bez metody GET). */
    public static function metoda(): string
    {
        return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : 'GET');
    }

    /**
     * Povolí jen vyjmenované metody, jinak 405 `nepovolena_metoda` s hlavičkou Allow.
     *
     * @param string[] $metody
     * @throws ApiChyba
     */
    public static function povolMetody(array $metody): string
    {
        $metoda = self::metoda();
        if (!in_array($metoda, $metody, true)) {
            throw new ApiChyba(405, 'nepovolena_metoda', "Metoda {$metoda} není u tohoto endpointu povolená. Povolené: " . implode(', ', $metody) . '.', [
                'metoda' => $metoda,
                'povolene' => $metody,
            ], ['Allow' => implode(', ', $metody)]);
        }
        return $metoda;
    }

    /** Surové tělo požadavku. */
    public static function surovyVstup(): string
    {
        if (self::$surovyVstup !== null) return self::$surovyVstup;
        $raw = file_get_contents('php://input');
        return $raw === false ? '' : $raw;
    }

    /**
     * Tělo požadavku jako JSON objekt (API v1: neplatný JSON je chyba, ne tichý přechod na GET).
     *
     * @throws ApiChyba 400 `neplatny_json`.
     */
    public static function teloJson(): array
    {
        return self::jsonObjekt(self::surovyVstup(), 'Tělo požadavku');
    }

    /**
     * Text jako JSON objekt (tělo požadavku, pole `vstup` v multipart).
     *
     * @throws ApiChyba 400 `neplatny_json`.
     */
    public static function jsonObjekt(string $raw, string $co): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $duvod = trim($raw) === '' ? 'prázdné tělo' : json_last_error_msg();
            throw new ApiChyba(400, 'neplatny_json', "{$co} není platný JSON objekt ({$duvod}).", ['json_chyba' => $duvod]);
        }
        return $data;
    }

    /** Je požadavek multipart/form-data (nahrání souboru formulářem)? */
    public static function jeMultipart(): bool
    {
        $typ = isset($_SERVER['CONTENT_TYPE']) ? (string)$_SERVER['CONTENT_TYPE'] : '';
        return stripos($typ, 'multipart/form-data') === 0 || !empty($_FILES);
    }

    /**
     * Nahraný soubor modelu (import a export 3MF/STL): multipart pole `$pole`, nebo (s $zTela) surové tělo požadavku
     * s názvem souboru v `?nazev=`. Velikost hlídá ImportModelu::overVelikost() (IMPORT_MAX_MB), překročení limitů PHP
     * (upload_max_filesize, post_max_size) je srozumitelná chyba 413. Dočasný soubor z těla se po odeslání odpovědi smaže.
     *
     * @return array|null ['cesta' => soubor na disku, 'nazev' => původní název], null = nic nenahráno.
     * @throws ApiChyba 400 chyba_nahrani, 413 soubor_prilis_velky.
     */
    public static function nahranySoubor(string $pole = 'soubor', bool $zTela = true): ?array
    {
        $delka = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
        $postMax = self::bajtyZIni((string)ini_get('post_max_size'));
        if (self::jeMultipart() && empty($_FILES) && empty($_POST) && $postMax > 0 && $delka > $postMax) {
            throw new ApiChyba(413, 'soubor_prilis_velky', 'Požadavek je větší než post_max_size v PHP (' . ini_get('post_max_size') . ').', [
                'velikost' => $delka,
                'limit' => $postMax,
            ]);
        }
        if (isset($_FILES[$pole]) && is_array($_FILES[$pole])) {
            $f = $_FILES[$pole];
            $chyba = isset($f['error']) ? (int)$f['error'] : UPLOAD_ERR_NO_FILE;
            if ($chyba === UPLOAD_ERR_NO_FILE) return null;
            if ($chyba === UPLOAD_ERR_INI_SIZE || $chyba === UPLOAD_ERR_FORM_SIZE) {
                throw new ApiChyba(413, 'soubor_prilis_velky', 'Soubor je větší než upload_max_filesize v PHP (' . ini_get('upload_max_filesize') . ').', [
                    'limit' => self::bajtyZIni((string)ini_get('upload_max_filesize')),
                ]);
            }
            if ($chyba !== UPLOAD_ERR_OK || !is_string($f['tmp_name'] ?? null) || $f['tmp_name'] === ''
                || (PHP_SAPI !== 'cli' && !is_uploaded_file($f['tmp_name']))) {
                throw new ApiChyba(400, 'chyba_nahrani', "Soubor se nepodařilo nahrát (kód {$chyba}).", ['kod' => $chyba]);
            }
            ImportModelu::overVelikost((int)filesize($f['tmp_name']));
            return ['cesta' => $f['tmp_name'], 'nazev' => self::nazevSouboru($f['name'] ?? '')];
        }
        if (!$zTela || self::jeMultipart()) return null;
        $telo = self::surovyVstup();
        if ($telo === '') return null;
        ImportModelu::overVelikost(strlen($telo));
        $cesta = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($cesta, $telo);
        register_shutdown_function(function () use ($cesta): void { @unlink($cesta); });
        return ['cesta' => $cesta, 'nazev' => self::nazevSouboru($_GET['nazev'] ?? '')];
    }

    /** Název souboru bez cesty a řídicích znaků (jen pro zobrazení a název objektu u STL). */
    public static function nazevSouboru($nazev): string
    {
        $nazev = is_string($nazev) ? basename(str_replace('\\', '/', $nazev)) : '';
        $nazev = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $nazev));
        return $nazev === '' ? 'model' : $nazev;
    }

    /** Hodnota z php.ini (např. "2M") v bajtech. */
    public static function bajtyZIni(string $hodnota): int
    {
        $hodnota = trim($hodnota);
        if ($hodnota === '') return 0;
        $cislo = (float)$hodnota;
        switch (strtolower(substr($hodnota, -1))) {
            case 'g': $cislo *= 1024;
            // no break
            case 'm': $cislo *= 1024;
            // no break
            case 'k': $cislo *= 1024;
        }
        return (int)$cislo;
    }

    /**
     * Vstup výpočtu: POST = JSON tělo, GET = query string (stejné parametry jako UI a api.php).
     *
     * @return array [vstup, zDotazu]
     * @throws ApiChyba
     */
    public static function vstupVypoctu(): array
    {
        if (self::metoda() === 'POST') return [self::teloJson(), false];
        return [$_GET, true];
    }

    /**
     * Ověří API klíč a započítá požadavek do rate limitu (ApiAuth). Stav okna přidá do hlaviček
     * odpovědi (X-RateLimit-Limit, X-RateLimit-Remaining).
     *
     * @param bool $legacy Text chyby jako dřív (429 „Rate limit exceeded.“), jinak česky.
     * @return array Řádek klíče z ApiAuth::requireApiKey().
     * @throws ApiChyba 401, 429 (s Retry-After), 500.
     */
    public static function overKlic(bool $legacy = false): array
    {
        try {
            $auth = ApiAuth::requireApiKey();
        } catch (ApiAuthHttpException $e) {
            $details = [];
            $hlavicky = [];
            $zprava = $e->getMessage();
            if ($e->statusCode === 429 && $e->retryAfter !== null) {
                $details = ['retry_after' => $e->retryAfter, 'limit' => $e->limit];
                $hlavicky = ['Retry-After' => (string)$e->retryAfter];
                if ($e->limit !== null) {
                    $hlavicky['X-RateLimit-Limit'] = (string)$e->limit;
                    $hlavicky['X-RateLimit-Remaining'] = '0';
                }
                if (!$legacy) $zprava = "Překročen limit {$e->limit} požadavků za minutu. Zkus to znovu za {$e->retryAfter} s.";
            }
            throw new ApiChyba($e->statusCode, $e->kod, $zprava, $details, $hlavicky);
        } catch (Throwable $e) {
            throw new ApiChyba(500, 'chyba_overeni', 'Chyba při ověření API klíče.');
        }
        if (!empty($auth['rate_limit'])) {
            self::$hlavicky['X-RateLimit-Limit'] = (string)$auth['rate_limit']['limit'];
            self::$hlavicky['X-RateLimit-Remaining'] = (string)$auth['rate_limit']['zbyva'];
        }
        return $auth;
    }

    /**
     * Výpočet z ověřeného vstupu (VstupVypoctu::over()) ve veřejném kontraktu vystupApi().
     */
    public static function vypocet(array $overeno): array
    {
        $calculator = new SequentialPrintCalculator($overeno['tiskarna'], $overeno['options']);
        return SequentialPrintCalculator::vystupApi($calculator->calculate($overeno['objekty']));
    }

    /**
     * Číslo podložky pro export (`podlozka`): null = nezadáno (všechny podložky), jinak celé číslo >= 1.
     * Horní mez (počet podložek) ověří až Export3mf::zVysledku().
     *
     * @throws ApiChyba 400 neplatny_vstup
     */
    public static function cisloPodlozky($hodnota): ?int
    {
        if ($hodnota === null || $hodnota === '') return null;
        if (is_int($hodnota) && $hodnota >= 1) return $hodnota;
        if (is_string($hodnota) && ctype_digit($hodnota) && (int)$hodnota >= 1) return (int)$hodnota;
        throw new ApiChyba(400, 'neplatny_vstup', 'Vstup obsahuje chyby (1).', ['chyby' => [
            ['pole' => 'podlozka', 'zprava' => "'podlozka' musí být celé číslo >= 1."],
        ]]);
    }

    /**
     * Import + výpočet + export 3MF v jednom kroku (API v1 export.php a CLI --export-3mf).
     *
     * Když je nahraný model a vstup nemá `objekty`, vezmou se objekty z modelu (rozměry, název, počet instancí, `sit`).
     * Jinak objekty vstupu odkazují na sítě modelu přes `sit` (index objektu v modelu); bez `sit` = kvádr.
     *
     * @param array|null $soubor ['cesta', 'nazev'] nahraného 3MF/STL, nebo null (jen kvádry).
     * @return array Export3mf::zVysledku() + 'vysledek' (výstup vystupApi()) a 'hlavicky' (X-Pocet-*, X-Kontrola-Kolizi).
     * @throws ApiChyba
     */
    public static function export(array $vstup, bool $zDotazu, ?array $soubor, ?int $podlozka): array
    {
        $model = null;
        if ($soubor !== null) {
            $model = ImportModelu::nacti($soubor['cesta'], $soubor['nazev'], true);
            if (!isset($vstup['objekty'])) $vstup['objekty'] = ImportModelu::objektyProVstup($model);
        }
        $overeno = VstupVypoctu::over($vstup, $zDotazu);
        $vysledek = self::vypocet($overeno);
        $export = Export3mf::zVysledku($vysledek, $overeno['objekty'], $model, $podlozka);
        $export['vysledek'] = $vysledek;
        $export['hlavicky'] = [
            'X-Pocet-Kusu' => (string)$vysledek['pocet_instanci'],
            'X-Pocet-Podlozek' => (string)$vysledek['pocet_podlozek'],
            'X-Kontrola-Kolizi' => (is_array($vysledek['kontrola_kolizi']) && $vysledek['kontrola_kolizi']['ok']) ? 'ok' : 'kolize',
        ];
        return $export;
    }

    /** Odešle JSON odpověď a skončí. */
    public static function odeslat(int $status, $telo, array $hlavicky = []): void
    {
        $hlavicky = array_merge(['Content-Type' => 'application/json; charset=UTF-8'], self::$hlavicky, $hlavicky);
        self::$odeslano = ['status' => $status, 'hlavicky' => $hlavicky, 'telo' => $telo];
        if (!headers_sent()) {
            http_response_code($status);
            foreach ($hlavicky as $nazev => $hodnota) header($nazev . ': ' . $hodnota);
        }
        $json = json_encode($telo, self::JSON_PRIZNAKY);
        if ($json === false) $json = json_encode($telo, self::JSON_PRIZNAKY | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        echo $json;
        exit;
    }

    /**
     * Odešle soubor ke stažení (export 3MF/ZIP) a skončí. Do Api::$odeslano jde místo těla velikost a název.
     */
    public static function odeslatSoubor(string $obsah, string $typ, string $nazev, array $hlavicky = []): void
    {
        $hlavicky = array_merge([
            'Content-Type' => $typ,
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', '\\'], '', $nazev) . '"',
            'Content-Length' => (string)strlen($obsah),
        ], self::$hlavicky, $hlavicky);
        self::$odeslano = ['status' => 200, 'hlavicky' => $hlavicky, 'telo' => null, 'soubor' => ['nazev' => $nazev, 'velikost' => strlen($obsah)]];
        if (!headers_sent()) {
            http_response_code(200);
            foreach ($hlavicky as $n => $hodnota) header($n . ': ' . $hodnota);
        }
        echo $obsah;
        exit;
    }

    /** Odešle chybu ve formátu API v1. */
    public static function odeslatChybu(ApiChyba $e): void
    {
        self::odeslat($e->status, $e->telo(), $e->hlavicky);
    }

    /** Odešle chybu v dosavadním tvaru legacy endpointů: {"error": "<text>"} (hlavičky jako v1, tělo beze změny). */
    public static function odeslatLegacyChybu(ApiChyba $e, ?string $zprava = null): void
    {
        self::odeslat($e->status, ['error' => $zprava ?? $e->getMessage()], $e->hlavicky);
    }

    /**
     * Spustí obsluhu endpointu API v1: ApiChyba = odpověď ve formátu chyb, jiná výjimka = 500 `interni_chyba`
     * (bez podrobností, aby neunikly interní informace).
     */
    public static function obsluz(callable $obsluha): void
    {
        try {
            $obsluha();
        } catch (ApiChyba $e) {
            self::odeslatChybu($e);
        } catch (Throwable $e) {
            self::odeslatChybu(new ApiChyba(500, 'interni_chyba', 'Interní chyba serveru.'));
        }
    }
}

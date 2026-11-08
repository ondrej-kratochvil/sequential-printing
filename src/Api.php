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
        $raw = self::surovyVstup();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $duvod = trim($raw) === '' ? 'prázdné tělo' : json_last_error_msg();
            throw new ApiChyba(400, 'neplatny_json', "Tělo požadavku není platný JSON objekt ({$duvod}).", ['json_chyba' => $duvod]);
        }
        return $data;
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

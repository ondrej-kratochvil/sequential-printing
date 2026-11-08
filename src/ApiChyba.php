<?php
/**
 * Chyba API ve stabilním formátu (API v1, ROADMAP C1 7.a):
 *
 *   {"error": {"code": "<strojový_kód>", "message": "<česky>", "details": {...}}}
 *
 * s odpovídajícím HTTP statusem. Hlavičky (Allow u 405, Retry-After u 429) nese výjimka s sebou.
 * Používá ji sdílená vrstva src/Api.php, validace vstupu src/VstupVypoctu.php (i CLI) a správa klíčů.
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

class ApiChyba extends RuntimeException
{
    /** @var int HTTP status (400, 401, 403, 404, 405, 429, 500). */
    public $status;

    /** @var string Strojový kód chyby (ASCII snake_case, např. neplatny_vstup). */
    public $kod;

    /** @var array Podrobnosti (u 400 `chyby`: [{pole, zprava}], u 429 `retry_after` …). */
    public $details;

    /** @var array HTTP hlavičky odpovědi: název => hodnota. */
    public $hlavicky;

    public function __construct(int $status, string $kod, string $zprava, array $details = [], array $hlavicky = [])
    {
        parent::__construct($zprava);
        $this->status = $status;
        $this->kod = $kod;
        $this->details = $details;
        $this->hlavicky = $hlavicky;
    }

    /** Tělo odpovědi API v1. Prázdné `details` je v JSON objekt `{}`, ne pole. */
    public function telo(): array
    {
        return [
            'error' => [
                'code' => $this->kod,
                'message' => $this->getMessage(),
                'details' => empty($this->details) ? new stdClass() : $this->details,
            ],
        ];
    }

    /**
     * Lidsky čitelné zprávy (CLI, legacy endpointy): u chyb vstupu zprávy jednotlivých polí, jinak hlavní zpráva.
     *
     * @return string[]
     */
    public function zpravy(): array
    {
        if (isset($this->details['chyby']) && is_array($this->details['chyby']) && !empty($this->details['chyby'])) {
            return array_map(function ($chyba) { return (string)$chyba['zprava']; }, $this->details['chyby']);
        }
        return [$this->getMessage()];
    }
}

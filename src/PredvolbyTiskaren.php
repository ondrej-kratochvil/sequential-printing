<?php
/**
 * Předvolby tiskáren bez DB: config/tiskarny.json.
 *
 * Jediný zdroj výchozí tiskárny (Prusa MINI) pro kalkulátor, UI, API i CLI
 * (SequentialPrintCalculator::vychoziTiskarna() čte předvolbu z tohoto souboru).
 *
 * Formát souboru:
 *   {"vychozi": "<id>", "tiskarny": {"<id>": {"nazev": "...", "parametry": {...}, "zdroje": {...}}}}
 * `parametry` mají stejné klíče jako `printer` (x, y, z, posun_zprava, Xl, Xr, Yl, Yr,
 * vodici_tyce_Z, vodici_tyce_Y, volitelně head_steps), `zdroje` u každé hodnoty říkají, odkud je.
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

class PredvolbyTiskaren
{
    /** Povinné parametry předvolby (head_steps je volitelné). */
    public const POVINNE_PARAMETRY = ['x', 'y', 'z', 'posun_zprava', 'Xr', 'Xl', 'Yr', 'Yl', 'vodici_tyce_Z', 'vodici_tyce_Y'];

    /** @var array|null Načtený a ověřený soubor (cache pro jeden běh). */
    private static $data = null;

    /** Cesta k souboru s předvolbami. */
    public static function soubor(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tiskarny.json';
    }

    /**
     * Načte a ověří config/tiskarny.json.
     *
     * @throws RuntimeException Když soubor chybí, není platný JSON nebo předvolbě chybí povinný parametr.
     */
    private static function data(): array
    {
        if (self::$data !== null) return self::$data;

        $soubor = self::soubor();
        $raw = is_readable($soubor) ? file_get_contents($soubor) : false;
        if ($raw === false) throw new RuntimeException("Soubor s předvolbami tiskáren {$soubor} nejde přečíst.");
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['tiskarny']) || !is_array($data['tiskarny']) || empty($data['tiskarny'])) {
            throw new RuntimeException("Soubor s předvolbami tiskáren {$soubor} nemá neprázdný objekt 'tiskarny'.");
        }
        foreach ($data['tiskarny'] as $id => $tiskarna) {
            $parametry = $tiskarna['parametry'] ?? null;
            if (!is_array($parametry)) throw new RuntimeException("Předvolba tiskárny '{$id}' nemá 'parametry'.");
            foreach (self::POVINNE_PARAMETRY as $klic) {
                if (!isset($parametry[$klic]) || !is_numeric($parametry[$klic])) {
                    throw new RuntimeException("Předvolba tiskárny '{$id}': parametr '{$klic}' chybí nebo není číslo.");
                }
            }
        }
        if (!isset($data['vychozi']) || !isset($data['tiskarny'][$data['vychozi']])) {
            throw new RuntimeException("Soubor s předvolbami tiskáren: 'vychozi' musí být id existující předvolby.");
        }
        self::$data = $data;
        return $data;
    }

    /** Id výchozí předvolby (Prusa MINI). */
    public static function vychoziId(): string
    {
        return (string)self::data()['vychozi'];
    }

    /** Existuje předvolba s tímto id? */
    public static function existuje($id): bool
    {
        return (is_string($id) || is_int($id)) && isset(self::data()['tiskarny'][(string)$id]);
    }

    /**
     * Parametry předvolby (klíče jako `printer`).
     *
     * @throws InvalidArgumentException Neznámé id předvolby.
     */
    public static function parametry($id): array
    {
        if (!self::existuje($id)) {
            throw new InvalidArgumentException("Neznámá tiskárna '" . (is_scalar($id) ? (string)$id : gettype($id)) . "'. Dostupné předvolby: " . implode(', ', array_keys(self::seznam())) . '.');
        }
        return self::data()['tiskarny'][(string)$id]['parametry'];
    }

    /** Seznam předvoleb pro výběr v UI: id => název. */
    public static function seznam(): array
    {
        $seznam = [];
        foreach (self::data()['tiskarny'] as $id => $tiskarna) {
            $seznam[(string)$id] = isset($tiskarna['nazev']) && is_string($tiskarna['nazev']) ? $tiskarna['nazev'] : (string)$id;
        }
        return $seznam;
    }

    /**
     * Tisková plocha, do které se musí objekt vejít (mm): x po korekci `posun_zprava`, y a z.
     * Stejné meze jako výpočet (rozměr nad mez = `limit` s důvodem `rozmer`); bere je validace vstupu
     * (CLI, API v1) i UI (atribut max u rozměrů objektu).
     */
    public static function plocha(array $tiskarna): array
    {
        return [
            'x' => round((float)$tiskarna['x'] - (float)($tiskarna['posun_zprava'] ?? 0), 2),
            'y' => round((float)$tiskarna['y'], 2),
            'z' => round((float)$tiskarna['z'], 2),
        ];
    }

    /**
     * Předvolba pro API v1 (printers): id, název, příznak výchozí, parametry, plocha a zdroje hodnot.
     *
     * @throws InvalidArgumentException Neznámé id předvolby.
     */
    public static function detail($id): array
    {
        $parametry = self::parametry($id);
        $tiskarna = self::data()['tiskarny'][(string)$id];
        return [
            'id' => (string)$id,
            'nazev' => self::seznam()[(string)$id],
            'vychozi' => (string)$id === self::vychoziId(),
            'parametry' => $parametry,
            'plocha' => self::plocha($parametry),
            'zdroje' => isset($tiskarna['zdroje']) && is_array($tiskarna['zdroje']) ? $tiskarna['zdroje'] : new stdClass(),
        ];
    }

    /**
     * Tiskárna pro kalkulátor: parametry předvolby (bez id = výchozí), přes ně vlastní hodnoty `printer`.
     *
     * @throws InvalidArgumentException Neznámé id předvolby.
     */
    public static function sestav(?string $id, array $printer = []): array
    {
        $zaklad = self::parametry($id === null ? self::vychoziId() : $id);
        return array_merge($zaklad, array_filter($printer, function ($v) { return $v !== null; }));
    }
}

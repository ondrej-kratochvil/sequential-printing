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
 * S DB (env DB_DSN, Milník D3) se k předvolbám ze souboru přidají schválené tiskárny z tabulky printers
 * s id `db_<číslo>` (src/TiskarnyDb.php); po zahrnoutVlastni() i neschválené tiskárny přihlášeného uživatele.
 * Výchozí tiskárna je vždy ze souboru. Chyba DB = jen předvolby ze souboru (aplikace funguje dál).
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

class PredvolbyTiskaren
{
    /** Povinné parametry předvolby (head_steps je volitelné). */
    public const POVINNE_PARAMETRY = ['x', 'y', 'z', 'posun_zprava', 'Xr', 'Xl', 'Yr', 'Yl', 'vodici_tyce_Z', 'vodici_tyce_Y'];

    /** @var array|null Předvolby ze souboru + z DB (cache pro jeden běh). */
    private static $data = null;

    /** @var array|null Načtený a ověřený soubor (cache pro jeden běh). */
    private static $soubor = null;

    /** @var int|null Přihlášený uživatel, jehož neschválené tiskárny se mají nabízet taky. */
    private static $uzivatelId = null;

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
    private static function souborData(): array
    {
        if (self::$soubor !== null) return self::$soubor;

        $soubor = self::soubor();
        $raw = is_readable($soubor) ? file_get_contents($soubor) : false;
        if ($raw === false) throw new RuntimeException("Soubor s předvolbami tiskáren {$soubor} nejde přečíst.");
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['tiskarny']) || !is_array($data['tiskarny']) || empty($data['tiskarny'])) {
            throw new RuntimeException("Soubor s předvolbami tiskáren {$soubor} nemá neprázdný objekt 'tiskarny'.");
        }
        foreach ($data['tiskarny'] as $id => $tiskarna) {
            if (strpos((string)$id, 'db_') === 0) throw new RuntimeException("Předvolba tiskárny '{$id}': id nesmí začínat 'db_' (vyhrazeno pro tiskárny z DB).");
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
        foreach ($data['tiskarny'] as $id => $tiskarna) $data['tiskarny'][$id]['zdroj'] = 'soubor';
        self::$soubor = $data;
        return $data;
    }

    /** Předvolby ze souboru a (s DB) tiskárny z DB. */
    private static function data(): array
    {
        if (self::$data !== null) return self::$data;
        $data = self::souborData();
        if ((string)(getenv('DB_DSN') ?: '') !== '') {
            try {
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'TiskarnyDb.php';
                $pdo = Db::pdo();
                $zDb = TiskarnyDb::schvalene($pdo);
                if (self::$uzivatelId !== null) $zDb = array_merge($zDb, TiskarnyDb::vlastniNeschvalene($pdo, self::$uzivatelId));
                foreach ($zDb as $t) {
                    $data['tiskarny'][TiskarnyDb::idPredvolby($t['id'])] = [
                        'nazev' => $t['name'] . ($t['approved'] ? '' : ' (vlastní, neschválená)'),
                        'parametry' => TiskarnyDb::naParametry($t),
                        'zdroje' => $t['poznamka'] !== '' ? ['poznamka' => $t['poznamka']] : [],
                        'zdroj' => 'db',
                        'schvaleno' => $t['approved'],
                    ];
                }
            } catch (Throwable $e) {
                error_log('Sekvenční tisk: tiskárny z DB nejde načíst: ' . $e->getMessage());
            }
        }
        self::$data = $data;
        return $data;
    }

    /**
     * Nabízet i neschválené tiskárny tohoto uživatele (UI po přihlášení). null = jen schválené.
     * Vymaže cache, další dotaz načte DB znovu.
     */
    public static function zahrnoutVlastni(?int $userId): void
    {
        self::$uzivatelId = $userId;
        self::$data = null;
    }

    /** Vymaže cache (testy, po změně tiskáren v DB). */
    public static function obnov(): void
    {
        self::$data = null;
        self::$soubor = null;
    }

    /** Id výchozí předvolby (Prusa MINI). */
    public static function vychoziId(): string
    {
        return (string)self::souborData()['vychozi'];
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
            'zdroje' => isset($tiskarna['zdroje']) && is_array($tiskarna['zdroje']) && !empty($tiskarna['zdroje']) ? $tiskarna['zdroje'] : new stdClass(),
            // Odkud předvolba je: soubor (config/tiskarny.json) nebo db (schválená tiskárna z DB).
            'zdroj' => $tiskarna['zdroj'] ?? 'soubor',
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

<?php
/**
 * Tiskárny v DB (Milník D3): vlastní tiskárny uživatelů, návrh na zveřejnění a schválení adminem.
 *
 * Stavy záznamu v tabulce printers:
 *   koncept   approved = 0, navrzeno_at IS NULL     (vidí a v UI používá jen vlastník)
 *   navrženo  approved = 0, navrzeno_at IS NOT NULL (čeká na admina, vlastník ji dál může upravit nebo návrh stáhnout)
 *   schváleno approved = 1                          (veřejná: výběr v UI, api/v1/printers.php, CLI; vlastník ji už neupraví)
 * Úprava schválené tiskárny = kopie s replaces_printer_id; když ji admin schválí, hodnoty se přepíšou do původního
 * záznamu (id a tím i odkazy `tiskarna=db_<id>` zůstanou) a kopie se smaže.
 *
 * Rozměry hlavy: základ xl_mm/xr_mm/yl_mm/yr_mm (platí od výšky 0) + volitelné schody printer_head_steps (od výšky z
 * platí rozměry hlavy …). Kalkulátor dostane schody včetně základu jako schodu z = 0 (viz naParametry()).
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Db.php';

class TiskarnyDb
{
    /** Předpona id tiskárny z DB ve výběru tiskárny (`tiskarna=db_12`). Předvolby v config/tiskarny.json ji mít nesmí. */
    public const PREDPONA = 'db_';

    public const MAX_DELKA_NAZVU = 150;
    public const MAX_DELKA_POZNAMKY = 2000;
    public const MAX_SCHODU = 20;

    /** Číselné parametry: klíč jako v `printer` => [sloupec v DB, min, max, popis]. */
    public const PARAMETRY = [
        'x' => ['bed_x_mm', 1, 2000, 'Šířka podložky X'],
        'y' => ['bed_y_mm', 1, 2000, 'Hloubka podložky Y'],
        'z' => ['bed_z_mm', 1, 2000, 'Výška tisku Z'],
        'posun_zprava' => ['posun_zprava_mm', 0, 500, 'Korekce zprava'],
        'Xl' => ['xl_mm', 0, 1000, 'Hlava vlevo (Xl)'],
        'Xr' => ['xr_mm', 0, 1000, 'Hlava vpravo (Xr)'],
        'Yl' => ['yl_mm', 0, 1000, 'Hlava vpředu (Yl)'],
        'Yr' => ['yr_mm', 0, 1000, 'Hlava vzadu (Yr)'],
        'vodici_tyce_Z' => ['vodici_tyce_z_mm', 0, 2000, 'Vodicí tyče: výška nad tryskou (Z)'],
        'vodici_tyce_Y' => ['vodici_tyce_y_mm', 0, 1000, 'Vodicí tyče: vzdálenost od trysky (Y)'],
    ];

    /** Klíče rozměrů hlavy ve schodu. */
    public const KLICE_HLAVY = ['Xl', 'Xr', 'Yl', 'Yr'];

    /** Id ve výběru tiskárny pro záznam z DB. */
    public static function idPredvolby(int $id): string
    {
        return self::PREDPONA . $id;
    }

    /** Číslo záznamu z id předvolby (`db_12` => 12), jinak null. */
    public static function cisloZId($id): ?int
    {
        if (!is_string($id) || !preg_match('/^' . self::PREDPONA . '([1-9][0-9]{0,18})$/', $id, $m)) return null;
        return (int)$m[1];
    }

    /** Číslo z formuláře (desetinná čárka povolená), null = prázdné / nečíselné. */
    private static function cislo($hodnota): ?float
    {
        if (is_int($hodnota) || is_float($hodnota)) return (float)$hodnota;
        if (!is_string($hodnota)) return null;
        $hodnota = str_replace(',', '.', trim($hodnota));
        if ($hodnota === '' || !is_numeric($hodnota)) return null;
        return (float)$hodnota;
    }

    /**
     * Ověří formulář tiskárny. Vrací ['tiskarna' => [...], 'chyby' => [pole => zpráva]].
     * `head_steps` = řádky [z, Xl, Xr, Yl, Yr]; úplně prázdné řádky se přeskočí, `z` musí být > 0, ostře rostoucí
     * a nejvýš výška tisku Z.
     */
    public static function overVstup(array $vstup): array
    {
        $chyby = [];
        $tiskarna = [];
        $nazev = isset($vstup['name']) && is_string($vstup['name']) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $vstup['name']) ?? '') : '';
        if ($nazev === '' || mb_strlen($nazev, 'UTF-8') > self::MAX_DELKA_NAZVU) $chyby['name'] = 'Název je povinný (nejvýš ' . self::MAX_DELKA_NAZVU . ' znaků).';
        $tiskarna['name'] = $nazev;
        foreach (self::PARAMETRY as $klic => [$sloupec, $min, $max, $popis]) {
            $hodnota = self::cislo($vstup[$klic] ?? null);
            if ($hodnota === null) $chyby[$klic] = "{$popis}: vyplň číslo.";
            elseif ($hodnota < $min || $hodnota > $max) $chyby[$klic] = "{$popis}: musí být {$min} až {$max} mm.";
            $tiskarna[$klic] = $hodnota === null ? null : round($hodnota, 2);
        }
        if (!isset($chyby['x']) && !isset($chyby['posun_zprava']) && $tiskarna['x'] !== null && $tiskarna['posun_zprava'] !== null && $tiskarna['posun_zprava'] >= $tiskarna['x']) {
            $chyby['posun_zprava'] = 'Korekce zprava musí být menší než šířka podložky.';
        }
        $poznamka = isset($vstup['poznamka']) && is_string($vstup['poznamka']) ? trim($vstup['poznamka']) : '';
        if (mb_strlen($poznamka, 'UTF-8') > self::MAX_DELKA_POZNAMKY) $chyby['poznamka'] = 'Poznámka může mít nejvýš ' . self::MAX_DELKA_POZNAMKY . ' znaků.';
        $tiskarna['poznamka'] = $poznamka;

        $schody = [];
        $radky = isset($vstup['head_steps']) && is_array($vstup['head_steps']) ? array_values($vstup['head_steps']) : [];
        $predchoziZ = 0.0;
        foreach ($radky as $i => $radek) {
            if (!is_array($radek)) continue;
            $hodnoty = [];
            $prazdny = true;
            foreach (array_merge(['z'], self::KLICE_HLAVY) as $klic) {
                $surova = $radek[$klic] ?? '';
                if (!(is_string($surova) && trim($surova) === '')) $prazdny = false;
                $hodnoty[$klic] = self::cislo($surova);
            }
            if ($prazdny) continue;
            $cislo = count($schody) + 1;
            $pole = 'head_steps.' . count($schody); // index v uloženém seznamu (prázdné řádky se přeskočí)
            if (in_array(null, $hodnoty, true)) {
                $chyby[$pole] = "Schod {$cislo}: vyplň všech pět čísel (z, Xl, Xr, Yl, Yr).";
            } elseif ($hodnoty['z'] <= 0) {
                $chyby[$pole] = "Schod {$cislo}: výška z musí být větší než 0 (rozměry od výšky 0 jsou základní rozměry hlavy výše).";
            } elseif ($hodnoty['z'] <= $predchoziZ) {
                $chyby[$pole] = "Schod {$cislo}: výšky z musí jít vzestupně (z = {$hodnoty['z']} není větší než {$predchoziZ}).";
            } elseif ($tiskarna['z'] !== null && $hodnoty['z'] > $tiskarna['z']) {
                $chyby[$pole] = "Schod {$cislo}: výška z je větší než výška tisku Z ({$tiskarna['z']} mm).";
            } else {
                foreach (self::KLICE_HLAVY as $klic) {
                    if ($hodnoty[$klic] < 0 || $hodnoty[$klic] > 1000) $chyby[$pole] = "Schod {$cislo}: {$klic} musí být 0 až 1000 mm.";
                }
            }
            if ($hodnoty['z'] !== null) $predchoziZ = max($predchoziZ, $hodnoty['z']);
            $schody[] = array_map(function ($v) { return $v === null ? null : round($v, 2); }, $hodnoty);
        }
        if (count($schody) > self::MAX_SCHODU) $chyby['head_steps'] = 'Nejvýš ' . self::MAX_SCHODU . ' schodů.';
        $tiskarna['head_steps'] = $schody;
        return ['tiskarna' => $tiskarna, 'chyby' => $chyby];
    }

    /** Řádek z DB + schody => tiskárna (klíče jako ve formuláři), null = neúplný záznam (např. starý seed bez rozměrů hlavy). */
    private static function zRadku(array $radek, array $schody): ?array
    {
        $tiskarna = [
            'id' => (int)$radek['id'],
            'name' => (string)$radek['name'],
            'created_by_user_id' => $radek['created_by_user_id'] === null ? null : (int)$radek['created_by_user_id'],
            'approved' => (bool)$radek['approved'],
            'navrzeno_at' => $radek['navrzeno_at'],
            'replaces_printer_id' => $radek['replaces_printer_id'] === null ? null : (int)$radek['replaces_printer_id'],
            'poznamka' => (string)($radek['poznamka'] ?? ''),
            'updated_at' => (string)$radek['updated_at'],
            'head_steps' => [],
        ];
        foreach (self::PARAMETRY as $klic => [$sloupec]) $tiskarna[$klic] = $radek[$sloupec] === null ? null : (float)$radek[$sloupec];
        foreach ($schody as $s) {
            $schod = ['z' => (float)$s['z_mm'], 'Xl' => (float)$s['xl_mm'], 'Xr' => (float)$s['xr_mm'], 'Yl' => (float)$s['yl_mm'], 'Yr' => (float)$s['yr_mm']];
            // Starý záznam bez základních rozměrů hlavy: základ = schod z = 0.
            if ($schod['z'] <= 0) {
                foreach (self::KLICE_HLAVY as $klic) if ($tiskarna[$klic] === null) $tiskarna[$klic] = $schod[$klic];
                continue;
            }
            $tiskarna['head_steps'][] = $schod;
        }
        foreach (self::PARAMETRY as $klic => $_) if ($tiskarna[$klic] === null) return null;
        return $tiskarna;
    }

    /** Načte tiskárny podle podmínky (WHERE …) se schody. */
    private static function nacti(PDO $pdo, string $kde, array $parametry): array
    {
        $stmt = $pdo->prepare("SELECT p.*, u.email AS vlastnik_email FROM printers p LEFT JOIN users u ON u.id = p.created_by_user_id WHERE {$kde} ORDER BY p.name, p.id");
        $stmt->execute($parametry);
        $radky = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($radky)) return [];
        $ids = array_map(function ($r) { return (int)$r['id']; }, $radky);
        $schody = array_fill_keys($ids, []);
        $stmt = $pdo->prepare('SELECT printer_id, z_mm, xl_mm, xr_mm, yl_mm, yr_mm FROM printer_head_steps WHERE printer_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY printer_id, z_mm');
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) $schody[(int)$s['printer_id']][] = $s;
        $vysledek = [];
        foreach ($radky as $radek) {
            $tiskarna = self::zRadku($radek, $schody[(int)$radek['id']]);
            if ($tiskarna === null) continue;
            $tiskarna['vlastnik_email'] = $radek['vlastnik_email'];
            $vysledek[] = $tiskarna;
        }
        return $vysledek;
    }

    /** Schválené (veřejné) tiskárny. */
    public static function schvalene(PDO $pdo): array
    {
        return self::nacti($pdo, 'p.approved = 1', []);
    }

    /** Neschválené tiskárny uživatele (koncepty a návrhy). */
    public static function vlastniNeschvalene(PDO $pdo, int $userId): array
    {
        return self::nacti($pdo, 'p.created_by_user_id = ? AND p.approved = 0', [$userId]);
    }

    /** Všechny tiskárny uživatele (i schválené). */
    public static function vlastni(PDO $pdo, int $userId): array
    {
        return self::nacti($pdo, 'p.created_by_user_id = ?', [$userId]);
    }

    /** Návrhy čekající na admina. */
    public static function navrhy(PDO $pdo): array
    {
        return self::nacti($pdo, 'p.approved = 0 AND p.navrzeno_at IS NOT NULL', []);
    }

    /** Jedna tiskárna (bez ohledu na vlastníka), null = není. */
    public static function jedna(PDO $pdo, int $id): ?array
    {
        $t = self::nacti($pdo, 'p.id = ?', [$id]);
        return $t[0] ?? null;
    }

    /** Tiskárna, kterou uživatel smí použít ve výpočtu: schválená, nebo jeho vlastní. */
    public static function pouzitelna(PDO $pdo, int $id, ?int $userId): ?array
    {
        $t = self::jedna($pdo, $id);
        if ($t === null) return null;
        if ($t['approved'] || ($userId !== null && $t['created_by_user_id'] === $userId)) return $t;
        return null;
    }

    /**
     * Parametry pro kalkulátor (klíče jako `printer`). Se schody dostane kalkulátor i základ jako schod z = 0:
     * kalkulátor při schodech bere jen je (pod nejnižším schodem by jinak použil první schod, ne základ).
     */
    public static function naParametry(array $tiskarna): array
    {
        $parametry = [];
        foreach (self::PARAMETRY as $klic => $_) $parametry[$klic] = $tiskarna[$klic];
        if (!empty($tiskarna['head_steps'])) {
            $parametry['head_steps'] = array_merge(
                [['z' => 0.0, 'Xl' => $tiskarna['Xl'], 'Xr' => $tiskarna['Xr'], 'Yl' => $tiskarna['Yl'], 'Yr' => $tiskarna['Yr']]],
                $tiskarna['head_steps']
            );
        }
        return $parametry;
    }

    /** Stav pro zobrazení: koncept | navrzeno | schvaleno. */
    public static function stav(array $tiskarna): string
    {
        if ($tiskarna['approved']) return 'schvaleno';
        return $tiskarna['navrzeno_at'] !== null ? 'navrzeno' : 'koncept';
    }

    /** Uloží hodnoty (sloupce + schody) do existujícího záznamu. */
    private static function zapisHodnoty(PDO $pdo, int $id, array $t): void
    {
        $sloupce = ['name = ?', 'poznamka = ?'];
        $hodnoty = [$t['name'], $t['poznamka'] !== '' ? $t['poznamka'] : null];
        foreach (self::PARAMETRY as $klic => [$sloupec]) {
            $sloupce[] = "{$sloupec} = ?";
            $hodnoty[] = $t[$klic];
        }
        $hodnoty[] = $id;
        $pdo->prepare('UPDATE printers SET ' . implode(', ', $sloupce) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute($hodnoty);
        $pdo->prepare('DELETE FROM printer_head_steps WHERE printer_id = ?')->execute([$id]);
        $vloz = $pdo->prepare('INSERT INTO printer_head_steps (printer_id, z_mm, xl_mm, xr_mm, yl_mm, yr_mm) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($t['head_steps'] as $s) $vloz->execute([$id, $s['z'], $s['Xl'], $s['Xr'], $s['Yl'], $s['Yr']]);
    }

    /** Nová tiskárna uživatele (koncept). `$nahrazuje` = id schválené tiskárny, jejíž úpravu navrhuje. Vrací id. */
    public static function vytvor(PDO $pdo, int $userId, array $t, ?int $nahrazuje = null): int
    {
        if ($nahrazuje !== null) {
            $puvodni = self::jedna($pdo, $nahrazuje);
            if ($puvodni === null || !$puvodni['approved']) $nahrazuje = null;
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO printers (name, created_by_user_id, approved, replaces_printer_id, bed_x_mm, bed_y_mm, bed_z_mm) VALUES (?, ?, 0, ?, ?, ?, ?)')
                ->execute([$t['name'], $userId, $nahrazuje, $t['x'], $t['y'], $t['z']]);
            $id = (int)$pdo->lastInsertId();
            self::zapisHodnoty($pdo, $id, $t);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Upraví vlastní neschválenou tiskárnu. false = není vlastní nebo je schválená. */
    public static function uprav(PDO $pdo, int $userId, int $id, array $t): bool
    {
        $puvodni = self::jedna($pdo, $id);
        if ($puvodni === null || $puvodni['created_by_user_id'] !== $userId || $puvodni['approved']) return false;
        $pdo->beginTransaction();
        try {
            self::zapisHodnoty($pdo, $id, $t);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return true;
    }

    /** Smaže vlastní neschválenou tiskárnu. */
    public static function smaz(PDO $pdo, int $userId, int $id): bool
    {
        $stmt = $pdo->prepare('DELETE FROM printers WHERE id = ? AND created_by_user_id = ? AND approved = 0');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /** Navrhne vlastní koncept ke zveřejnění (true) nebo návrh stáhne (false). */
    public static function navrhni(PDO $pdo, int $userId, int $id, bool $navrhnout = true): bool
    {
        $stmt = $pdo->prepare($navrhnout
            ? 'UPDATE printers SET navrzeno_at = CURRENT_TIMESTAMP WHERE id = ? AND created_by_user_id = ? AND approved = 0 AND navrzeno_at IS NULL'
            : 'UPDATE printers SET navrzeno_at = NULL WHERE id = ? AND created_by_user_id = ? AND approved = 0 AND navrzeno_at IS NOT NULL');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Admin: schválí návrh. Návrh úpravy schválené tiskárny přepíše hodnoty původní tiskárny (id zůstane) a smaže se.
     * Vrací id schválené tiskárny, null = návrh neexistuje.
     */
    public static function schval(PDO $pdo, int $id): ?int
    {
        $navrh = self::jedna($pdo, $id);
        if ($navrh === null || $navrh['approved'] || $navrh['navrzeno_at'] === null) return null;
        $puvodni = $navrh['replaces_printer_id'] !== null ? self::jedna($pdo, $navrh['replaces_printer_id']) : null;
        $pdo->beginTransaction();
        try {
            if ($puvodni !== null && $puvodni['approved']) {
                self::zapisHodnoty($pdo, $puvodni['id'], $navrh);
                $pdo->prepare('DELETE FROM printers WHERE id = ?')->execute([$id]);
                $vysledek = $puvodni['id'];
            } else {
                $pdo->prepare('UPDATE printers SET approved = 1, navrzeno_at = NULL, replaces_printer_id = NULL WHERE id = ?')->execute([$id]);
                $vysledek = $id;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $vysledek;
    }

    /** Admin: vrátí návrh autorovi k úpravám (zpět do konceptu). */
    public static function vratNavrh(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare('UPDATE printers SET navrzeno_at = NULL WHERE id = ? AND approved = 0 AND navrzeno_at IS NOT NULL');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** Admin: zruší zveřejnění schválené tiskárny (zpět do konceptu vlastníka). */
    public static function zrusZverejneni(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare('UPDATE printers SET approved = 0, navrzeno_at = NULL WHERE id = ? AND approved = 1');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}

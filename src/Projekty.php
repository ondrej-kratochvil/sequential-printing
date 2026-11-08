<?php
/**
 * Projekty uživatelů (DB, tabulka projects): uložený vstup formuláře pod názvem.
 *
 * Data projektu mají stejný tvar jako export vstupu z UI (JSON, get_form_state() v index.php):
 *   {"version": 1, "objekty": [{x, y, z, instances: {d}, nazev?, rotace?}], "nastaveni": {...}}
 * Před uložením se vstup očistí (overData): jen známé klíče, omezené délky, nejvýš MAX_OBJEKTU objektů.
 * Rozměry se ukládají tak, jak jsou ve formuláři (i rozpracované), výpočet je ověří až při otevření.
 *
 * Každá operace bere user_id: cizí projekt se chová jako neexistující.
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'Db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SequentialPrintCalculator.php';

class Projekty
{
    public const MAX_DELKA_NAZVU = 150;
    public const MAX_OBJEKTU = 200;
    /** Nejvyšší délka uložených dat (JSON) v bajtech. */
    public const MAX_VELIKOST_DAT = 262144;

    /** Volby nastavení, které jsou zaškrtávátka (0/1), v pořadí jako v get_form_state(). */
    public const PREPINACE = [
        'rozprostrit_instance_v_ose_x',
        'rozprostrit_instance_v_ose_y',
        'povolit_rotaci_vsem',
        'vice_podlozek',
        'rozprostrit_instance_po_cele_podlozce',
        'umistit_na_stred_v_ose_x',
        'umistit_na_stred_v_ose_y',
    ];

    /** Název projektu: oříznutý, bez řídicích znaků. Chyba = null. */
    public static function upravNazev($nazev): ?string
    {
        if (!is_string($nazev)) return null;
        $nazev = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $nazev) ?? '');
        if ($nazev === '' || mb_strlen($nazev, 'UTF-8') > self::MAX_DELKA_NAZVU) return null;
        return $nazev;
    }

    /**
     * Očistí data projektu. Vrací ['data' => array, 'chyby' => [{pole, zprava}]].
     */
    public static function overData($data): array
    {
        $chyby = [];
        if (!is_array($data)) return ['data' => null, 'chyby' => [['pole' => 'data', 'zprava' => "'data' musí být objekt se vstupem formuláře."]]];
        $objekty = $data['objekty'] ?? null;
        if (!is_array($objekty) || empty($objekty)) {
            $chyby[] = ['pole' => 'data.objekty', 'zprava' => 'Projekt musí mít aspoň jeden objekt.'];
            $objekty = [];
        }
        if (count($objekty) > self::MAX_OBJEKTU) {
            $chyby[] = ['pole' => 'data.objekty', 'zprava' => 'Projekt může mít nejvýš ' . self::MAX_OBJEKTU . ' objektů.'];
            $objekty = array_slice($objekty, 0, self::MAX_OBJEKTU);
        }
        $cisteObjekty = [];
        foreach (array_values($objekty) as $i => $o) {
            if (!is_array($o)) {
                $chyby[] = ['pole' => "data.objekty[{$i}]", 'zprava' => "objekt " . ($i + 1) . ' musí být objekt.'];
                continue;
            }
            $cisty = [];
            foreach (['x', 'y', 'z'] as $rozmer) {
                $hodnota = $o[$rozmer] ?? '';
                $cisty[$rozmer] = (is_int($hodnota) || is_float($hodnota) || (is_string($hodnota) && preg_match('/^\s*[0-9]{0,6}([.,][0-9]{0,4})?\s*$/', $hodnota)))
                    ? (is_string($hodnota) ? str_replace(',', '.', trim($hodnota)) : $hodnota)
                    : '';
            }
            $d = $o['instances']['d'] ?? 1;
            if ($d === 'max') $cisty['instances'] = ['d' => 'max'];
            elseif ((is_int($d) || (is_string($d) && ctype_digit($d))) && (int)$d >= 1 && (int)$d <= 999) $cisty['instances'] = ['d' => (string)(int)$d];
            else $cisty['instances'] = ['d' => ''];
            $nazev = SequentialPrintCalculator::upravNazev($o['nazev'] ?? null);
            if ($nazev !== '') $cisty['nazev'] = $nazev;
            if (SequentialPrintCalculator::jePovolenaRotace($o['rotace'] ?? null)) $cisty['rotace'] = true;
            $cisteObjekty[] = $cisty;
        }
        $n = isset($data['nastaveni']) && is_array($data['nastaveni']) ? $data['nastaveni'] : [];
        $nastaveni = [];
        foreach (self::PREPINACE as $klic) $nastaveni[$klic] = !empty($n[$klic]) ? 1 : 0;
        $nastaveni['rozprostreni_y_rezim'] = (isset($n['rozprostreni_y_rezim']) && in_array($n['rozprostreni_y_rezim'], SequentialPrintCalculator::ROZPROSTRENI_Y_REZIMY, true))
            ? $n['rozprostreni_y_rezim'] : SequentialPrintCalculator::VYCHOZI_NASTAVENI['rozprostreni_y_rezim'];
        $nastaveni['tiskarna'] = (isset($n['tiskarna']) && is_string($n['tiskarna']) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $n['tiskarna'])) ? $n['tiskarna'] : '';
        $max = isset($n['max_podlozek']) && is_numeric($n['max_podlozek']) ? (int)$n['max_podlozek'] : SequentialPrintCalculator::VYCHOZI_NASTAVENI['max_podlozek'];
        $nastaveni['max_podlozek'] = max(1, min(SequentialPrintCalculator::MAXIMALNI_POCET_PODLOZEK, $max));

        $vysledek = ['version' => 1, 'objekty' => $cisteObjekty, 'nastaveni' => $nastaveni];
        if (strlen((string)json_encode($vysledek, JSON_UNESCAPED_UNICODE)) > self::MAX_VELIKOST_DAT) {
            $chyby[] = ['pole' => 'data', 'zprava' => 'Projekt je příliš velký.'];
        }
        return ['data' => $vysledek, 'chyby' => $chyby];
    }

    /**
     * Parametry GET pro index.php (stejné jako odeslání formuláře): otevření projektu = přesměrování na výpočet.
     */
    public static function naDotaz(array $data): array
    {
        $dotaz = ['objekty' => []];
        foreach (($data['objekty'] ?? []) as $i => $o) {
            $polozka = ['x' => (string)($o['x'] ?? ''), 'y' => (string)($o['y'] ?? ''), 'z' => (string)($o['z'] ?? ''), 'instances' => ['d' => (string)($o['instances']['d'] ?? '')]];
            if (isset($o['nazev']) && $o['nazev'] !== '') $polozka['nazev'] = (string)$o['nazev'];
            if (!empty($o['rotace'])) $polozka['rotace'] = '1';
            $dotaz['objekty'][$i] = $polozka;
        }
        $n = $data['nastaveni'] ?? [];
        if (!empty($n['tiskarna'])) $dotaz['tiskarna'] = (string)$n['tiskarna'];
        foreach (self::PREPINACE as $klic) {
            if (!empty($n[$klic])) $dotaz[$klic] = '1';
        }
        $dotaz['rozprostreni_y_rezim'] = (string)($n['rozprostreni_y_rezim'] ?? SequentialPrintCalculator::VYCHOZI_NASTAVENI['rozprostreni_y_rezim']);
        $dotaz['max_podlozek'] = (string)($n['max_podlozek'] ?? SequentialPrintCalculator::VYCHOZI_NASTAVENI['max_podlozek']);
        return $dotaz;
    }

    /** Projekty uživatele bez dat (id, name, pocet_objektu, created_at, updated_at), naposledy upravené první. */
    public static function seznam(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('SELECT id, name, data, created_at, updated_at FROM projects WHERE user_id = ? ORDER BY updated_at DESC, id DESC');
        $stmt->execute([$userId]);
        $seznam = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $radek) {
            $data = json_decode((string)$radek['data'], true);
            $seznam[] = [
                'id' => (int)$radek['id'],
                'name' => (string)$radek['name'],
                'pocet_objektu' => is_array($data['objekty'] ?? null) ? count($data['objekty']) : 0,
                'created_at' => (string)$radek['created_at'],
                'updated_at' => (string)$radek['updated_at'],
            ];
        }
        return $seznam;
    }

    /** Projekt uživatele včetně dat (pole `data` dekódované), null = neexistuje nebo je cizí. */
    public static function nacti(PDO $pdo, int $userId, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT id, name, data, created_at, updated_at FROM projects WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $userId]);
        $radek = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$radek) return null;
        $data = json_decode((string)$radek['data'], true);
        return [
            'id' => (int)$radek['id'],
            'name' => (string)$radek['name'],
            'data' => is_array($data) ? $data : ['version' => 1, 'objekty' => [], 'nastaveni' => []],
            'created_at' => (string)$radek['created_at'],
            'updated_at' => (string)$radek['updated_at'],
        ];
    }

    /** Nový projekt (data už očištěná overData). Vrací id. */
    public static function vytvor(PDO $pdo, int $userId, string $nazev, array $data): int
    {
        $pdo->prepare('INSERT INTO projects (user_id, name, data) VALUES (?, ?, ?)')
            ->execute([$userId, $nazev, json_encode($data, JSON_UNESCAPED_UNICODE)]);
        return (int)$pdo->lastInsertId();
    }

    /** Přepíše data (a název) existujícího projektu. false = neexistuje nebo je cizí. */
    public static function prepis(PDO $pdo, int $userId, int $id, string $nazev, array $data): bool
    {
        if (self::nacti($pdo, $userId, $id) === null) return false;
        $pdo->prepare('UPDATE projects SET name = ?, data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?')
            ->execute([$nazev, json_encode($data, JSON_UNESCAPED_UNICODE), $id, $userId]);
        return true;
    }

    /** Přejmenuje projekt. false = neexistuje nebo je cizí. */
    public static function prejmenuj(PDO $pdo, int $userId, int $id, string $nazev): bool
    {
        if (self::nacti($pdo, $userId, $id) === null) return false;
        $pdo->prepare('UPDATE projects SET name = ? WHERE id = ? AND user_id = ?')->execute([$nazev, $id, $userId]);
        return true;
    }

    /** Smaže projekt. false = neexistuje nebo je cizí. */
    public static function smaz(PDO $pdo, int $userId, int $id): bool
    {
        $stmt = $pdo->prepare('DELETE FROM projects WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }
}

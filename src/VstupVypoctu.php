<?php
/**
 * Jednotná validace vstupu výpočtu pro CLI (bin/arrange.php) a API v1 (api/v1/arrange.php).
 *
 * Vstup: {"objekty": [...], "options": {...}, "tiskarna": "<id>", "printer": {...} | "<id>"}
 * (viz README, CLI). Hledá všechny chyby najednou a vrací je po polích; neznámá předvolba
 * tiskárny je samostatná chyba 404 (`neznama_tiskarna`), protože bez ní nejde ověřit rozměry.
 *
 * Režim `$zDotazu` (GET query string jako v UI): hodnoty jsou texty, takže pravdivostní volby
 * přijmou i "1"/"0"/"true"/"false"/"" a `max_podlozek` číslice; prázdné `tiskarna` = výchozí.
 * Bez něj (JSON v CLI a POST) jsou typy přísné jako dřív v CLI (true/false nebo 0/1, celé číslo).
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'SequentialPrintCalculator.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ApiChyba.php';

class VstupVypoctu
{
    /** Číselné parametry tiskárny, které musí být kladné (tisková plocha). */
    private const KLADNE_PARAMETRY = ['x', 'y', 'z'];

    /**
     * Ověří vstup a vrátí hodnoty připravené pro kalkulátor.
     *
     * @param array $vstup Dekódovaný JSON (CLI, POST) nebo $_GET (GET).
     * @param bool $zDotazu Hodnoty jsou z query stringu (texty).
     * @return array ['tiskarna' => parametry pro kalkulátor, 'id_tiskarny' => ?string,
     *                'options' => nastavení (pravdivostní volby už jako bool), 'objekty' => seznam objektů]
     * @throws ApiChyba 400 `neplatny_vstup` (details.chyby = [{pole, zprava}]), 404 `neznama_tiskarna`.
     */
    public static function over(array $vstup, bool $zDotazu = false): array
    {
        $chyby = [];
        $pridej = function (string $pole, string $zprava) use (&$chyby): void {
            $chyby[] = ['pole' => $pole, 'zprava' => $zprava];
        };

        // --- Tiskárna: "tiskarna": "<id>", nebo "printer": "<id>" místo objektu; objekt "printer" přepíše předvolbu.
        $printer = $vstup['printer'] ?? [];
        $options = $vstup['options'] ?? [];
        $idTiskarny = $vstup['tiskarna'] ?? null;
        if ($zDotazu && $idTiskarny === '') $idTiskarny = null;
        if ($zDotazu && $printer === '') $printer = [];
        $tiskarnaOk = true;
        if (is_string($printer)) {
            if ($idTiskarny !== null && $idTiskarny !== $printer) {
                $pridej('printer', "'tiskarna' a 'printer' určují různé předvolby.");
                $tiskarnaOk = false;
            }
            $idTiskarny = $printer;
            $printer = [];
        }
        if ($idTiskarny !== null && !is_string($idTiskarny)) {
            $pridej('tiskarna', "'tiskarna' musí být id předvolby (text).");
            $tiskarnaOk = false;
        }
        if ($tiskarnaOk && $idTiskarny !== null && !PredvolbyTiskaren::existuje($idTiskarny)) {
            throw self::neznamaTiskarna($idTiskarny);
        }
        if (!is_array($printer)) {
            $pridej('printer', "'printer' musí být objekt nebo id předvolby.");
            $tiskarnaOk = false;
            $printer = [];
        }
        foreach ($printer as $klic => $hodnota) {
            if ($klic === 'head_steps') {
                if (!is_array($hodnota)) {
                    $pridej('printer.head_steps', "'printer.head_steps' musí být pole.");
                    $tiskarnaOk = false;
                }
                continue;
            }
            if (!is_numeric($hodnota)) {
                $pridej("printer.{$klic}", "'printer.{$klic}' musí být číslo.");
                $tiskarnaOk = false;
            } elseif (in_array($klic, self::KLADNE_PARAMETRY, true) && (float)$hodnota <= 0) {
                $pridej("printer.{$klic}", "'printer.{$klic}' musí být kladné číslo.");
                $tiskarnaOk = false;
            }
        }
        $tiskarna = $tiskarnaOk ? PredvolbyTiskaren::sestav($idTiskarny, $printer) : null;

        // --- Nastavení
        if (!is_array($options)) {
            $pridej('options', "'options' musí být objekt.");
            $options = [];
        }
        if (isset($options['rozprostreni_y_rezim']) && !in_array($options['rozprostreni_y_rezim'], SequentialPrintCalculator::ROZPROSTRENI_Y_REZIMY, true)) {
            $pridej('options.rozprostreni_y_rezim', "'options.rozprostreni_y_rezim' musí být " . implode(' nebo ', SequentialPrintCalculator::ROZPROSTRENI_Y_REZIMY) . '.');
        }
        foreach (SequentialPrintCalculator::VYCHOZI_NASTAVENI as $klic => $vychozi) {
            if (!is_bool($vychozi) || !array_key_exists($klic, $options) || $options[$klic] === null) continue;
            $hodnota = self::naBool($options[$klic], $zDotazu);
            if ($hodnota === null) $pridej("options.{$klic}", "'options.{$klic}' musí být true/false.");
            else $options[$klic] = $hodnota;
        }
        if (isset($options['max_podlozek'])) {
            $max = $options['max_podlozek'];
            if ($zDotazu && is_string($max) && ctype_digit($max)) $max = (int)$max;
            if (!(is_int($max) && $max >= 1 && $max <= SequentialPrintCalculator::MAXIMALNI_POCET_PODLOZEK)) {
                $pridej('options.max_podlozek', "'options.max_podlozek' musí být celé číslo 1 až " . SequentialPrintCalculator::MAXIMALNI_POCET_PODLOZEK . '.');
            } else {
                $options['max_podlozek'] = $max;
            }
        }
        $rotaceVsem = !empty($options['povolit_rotaci_vsem']) && $options['povolit_rotaci_vsem'] === true;

        // --- Objekty
        $objekty = $vstup['objekty'] ?? null;
        if (!is_array($objekty) || empty($objekty)) {
            $pridej('objekty', "chybí neprázdné pole 'objekty'.");
            $objekty = [];
        } elseif (!$zDotazu && array_keys($objekty) !== range(0, count($objekty) - 1)) {
            // GET z UI posílá objekty[0], objekty[1] …; klíče tam zůstanou jako dřív v api.php.
            $pridej('objekty', "'objekty' musí být pole (seznam), ne objekt.");
            $objekty = [];
        }
        $plocha = $tiskarna !== null ? PredvolbyTiskaren::plocha($tiskarna) : null;

        foreach ($objekty as $i => $objekt) {
            $cislo = is_int($i) ? $i + 1 : $i;
            $pole = "objekty[{$i}]";
            if (!is_array($objekt)) {
                $pridej($pole, "objekt {$cislo} musí být objekt s x, y, z.");
                continue;
            }
            $rotace = false;
            if (array_key_exists('rotace', $objekt) && $objekt['rotace'] !== null) {
                $hodnota = self::naBool($objekt['rotace'], $zDotazu);
                if ($hodnota === null) {
                    $pridej("{$pole}.rotace", "objekt {$cislo}: 'rotace' musí být true/false.");
                } else {
                    $objekty[$i]['rotace'] = $hodnota;
                    $rotace = $hodnota;
                }
            }
            $rozmeryOk = true;
            foreach (['x', 'y', 'z'] as $osa) {
                if (!isset($objekt[$osa]) || !is_numeric($objekt[$osa]) || (float)$objekt[$osa] <= 0) {
                    $pridej("{$pole}.{$osa}", "objekt {$cislo}: '{$osa}' musí být kladné číslo.");
                    $rozmeryOk = false;
                }
            }
            if ($rozmeryOk && $plocha !== null) {
                // S povolenou rotací stačí, když se objekt vejde aspoň otočený o 90° (x a y zaměněné).
                $vejdeSeOtoceny = ($rotaceVsem || $rotace)
                    && (float)$objekt['y'] <= $plocha['x'] && (float)$objekt['x'] <= $plocha['y'];
                foreach (['x', 'y', 'z'] as $osa) {
                    if ($osa !== 'z' && $vejdeSeOtoceny) continue;
                    if ((float)$objekt[$osa] > $plocha[$osa]) {
                        $pridej("{$pole}.{$osa}", "objekt {$cislo}: '{$osa}' = {$objekt[$osa]} mm je větší než tisková plocha ({$plocha[$osa]} mm).");
                    }
                }
            }
            if (isset($objekt['instances'])) {
                if (!is_array($objekt['instances']) || !array_key_exists('d', $objekt['instances'])) {
                    $pridej("{$pole}.instances", "objekt {$cislo}: 'instances' musí být objekt s klíčem 'd'.");
                } else {
                    $d = $objekt['instances']['d'];
                    if ($d !== 'max' && !(is_numeric($d) && (int)$d == $d && (int)$d >= 1)) {
                        $pridej("{$pole}.instances.d", "objekt {$cislo}: 'instances.d' musí být celé číslo >= 1 nebo \"max\".");
                    }
                }
            }
            // Název propíše do výstupu kalkulátor (pozice, objekty, limit), oříznutý na MAXIMALNI_DELKA_NAZVU znaků.
            if (isset($objekt['nazev']) && !is_string($objekt['nazev'])) {
                $pridej("{$pole}.nazev", "objekt {$cislo}: 'nazev' musí být text.");
            }
            // Odkaz na síť importovaného modelu (export 3MF): index objektu v modelu. Na výpočet nemá vliv,
            // jestli index v modelu existuje, ověří až export (Export3mf::pripravSite()).
            if (isset($objekt['sit']) && !($zDotazu && $objekt['sit'] === '')) {
                $sit = $objekt['sit'];
                if ($zDotazu && is_string($sit) && ctype_digit($sit)) $sit = (int)$sit;
                if (!is_int($sit) || $sit < 0) {
                    $pridej("{$pole}.sit", "objekt {$cislo}: 'sit' musí být celé číslo >= 0 (index objektu v importovaném modelu).");
                } else {
                    $objekty[$i]['sit'] = $sit;
                }
            }
        }

        if (!empty($chyby)) {
            throw new ApiChyba(400, 'neplatny_vstup', 'Vstup obsahuje chyby (' . count($chyby) . ').', ['chyby' => $chyby]);
        }

        return [
            'tiskarna' => $tiskarna,
            'id_tiskarny' => $idTiskarny,
            'options' => $options,
            'objekty' => $objekty,
        ];
    }

    /** Chyba 404 pro neznámou předvolbu tiskárny (API v1 arrange i printers). */
    public static function neznamaTiskarna($id): ApiChyba
    {
        $dostupne = array_keys(PredvolbyTiskaren::seznam());
        $text = is_scalar($id) ? (string)$id : '?';
        return new ApiChyba(404, 'neznama_tiskarna', "Neznámá tiskárna '{$text}'. Dostupné předvolby: " . implode(', ', $dostupne) . '.', [
            'tiskarna' => $text,
            'dostupne' => $dostupne,
        ]);
    }

    /**
     * Pravdivostní hodnota: true/false, 0/1; z query stringu i "1"/"0"/"true"/"false"/"on"/"" (nezaškrtnuto).
     * Jiná hodnota = null (chyba vstupu).
     */
    public static function naBool($hodnota, bool $zDotazu): ?bool
    {
        if (is_bool($hodnota)) return $hodnota;
        if ($hodnota === 0 || $hodnota === 1) return (bool)$hodnota;
        if ($zDotazu && is_string($hodnota)) {
            $text = strtolower(trim($hodnota));
            if (in_array($text, ['1', 'true', 'on', 'yes'], true)) return true;
            if (in_array($text, ['0', 'false', 'off', 'no', ''], true)) return false;
        }
        return null;
    }
}

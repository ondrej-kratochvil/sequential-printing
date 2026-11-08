<?php
/**
 * Export výsledku rozmístění do 3MF pro PrusaSlicer.
 *
 * Každý kus je samostatný <object> v pořadí tisku (`poradi`), protože PrusaSlicer při tisku po objektech
 * (complete_objects) tiskne v pořadí seznamu objektů. Položka <item transform> posune kus tak, aby střed
 * ohraničení sítě byl na X/Y z `pozice` a spodek na z = 0; otočený kus (`otoceno`) se navíc otočí o 90° kolem Z.
 * Rotace a měřítko z importovaného 3MF (transform první položky) zůstanou v transformaci položky.
 *
 * Síť: importovaná (3MF/STL) podle `objekty[i].sit` = index objektu v importovaném modelu, jinak kvádr
 * zadaných rozměrů. Metadata/Slic3r_PE_model.config nese názvy "01 název" a per-object nastavení ze vstupu.
 *
 * Více podložek: jeden 3MF na podložku; bez volby podložky se více podložek zabalí do ZIPu
 * (sekvencni-tisk_podlozka_1.3mf, …), viz zVysledku().
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru), potřebuje rozšíření zip (ZipArchive).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ApiChyba.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'Sit3d.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ImportModelu.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SequentialPrintCalculator.php';

class Export3mf
{
    /** Tolerance, o kolik smí být síť větší než zadané rozměry objektu (zaokrouhlení na setiny). */
    private const TOLERANCE_ROZMERU = 0.011;

    /** Nejvýš o kolik mm smí kus vyčnívat z podložky, aby ho export posunul zpět (zaokrouhlení pozic a rozměrů). */
    private const PRESAH_PLOCHY = 0.05;

    public const TYP_3MF = 'model/3mf';
    public const TYP_ZIP = 'application/zip';
    public const ZAKLAD_NAZVU = 'sekvencni-tisk';

    /**
     * Přiřadí objektům vstupu sítě z importovaného modelu (`sit` = index objektu v modelu) a ověří, že síť není větší
     * než rozměry, se kterými počítal výpočet (jinak by hrozila kolize). Objekt bez `sit` dostane null (= kvádr).
     *
     * @param array $objekty Objekty vstupu výpočtu (klíč = `pozice[].objekt`).
     * @param array|null $model Výsledek ImportModelu::nacti(..., true), nebo null.
     * @return array klíč objektu => objekt modelu | null
     * @throws ApiChyba 400 neplatny_vstup (pole objekty[i].sit)
     */
    public static function pripravSite(array $objekty, ?array $model): array
    {
        $site = [];
        $chyby = [];
        foreach ($objekty as $klic => $objekt) {
            $site[$klic] = null;
            if (!is_array($objekt) || !isset($objekt['sit']) || $objekt['sit'] === '' || $objekt['sit'] === null) continue;
            $index = $objekt['sit'];
            $cislo = is_int($klic) ? $klic + 1 : $klic;
            if ($model === null) {
                $chyby[] = ['pole' => "objekty[{$klic}].sit", 'zprava' => "objekt {$cislo}: 'sit' odkazuje na importovaný model, ale žádný model není (nahraj soubor 3MF/STL)."];
                continue;
            }
            if (!(is_int($index) || (is_string($index) && ctype_digit($index))) || !isset($model['objekty'][(int)$index])) {
                $chyby[] = ['pole' => "objekty[{$klic}].sit", 'zprava' => "objekt {$cislo}: 'sit' = " . (is_scalar($index) ? $index : '?') . ' neodpovídá žádnému objektu modelu (0 až ' . (count($model['objekty']) - 1) . ').'];
                continue;
            }
            $zdroj = $model['objekty'][(int)$index];
            foreach (['x', 'y', 'z'] as $osa) {
                if (isset($objekt[$osa]) && is_numeric($objekt[$osa]) && $zdroj['rozmery'][$osa] > (float)$objekt[$osa] + self::TOLERANCE_ROZMERU) {
                    $chyby[] = ['pole' => "objekty[{$klic}].{$osa}", 'zprava' => "objekt {$cislo}: model má '{$osa}' " . round($zdroj['rozmery'][$osa], 2) . " mm, víc než zadaných {$objekt[$osa]} mm - rozmístění by nemuselo být bez kolize."];
                }
            }
            $site[$klic] = $zdroj;
        }
        if (!empty($chyby)) {
            throw new ApiChyba(400, 'neplatny_vstup', 'Vstup obsahuje chyby (' . count($chyby) . ').', ['chyby' => $chyby]);
        }
        return $site;
    }

    /**
     * Soubor(y) ke stažení z výsledku výpočtu.
     *
     * @param array $vysledek Výstup SequentialPrintCalculator::vystupApi() (stačí `pozice`, `pocet_podlozek`).
     * @param array $objekty Objekty vstupu (rozměry, název, `sit`).
     * @param array|null $model Importovaný model se sítěmi (ImportModelu::nacti(..., true)), nebo null.
     * @param int|null $podlozka Jen tato podložka (1..pocet_podlozek); null = všechny (víc podložek = ZIP).
     * @return array ['obsah' => binární data, 'typ' => MIME, 'nazev' => název souboru, 'soubory' => [název => obsah 3MF]]
     * @throws ApiChyba
     */
    public static function zVysledku(array $vysledek, array $objekty, ?array $model = null, ?int $podlozka = null): array
    {
        if (!class_exists('ZipArchive')) throw ImportModelu::chybiZip();
        $pozice = isset($vysledek['pozice']) && is_array($vysledek['pozice']) ? $vysledek['pozice'] : [];
        if (empty($pozice)) {
            throw new ApiChyba(400, 'nic_k_exportu', 'Na podložku se nevešel žádný kus, není co exportovat.');
        }
        $pocetPodlozek = max(1, (int)($vysledek['pocet_podlozek'] ?? 1));
        if ($podlozka !== null && ($podlozka < 1 || $podlozka > $pocetPodlozek)) {
            throw new ApiChyba(400, 'neplatny_vstup', 'Vstup obsahuje chyby (1).', ['chyby' => [
                ['pole' => 'podlozka', 'zprava' => "'podlozka' musí být číslo podložky 1 až {$pocetPodlozek}."],
            ]]);
        }
        $site = self::pripravSite($objekty, $model);
        $podlozky = $podlozka !== null ? [$podlozka] : range(1, $pocetPodlozek);
        $soubory = [];
        foreach ($podlozky as $p) {
            $kusy = array_values(array_filter($pozice, function ($kus) use ($p) { return (int)($kus['podlozka'] ?? 1) === $p; }));
            usort($kusy, function ($a, $b) { return $a['poradi'] <=> $b['poradi']; });
            $nazev = self::ZAKLAD_NAZVU . (count($podlozky) > 1 || $pocetPodlozek > 1 ? "_podlozka_{$p}" : '') . '.3mf';
            $soubory[$nazev] = self::vytvor($kusy, $objekty, $site, isset($vysledek['printer']['x'], $vysledek['printer']['y'])
                ? ['x' => (float)$vysledek['printer']['x'], 'y' => (float)$vysledek['printer']['y']] : null);
        }
        if (count($soubory) === 1) {
            $nazev = array_key_first($soubory);
            return ['obsah' => $soubory[$nazev], 'typ' => self::TYP_3MF, 'nazev' => $nazev, 'soubory' => $soubory];
        }
        return ['obsah' => self::zabal($soubory, false), 'typ' => self::TYP_ZIP, 'nazev' => self::ZAKLAD_NAZVU . '.zip', 'soubory' => $soubory];
    }

    /**
     * 3MF jedné podložky.
     *
     * @param array $kusy Pozice kusů jedné podložky v pořadí tisku.
     * @param array $objekty Objekty vstupu (klíč = `objekt` v pozici).
     * @param array $site klíč objektu => objekt modelu | null (pripravSite()).
     * @param array|null $plocha ['x', 'y'] tisková plocha výpočtu (x po korekci posun_zprava): kus, který by z ní vyčníval
     *        jen o zaokrouhlení pozic (nejvýš PRESAH_PLOCHY), se posune dovnitř. PrusaSlicer kus i o 0,001 mm za okrajem
     *        podložky tiše vynechá z tisku.
     */
    public static function vytvor(array $kusy, array $objekty, array $site, ?array $plocha = null): string
    {
        $docasny = tempnam(sys_get_temp_dir(), '3mf');
        $fh = fopen($docasny, 'wb');
        $sirka = max(2, strlen((string)count($kusy)));
        $konfigurace = [];
        $polozky = [];
        $meshCache = [];

        fwrite($fh, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<model unit="millimeter" xml:lang="en-US" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02" xmlns:slic3rpe="http://schemas.slic3r.org/3mf/2017/06">' . "\n"
            . ' <metadata name="slic3rpe:Version3mf">1</metadata>' . "\n"
            . ' <metadata name="Application">Sekvencni tisk</metadata>' . "\n"
            . " <resources>\n");
        foreach ($kusy as $i => $kus) {
            $id = $i + 1;
            $klic = $kus['objekt'];
            $objekt = $objekty[$klic] ?? [];
            $zdroj = $site[$klic] ?? null;
            if ($zdroj !== null) {
                $sit = $zdroj['sit'];
                $linearni = $zdroj['matice'];
            } else {
                $sit = Sit3d::kvadr((float)$objekt['x'], (float)$objekt['y'], (float)$objekt['z']);
                $linearni = Sit3d::jednotkova();
            }
            // Transformace položky: rotace/měřítko ze vstupu, případně otočení o 90° kolem Z, pak posun středu na X/Y a spodku na z = 0.
            if (!empty($kus['otoceno'])) $linearni = Sit3d::slozit(Sit3d::rotaceZ90(), $linearni);
            $klicCache = ($zdroj !== null ? 's' : 'k') . $klic;
            if (!isset($meshCache[$klicCache])) $meshCache[$klicCache] = Sit3d::meshXml($sit);
            $ohraniceni = Sit3d::ohraniceni($sit['vrcholy'], $sit['pocet_vrcholu'], $linearni);
            $stredX = ($ohraniceni['min'][0] + $ohraniceni['max'][0]) / 2;
            $stredY = ($ohraniceni['min'][1] + $ohraniceni['max'][1]) / 2;
            $posunX = (float)$kus['X'] - $stredX;
            $posunY = (float)$kus['Y'] - $stredY;
            if ($plocha !== null) {
                $posunX += self::dovnitr($ohraniceni['min'][0] + $posunX, $ohraniceni['max'][0] + $posunX, $plocha['x']);
                $posunY += self::dovnitr($ohraniceni['min'][1] + $posunY, $ohraniceni['max'][1] + $posunY, $plocha['y']);
            }
            $matice = Sit3d::slozit(Sit3d::posun($posunX, $posunY, -$ohraniceni['min'][2]), $linearni);

            $nazevObjektu = SequentialPrintCalculator::upravNazev($objekt['nazev'] ?? '');
            if ($nazevObjektu === '' && $zdroj !== null) $nazevObjektu = $zdroj['nazev'];
            if ($nazevObjektu === '') $nazevObjektu = 'objekt ' . (is_int($klic) ? $klic + 1 : $klic);
            $nazev = str_pad((string)$kus['poradi'], $sirka, '0', STR_PAD_LEFT) . ' ' . $nazevObjektu;

            fwrite($fh, '  <object id="' . $id . '" name="' . self::xml($nazev) . '" type="model">' . "\n");
            fwrite($fh, $meshCache[$klicCache]);
            fwrite($fh, "\n  </object>\n");
            $polozky[] = '  <item objectid="' . $id . '" transform="' . Sit3d::naTransform3mf($matice) . '" printable="1"/>';
            $konfigurace[] = self::konfiguraceObjektu($id, $nazev, $nazevObjektu, $sit['pocet_trojuhelniku'], $zdroj);
        }
        fwrite($fh, " </resources>\n <build>\n" . implode("\n", $polozky) . "\n </build>\n</model>\n");
        fclose($fh);

        $obsah = self::zabal([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml"/>'
                . '<Default Extension="config" ContentType="text/xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Target="/3D/3dmodel.model" Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel"/>'
                . '</Relationships>',
            '3D/3dmodel.model' => ['soubor' => $docasny],
            'Metadata/Slic3r_PE_model.config' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n<config>\n" . implode('', $konfigurace) . "</config>\n",
        ], true);
        @unlink($docasny);
        return $obsah;
    }

    /**
     * Korekce, která vrátí interval [min, max] do [0, mez], když z něj vyčnívá nejvýš o PRESAH_PLOCHY
     * (pozice jsou zaokrouhlené na setiny, rozměry nahoru). Větší přesah nechá být (je to chyba jinde).
     */
    private static function dovnitr(float $min, float $max, float $mez): float
    {
        if ($max - $min > $mez) return 0.0;
        if ($max > $mez && $max - $mez <= self::PRESAH_PLOCHY) return $mez - $max;
        if ($min < 0 && -$min <= self::PRESAH_PLOCHY) return -$min;
        return 0.0;
    }

    /** Záznam objektu v Metadata/Slic3r_PE_model.config: název, per-object metadata a části (volume) ze vstupu. */
    private static function konfiguraceObjektu(int $id, string $nazev, string $nazevCasti, int $pocetTrojuhelniku, ?array $zdroj): string
    {
        $xml = ' <object id="' . $id . '" instances_count="1">' . "\n";
        $xml .= '  <metadata type="object" key="name" value="' . self::xml($nazev) . '"/>' . "\n";
        if ($zdroj !== null) {
            foreach ($zdroj['metadata'] as [$klic, $hodnota]) {
                $xml .= '  <metadata type="object" key="' . self::xml($klic) . '" value="' . self::xml($hodnota) . '"/>' . "\n";
            }
        }
        $objemy = ($zdroj !== null && !empty($zdroj['objemy']))
            ? $zdroj['objemy']
            : [['prvni' => 0, 'posledni' => $pocetTrojuhelniku - 1, 'metadata' => [['name', $nazevCasti]]]];
        foreach ($objemy as $objem) {
            $xml .= '  <volume firstid="' . (int)$objem['prvni'] . '" lastid="' . (int)$objem['posledni'] . '">' . "\n";
            foreach ($objem['metadata'] as [$klic, $hodnota]) {
                $xml .= '   <metadata type="volume" key="' . self::xml($klic) . '" value="' . self::xml($hodnota) . '"/>' . "\n";
            }
            $xml .= "  </volume>\n";
        }
        return $xml . " </object>\n";
    }

    /**
     * ZIP z obsahů (text) nebo souborů na disku (['soubor' => cesta]).
     *
     * @param bool $komprimovat Deflate (3MF), jinak jen uložit (ZIP s hotovými 3MF).
     */
    public static function zabal(array $polozky, bool $komprimovat): string
    {
        if (!class_exists('ZipArchive')) throw ImportModelu::chybiZip();
        $cesta = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive();
        if ($zip->open($cesta, ZipArchive::OVERWRITE) !== true) {
            @unlink($cesta);
            throw new ApiChyba(500, 'interni_chyba', 'Nepodařilo se vytvořit ZIP.');
        }
        foreach ($polozky as $nazev => $obsah) {
            if (is_array($obsah)) $zip->addFile($obsah['soubor'], $nazev);
            else $zip->addFromString($nazev, $obsah);
            if (method_exists($zip, 'setCompressionName')) {
                $zip->setCompressionName($nazev, $komprimovat ? ZipArchive::CM_DEFLATE : ZipArchive::CM_STORE);
            }
        }
        $zip->close();
        $data = (string)file_get_contents($cesta);
        @unlink($cesta);
        return $data;
    }

    /** Escapování hodnoty atributu XML. */
    private static function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}

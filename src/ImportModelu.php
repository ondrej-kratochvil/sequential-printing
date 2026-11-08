<?php
/**
 * Import modelu z 3MF (PrusaSlicer i jiné slicery) nebo STL (binární i ASCII).
 *
 * Výsledek nacti():
 *   ['typ' => '3mf'|'stl', 'soubor' => název souboru, 'varovani' => [text, ...],
 *    'objekty' => [[
 *        'nazev' => text, 'pocet' => počet instancí (položek <item> se stejným objektem),
 *        'sit' => síť ve tvaru Sit3d (vrcholy v souřadnicích objektu, komponenty už spojené),
 *        'matice' => lineární část transformace první položky <item> (rotace, měřítko; posun nulový),
 *        'rozmery' => ['x', 'y', 'z'] ohraničení sítě po použití 'matice' (přesně, mm),
 *        'metadata' => [[klíč, hodnota], ...] per-object metadata z Metadata/Slic3r_PE_model.config (bez name),
 *        'objemy' => [['prvni' => int, 'posledni' => int, 'metadata' => [[klíč, hodnota], ...]], ...] (části objektu PrusaSliceru),
 *    ], ...]]
 *
 * 3MF: každý <object> z 3D/3dmodel.model, na který odkazuje aspoň jedna položka <build><item>, je jeden objekt.
 * Komponenty (<components>, i s p:path do jiného souboru v balíčku) se spojí do jedné sítě. Jednotky modelu
 * (unit) se převedou na mm. STL: jeden objekt, název z názvu souboru, 1 instance.
 *
 * Chyby jsou ApiChyba (formát API v1): 413 soubor_prilis_velky, 415 nepodporovany_soubor, 400 neplatny_soubor,
 * 500 chybi_zip (PHP bez rozšíření zip). Limity: IMPORT_MAX_MB (výchozí 50) a IMPORT_MAX_ROZBALENO_MB (500).
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru), 3MF potřebuje rozšíření zip (ZipArchive) a xmlreader.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ApiChyba.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'Sit3d.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'SequentialPrintCalculator.php';

class ImportModelu
{
    /** Výchozí největší velikost nahraného souboru v MB (env IMPORT_MAX_MB). */
    public const VYCHOZI_MAX_MB = 50;

    /** Výchozí největší velikost jednoho souboru uvnitř 3MF po rozbalení v MB (env IMPORT_MAX_ROZBALENO_MB, ochrana proti zip bombě). */
    public const VYCHOZI_MAX_ROZBALENO_MB = 500;

    /** Největší hloubka vnoření komponent (ochrana proti cyklu). */
    private const MAX_HLOUBKA_KOMPONENT = 5;

    private const NS_PRODUCTION = 'http://schemas.microsoft.com/3dmanufacturing/production/2015/06';

    /** Převod jednotek 3MF na mm. */
    private const JEDNOTKY = [
        'micron' => 0.001, 'millimeter' => 1.0, 'centimeter' => 10.0,
        'inch' => 25.4, 'foot' => 304.8, 'meter' => 1000.0,
    ];

    /** Největší velikost nahraného souboru v bajtech. */
    public static function maxVelikost(): int
    {
        return self::limitMb('IMPORT_MAX_MB', self::VYCHOZI_MAX_MB);
    }

    /** Největší velikost souboru uvnitř 3MF po rozbalení v bajtech. */
    public static function maxRozbaleno(): int
    {
        return self::limitMb('IMPORT_MAX_ROZBALENO_MB', self::VYCHOZI_MAX_ROZBALENO_MB);
    }

    private static function limitMb(string $promenna, int $vychozi): int
    {
        $hodnota = getenv($promenna);
        $mb = ($hodnota !== false && is_numeric($hodnota) && (float)$hodnota > 0) ? (float)$hodnota : $vychozi;
        return (int)round($mb * 1024 * 1024);
    }

    /**
     * Načte 3MF nebo STL ze souboru na disku. Typ se pozná podle obsahu (3MF je ZIP), ne podle přípony.
     *
     * @param bool $sTrojuhelniky Načíst i trojúhelníky (pro export). Bez nich jen vrcholy na rozměry (méně paměti).
     * @throws ApiChyba
     */
    public static function nacti(string $cesta, string $nazevSouboru, bool $sTrojuhelniky = false): array
    {
        if (!is_file($cesta) || !is_readable($cesta)) {
            throw new ApiChyba(400, 'chybi_soubor', 'Soubor s modelem nejde přečíst.');
        }
        $velikost = (int)filesize($cesta);
        self::overVelikost($velikost);
        if ($velikost === 0) throw new ApiChyba(400, 'neplatny_soubor', 'Soubor je prázdný.', ['duvod' => 'prazdny']);

        $zacatek = (string)file_get_contents($cesta, false, null, 0, 4);
        if ($zacatek === "PK\x03\x04") {
            $model = self::nacti3mf($cesta, $nazevSouboru, $sTrojuhelniky);
            $model['typ'] = '3mf';
        } elseif (preg_match('/\.(3mf|zip)$/i', $nazevSouboru)) {
            throw new ApiChyba(400, 'neplatny_soubor', 'Soubor má příponu .3mf, ale není to ZIP balíček 3MF.', ['duvod' => 'neni_zip']);
        } else {
            $model = self::nactiStl($cesta, $nazevSouboru, $sTrojuhelniky);
            $model['typ'] = 'stl';
        }
        $model['soubor'] = $nazevSouboru;
        return $model;
    }

    /** 413, když je soubor větší než limit. */
    public static function overVelikost(int $velikost): void
    {
        $limit = self::maxVelikost();
        if ($velikost > $limit) {
            $limitMb = round($limit / 1048576, 1);
            throw new ApiChyba(413, 'soubor_prilis_velky', "Soubor má " . round($velikost / 1048576, 1) . " MB, limit je {$limitMb} MB (IMPORT_MAX_MB).", [
                'velikost' => $velikost,
                'limit' => $limit,
            ]);
        }
    }

    /**
     * Objekty pro vstup výpočtu (formulář, API, CLI): rozměry zaokrouhlené na 2 desetinná místa NAHORU
     * (zaokrouhlení dolů by mohlo kus zmenšit pod skutečnou velikost), název, počet instancí, `sit` = index objektu v modelu.
     */
    public static function objektyProVstup(array $model): array
    {
        $vystup = [];
        foreach ($model['objekty'] as $index => $objekt) {
            $polozka = [
                'x' => self::nahoru2($objekt['rozmery']['x']),
                'y' => self::nahoru2($objekt['rozmery']['y']),
                'z' => self::nahoru2($objekt['rozmery']['z']),
                'instances' => ['d' => $objekt['pocet']],
                'sit' => $index,
            ];
            if ($objekt['nazev'] !== '') $polozka['nazev'] = $objekt['nazev'];
            $vystup[] = $polozka;
        }
        return $vystup;
    }

    /** Souhrn modelu pro odpověď importu (bez sítí). */
    public static function souhrn(array $model): array
    {
        $modely = [];
        foreach ($model['objekty'] as $index => $objekt) {
            $modely[] = [
                'sit' => $index,
                'nazev' => $objekt['nazev'],
                'pocet_instanci' => $objekt['pocet'],
                'rozmery' => [
                    'x' => round($objekt['rozmery']['x'], 4),
                    'y' => round($objekt['rozmery']['y'], 4),
                    'z' => round($objekt['rozmery']['z'], 4),
                ],
                'pocet_vrcholu' => $objekt['sit']['pocet_vrcholu'],
            ];
        }
        return [
            'objekty' => self::objektyProVstup($model),
            'soubor' => ['nazev' => $model['soubor'], 'typ' => $model['typ']],
            'modely' => $modely,
            'varovani' => $model['varovani'],
        ];
    }

    /** Zaokrouhlení na setiny nahoru; šum float32 (do 0,00001 mm) se zanedbá. */
    public static function nahoru2(float $x): float
    {
        return ceil(round($x * 100, 3)) / 100;
    }

    // ---------------------------------------------------------------- 3MF

    /** Název souboru bez cesty a přípony. */
    private static function zakladNazvu(string $nazevSouboru): string
    {
        return (string)preg_replace('/\.[^.\/]*$/', '', basename(str_replace('\\', '/', $nazevSouboru)));
    }

    private static function nacti3mf(string $cesta, string $nazevSouboru, bool $sTrojuhelniky): array
    {
        if (!class_exists('ZipArchive')) {
            throw self::chybiZip();
        }
        if (!class_exists('XMLReader')) {
            throw new ApiChyba(500, 'chybi_xmlreader', 'Import 3MF potřebuje PHP rozšíření xmlreader, které na serveru chybí.');
        }
        $zip = new ZipArchive();
        if ($zip->open($cesta) !== true) {
            throw new ApiChyba(400, 'neplatny_soubor', 'Soubor 3MF nejde otevřít (poškozený ZIP).', ['duvod' => 'zip']);
        }
        try {
            $hlavni = self::hlavniModel($zip);
            $soubory = [];
            $nactiSoubor = function (string $vnitrni) use (&$soubory, $zip, $cesta, $sTrojuhelniky): array {
                $vnitrni = ltrim($vnitrni, '/');
                if (!isset($soubory[$vnitrni])) {
                    $stat = $zip->statName($vnitrni);
                    if ($stat === false) {
                        throw new ApiChyba(400, 'neplatny_soubor', "V 3MF chybí soubor '{$vnitrni}'.", ['duvod' => 'chybi_model', 'soubor' => $vnitrni]);
                    }
                    if ($stat['size'] > self::maxRozbaleno()) {
                        throw new ApiChyba(413, 'soubor_prilis_velky', "Soubor '{$vnitrni}' má po rozbalení " . round($stat['size'] / 1048576) . ' MB, limit je ' . round(self::maxRozbaleno() / 1048576) . ' MB (IMPORT_MAX_ROZBALENO_MB).', [
                            'velikost' => $stat['size'],
                            'limit' => self::maxRozbaleno(),
                        ]);
                    }
                    $soubory[$vnitrni] = self::ctiModelXml($cesta, $vnitrni, $sTrojuhelniky);
                }
                return $soubory[$vnitrni];
            };
            // ZIP zůstane otevřený až do konce: komponenty s p:path načítají další soubory balíčku.
            return self::objekty3mf($nactiSoubor($hlavni), $hlavni, $nactiSoubor, self::ctiKonfiguraciPrusaSliceru($zip), $nazevSouboru, $sTrojuhelniky);
        } finally {
            $zip->close();
        }
    }

    /** Objekty z načteného modelu: položky build seskupené podle objektu, spojené sítě, rozměry, názvy, metadata. */
    private static function objekty3mf(array $model, string $hlavni, callable $nactiSoubor, array $konfigurace, string $nazevSouboru, bool $sTrojuhelniky): array
    {
        // Položky <build><item> seskupené podle objektu (v pořadí prvního výskytu).
        $skupiny = [];
        foreach ($model['polozky'] as $polozka) {
            $soubor = $polozka['path'] !== null ? ltrim($polozka['path'], '/') : $hlavni;
            $klic = $soubor . '#' . $polozka['objectid'];
            if (!isset($skupiny[$klic])) $skupiny[$klic] = ['soubor' => $soubor, 'id' => $polozka['objectid'], 'matice' => []];
            $skupiny[$klic]['matice'][] = $polozka['matice'];
        }
        if (empty($skupiny)) {
            throw new ApiChyba(400, 'neplatny_soubor', '3MF neobsahuje žádnou položku <build><item> (nic k tisku).', ['duvod' => 'prazdny_build']);
        }

        $varovani = [];
        $objekty = [];
        foreach ($skupiny as $skupina) {
            $zdroj = $nactiSoubor($skupina['soubor']);
            if (!isset($zdroj['objekty'][$skupina['id']])) {
                throw new ApiChyba(400, 'neplatny_soubor', "Položka build odkazuje na neexistující objekt {$skupina['id']}.", ['duvod' => 'chybi_objekt', 'objectid' => $skupina['id']]);
            }
            $sit = self::spojSit($nactiSoubor, $skupina['soubor'], $skupina['id'], Sit3d::jednotkova(), $sTrojuhelniky, 0);
            if ($sit['pocet_vrcholu'] === 0 || ($sTrojuhelniky && $sit['pocet_trojuhelniku'] === 0)) {
                throw new ApiChyba(400, 'neplatny_soubor', "Objekt {$skupina['id']} nemá žádnou síť.", ['duvod' => 'prazdna_sit', 'objectid' => $skupina['id']]);
            }
            $poradoveCislo = count($objekty) + 1;
            $matice = Sit3d::bezPosunu($skupina['matice'][0]);
            foreach ($skupina['matice'] as $dalsi) {
                if (!Sit3d::stejnaLinearniCast($matice, $dalsi)) {
                    $varovani[] = "Objekt {$poradoveCislo}: instance mají různé natočení nebo měřítko, rozměry jsou podle první instance.";
                    break;
                }
            }
            $ohraniceni = Sit3d::ohraniceni($sit['vrcholy'], $sit['pocet_vrcholu'], $matice);

            // Název a per-object nastavení z Metadata/Slic3r_PE_model.config (jen u objektů hlavního souboru).
            $prusa = ($skupina['soubor'] === $hlavni && isset($konfigurace[$skupina['id']])) ? $konfigurace[$skupina['id']] : null;
            $nazev = $prusa !== null && $prusa['nazev'] !== '' ? $prusa['nazev'] : $zdroj['objekty'][$skupina['id']]['nazev'];
            $nazev = SequentialPrintCalculator::upravNazev($nazev);
            // Bez názvu (např. kopie z PrusaSliceru bez záznamu v konfiguraci): název souboru a pořadí objektu.
            if ($nazev === '') $nazev = SequentialPrintCalculator::upravNazev(self::zakladNazvu($nazevSouboru) . ' ' . $poradoveCislo);
            $objemy = [];
            if ($prusa !== null && !$zdroj['objekty'][$skupina['id']]['ma_komponenty']) {
                foreach ($prusa['objemy'] as $objem) {
                    if ($objem['prvni'] < 0 || $objem['posledni'] < $objem['prvni'] || ($sTrojuhelniky && $objem['posledni'] >= $sit['pocet_trojuhelniku'])) {
                        $varovani[] = "Objekt {$poradoveCislo}: části objektu (volume) v Slic3r_PE_model.config neodpovídají síti, export je vynechá.";
                        $objemy = [];
                        break;
                    }
                    $objemy[] = $objem;
                }
            }
            $objekty[] = [
                'nazev' => $nazev,
                'pocet' => count($skupina['matice']),
                'sit' => $sit,
                'matice' => $matice,
                'rozmery' => [
                    'x' => $ohraniceni['max'][0] - $ohraniceni['min'][0],
                    'y' => $ohraniceni['max'][1] - $ohraniceni['min'][1],
                    'z' => $ohraniceni['max'][2] - $ohraniceni['min'][2],
                ],
                'metadata' => $prusa !== null ? $prusa['metadata'] : [],
                'objemy' => $objemy,
            ];
        }
        return ['objekty' => $objekty, 'varovani' => $varovani];
    }

    /** Chyba, když PHP nemá rozšíření zip. */
    public static function chybiZip(): ApiChyba
    {
        return new ApiChyba(500, 'chybi_zip', 'Práce s 3MF potřebuje PHP rozšíření zip (ZipArchive), které na serveru chybí. Zapni extension=zip v php.ini (import STL funguje i bez něj).');
    }

    /** Cesta k hlavnímu modelu podle _rels/.rels (výchozí 3D/3dmodel.model). */
    private static function hlavniModel(ZipArchive $zip): string
    {
        $rels = $zip->getFromName('_rels/.rels');
        if (is_string($rels) && strlen($rels) < 1048576) {
            $xml = @simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET);
            if ($xml !== false) {
                foreach ($xml->children() as $vztah) {
                    $typ = (string)$vztah['Type'];
                    if (substr($typ, -strlen('/3dmodel')) === '/3dmodel' && (string)$vztah['Target'] !== '') {
                        return ltrim((string)$vztah['Target'], '/');
                    }
                }
            }
        }
        return '3D/3dmodel.model';
    }

    /**
     * Přečte jeden soubor modelu proudově (XMLReader, bez načtení celého XML do paměti).
     *
     * @return array ['objekty' => [id => ['nazev', 'sit' => ?síť, 'komponenty' => [[objectid, matice, path]], 'ma_komponenty']],
     *                'polozky' => [[objectid, matice, path]]]
     */
    private static function ctiModelXml(string $cestaZip, string $vnitrni, bool $sTrojuhelniky): array
    {
        $r = new XMLReader();
        $url = 'zip://' . $cestaZip . '#' . $vnitrni;
        // Bez LIBXML_NOENT / DTDLOAD: externí entity se nenačítají, NONET zakáže síť.
        if (!@$r->open($url, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new ApiChyba(400, 'neplatny_soubor', "Model '{$vnitrni}' v 3MF nejde přečíst.", ['duvod' => 'xml']);
        }
        $chyba = function (string $zprava) use ($vnitrni): ApiChyba {
            return new ApiChyba(400, 'neplatny_soubor', "Model '{$vnitrni}': {$zprava}", ['duvod' => 'xml']);
        };
        $jednotka = 1.0;
        $objekty = [];
        $polozky = [];
        $aktualni = null;
        $vSiti = false;
        $vrcholy = $trojuhelniky = '';
        $pocetV = $pocetT = 0;
        $davkaV = $davkaT = [];

        $predchoziChyby = libxml_use_internal_errors(true);
        try {
            while (true) {
                $ok = @$r->read();
                if (!$ok) break;
                $typ = $r->nodeType;
                if ($typ === XMLReader::END_ELEMENT) {
                    if ($r->localName === 'mesh' && $vSiti) {
                        if (!empty($davkaV)) { $vrcholy .= pack('g*', ...$davkaV); $davkaV = []; }
                        if (!empty($davkaT)) { $trojuhelniky .= pack('V*', ...$davkaT); $davkaT = []; }
                        $objekty[$aktualni]['sit'] = [
                            'vrcholy' => $vrcholy, 'pocet_vrcholu' => $pocetV,
                            'trojuhelniky' => $sTrojuhelniky ? $trojuhelniky : null, 'pocet_trojuhelniku' => $pocetT,
                        ];
                        $vSiti = false;
                    } elseif ($r->localName === 'object') {
                        $aktualni = null;
                    }
                    continue;
                }
                if ($typ !== XMLReader::ELEMENT) continue;
                switch ($r->localName) {
                    case 'model':
                        $unit = $r->getAttribute('unit');
                        if ($unit !== null && $unit !== '') {
                            if (!isset(self::JEDNOTKY[$unit])) throw $chyba("neznámá jednotka '{$unit}'.");
                            $jednotka = self::JEDNOTKY[$unit];
                        }
                        break;
                    case 'object':
                        $id = (string)$r->getAttribute('id');
                        if ($id === '') throw $chyba('objekt bez atributu id.');
                        $objekty[$id] = ['nazev' => (string)$r->getAttribute('name'), 'sit' => null, 'komponenty' => [], 'ma_komponenty' => false];
                        $aktualni = $r->isEmptyElement ? null : $id;
                        break;
                    case 'mesh':
                        if ($aktualni !== null && !$r->isEmptyElement) {
                            $vSiti = true;
                            $vrcholy = $trojuhelniky = '';
                            $pocetV = $pocetT = 0;
                        }
                        break;
                    case 'vertex':
                        if (!$vSiti) break;
                        $x = $r->getAttribute('x');
                        $y = $r->getAttribute('y');
                        $z = $r->getAttribute('z');
                        if (!is_numeric($x) || !is_numeric($y) || !is_numeric($z)) throw $chyba('vrchol bez číselných souřadnic.');
                        $davkaV[] = (float)$x * $jednotka;
                        $davkaV[] = (float)$y * $jednotka;
                        $davkaV[] = (float)$z * $jednotka;
                        $pocetV++;
                        if (count($davkaV) >= 3000) { $vrcholy .= pack('g*', ...$davkaV); $davkaV = []; }
                        break;
                    case 'triangle':
                        if (!$vSiti) break;
                        $pocetT++;
                        if (!$sTrojuhelniky) break;
                        foreach (['v1', 'v2', 'v3'] as $atribut) {
                            $v = $r->getAttribute($atribut);
                            if ($v === null || !ctype_digit($v) || (int)$v >= $pocetV) throw $chyba("trojúhelník odkazuje na neexistující vrchol ({$atribut}).");
                            $davkaT[] = (int)$v;
                        }
                        if (count($davkaT) >= 3000) { $trojuhelniky .= pack('V*', ...$davkaT); $davkaT = []; }
                        break;
                    case 'component':
                        if ($aktualni === null) break;
                        $objekty[$aktualni]['ma_komponenty'] = true;
                        $objekty[$aktualni]['komponenty'][] = self::odkaz($r, $jednotka, $chyba);
                        break;
                    case 'item':
                        $polozky[] = self::odkaz($r, $jednotka, $chyba);
                        break;
                }
            }
            $chybyXml = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($predchoziChyby);
            $r->close();
        }
        foreach ($chybyXml as $chybaXml) {
            if ($chybaXml->level >= LIBXML_ERR_ERROR) throw $chyba('neplatné XML (' . trim($chybaXml->message) . ', řádek ' . $chybaXml->line . ').');
        }
        return ['objekty' => $objekty, 'polozky' => $polozky];
    }

    /** Odkaz na objekt (<item> nebo <component>): objectid, matice (posun v mm), volitelný p:path. */
    private static function odkaz(XMLReader $r, float $jednotka, callable $chyba): array
    {
        $id = (string)$r->getAttribute('objectid');
        if ($id === '') throw $chyba('<' . $r->localName . '> bez objectid.');
        $matice = Sit3d::zTransformu3mf($r->getAttribute('transform'));
        if ($matice === null) throw $chyba("neplatný atribut transform u objektu {$id}.");
        $matice[9] *= $jednotka;
        $matice[10] *= $jednotka;
        $matice[11] *= $jednotka;
        $path = $r->getAttributeNs('path', self::NS_PRODUCTION);
        return ['objectid' => $id, 'matice' => $matice, 'path' => ($path === null || $path === '') ? null : $path];
    }

    /** Síť objektu se spojenými komponentami (rekurzivně, transformace komponent se zapečou do vrcholů). */
    private static function spojSit(callable $nactiSoubor, string $soubor, string $id, array $matice, bool $sTrojuhelniky, int $hloubka): array
    {
        if ($hloubka > self::MAX_HLOUBKA_KOMPONENT) {
            throw new ApiChyba(400, 'neplatny_soubor', 'Komponenty v 3MF jsou vnořené příliš hluboko (nebo cyklus).', ['duvod' => 'komponenty']);
        }
        $model = $nactiSoubor($soubor);
        if (!isset($model['objekty'][$id])) {
            throw new ApiChyba(400, 'neplatny_soubor', "Komponenta odkazuje na neexistující objekt {$id}.", ['duvod' => 'chybi_objekt', 'objectid' => $id]);
        }
        $objekt = $model['objekty'][$id];
        if ($objekt['sit'] !== null) {
            $sit = $objekt['sit'];
            $sit['vrcholy'] = Sit3d::transformujVrcholy($sit['vrcholy'], $sit['pocet_vrcholu'], $matice);
            return $sit;
        }
        $vysledek = ['vrcholy' => '', 'pocet_vrcholu' => 0, 'trojuhelniky' => $sTrojuhelniky ? '' : null, 'pocet_trojuhelniku' => 0];
        foreach ($objekt['komponenty'] as $komponenta) {
            $cil = $komponenta['path'] !== null ? ltrim($komponenta['path'], '/') : $soubor;
            $cast = self::spojSit($nactiSoubor, $cil, $komponenta['objectid'], Sit3d::slozit($matice, $komponenta['matice']), $sTrojuhelniky, $hloubka + 1);
            if ($sTrojuhelniky) {
                $vysledek['trojuhelniky'] .= Sit3d::posunIndexy($cast['trojuhelniky'], $cast['pocet_trojuhelniku'], $vysledek['pocet_vrcholu']);
            }
            $vysledek['vrcholy'] .= $cast['vrcholy'];
            $vysledek['pocet_vrcholu'] += $cast['pocet_vrcholu'];
            $vysledek['pocet_trojuhelniku'] += $cast['pocet_trojuhelniku'];
        }
        return $vysledek;
    }

    /**
     * Metadata/Slic3r_PE_model.config (PrusaSlicer): pro každý objekt název, per-object nastavení a části (volume).
     *
     * @return array id objektu => ['nazev' => text, 'metadata' => [[klíč, hodnota]], 'objemy' => [...]]
     */
    private static function ctiKonfiguraciPrusaSliceru(ZipArchive $zip): array
    {
        $stat = $zip->statName('Metadata/Slic3r_PE_model.config');
        if ($stat === false || $stat['size'] > 64 * 1048576) return [];
        $obsah = $zip->getFromName('Metadata/Slic3r_PE_model.config');
        if (!is_string($obsah) || $obsah === '') return [];
        $xml = @simplexml_load_string($obsah, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) return [];
        $vystup = [];
        foreach ($xml->object as $objekt) {
            $id = (string)$objekt['id'];
            if ($id === '') continue;
            $polozka = ['nazev' => '', 'metadata' => [], 'objemy' => []];
            foreach ($objekt->metadata as $metadata) {
                $klic = (string)$metadata['key'];
                if ($klic === '') continue;
                if ($klic === 'name') $polozka['nazev'] = (string)$metadata['value'];
                else $polozka['metadata'][] = [$klic, (string)$metadata['value']];
            }
            foreach ($objekt->volume as $objem) {
                $metadata = [];
                foreach ($objem->metadata as $m) {
                    if ((string)$m['key'] !== '') $metadata[] = [(string)$m['key'], (string)$m['value']];
                }
                $polozka['objemy'][] = ['prvni' => (int)$objem['firstid'], 'posledni' => (int)$objem['lastid'], 'metadata' => $metadata];
            }
            $vystup[$id] = $polozka;
        }
        return $vystup;
    }

    // ---------------------------------------------------------------- STL

    private static function nactiStl(string $cesta, string $nazevSouboru, bool $sTrojuhelniky): array
    {
        $velikost = (int)filesize($cesta);
        $fh = fopen($cesta, 'rb');
        if ($fh === false) throw new ApiChyba(400, 'chybi_soubor', 'Soubor s modelem nejde přečíst.');
        try {
            $hlavicka = (string)fread($fh, 84);
            $pocet = strlen($hlavicka) === 84 ? unpack('V', $hlavicka, 80)[1] : -1;
            $binarniDelka = $pocet >= 0 && $velikost === 84 + 50 * $pocet;
            $vypadaAscii = preg_match('/^\s*solid\b/i', $hlavicka) === 1;
            if ($binarniDelka && $pocet > 0) {
                $sit = self::ctiStlBinarni($fh, $pocet, $sTrojuhelniky);
            } elseif ($vypadaAscii) {
                rewind($fh);
                $sit = self::ctiStlAscii($fh, $sTrojuhelniky);
                if ($sit === null && $pocet > 0 && $velikost >= 84 + 50 * $pocet) {
                    fseek($fh, 84);
                    $sit = self::ctiStlBinarni($fh, $pocet, $sTrojuhelniky);
                }
                if ($sit === null) throw new ApiChyba(400, 'neplatny_soubor', 'STL neobsahuje žádné trojúhelníky.', ['duvod' => 'prazdna_sit']);
            } elseif ($pocet === 0 && $velikost === 84) {
                throw new ApiChyba(400, 'neplatny_soubor', 'STL neobsahuje žádné trojúhelníky.', ['duvod' => 'prazdna_sit']);
            } else {
                throw new ApiChyba(415, 'nepodporovany_soubor', 'Soubor není 3MF ani STL (podporované formáty: .3mf z PrusaSliceru a jiných slicerů, .stl binární i ASCII).', [
                    'podporovane' => ['3mf', 'stl'],
                ]);
            }
        } finally {
            fclose($fh);
        }
        $ohraniceni = $sit['ohraniceni'];
        unset($sit['ohraniceni']);
        $nazev = SequentialPrintCalculator::upravNazev(self::zakladNazvu($nazevSouboru));
        return [
            'objekty' => [[
                'nazev' => $nazev,
                'pocet' => 1,
                'sit' => $sit,
                'matice' => Sit3d::jednotkova(),
                'rozmery' => [
                    'x' => $ohraniceni['max'][0] - $ohraniceni['min'][0],
                    'y' => $ohraniceni['max'][1] - $ohraniceni['min'][1],
                    'z' => $ohraniceni['max'][2] - $ohraniceni['min'][2],
                ],
                'metadata' => [],
                'objemy' => [],
            ]],
            'varovani' => [],
        ];
    }

    /** Sběrač trojúhelníků STL: ohraničení vždy, síť se sloučenými vrcholy jen s $sTrojuhelniky. */
    private static function novySberac(bool $sTrojuhelniky): array
    {
        return ['min' => [INF, INF, INF], 'max' => [-INF, -INF, -INF], 'indexy' => [], 'vrcholy' => '', 'troj' => [], 'trojuhelniky' => '',
                'pocet_vrcholu' => 0, 'pocet_trojuhelniku' => 0, 's_trojuhelniky' => $sTrojuhelniky];
    }

    /** Přidá trojúhelník (9 souřadnic) do sběrače. */
    private static function pridejTrojuhelnik(array &$s, array $v): void
    {
        for ($i = 0; $i < 9; $i += 3) {
            for ($osa = 0; $osa < 3; $osa++) {
                if ($v[$i + $osa] < $s['min'][$osa]) $s['min'][$osa] = $v[$i + $osa];
                if ($v[$i + $osa] > $s['max'][$osa]) $s['max'][$osa] = $v[$i + $osa];
            }
            if ($s['s_trojuhelniky']) {
                // Sloučení stejných vrcholů (STL je má u každého trojúhelníku zvlášť), klíč = float32 bajty.
                $klic = pack('g3', $v[$i], $v[$i + 1], $v[$i + 2]);
                if (!isset($s['indexy'][$klic])) {
                    $s['indexy'][$klic] = $s['pocet_vrcholu']++;
                    $s['vrcholy'] .= $klic;
                }
                $s['troj'][] = $s['indexy'][$klic];
            }
        }
        $s['pocet_trojuhelniku']++;
        if (count($s['troj']) >= 3000) {
            $s['trojuhelniky'] .= pack('V*', ...$s['troj']);
            $s['troj'] = [];
        }
    }

    private static function uzavriSberac(array $s): array
    {
        if (!empty($s['troj'])) $s['trojuhelniky'] .= pack('V*', ...$s['troj']);
        return [
            'vrcholy' => $s['s_trojuhelniky'] ? $s['vrcholy'] : pack('g*', $s['min'][0], $s['min'][1], $s['min'][2], $s['max'][0], $s['max'][1], $s['max'][2]),
            'pocet_vrcholu' => $s['s_trojuhelniky'] ? $s['pocet_vrcholu'] : 2,
            'trojuhelniky' => $s['s_trojuhelniky'] ? $s['trojuhelniky'] : null,
            'pocet_trojuhelniku' => $s['pocet_trojuhelniku'],
            'ohraniceni' => ['min' => $s['min'], 'max' => $s['max']],
        ];
    }

    private static function ctiStlBinarni($fh, int $pocet, bool $sTrojuhelniky): array
    {
        $s = self::novySberac($sTrojuhelniky);
        $zbyva = $pocet;
        while ($zbyva > 0) {
            $n = min(2000, $zbyva);
            $data = fread($fh, 50 * $n);
            if ($data === false || strlen($data) < 50 * $n) {
                throw new ApiChyba(400, 'neplatny_soubor', 'Binární STL je zkrácený.', ['duvod' => 'zkraceny']);
            }
            for ($i = 0; $i < $n; $i++) {
                // 12 B normála (přeskočit), 36 B vrcholy, 2 B atributy.
                $v = array_values(unpack('g9', $data, $i * 50 + 12));
                foreach ($v as $souradnice) {
                    if (!is_finite($souradnice)) throw new ApiChyba(400, 'neplatny_soubor', 'Binární STL obsahuje neplatné číslo.', ['duvod' => 'cislo']);
                }
                self::pridejTrojuhelnik($s, $v);
            }
            $zbyva -= $n;
        }
        return self::uzavriSberac($s);
    }

    /** ASCII STL; null = žádný trojúhelník (možná binární STL s hlavičkou "solid"). */
    private static function ctiStlAscii($fh, bool $sTrojuhelniky): ?array
    {
        $s = self::novySberac($sTrojuhelniky);
        $v = [];
        $radek = 0;
        while (($text = fgets($fh)) !== false) {
            $radek++;
            if (stripos($text, 'vertex') === false) continue;
            if (!preg_match('/^\s*vertex\s+(\S+)\s+(\S+)\s+(\S+)/i', $text, $m)) continue;
            if (!is_numeric($m[1]) || !is_numeric($m[2]) || !is_numeric($m[3])) {
                if ($s['pocet_trojuhelniku'] === 0 && empty($v)) return null;
                throw new ApiChyba(400, 'neplatny_soubor', "ASCII STL: neplatný vrchol na řádku {$radek}.", ['duvod' => 'cislo', 'radek' => $radek]);
            }
            array_push($v, (float)$m[1], (float)$m[2], (float)$m[3]);
            if (count($v) === 9) {
                self::pridejTrojuhelnik($s, $v);
                $v = [];
            }
        }
        if ($s['pocet_trojuhelniku'] === 0) return null;
        return self::uzavriSberac($s);
    }
}

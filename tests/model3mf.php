<?php
/**
 * Testy importu a exportu 3MF/STL (bez DB). Vkládá je tests/run.php (CLI režim) a používají jeho pomocné funkce
 * (assertSame, assertTrue, assertNear, ok, fail, phpPrikaz) a closures $spustEndpoint, $chybaV1, $spustCli.
 *
 * 3MF potřebuje rozšíření zip. Bez něj se testy 3MF přeskočí a ověří se jen STL a srozumitelná chyba `chybi_zip`;
 * celé spuštění: php -d extension=zip tests/run.php. Ověření v PrusaSliceru (nařezání exportu, pořadí objektů
 * v G-code) běží, jen když je nainstalovaný (PRUSASLICER_CLI, výchozí cesta níže); TEST_BEZ_PRUSASLICERU=1 ho vypne.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'UlozisteModelu.php';

$maZip = class_exists('ZipArchive');
$tmpTest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sekvencni_tisk_test_' . bin2hex(random_bytes(4));
mkdir($tmpTest);
$docasny = function (string $nazev, string $obsah) use ($tmpTest): string {
    $cesta = $tmpTest . DIRECTORY_SEPARATOR . $nazev;
    file_put_contents($cesta, $obsah);
    return $cesta;
};
$uklidTmp = function (string $slozka) use (&$uklidTmp): void {
    foreach ((array)glob($slozka . DIRECTORY_SEPARATOR . '*') as $soubor) {
        if (is_dir($soubor)) $uklidTmp($soubor); else @unlink($soubor);
    }
    @rmdir($slozka);
};
// Očekávaná ApiChyba (status + kód).
$ocekavejChybu = function (callable $f, int $status, string $kod, string $label): ApiChyba {
    try {
        $f();
    } catch (ApiChyba $e) {
        assertSame([$status, $kod], [$e->status, $e->kod], "{$label} ({$e->getMessage()})");
        return $e;
    }
    fail("{$label}: čekala se chyba {$kod}");
    throw new RuntimeException();
};

// --- Výroba testovacích souborů ---------------------------------------------------------------

// Trojúhelníky kvádru x × y × z s rohem v (ox, oy, oz): [[[x, y, z] × 3] × 12].
$kvadrTrojuhelniky = function (float $x, float $y, float $z, float $ox = 0, float $oy = 0, float $oz = 0): array {
    $sit = Sit3d::kvadr($x, $y, $z);
    $v = array_values(unpack('g*', $sit['vrcholy']));
    $t = array_values(unpack('V*', $sit['trojuhelniky']));
    $vystup = [];
    for ($i = 0; $i < 36; $i += 3) {
        $troj = [];
        foreach ([$t[$i], $t[$i + 1], $t[$i + 2]] as $k) $troj[] = [$v[$k * 3] + $ox, $v[$k * 3 + 1] + $oy, $v[$k * 3 + 2] + $oz];
        $vystup[] = $troj;
    }
    return $vystup;
};
$stlBinarni = function (array $trojuhelniky, string $hlavicka = 'binarni STL z testu'): string {
    $data = str_pad($hlavicka, 80, ' ') . pack('V', count($trojuhelniky));
    foreach ($trojuhelniky as $t) {
        $data .= pack('g3', 0, 0, 0);
        foreach ($t as $v) $data .= pack('g3', ...$v);
        $data .= pack('v', 0);
    }
    return $data;
};
$stlAscii = function (array $trojuhelniky): string {
    $s = "solid test\n";
    foreach ($trojuhelniky as $t) {
        $s .= "  facet normal 0 0 0\n    outer loop\n";
        foreach ($t as $v) $s .= sprintf("      vertex %.6e %.6e %.6e\n", ...$v);
        $s .= "    endloop\n  endfacet\n";
    }
    return $s . "endsolid test\n";
};
// <mesh> kvádru pro 3MF.
$meshKvadru = function (float $x, float $y, float $z, float $ox = 0, float $oy = 0, float $oz = 0): string {
    $sit = Sit3d::kvadr($x, $y, $z);
    $sit['vrcholy'] = Sit3d::transformujVrcholy($sit['vrcholy'], 8, Sit3d::posun($ox, $oy, $oz));
    return Sit3d::meshXml($sit);
};
$model3mf = function (string $resources, string $build, string $unit = 'millimeter'): string {
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<model unit="' . $unit . '" xml:lang="en-US" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02"'
        . ' xmlns:p="http://schemas.microsoft.com/3dmanufacturing/production/2015/06">'
        . "<resources>{$resources}</resources><build>{$build}</build></model>";
};
$rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Target="/3D/3dmodel.model" Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel"/></Relationships>';
$zabal = function (string $nazev, array $soubory) use ($tmpTest): string {
    $cesta = $tmpTest . DIRECTORY_SEPARATOR . $nazev;
    $zip = new ZipArchive();
    $zip->open($cesta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($soubory as $vnitrni => $obsah) $zip->addFromString($vnitrni, $obsah);
    $zip->close();
    return $cesta;
};
// Rozbalí exportovaný 3MF: objekty (název, vrcholy), položky (objectid, matice), konfigurace PrusaSliceru.
$nactiExport = function (string $obsah) use ($tmpTest): array {
    $cesta = $tmpTest . DIRECTORY_SEPARATOR . 'export_' . bin2hex(random_bytes(3)) . '.3mf';
    file_put_contents($cesta, $obsah);
    $zip = new ZipArchive();
    assertTrue($zip->open($cesta) === true, 'Export je ZIP');
    $vystup = ['soubory' => []];
    for ($i = 0; $i < $zip->numFiles; $i++) $vystup['soubory'][] = $zip->getNameIndex($i);
    $model = simplexml_load_string((string)$zip->getFromName('3D/3dmodel.model'));
    $konfigurace = simplexml_load_string((string)$zip->getFromName('Metadata/Slic3r_PE_model.config'));
    $zip->close();
    unlink($cesta);
    assertTrue($model !== false && $konfigurace !== false, 'Export: model i konfigurace jsou platné XML');
    $vystup['objekty'] = [];
    foreach ($model->resources->object as $objekt) {
        $vrcholy = [];
        foreach ($objekt->mesh->vertices->vertex as $v) $vrcholy[] = [(float)$v['x'], (float)$v['y'], (float)$v['z']];
        $vystup['objekty'][(string)$objekt['id']] = ['nazev' => (string)$objekt['name'], 'vrcholy' => $vrcholy, 'trojuhelniku' => count($objekt->mesh->triangles->triangle)];
    }
    $vystup['polozky'] = [];
    foreach ($model->build->item as $polozka) {
        $vystup['polozky'][] = ['objectid' => (string)$polozka['objectid'], 'matice' => Sit3d::zTransformu3mf((string)$polozka['transform'])];
    }
    $vystup['konfigurace'] = [];
    foreach ($konfigurace->object as $objekt) {
        $meta = [];
        foreach ($objekt->metadata as $m) $meta[(string)$m['key']] = (string)$m['value'];
        $objemy = [];
        foreach ($objekt->volume as $objem) {
            $metaObjemu = [];
            foreach ($objem->metadata as $m) $metaObjemu[(string)$m['key']] = (string)$m['value'];
            $objemy[] = ['prvni' => (int)$objem['firstid'], 'posledni' => (int)$objem['lastid'], 'metadata' => $metaObjemu];
        }
        $vystup['konfigurace'][(string)$objekt['id']] = ['metadata' => $meta, 'objemy' => $objemy];
    }
    return $vystup;
};
// Ohraničení vrcholů objektu po transformaci položky.
$svetoveOhraniceni = function (array $vrcholy, array $matice): array {
    $min = [INF, INF, INF];
    $max = [-INF, -INF, -INF];
    foreach ($vrcholy as [$x, $y, $z]) {
        $p = [
            $matice[0] * $x + $matice[1] * $y + $matice[2] * $z + $matice[9],
            $matice[3] * $x + $matice[4] * $y + $matice[5] * $z + $matice[10],
            $matice[6] * $x + $matice[7] * $y + $matice[8] * $z + $matice[11],
        ];
        for ($o = 0; $o < 3; $o++) { $min[$o] = min($min[$o], $p[$o]); $max[$o] = max($max[$o], $p[$o]); }
    }
    return ['min' => $min, 'max' => $max];
};
// Export: pořadí objektů = pořadí tisku, střed každého kusu na X/Y z pozice, spodek na z = 0, rozměry a otočení.
$overExport = function (array $export, array $pozice, array $rozmery, string $label) use ($svetoveOhraniceni): void {
    usort($pozice, function ($a, $b) { return $a['poradi'] <=> $b['poradi']; });
    assertSame(count($pozice), count($export['polozky']), "{$label}: počet položek = počet kusů");
    assertSame(count($pozice), count($export['objekty']), "{$label}: každý kus samostatný objekt");
    $sirka = max(2, strlen((string)count($pozice)));
    foreach ($pozice as $i => $p) {
        $polozka = $export['polozky'][$i];
        assertSame((string)($i + 1), $polozka['objectid'], "{$label}: položka {$i} odkazuje na objekt v pořadí tisku");
        $objekt = $export['objekty'][$polozka['objectid']];
        assertTrue(strpos($objekt['nazev'], str_pad((string)$p['poradi'], $sirka, '0', STR_PAD_LEFT) . ' ') === 0, "{$label}: název '{$objekt['nazev']}' začíná pořadím {$p['poradi']}");
        $o = $svetoveOhraniceni($objekt['vrcholy'], $polozka['matice']);
        assertNear((float)$p['X'], ($o['min'][0] + $o['max'][0]) / 2, 0.002, "{$label}: kus #{$p['poradi']} střed X");
        assertNear((float)$p['Y'], ($o['min'][1] + $o['max'][1]) / 2, 0.002, "{$label}: kus #{$p['poradi']} střed Y");
        assertNear(0.0, $o['min'][2], 0.0001, "{$label}: kus #{$p['poradi']} leží na podložce");
        $r = $rozmery[$p['objekt']];
        $ocekavane = !empty($p['otoceno']) ? [$r['y'], $r['x']] : [$r['x'], $r['y']];
        assertNear($ocekavane[0], $o['max'][0] - $o['min'][0], 0.002, "{$label}: kus #{$p['poradi']} šířka v X" . (!empty($p['otoceno']) ? ' (otočený)' : ''));
        assertNear($ocekavane[1], $o['max'][1] - $o['min'][1], 0.002, "{$label}: kus #{$p['poradi']} hloubka v Y");
        assertNear($r['z'], $o['max'][2] - $o['min'][2], 0.002, "{$label}: kus #{$p['poradi']} výška");
    }
};

// --- Sit3d: transformace ---
$m = Sit3d::zTransformu3mf('0 1.5 0 -1 0 0 0 0 1 50 60 2.5');
assertSame([0.0, -1.0, 0.0, 1.5, 0.0, 0.0, 0.0, 0.0, 1.0, 50.0, 60.0, 2.5], $m, 'Sit3d: transform 3MF (řádkový tvar) na sloupcovou matici');
assertSame('0 1.5 0 -1 0 0 0 0 1 50 60 2.5', Sit3d::naTransform3mf($m), 'Sit3d: zpět na transform 3MF');
assertSame(null, Sit3d::zTransformu3mf('1 0 0'), 'Sit3d: neúplný transform = null');
assertSame(Sit3d::jednotkova(), Sit3d::zTransformu3mf(''), 'Sit3d: prázdný transform = jednotková');
$s = Sit3d::slozit(Sit3d::posun(1, 2, 3), Sit3d::rotaceZ90());
assertSame([0.0, -1.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 1.0, 2.0, 3.0], $s, 'Sit3d: složení rotace a posunu');
$o = Sit3d::ohraniceni(Sit3d::kvadr(10, 20, 5)['vrcholy'], 8, Sit3d::rotaceZ90());
assertSame([[-20.0, 0.0, 0.0], [0.0, 10.0, 5.0]], [$o['min'], $o['max']], 'Sit3d: ohraničení kvádru otočeného o 90°');
assertSame([18.67, 22.33, 5.0, 0.01], [ImportModelu::nahoru2(18.660254), ImportModelu::nahoru2(22.320508), ImportModelu::nahoru2(5.0000001), ImportModelu::nahoru2(0.001)], 'Import: rozměry na setiny nahoru (šum float32 se zanedbá)');
ok('Sit3d: transformace 3MF, složení, ohraničení, zaokrouhlení rozměrů nahoru.');

// --- STL ---
$troj = $kvadrTrojuhelniky(10, 20, 5, 100, 100, 7);
foreach (['binární' => $stlBinarni($troj), 'binární s hlavičkou solid' => $stlBinarni($troj, 'solid exportovano jako binarni'), 'ASCII' => $stlAscii($troj)] as $druh => $obsah) {
    $cesta = $docasny('díl.stl', $obsah);
    $model = ImportModelu::nacti($cesta, 'C:\\modely\\díl.stl', true);
    assertSame(['stl', 1, 'díl', 1], [$model['typ'], count($model['objekty']), $model['objekty'][0]['nazev'], $model['objekty'][0]['pocet']], "STL {$druh}: typ, 1 objekt, název ze souboru, 1 instance");
    $r = $model['objekty'][0]['rozmery'];
    assertSame([10.0, 20.0, 5.0], [round($r['x'], 4), round($r['y'], 4), round($r['z'], 4)], "STL {$druh}: rozměry z ohraničení");
    assertSame([8, 12], [$model['objekty'][0]['sit']['pocet_vrcholu'], $model['objekty'][0]['sit']['pocet_trojuhelniku']], "STL {$druh}: sloučené vrcholy (8) a 12 trojúhelníků");
    $vstup = ImportModelu::objektyProVstup($model);
    assertSame([['x' => 10.0, 'y' => 20.0, 'z' => 5.0, 'instances' => ['d' => 1], 'sit' => 0, 'nazev' => 'díl']], $vstup, "STL {$druh}: objekty pro formulář");
    // Bez trojúhelníků (jen import do formuláře) stejné rozměry.
    $jenRozmery = ImportModelu::nacti($cesta, 'díl.stl', false);
    assertSame($vstup, ImportModelu::objektyProVstup($jenRozmery), "STL {$druh}: import bez trojúhelníků dá stejné objekty");
}
$ocekavejChybu(function () use ($docasny) { ImportModelu::nacti($docasny('prazdny.stl', ''), 'prazdny.stl'); }, 400, 'neplatny_soubor', 'STL prázdný soubor');
$ocekavejChybu(function () use ($docasny) { ImportModelu::nacti($docasny('nesmysl.stl', str_repeat('x', 200)), 'nesmysl.stl'); }, 415, 'nepodporovany_soubor', 'Nesmysl není STL ani 3MF');
$ocekavejChybu(function () use ($docasny) { ImportModelu::nacti($docasny('bez.stl', "solid prazdny\nendsolid prazdny\n"), 'bez.stl'); }, 400, 'neplatny_soubor', 'ASCII STL bez trojúhelníků');
$ocekavejChybu(function () use ($docasny) { ImportModelu::nacti($docasny('spatny.stl', "solid x\nfacet normal 0 0 1\nouter loop\nvertex 0 0 0\nvertex 1 a 0\n"), 'spatny.stl'); }, 400, 'neplatny_soubor', 'ASCII STL s neplatným číslem');
$ocekavejChybu(function () use ($docasny, $stlBinarni, $troj) { ImportModelu::nacti($docasny('neni.3mf', $stlBinarni($troj)), 'neni.3mf'); }, 400, 'neplatny_soubor', 'Přípona .3mf bez ZIPu');
putenv('IMPORT_MAX_MB=0.0001');
$ocekavejChybu(function () use ($docasny, $stlBinarni, $troj) { ImportModelu::nacti($docasny('velky.stl', $stlBinarni($troj)), 'velky.stl'); }, 413, 'soubor_prilis_velky', 'Limit velikosti souboru (IMPORT_MAX_MB)');
putenv('IMPORT_MAX_MB');
ok('Import STL: binární i ASCII (i binární s hlavičkou „solid“), název ze souboru, rozměry, sloučené vrcholy, chybové stavy, limit velikosti.');

// --- 3MF ---
$model = null;
if (!$maZip) {
    $ocekavejChybu(function () use ($docasny) { ImportModelu::nacti($docasny('a.3mf', "PK\x03\x04nic"), 'a.3mf'); }, 500, 'chybi_zip', 'Bez rozšíření zip: srozumitelná chyba');
    $ocekavejChybu(function () { Export3mf::zVysledku(['pozice' => [['poradi' => 1, 'objekt' => 0, 'X' => 10, 'Y' => 10]]], [['x' => 5, 'y' => 5, 'z' => 5]]); }, 500, 'chybi_zip', 'Export bez rozšíření zip');
    ok('3MF PŘESKOČENO: PHP nemá rozšíření zip (ověřena jen chyba chybi_zip). Celé testy: php -d extension=zip tests/run.php');
} else {
    // Hlavní testovací 3MF: objekt 1 = kvádr 10×20×5 se středem v počátku, 2 instance otočené o 90° a v X zvětšené 1,5×
    // (rozměry 20×15×5), per-object nastavení z PrusaSliceru; objekt 2 = sestava ze 2 komponent (kvádr 4×4×4, druhý
    // posunutý o 10 mm v X => 14×4×4); objekt 4 není v build (ignoruje se).
    $resources = '<object id="1" type="model">' . $meshKvadru(10, 20, 5, -5, -10, -2.5) . '</object>'
        . '<object id="3" type="model">' . $meshKvadru(4, 4, 4) . '</object>'
        . '<object id="2" name="Sestava" type="model"><components><component objectid="3"/><component objectid="3" transform="1 0 0 0 1 0 0 0 1 10 0 0"/></components></object>'
        . '<object id="4" type="model">' . $meshKvadru(1, 1, 1) . '</object>';
    $build = '<item objectid="1" transform="0 1.5 0 -1 0 0 0 0 1 50 60 2.5"/>'
        . '<item objectid="2" transform="1 0 0 0 1 0 0 0 1 100 100 0"/>'
        . '<item objectid="1" transform="0 1.5 0 -1 0 0 0 0 1 120 60 2.5"/>';
    $konfigurace = '<?xml version="1.0" encoding="UTF-8"?><config>'
        . '<object id="1" instances_count="2"><metadata type="object" key="name" value="Držák"/>'
        . '<metadata type="object" key="bottom_solid_layers" value="7"/>'
        . '<volume firstid="0" lastid="11"><metadata type="volume" key="name" value="díl"/><metadata type="volume" key="volume_type" value="ModelPart"/></volume></object>'
        . '</config>';
    $hlavni3mf = $zabal('hlavni.3mf', [
        '_rels/.rels' => $rels,
        '3D/3dmodel.model' => $model3mf($resources, $build),
        'Metadata/Slic3r_PE_model.config' => $konfigurace,
    ]);
    $model = ImportModelu::nacti($hlavni3mf, 'hlavni.3mf', true);
    assertSame(['3mf', 2, []], [$model['typ'], count($model['objekty']), $model['varovani']], '3MF: 2 objekty (komponenty ani objekt mimo build nejsou samostatné objekty)');
    [$drzak, $sestava] = $model['objekty'];
    assertSame(['Držák', 2, [['bottom_solid_layers', '7']]], [$drzak['nazev'], $drzak['pocet'], $drzak['metadata']], '3MF: název z Slic3r_PE_model.config, 2 instance (2 položky), per-object metadata');
    assertSame([20.0, 15.0, 5.0], array_map(function ($v) { return round($v, 4); }, array_values($drzak['rozmery'])), '3MF: rozměry po rotaci a měřítku z transformu položky');
    assertSame([['prvni' => 0, 'posledni' => 11, 'metadata' => [['name', 'díl'], ['volume_type', 'ModelPart']]]], $drzak['objemy'], '3MF: části objektu (volume) z konfigurace');
    assertSame(['Sestava', 1, 16, 24], [$sestava['nazev'], $sestava['pocet'], $sestava['sit']['pocet_vrcholu'], $sestava['sit']['pocet_trojuhelniku']], '3MF: název z atributu name, komponenty spojené do jedné sítě');
    assertSame([14.0, 4.0, 4.0], array_map(function ($v) { return round($v, 4); }, array_values($sestava['rozmery'])), '3MF: rozměry sestavy z komponent (s jejich transformací)');
    assertSame([
        ['x' => 20.0, 'y' => 15.0, 'z' => 5.0, 'instances' => ['d' => 2], 'sit' => 0, 'nazev' => 'Držák'],
        ['x' => 14.0, 'y' => 4.0, 'z' => 4.0, 'instances' => ['d' => 1], 'sit' => 1, 'nazev' => 'Sestava'],
    ], ImportModelu::objektyProVstup($model), '3MF: objekty pro formulář');

    // Jednotky (cm), rotace 30° (rozměry na setiny nahoru), instance s různým natočením = varování; bez názvu = soubor + pořadí.
    $cm3mf = $zabal('centimetry.3mf', [
        '_rels/.rels' => $rels,
        '3D/3dmodel.model' => $model3mf('<object id="7" type="model">' . $meshKvadru(1, 2, 0.5) . '</object>',
            '<item objectid="7" transform="0.8660254 0.5 0 -0.5 0.8660254 0 0 0 1 1 1 0"/><item objectid="7"/>', 'centimeter'),
    ]);
    $cm = ImportModelu::nacti($cm3mf, 'centimetry.3mf');
    assertSame([['x' => 18.67, 'y' => 22.33, 'z' => 5.0, 'instances' => ['d' => 2], 'sit' => 0, 'nazev' => 'centimetry 1']], ImportModelu::objektyProVstup($cm), '3MF: jednotky cm na mm, rotace 30°, název ze souboru');
    assertSame(1, count($cm['varovani']), '3MF: varování u instancí s různým natočením');

    // Komponenta v jiném souboru balíčku (rozšíření production, p:path, jako Bambu/Orca).
    $prod3mf = $zabal('production.3mf', [
        '_rels/.rels' => $rels,
        '3D/3dmodel.model' => $model3mf('<object id="1" name="Díl s cestou" type="model"><components><component p:path="/3D/Objects/dil.model" objectid="5" transform="1 0 0 0 1 0 0 0 1 3 3 3"/></components></object>', '<item objectid="1"/>'),
        '3D/Objects/dil.model' => $model3mf('<object id="5" type="model">' . $meshKvadru(6, 7, 8) . '</object>', ''),
    ]);
    $prod = ImportModelu::objektyProVstup(ImportModelu::nacti($prod3mf, 'production.3mf', true));
    assertSame([['x' => 6.0, 'y' => 7.0, 'z' => 8.0, 'instances' => ['d' => 1], 'sit' => 0, 'nazev' => 'Díl s cestou']], $prod, '3MF: komponenta v jiném souboru (p:path)');

    // Chybové stavy.
    $chyba3mf = function (string $nazev, array $soubory, string $kod, string $label, int $status = 400) use ($zabal, $ocekavejChybu): void {
        $cesta = $zabal($nazev, $soubory);
        $ocekavejChybu(function () use ($cesta, $nazev) { ImportModelu::nacti($cesta, $nazev, true); }, $status, $kod, $label);
    };
    $chyba3mf('bez_modelu.3mf', ['[Content_Types].xml' => '<Types/>'], 'neplatny_soubor', '3MF bez 3D/3dmodel.model');
    $chyba3mf('zlomeny.3mf', ['3D/3dmodel.model' => '<model><resources><object id="1"><mesh><vertices><vertex x="0" y="0" z="0"/>'], 'neplatny_soubor', '3MF s neplatným XML');
    $chyba3mf('index.3mf', ['3D/3dmodel.model' => $model3mf('<object id="1"><mesh><vertices><vertex x="0" y="0" z="0"/></vertices><triangles><triangle v1="0" v2="1" v3="2"/></triangles></mesh></object>', '<item objectid="1"/>')], 'neplatny_soubor', '3MF: trojúhelník na neexistující vrchol');
    $chyba3mf('bez_build.3mf', ['3D/3dmodel.model' => $model3mf('<object id="1">' . $meshKvadru(1, 1, 1) . '</object>', '')], 'neplatny_soubor', '3MF bez položek build');
    $chyba3mf('chybi_objekt.3mf', ['3D/3dmodel.model' => $model3mf('<object id="1">' . $meshKvadru(1, 1, 1) . '</object>', '<item objectid="9"/>')], 'neplatny_soubor', '3MF: položka na neexistující objekt');
    $chyba3mf('cyklus.3mf', ['3D/3dmodel.model' => $model3mf('<object id="1"><components><component objectid="1"/></components></object>', '<item objectid="1"/>')], 'neplatny_soubor', '3MF: cyklus komponent');
    $chyba3mf('transform.3mf', ['3D/3dmodel.model' => $model3mf('<object id="1">' . $meshKvadru(1, 1, 1) . '</object>', '<item objectid="1" transform="1 0 0"/>')], 'neplatny_soubor', '3MF: neplatný transform');
    putenv('IMPORT_MAX_ROZBALENO_MB=0.0001');
    $ocekavejChybu(function () use ($hlavni3mf) { ImportModelu::nacti($hlavni3mf, 'hlavni.3mf'); }, 413, 'soubor_prilis_velky', '3MF: limit velikosti po rozbalení (zip bomba)');
    putenv('IMPORT_MAX_ROZBALENO_MB');
    ok('Import 3MF: objekty z build, instance = položky, rozměry po transformu (rotace, měřítko, jednotky), komponenty (i p:path), název a per-object metadata z PrusaSliceru, chybové stavy.');

    // --- Export ---
    $rozmeryModelu = function (array $model): array {
        return array_map(function ($o) { return $o['rozmery']; }, $model['objekty']);
    };
    $overeno = VstupVypoctu::over(['objekty' => ImportModelu::objektyProVstup($model)]);
    $vysledek = Api::vypocet($overeno);
    assertSame(3, $vysledek['pocet_instanci'], 'Export: všechny 3 kusy z modelu na podložce');
    $export = Export3mf::zVysledku($vysledek, $overeno['objekty'], $model);
    assertSame([Export3mf::TYP_3MF, 'sekvencni-tisk.3mf'], [$export['typ'], $export['nazev']], 'Export: jedna podložka = 3MF');
    $e = $nactiExport($export['obsah']);
    foreach (['[Content_Types].xml', '_rels/.rels', '3D/3dmodel.model', 'Metadata/Slic3r_PE_model.config'] as $nutny) {
        assertTrue(in_array($nutny, $e['soubory'], true), "Export: balíček obsahuje {$nutny}");
    }
    assertTrue(!in_array('Metadata/Slic3r_PE.config', $e['soubory'], true), 'Export: bez Slic3r_PE.config (complete_objects zapíná uživatel v profilu)');
    $overExport($e, $vysledek['pozice'], $rozmeryModelu($model), 'Export modelu');
    foreach ($vysledek['pozice'] as $p) {
        $id = (string)$p['poradi'];
        $konf = $e['konfigurace'][$id];
        $nazev = sprintf('%02d %s', $p['poradi'], $p['nazev']);
        assertSame($nazev, $konf['metadata']['name'], "Export: název v Slic3r_PE_model.config ({$nazev})");
        assertSame($nazev, $e['objekty'][$id]['nazev'], 'Export: název i v atributu name objektu');
        if ($p['objekt'] === 0) {
            assertSame('7', $konf['metadata']['bottom_solid_layers'] ?? null, 'Export: per-object nastavení ze vstupu zachované');
            assertSame([['prvni' => 0, 'posledni' => 11, 'metadata' => ['name' => 'díl', 'volume_type' => 'ModelPart']]], $konf['objemy'], 'Export: části objektu ze vstupu');
            $polozka = $e['polozky'][$p['poradi'] - 1];
            assertSame([0.0, -1.0, 1.5, 0.0], [round($polozka['matice'][0], 6), round($polozka['matice'][1], 6), round($polozka['matice'][3], 6), round($polozka['matice'][4], 6)], 'Export: rotace a měřítko z importu zůstanou v transformu položky');
        } else {
            assertSame([['prvni' => 0, 'posledni' => 23, 'metadata' => ['name' => 'Sestava']]], $konf['objemy'], 'Export: objekt bez částí = jedna část přes celou síť');
            assertSame(24, $e['objekty'][$id]['trojuhelniku'], 'Export: síť sestavy (2 komponenty)');
        }
    }

    // Rotace o 90° (kvádry bez modelu): lišta 15×100×50 se otočí, v exportu je otočená kolem Z.
    $lista = ['x' => 15, 'y' => 100, 'z' => 50, 'instances' => ['d' => 'max'], 'rotace' => true, 'nazev' => 'lišta'];
    $overeno = VstupVypoctu::over(['objekty' => [$lista]]);
    $vysledek = Api::vypocet($overeno);
    assertSame([6, [0]], [$vysledek['pocet_instanci'], $vysledek['otocene_objekty']], 'Export rotace: 6 otočených lišt');
    $e = $nactiExport(Export3mf::zVysledku($vysledek, $overeno['objekty'])['obsah']);
    $overExport($e, $vysledek['pozice'], [['x' => 15.0, 'y' => 100.0, 'z' => 50.0]], 'Export kvádrů s rotací');
    assertSame([8, 12], [count($e['objekty']['1']['vrcholy']), $e['objekty']['1']['trojuhelniku']], 'Export bez modelu: kvádr (8 vrcholů, 12 trojúhelníků)');
    assertSame([0.0, -1.0, 1.0, 0.0], [$e['polozky'][0]['matice'][0], $e['polozky'][0]['matice'][1], $e['polozky'][0]['matice'][3], $e['polozky'][0]['matice'][4]], 'Export: otočený kus má v transformu rotaci 90° kolem Z');

    // Rotace se sítí ze STL, jejíž počátek není ve středu (roh v 3, 4, 5).
    $stlLista = ImportModelu::nacti($docasny('lista.stl', $stlBinarni($kvadrTrojuhelniky(15, 100, 50, 3, 4, 5))), 'lista.stl', true);
    $overeno = VstupVypoctu::over(['objekty' => [['sit' => 0] + $lista]]);
    $vysledek = Api::vypocet($overeno);
    $e = $nactiExport(Export3mf::zVysledku($vysledek, $overeno['objekty'], $stlLista)['obsah']);
    $overExport($e, $vysledek['pozice'], [['x' => 15.0, 'y' => 100.0, 'z' => 50.0]], 'Export sítě STL s rotací');
    assertSame('01 lišta', $e['objekty']['1']['nazev'], 'Export: název z formuláře má přednost před názvem souboru');

    // Více podložek: ZIP s 3MF na podložku (pořadí na každé od 01), podlozka=N jen jedna podložka.
    $overeno = VstupVypoctu::over(['objekty' => [['x' => 60, 'y' => 60, 'z' => 10, 'instances' => ['d' => 8]]], 'options' => ['vice_podlozek' => true]]);
    $vysledek = Api::vypocet($overeno);
    assertTrue($vysledek['pocet_podlozek'] >= 2, 'Export více podložek: scénář má víc podložek');
    $export = Export3mf::zVysledku($vysledek, $overeno['objekty']);
    $ocekavaneNazvy = array_map(function ($p) { return "sekvencni-tisk_podlozka_{$p}.3mf"; }, range(1, $vysledek['pocet_podlozek']));
    assertSame([Export3mf::TYP_ZIP, 'sekvencni-tisk.zip', $ocekavaneNazvy], [$export['typ'], $export['nazev'], array_keys($export['soubory'])], 'Export více podložek: ZIP s 3MF na podložku');
    $zipCesta = $docasny('podlozky.zip', $export['obsah']);
    $zip = new ZipArchive();
    $zip->open($zipCesta);
    assertSame($ocekavaneNazvy, array_map(function ($i) use ($zip) { return $zip->getNameIndex($i); }, range(0, $zip->numFiles - 1)), 'Export více podložek: obsah ZIPu');
    $zip->close();
    foreach ($vysledek['podlozky'] as $souhrn) {
        $p = $souhrn['podlozka'];
        $naPodlozce = array_values(array_filter($vysledek['pozice'], function ($kus) use ($p) { return $kus['podlozka'] === $p; }));
        $overExport($nactiExport($export['soubory']["sekvencni-tisk_podlozka_{$p}.3mf"]), $naPodlozce, [['x' => 60.0, 'y' => 60.0, 'z' => 10.0]], "Export podložky {$p}");
    }
    $jedna = Export3mf::zVysledku($vysledek, $overeno['objekty'], null, 2);
    assertSame([Export3mf::TYP_3MF, 'sekvencni-tisk_podlozka_2.3mf'], [$jedna['typ'], $jedna['nazev']], 'Export: podlozka=2 = jen 3MF podložky 2');
    $e = $ocekavejChybu(function () use ($vysledek, $overeno) { Export3mf::zVysledku($vysledek, $overeno['objekty'], null, 9); }, 400, 'neplatny_vstup', 'Export: neexistující podložka');
    assertSame('podlozka', $e->details['chyby'][0]['pole'], 'Export: chyba u pole podlozka');

    // Chybové stavy exportu.
    $vysledekModelu = Api::vypocet(VstupVypoctu::over(['objekty' => ImportModelu::objektyProVstup($model)]));
    $e = $ocekavejChybu(function () use ($vysledekModelu, $model) { Export3mf::zVysledku($vysledekModelu, [['x' => 20, 'y' => 15, 'z' => 5, 'sit' => 7], ['x' => 14, 'y' => 4, 'z' => 4, 'sit' => 1]], $model); }, 400, 'neplatny_vstup', 'Export: sit mimo model');
    assertSame('objekty[0].sit', $e->details['chyby'][0]['pole'], 'Export: chyba u objekty[0].sit');
    $e = $ocekavejChybu(function () use ($vysledekModelu, $model) { Export3mf::zVysledku($vysledekModelu, [['x' => 19, 'y' => 15, 'z' => 5, 'sit' => 0], ['x' => 14, 'y' => 4, 'z' => 4, 'sit' => 1]], $model); }, 400, 'neplatny_vstup', 'Export: síť větší než zadané rozměry');
    assertSame('objekty[0].x', $e->details['chyby'][0]['pole'], 'Export: chyba u rozměru, který síť přesahuje');
    $ocekavejChybu(function () use ($vysledekModelu) { Export3mf::zVysledku($vysledekModelu, [['x' => 20, 'y' => 15, 'z' => 5, 'sit' => 0], ['x' => 14, 'y' => 4, 'z' => 4]]); }, 400, 'neplatny_vstup', 'Export: sit bez nahraného modelu');
    $ocekavejChybu(function () { Export3mf::zVysledku(['pozice' => [], 'pocet_podlozek' => 0], []); }, 400, 'nic_k_exportu', 'Export: žádný kus');

    // Kus vyčnívající z podložky jen o zaokrouhlení se posune dovnitř (PrusaSlicer by ho tiše vynechal).
    $kraj = ['pozice' => [['poradi' => 1, 'podlozka' => 1, 'objekt' => 0, 'X' => 174.005, 'Y' => 175.003, 'otoceno' => false]], 'pocet_podlozek' => 1, 'printer' => ['x' => 179.0, 'y' => 180.0]];
    $e = $nactiExport(Export3mf::zVysledku($kraj, [['x' => 10, 'y' => 10, 'z' => 5]])['obsah']);
    $o = $svetoveOhraniceni($e['objekty']['1']['vrcholy'], $e['polozky'][0]['matice']);
    assertSame([179.0, 180.0], [round($o['max'][0], 6), round($o['max'][1], 6)], 'Export: kus o zaokrouhlení za okrajem podložky posunutý dovnitř');
    ok('Export 3MF: každý kus samostatný objekt v pořadí tisku, středy na X/Y, z = 0, rotace 90° i rotace/měřítko z importu, kvádry bez modelu, názvy 01 …, per-object metadata, více podložek (ZIP / podlozka), chybové stavy.');
}

// --- Validace `sit` ve vstupu (společná s CLI a API v1) ---
$e = $ocekavejChybu(function () { VstupVypoctu::over(['objekty' => [['x' => 10, 'y' => 10, 'z' => 10, 'sit' => -1]]]); }, 400, 'neplatny_vstup', 'Vstup: sit záporné');
assertSame('objekty[0].sit', $e->details['chyby'][0]['pole'], 'Vstup: chyba u objekty[0].sit');
$ocekavejChybu(function () { VstupVypoctu::over(['objekty' => [['x' => 10, 'y' => 10, 'z' => 10, 'sit' => '1']]]); }, 400, 'neplatny_vstup', 'Vstup POST: sit jako text');
assertSame(2, VstupVypoctu::over(['objekty' => [['x' => '10', 'y' => '10', 'z' => '10', 'sit' => '2']]], true)['objekty'][0]['sit'], 'Vstup GET: sit z textu na číslo');
assertTrue(!isset(VstupVypoctu::over(['objekty' => [['x' => '10', 'y' => '10', 'z' => '10', 'sit' => '']]], true)['objekty'][0]['sit']) || VstupVypoctu::over(['objekty' => [['x' => '10', 'y' => '10', 'z' => '10', 'sit' => '']]], true)['objekty'][0]['sit'] === '', 'Vstup GET: prázdné sit = bez sítě');
ok('Vstup: pole sit (index objektu importovaného modelu) ověřené společně s CLI a API v1.');

// --- API v1: import.php a export.php ---
$stlAsciiObsah = $stlAscii($kvadrTrojuhelniky(10, 20, 5, 100, 100, 7));
$r = $spustEndpoint('api/v1/import.php', 'POST', ['nazev' => 'díl.stl'], $stlAsciiObsah);
assertSame(200, $r['status'], "v1 import STL (tělo): status ({$r['out']}{$r['err']})");
assertSame([['x' => 10, 'y' => 20, 'z' => 5, 'instances' => ['d' => 1], 'sit' => 0, 'nazev' => 'díl']], $r['json']['objekty'] ?? null, 'v1 import STL: objekty pro vstup');
assertSame(['nazev' => 'díl.stl', 'typ' => 'stl'], $r['json']['soubor'] ?? null, 'v1 import STL: soubor');
assertSame('', $r['err'], 'v1 import STL: bez warningů');
$stlCesta = $docasny('nahrany.stl', $stlBinarni($kvadrTrojuhelniky(10, 20, 5)));
$r = $spustEndpoint('api/v1/import.php', 'POST', [], null, ['API_REQUIRE_KEY' => '0'], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'], ['soubor' => ['name' => 'C:\\fakepath\\nahraný.stl', 'type' => 'model/stl', 'tmp_name' => $stlCesta, 'error' => UPLOAD_ERR_OK, 'size' => filesize($stlCesta)]]);
assertSame([200, 'nahraný'], [$r['status'], $r['json']['objekty'][0]['nazev'] ?? null], 'v1 import multipart: název souboru bez cesty');
$chybaV1($spustEndpoint('api/v1/import.php', 'POST', [], ''), 400, 'chybi_soubor', 'v1 import bez souboru');
$chybaV1($spustEndpoint('api/v1/import.php', 'GET'), 405, 'nepovolena_metoda', 'v1 import GET');
$chybaV1($spustEndpoint('api/v1/import.php', 'POST', ['nazev' => 'x.obj'], str_repeat('v 1 2 3', 40)), 415, 'nepodporovany_soubor', 'v1 import nepodporovaný soubor');
$chybaV1($spustEndpoint('api/v1/import.php', 'POST', [], $stlAsciiObsah, ['API_REQUIRE_KEY' => '0', 'IMPORT_MAX_MB' => '0.0001']), 413, 'soubor_prilis_velky', 'v1 import nad limit');
$chybaV1($spustEndpoint('api/v1/import.php', 'POST', [], null, ['API_REQUIRE_KEY' => '0'], [], ['soubor' => ['name' => 'x.stl', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]]), 413, 'soubor_prilis_velky', 'v1 import nad upload_max_filesize');
$chybaV1($spustEndpoint('api/v1/import.php', 'POST', [], null, ['API_REQUIRE_KEY' => '0'], [], ['soubor' => ['name' => 'x.stl', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0]]), 400, 'chyba_nahrani', 'v1 import přerušené nahrání');
$r = $spustEndpoint('api/v1/index.php');
assertTrue(isset($r['json']['endpointy']['import'], $r['json']['endpointy']['export']), 'v1 rozcestník: import a export');
$vstupExportu = json_encode(['objekty' => [['x' => 30, 'y' => 20, 'z' => 10, 'instances' => ['d' => 3], 'nazev' => 'kostka']]]);
if ($maZip) {
    $hlavni3mfCesta = $hlavni3mf;
    $r = $spustEndpoint('api/v1/import.php', 'POST', [], null, ['API_REQUIRE_KEY' => '0'], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'], ['soubor' => ['name' => 'hlavni.3mf', 'tmp_name' => $hlavni3mfCesta, 'error' => UPLOAD_ERR_OK, 'size' => filesize($hlavni3mfCesta)]]);
    assertSame([200, json_decode(json_encode(ImportModelu::objektyProVstup($model)), true)], [$r['status'], $r['json']['objekty'] ?? null], 'v1 import 3MF multipart');
    $r = $spustEndpoint('api/v1/export.php', 'POST', [], $vstupExportu);
    assertSame([200, Export3mf::TYP_3MF, '3', '1', 'ok'], [$r['status'], $r['hlavicky']['Content-Type'] ?? null, $r['hlavicky']['X-Pocet-Kusu'] ?? null, $r['hlavicky']['X-Pocet-Podlozek'] ?? null, $r['hlavicky']['X-Kontrola-Kolizi'] ?? null], "v1 export JSON: 3MF a hlavičky ({$r['err']})");
    assertTrue(strpos($r['hlavicky']['Content-Disposition'] ?? '', 'attachment; filename="sekvencni-tisk.3mf"') === 0, 'v1 export: Content-Disposition');
    assertSame(3, count($nactiExport($r['out'])['polozky']), 'v1 export JSON: 3 kusy v 3MF');
    assertSame('', $r['err'], 'v1 export: bez warningů');
    $r = $spustEndpoint('api/v1/export.php', 'GET', ['objekty' => [['x' => '30', 'y' => '20', 'z' => '10', 'instances' => ['d' => '2']]]]);
    assertSame([200, '2'], [$r['status'], $r['hlavicky']['X-Pocet-Kusu'] ?? null], 'v1 export GET');
    // Import + výpočet + export v jednom požadavku: model bez vstupu, model se vstupem (sit, nastavení).
    $r = $spustEndpoint('api/v1/export.php', 'POST', [], null, ['API_REQUIRE_KEY' => '0'], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'], ['soubor' => ['name' => 'hlavni.3mf', 'tmp_name' => $hlavni3mfCesta, 'error' => UPLOAD_ERR_OK, 'size' => filesize($hlavni3mfCesta)]]);
    assertSame([200, '3'], [$r['status'], $r['hlavicky']['X-Pocet-Kusu'] ?? null], "v1 export multipart: objekty z modelu ({$r['out']})");
    $e = $nactiExport($r['out']);
    assertSame(48, array_sum(array_column($e['objekty'], 'trojuhelniku')), 'v1 export multipart: sítě modelu (2× držák po 12 + sestava 24 trojúhelníků)');
    $r = $spustEndpoint('api/v1/export.php', 'POST', [], null, ['API_REQUIRE_KEY' => '0'], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'], ['soubor' => ['name' => 'hlavni.3mf', 'tmp_name' => $hlavni3mfCesta, 'error' => UPLOAD_ERR_OK, 'size' => filesize($hlavni3mfCesta)]], ['vstup' => json_encode(['objekty' => [['x' => 14, 'y' => 4, 'z' => 4, 'instances' => ['d' => 5], 'sit' => 1, 'nazev' => 'sestava']]])]);
    assertSame([200, '5'], [$r['status'], $r['hlavicky']['X-Pocet-Kusu'] ?? null], 'v1 export multipart se vstupem (sit, počet)');
    assertSame('05 sestava', $nactiExport($r['out'])['konfigurace']['5']['metadata']['name'] ?? null, 'v1 export multipart: název z vstupu');
    $chybaV1($spustEndpoint('api/v1/export.php', 'POST', [], null, ['API_REQUIRE_KEY' => '0'], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'], ['soubor' => ['name' => 'hlavni.3mf', 'tmp_name' => $hlavni3mfCesta, 'error' => UPLOAD_ERR_OK, 'size' => filesize($hlavni3mfCesta)]], ['vstup' => '{"objekty":[{"x":5,"y":4,"z":4,"sit":1}]}']), 400, 'neplatny_vstup', 'v1 export: síť větší než rozměry');
    $chybaV1($spustEndpoint('api/v1/export.php', 'POST', [], null, ['API_REQUIRE_KEY' => '0'], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'], [], ['vstup' => 'nejson']), 400, 'neplatny_json', 'v1 export: vstup není JSON');
    $r = $spustEndpoint('api/v1/export.php', 'POST', [], json_encode(['objekty' => [['x' => 60, 'y' => 60, 'z' => 10, 'instances' => ['d' => 8]]], 'options' => ['vice_podlozek' => true]]));
    assertSame([200, Export3mf::TYP_ZIP], [$r['status'], $r['hlavicky']['Content-Type'] ?? null], 'v1 export více podložek = ZIP');
    $r = $spustEndpoint('api/v1/export.php', 'POST', ['podlozka' => '2'], json_encode(['objekty' => [['x' => 60, 'y' => 60, 'z' => 10, 'instances' => ['d' => 8]]], 'options' => ['vice_podlozek' => true]]));
    assertSame([200, Export3mf::TYP_3MF], [$r['status'], $r['hlavicky']['Content-Type'] ?? null], 'v1 export podlozka=2 = 3MF');
} else {
    $chybaV1($spustEndpoint('api/v1/export.php', 'POST', [], $vstupExportu), 500, 'chybi_zip', 'v1 export bez rozšíření zip');
}
$chybaV1($spustEndpoint('api/v1/export.php', 'POST', ['podlozka' => 'abc'], $vstupExportu), 400, 'neplatny_vstup', 'v1 export: podlozka není číslo');
$chybaV1($spustEndpoint('api/v1/export.php', 'POST', [], '{"objekty":[{"x":10,"y":10,"z":10,"sit":"a"}]}'), 400, 'neplatny_vstup', 'v1 export: sit není číslo');
$chybaV1($spustEndpoint('api/v1/export.php', 'PUT', [], $vstupExportu), 405, 'nepovolena_metoda', 'v1 export PUT');
$chybaV1($spustEndpoint('api/v1/export.php', 'POST', [], null, ['API_REQUIRE_KEY' => '0'], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x']), 400, 'chybi_soubor', 'v1 export multipart bez souboru i objektů');
ok('API v1: import.php (tělo i multipart, limity, chyby v1), export.php (JSON, GET, multipart import+výpočet+export, více podložek, chyby v1), rozcestník.');

// --- UI: import přes index.php (úložiště s tokenem) a export 3MF ---
$uloziste = $tmpTest . DIRECTORY_SEPARATOR . 'uloziste';
$envUi = ['API_REQUIRE_KEY' => '0', 'IMPORT_TMP_DIR' => $uloziste];
$r = $spustEndpoint('index.php', 'POST', ['akce' => 'import_modelu'], null, $envUi, ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'], ['soubor' => ['name' => 'nahrany.stl', 'tmp_name' => $stlCesta, 'error' => UPLOAD_ERR_OK, 'size' => filesize($stlCesta)]]);
assertSame(200, $r['status'], "UI import: status ({$r['out']}{$r['err']})");
$token = $r['json']['token'] ?? '';
assertTrue(UlozisteModelu::jePlatnyToken($token), 'UI import: token 32 hex');
assertTrue(is_file($uloziste . DIRECTORY_SEPARATOR . $token . '.model'), 'UI import: model uložený pod tokenem');
assertSame([['x' => 10, 'y' => 20, 'z' => 5, 'instances' => ['d' => 1], 'sit' => 0, 'nazev' => 'nahrany']], $r['json']['objekty'] ?? null, 'UI import: objekty pro formulář');
assertSame('', $r['err'], 'UI import: bez warningů');
$chybaV1($spustEndpoint('index.php', 'GET', ['akce' => 'import_modelu'], null, $envUi), 405, 'nepovolena_metoda', 'UI import GET');
$chybaV1($spustEndpoint('index.php', 'POST', ['akce' => 'import_modelu'], null, $envUi, ['CONTENT_TYPE' => 'multipart/form-data; boundary=x']), 400, 'chybi_soubor', 'UI import bez souboru');
$getUi = ['sit_token' => $token, 'objekty' => [['x' => '10', 'y' => '20', 'z' => '5', 'instances' => ['d' => '2'], 'sit' => '0', 'nazev' => 'nahrany'], ['x' => '15', 'y' => '15', 'z' => '5', 'instances' => ['d' => '1']]]];
$html = $spustEndpoint('index.php', 'GET', $getUi, null, $envUi)['out'];
assertTrue(strpos($html, 'id="download_3mf"') !== false && strpos($html, 'name="sit_token" value="' . $token . '"') !== false, 'UI: tlačítko Stáhnout 3MF a token ve formuláři');
assertTrue(strpos($html, 'id="import_modelu"') !== false && strpos($html, 'accept=".3mf,.stl"') !== false, 'UI: tlačítko Import 3MF/STL');
assertTrue(!preg_match('/(Warning|Notice|Deprecated|Fatal error)/', $html), 'UI s tokenem: bez warningů');
$htmlSpatny = $spustEndpoint('index.php', 'GET', ['sit_token' => '../../etc', 'objekty' => $getUi['objekty']], null, $envUi)['out'];
assertTrue(strpos($htmlSpatny, 'name="sit_token" value=""') !== false, 'UI: neplatný token se zahodí');
if ($maZip) {
    $r = $spustEndpoint('index.php', 'GET', $getUi + ['format' => '3mf'], null, $envUi);
    assertSame([200, Export3mf::TYP_3MF], [$r['status'], $r['hlavicky']['Content-Type'] ?? null], "UI export 3MF ({$r['err']})");
    $e = $nactiExport($r['out']);
    assertSame(3, count($e['polozky']), 'UI export: 3 kusy');
    $nazvy = array_map(function ($k) { return $k['metadata']['name']; }, $e['konfigurace']);
    assertSame(2, count(array_filter($nazvy, function ($n) { return substr($n, 3) === 'nahrany'; })), 'UI export: síť z nahraného modelu u objektu se sit');
    $r = $spustEndpoint('index.php', 'GET', ['sit_token' => str_repeat('a', 32), 'objekty' => $getUi['objekty'], 'format' => '3mf'], null, $envUi);
    $chybaV1($r, 410, 'model_vyprsel', 'UI export: model už není');
    $r = $spustEndpoint('index.php', 'GET', ['objekty' => [['x' => '15', 'y' => '15', 'z' => '5', 'instances' => ['d' => '2']]], 'format' => '3mf'], null, $envUi);
    assertSame([200, 2], [$r['status'], count($nactiExport($r['out'])['polozky'])], 'UI export bez modelu: kvádry');
} else {
    $chybaV1($spustEndpoint('index.php', 'GET', $getUi + ['format' => '3mf'], null, $envUi), 500, 'chybi_zip', 'UI export bez rozšíření zip');
}
// Úklid úložiště: prošlé soubory se smažou a token přestane platit.
putenv('IMPORT_TMP_DIR=' . $uloziste);
assertTrue(UlozisteModelu::najdi($token) !== null, 'Úložiště: čerstvý model se najde');
touch($uloziste . DIRECTORY_SEPARATOR . $token . '.model', time() - 2 * UlozisteModelu::ttl());
assertSame(null, UlozisteModelu::najdi($token), 'Úložiště: prošlý model se nenajde');
touch($uloziste . DIRECTORY_SEPARATOR . $token . '.json', time() - 2 * UlozisteModelu::ttl());
file_put_contents($uloziste . DIRECTORY_SEPARATOR . 'cizi.txt', 'x');
touch($uloziste . DIRECTORY_SEPARATOR . 'cizi.txt', time() - 2 * UlozisteModelu::ttl());
assertSame(2, UlozisteModelu::uklid(), 'Úložiště: úklid smaže prošlé soubory modelu');
assertTrue(is_file($uloziste . DIRECTORY_SEPARATOR . 'cizi.txt'), 'Úložiště: cizí soubor nechá být');
assertSame(null, UlozisteModelu::najdi('../' . $token), 'Úložiště: token mimo tvar se nehledá');
putenv('IMPORT_TMP_DIR');
ok('UI: import 3MF/STL přes index.php (token, úložiště s úklidem), Stáhnout 3MF se sítěmi i bez nich, prošlý model = 410.');

// --- CLI ---
$cli = $spustCli('', ['--import', $stlCesta]);
assertSame([0, 'nahrany'], [$cli['code'], $cli['json']['objekty'][0]['nazev'] ?? null], 'CLI --import: výsledek importu');
assertSame(1, $spustCli('', ['--import', $tmpTest . DIRECTORY_SEPARATOR . 'neexistuje.stl'])['code'], 'CLI --import: neexistující soubor');
assertSame(1, $spustCli('{"objekty":[{"x":10,"y":10,"z":10}]}', ['-', '--podlozka', '1'])['code'], 'CLI --podlozka bez exportu');
assertSame(1, $spustCli('', ['--neznama'])['code'], 'CLI neznámá volba');
$cli = $spustCli('{"objekty":[{"x":10,"y":20,"z":5,"instances":{"d":3},"sit":0}]}', ['-', '--import', $stlCesta]);
assertSame([0, 3], [$cli['code'], $cli['json']['pocet_instanci'] ?? null], 'CLI vstup + --import: výpočet');
if ($maZip) {
    $vystup3mf = $tmpTest . DIRECTORY_SEPARATOR . 'cli.3mf';
    $cli = $spustCli('', ['--import', $hlavni3mf, '--export-3mf', $vystup3mf]);
    assertSame([0, 3], [$cli['code'], $cli['json']['pocet_instanci'] ?? null], "CLI --import --export-3mf: výpočet ({$cli['err']})");
    assertSame(3, count($nactiExport((string)file_get_contents($vystup3mf))['polozky']), 'CLI --export-3mf: zapsaný 3MF');
    $cli = $spustCli('{"objekty":[{"x":60,"y":60,"z":10,"instances":{"d":8}}],"options":{"vice_podlozek":true}}', ['-', '--export-3mf=' . $tmpTest . DIRECTORY_SEPARATOR . 'vice.3mf']);
    assertTrue($cli['code'] === 0 && is_file($tmpTest . DIRECTORY_SEPARATOR . 'vice_podlozka_1.3mf') && is_file($tmpTest . DIRECTORY_SEPARATOR . 'vice_podlozka_2.3mf'), 'CLI více podložek: soubor na podložku');
    $cli = $spustCli('{"objekty":[{"x":60,"y":60,"z":10,"instances":{"d":8}}],"options":{"vice_podlozek":true}}', ['-', '--export-3mf', $tmpTest . DIRECTORY_SEPARATOR . 'druha.3mf', '--podlozka', '2']);
    assertTrue($cli['code'] === 0 && is_file($tmpTest . DIRECTORY_SEPARATOR . 'druha.3mf'), 'CLI --podlozka 2: jen jeden soubor');
} else {
    assertSame(1, $spustCli('{"objekty":[{"x":10,"y":10,"z":10}]}', ['-', '--export-3mf', $tmpTest . DIRECTORY_SEPARATOR . 'x.3mf'])['code'], 'CLI export bez rozšíření zip = chyba');
}
ok('CLI: --import, --export-3mf (i více podložek a --podlozka), chybné volby.');

// --- Volitelně PrusaSlicer: export jde nařezat a G-code tiskne objekty v pořadí tisku ---
$prusaSlicer = getenv('PRUSASLICER_CLI') ?: 'C:\\Program Files\\Prusa3D\\PrusaSlicer\\prusa-slicer-console.exe';
if (!$maZip || getenv('TEST_BEZ_PRUSASLICERU') === '1' || !is_file($prusaSlicer)) {
    ok('PrusaSlicer PŘESKOČENO (' . (!$maZip ? 'bez rozšíření zip' : (getenv('TEST_BEZ_PRUSASLICERU') === '1' ? 'TEST_BEZ_PRUSASLICERU=1' : "není {$prusaSlicer}")) . ').');
} else {
    $datadir = (getenv('APPDATA') ?: '') . DIRECTORY_SEPARATOR . 'PrusaSlicer';
    // Skutečné pořadí tisku: začátky objektů, za kterými hned nenásleduje konec (v hlavičce jsou začátek a konec za sebou).
    $poradiVGcode = function (string $gcode): array {
        $radky = preg_split('/\r?\n/', $gcode);
        $poradi = [];
        foreach ($radky as $i => $radek) {
            if (strpos($radek, '; printing object ') !== 0) continue;
            if (isset($radky[$i + 1]) && strpos($radky[$i + 1], '; stop printing object ') === 0) continue;
            $poradi[] = preg_replace('/ id:\d+ copy \d+$/', '', substr($radek, strlen('; printing object ')));
        }
        return $poradi;
    };
    $scenare = [
        'otočené lišty (kvádry)' => [VstupVypoctu::over(['objekty' => [$lista]]), null],
        'importovaný 3MF' => [VstupVypoctu::over(['objekty' => ImportModelu::objektyProVstup($model)]), $model],
    ];
    $preskoceno = null;
    foreach ($scenare as $popis => [$overeno, $sModelem]) {
        $vysledek = Api::vypocet($overeno);
        $vstup3mf = $docasny('prusa_' . md5($popis) . '.3mf', Export3mf::zVysledku($vysledek, $overeno['objekty'], $sModelem)['obsah']);
        $gcodeCesta = $tmpTest . DIRECTORY_SEPARATOR . 'prusa_' . md5($popis) . '.gcode';
        $cmd = escapeshellarg($prusaSlicer) . ' --datadir ' . escapeshellarg($datadir)
            . ' --printer-profile ' . escapeshellarg('Original Prusa MINI & MINI+ Input Shaper')
            . ' --print-profile ' . escapeshellarg('0.20mm QUALITY @MINI - OK')
            . ' --material-profile ' . escapeshellarg('Alzament Basic PLA')
            . ' --complete-objects --dont-arrange --no-binary-gcode --export-gcode ' . escapeshellarg($vstup3mf)
            . ' --output ' . escapeshellarg($gcodeCesta) . ' 2>&1';
        $vypis = (string)shell_exec($cmd);
        if (!is_file($gcodeCesta)) {
            if (preg_match('/(preset|profile).*(not found|nenalezen)|Unknown (printer|print|material)/i', $vypis)) {
                $preskoceno = 'chybí profily MINI v ' . $datadir;
                break;
            }
            fail("PrusaSlicer nenařezal export ({$popis}): " . substr($vypis, -500));
        }
        usort($vysledek['pozice'], function ($a, $b) { return $a['poradi'] <=> $b['poradi']; });
        $ocekavane = array_map(function ($p) use ($vysledek) {
            return str_pad((string)$p['poradi'], max(2, strlen((string)count($vysledek['pozice']))), '0', STR_PAD_LEFT) . ' ' . $p['nazev'];
        }, $vysledek['pozice']);
        assertSame($ocekavane, $poradiVGcode((string)file_get_contents($gcodeCesta)), "PrusaSlicer ({$popis}): pořadí a počet objektů v G-code = pořadí tisku");
    }
    ok($preskoceno !== null ? "PrusaSlicer PŘESKOČENO ({$preskoceno})." : 'PrusaSlicer: export jde nařezat (complete_objects), G-code tiskne všechny kusy v pořadí tisku (i otočené a importované sítě).');
}

$uklidTmp($tmpTest);

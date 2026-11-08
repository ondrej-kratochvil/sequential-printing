<?php
define("MAXIMALNI_POCET_INSTANCI", 99);
define("MAXIMALNI_POCET_ITERACI", 99);

require_once __DIR__ . "/src/SequentialPrintCalculator.php";
require_once __DIR__ . "/src/Api.php";
require_once __DIR__ . "/src/UlozisteModelu.php";
require_once __DIR__ . "/src/Relace.php";
require_once __DIR__ . "/src/Projekty.php";
require_once __DIR__ . "/src/Ucty.php";

/* Import modelu 3MF/STL z UI (POST multipart, pole soubor): soubor se uloží pod náhodný token (UlozisteModelu),
   odpověď JSON = objekty pro formulář + token; chyby ve formátu API v1. Bez DB a bez API klíče (UI funguje bez DB). */
if (isset($_GET["akce"]) && $_GET["akce"] === "import_modelu") {
	Api::obsluz(function (): void {
		Api::povolMetody(["POST"]);
		$soubor = Api::nahranySoubor("soubor", false);
		if ($soubor === null) throw new ApiChyba(400, "chybi_soubor", "Vyber soubor 3MF nebo STL.");
		$model = ImportModelu::nacti($soubor["cesta"], $soubor["nazev"], false);
		Api::odeslat(200, ImportModelu::souhrn($model) + ["token" => UlozisteModelu::uloz($soubor["cesta"], $soubor["nazev"])]);
	});
}
// Token nahraného modelu (sítě pro export 3MF); jiný tvar se zahodí.
$sit_token = (isset($_GET["sit_token"]) && UlozisteModelu::jePlatnyToken($_GET["sit_token"])) ? $_GET["sit_token"] : "";
// Největší soubor, který projde importem: limit aplikace (IMPORT_MAX_MB) i PHP (upload_max_filesize, post_max_size).
$import_max_bajtu = ImportModelu::maxVelikost();
foreach (["upload_max_filesize", "post_max_size"] as $ini_limit) {
	$bajty = Api::bajtyZIni((string)ini_get($ini_limit));
	if ($bajty > 0) $import_max_bajtu = min($import_max_bajtu, $bajty);
}

/* Funkce */

// Ikona ze SVG spritu (symboly i-* na začátku <body>). Dekorativní: text tlačítka nese význam, proto aria-hidden.
function ikona($nazev) {
	return '<svg class="ico" aria-hidden="true" focusable="false"><use href="#i-'.htmlspecialchars($nazev, ENT_QUOTES, "UTF-8").'"/></svg>';
}

function normalize_input_2dp($v) {
	if ($v === null) return $v;
	if (is_string($v)) $v = str_replace(",", ".", $v);
	if (!is_numeric($v)) return $v;
	$n = round((float)$v, 2);
	$s = number_format($n, 2, ".", "");
	$s = rtrim(rtrim($s, "0"), ".");
	return $s;
}

function is_decimal ($value) {
  return (is_numeric($value) and floor($value) != $value);
}

function countDecimals ($fNumber) {
  if (is_float($fNumber) and is_nan($fNumber)) return false;
  $fNumber = floatval($fNumber);
  for ($iDecimals = 0; $fNumber != round($fNumber, $iDecimals); $iDecimals++) ;
  return $iDecimals;
}

function sklonovani ($cislo, $jedna, $dva_ctyri, $nula_pet, $po_desetinnem_cisle = false) {
  if ($cislo == 1) $sklonovani = $jedna;
  else if ($cislo == 2 or $cislo == 3 or $cislo == 4) $sklonovani = $dva_ctyri;
  else if ($cislo and is_decimal($cislo) and $po_desetinnem_cisle) $sklonovani = $po_desetinnem_cisle;
  else $sklonovani = $nula_pet;
  return $sklonovani;
}

/**
 * Funkce formátuje zadané číslo podle zadaných parametrů.
 *
 * @param float $cislo Číslo, které chcete zformátovat.
 * @param bool $oddelit_tisice True, pokud chcete oddělit tisíce mezerou. False jinak.
 * @param int|bool $pocet_desetinnych_mist Počet desetinných míst. False pro automatické určení počtu desetinných míst.
 * @param bool $proskrtnuti True, pokud má být výstup "-" pro prázdný vstup. False jinak.
 * @param string $oddelovac_desetinnych_mist Oddělovač desetinných míst.
 *
 * @return string Zformátované číslo jako řetězec.
 */
function format_cislo($cislo, $oddelit_tisice = false, $pocet_desetinnych_mist = false, $proskrtnuti = false, $oddelovac_desetinnych_mist = ",") {
  if ($cislo == "" and $proskrtnuti == true) return "-";
  $pocet_desetinnych_mist1 = countDecimals($cislo);
  $zformatovane_cislo = $cislo; // provedu si zálohu čísla
  if ($oddelit_tisice) {
    $zformatovane_cislo = number_format($zformatovane_cislo, $pocet_desetinnych_mist, ".", ":"); // oddělit tisíce dvojtečkou
    $zformatovane_cislo = str_replace(":", " ", $zformatovane_cislo); // nahradit dvojtečku za nedělitelnou mezeru
  }
  else {
    if ($pocet_desetinnych_mist !== false and $pocet_desetinnych_mist1 != $pocet_desetinnych_mist and $zformatovane_cislo > 0) $zformatovane_cislo = number_format($zformatovane_cislo, $pocet_desetinnych_mist, ".", "");
    else $pocet_desetinnych_mist = $pocet_desetinnych_mist1;
  }
  if (strstr($zformatovane_cislo, ".")) $zformatovane_cislo = strtr($zformatovane_cislo, ".", $oddelovac_desetinnych_mist); // pokud je v čísle desetinná tečka, nahradím ji za desetinnou čárku
  return $zformatovane_cislo;
}

function array_msort($array, $cols) {
	$colarr = array();
	foreach ($cols as $col => $order) {
		$colarr[$col] = array();
		foreach ($array as $k => $row) {
			$colarr[$col]['_'.$k] = strtolower($row[$col]);
		}
	}
	$eval = 'array_multisort(';
	foreach ($cols as $col => $order) {
		$eval .= '$colarr[\''.$col.'\'],'.$order.',';
	}
	$eval = substr($eval,0,-1).');';
	eval($eval);
	$ret = array();
	foreach ($colarr as $col => $arr) {
		foreach ($arr as $k => $v) {
			$k = substr($k,1);
			if (!isset($ret[$k])) $ret[$k] = $array[$k];
			$ret[$k][$col] = $array[$k][$col];
		}
	}
	return $ret;
}

/* Nastavení tiskárny: předvolba z config/tiskarny.json (GET tiskarna, bez parametru výchozí Prusa MINI) */

$predvolby_tiskaren = PredvolbyTiskaren::seznam();
$tiskarna_id = PredvolbyTiskaren::vychoziId();
$chyba_tiskarny = "";
if (isset($_GET["tiskarna"]) && $_GET["tiskarna"] !== "") {
	if (PredvolbyTiskaren::existuje($_GET["tiskarna"])) $tiskarna_id = (string)$_GET["tiskarna"];
	else $chyba_tiskarny = "Neznámá tiskárna „".(is_string($_GET["tiskarna"]) ? $_GET["tiskarna"] : "?")."“. Dostupné předvolby: ".implode(", ", array_keys($predvolby_tiskaren)).". Výpočet neproběhl.";
}
$vychozi_tiskarna = PredvolbyTiskaren::parametry($tiskarna_id);
// Meze rozměrů objektu podle předvoleb (x po korekci posun_zprava) - atribut max a JS validace v tabulce objektů.
$plochy_tiskaren = [];
foreach (array_keys($predvolby_tiskaren) as $id_predvolby) $plochy_tiskaren[$id_predvolby] = PredvolbyTiskaren::plocha(PredvolbyTiskaren::parametry($id_predvolby));
$plocha_tiskarny = $plochy_tiskaren[$tiskarna_id];
$tiskova_plocha = [
	"x" => $vychozi_tiskarna["x"],
	"y" => $vychozi_tiskarna["y"],
	"z" => $vychozi_tiskarna["z"]
];
$posun_zprava = $vychozi_tiskarna["posun_zprava"]; // Korekce pro PRUSA MINI, kdy objekt umístění zcela vpravo má deformovanou stěnu
$Xr = $vychozi_tiskarna["Xr"]; // 10 - pro objekty krátké v ose Y
$Xl = $vychozi_tiskarna["Xl"];
$Yr = $vychozi_tiskarna["Yr"]; // 29
$Yl = $vychozi_tiskarna["Yl"];
$vodici_tyce_Z = $vychozi_tiskarna["vodici_tyce_Z"];
$vodici_tyce_Y = $vychozi_tiskarna["vodici_tyce_Y"];

$smer_X = ($Xl <= $Xr ? "zleva_doprava" : "zprava_doleva");
$smer_Y = ($Yl <= $Yr ? "zepredu_dozadu" : "zezadu_dopredu");

/* Nastavení (UI) */
$rozprostrit_instance_po_cele_podlozce = (empty($_GET) || (isset($_GET["rozprostrit_instance_po_cele_podlozce"]) && $_GET["rozprostrit_instance_po_cele_podlozce"]));
$rozprostrit_instance_v_ose_x = (empty($_GET) || (isset($_GET["rozprostrit_instance_v_ose_x"]) && $_GET["rozprostrit_instance_v_ose_x"]));
$rozprostrit_instance_v_ose_y = (empty($_GET) || (isset($_GET["rozprostrit_instance_v_ose_y"]) && $_GET["rozprostrit_instance_v_ose_y"]));
$umistit_na_stred_v_ose_x = (!empty($_GET) && (isset($_GET["umistit_na_stred_v_ose_x"]) && $_GET["umistit_na_stred_v_ose_x"]));
$umistit_na_stred_v_ose_y = (!empty($_GET) && (isset($_GET["umistit_na_stred_v_ose_y"]) && $_GET["umistit_na_stred_v_ose_y"]));
// Způsob rozprostření v ose Y: po_instancich (výchozí, kompatibilní) nebo po_radach.
$rozprostreni_y_rezim = (isset($_GET["rozprostreni_y_rezim"]) && in_array($_GET["rozprostreni_y_rezim"], SequentialPrintCalculator::ROZPROSTRENI_Y_REZIMY, true))
	? $_GET["rozprostreni_y_rezim"]
	: SequentialPrintCalculator::VYCHOZI_NASTAVENI["rozprostreni_y_rezim"];
// Rotace o 90° kolem Z pro všechny objekty (jinak jen objekty se zaškrtnutou rotací). Výchozí vypnuto.
$povolit_rotaci_vsem = (isset($_GET["povolit_rotaci_vsem"]) && $_GET["povolit_rotaci_vsem"]);
// Více podložek: co se nevejde na jednu podložku, pokračuje na další (nejvýš max_podlozek). Výchozí vypnuto.
$vice_podlozek = (isset($_GET["vice_podlozek"]) && $_GET["vice_podlozek"]);
$max_podlozek = (isset($_GET["max_podlozek"]) && is_numeric($_GET["max_podlozek"]))
	? max(1, min(SequentialPrintCalculator::MAXIMALNI_POCET_PODLOZEK, (int)$_GET["max_podlozek"]))
	: SequentialPrintCalculator::VYCHOZI_NASTAVENI["max_podlozek"];

/* Objekty (z UI/GET) */
$objekty = [];
if (isset($_GET["objekty"]) && is_array($_GET["objekty"]) && !empty($_GET["objekty"])) {
	$objekty = $_GET["objekty"];
		foreach ($objekty as $key => $objekt) {
		if (!is_array($objekt)) {
			unset($objekty[$key]);
			continue;
		}
		foreach ($objekt as $key1 => $hodnota) {
			// Desetinná čárka jen u čísel, název objektu nechat beze změny.
			if (is_string($hodnota) && $key1 !== "nazev") $objekty[$key][$key1] = str_replace(",", ".", $hodnota);
		}
		// Normalizace rozměrů na max 2 desetinná místa (bez přidávání 0.01).
		if (isset($objekty[$key]["x"])) $objekty[$key]["x"] = normalize_input_2dp($objekty[$key]["x"]);
		if (isset($objekty[$key]["y"])) $objekty[$key]["y"] = normalize_input_2dp($objekty[$key]["y"]);
		if (isset($objekty[$key]["z"])) $objekty[$key]["z"] = normalize_input_2dp($objekty[$key]["z"]);
	}
}

/* Výpočet */
$pos = $objekty_serazene = $datova_veta_pole = $pozice = $Xcount_pole = $zbyva_v_ose_X = [];
$zbyva_v_ose_Y = 0;
$pocet_instanci = $pocet_rad = $pocet_podlozek = $Xcount = 0;
$Xcount_min = $Xcount_max = 0;
$text_nad_tabulkou = "";
$datova_veta_json = "[]";
$kontrola_kolizi = null;
$limit = [];
$otocene_objekty = [];
$podlozky = [];
$objekty_podlozek = [];

if (!empty($objekty) && $chyba_tiskarny === "") {
	$objekty_input = $objekty; // zachovat pro formulář (aby se hodnoty po odeslání neměnily)

	$calculator = new SequentialPrintCalculator(
		$vychozi_tiskarna,
		[
			"rozprostrit_instance_po_cele_podlozce" => $rozprostrit_instance_po_cele_podlozce,
			"rozprostrit_instance_v_ose_x" => $rozprostrit_instance_v_ose_x,
			"rozprostrit_instance_v_ose_y" => $rozprostrit_instance_v_ose_y,
			"rozprostreni_y_rezim" => $rozprostreni_y_rezim,
			"povolit_rotaci_vsem" => $povolit_rotaci_vsem,
			"vice_podlozek" => $vice_podlozek,
			"max_podlozek" => $max_podlozek
		]
	);

	$vysledek = $calculator->calculate($objekty);

	// Do UI vrátím původní rozměry, ale doplním instances[r] z výpočtu.
	$objekty_vysledek = $vysledek["objekty"];
	$otocene_objekty = $vysledek["otocene_objekty"] ?? [];
	$objekty = $objekty_input;
	foreach ($objekty as $id => $o) {
		// Rotace: volba z formuláře jako bool; otoceno = výpočet objekt otočil o 90° (formulář drží původní rozměry).
		if (SequentialPrintCalculator::jePovolenaRotace($o["rotace"] ?? null)) $objekty[$id]["rotace"] = true;
		else unset($objekty[$id]["rotace"]);
		unset($objekty[$id]["otoceno"]);
		if (in_array($id, $otocene_objekty, true)) $objekty[$id]["otoceno"] = true;
		if (!isset($objekty[$id]["instances"])) $objekty[$id]["instances"] = [];
		if (isset($objekty_vysledek[$id]["instances"]["r"])) $objekty[$id]["instances"]["r"] = $objekty_vysledek[$id]["instances"]["r"];
		// Název vždy v upravené podobě (oříznutý, bez řídicích znaků); prázdný název z výstupu vypustit.
		$nazev_objektu = SequentialPrintCalculator::upravNazev($o["nazev"] ?? null);
		if ($nazev_objektu !== "") $objekty[$id]["nazev"] = $nazev_objektu;
		else unset($objekty[$id]["nazev"]);
	}
	$objekty_serazene = $vysledek["objekty_serazene"];
	$pos = $vysledek["pos"];
	$datova_veta_pole = $vysledek["datova_veta_pole"];
	$pozice = $vysledek["pozice"];
	$datova_veta_json = json_encode($datova_veta_pole);
	$zbyva_v_ose_X = $vysledek["zbyva_v_ose_X"];
	$zbyva_v_ose_Y = $vysledek["zbyva_v_ose_Y"];
	$pocet_instanci = $vysledek["pocet_instanci"];
	$pocet_rad = $vysledek["pocet_rad"];
	$pocet_podlozek = $vysledek["pocet_podlozek"];
	$Xcount = $vysledek["Xcount"];
	$Xcount_pole = $vysledek["Xcount_pole"];
	$Xcount_min = $vysledek["Xcount_min"];
	$Xcount_max = $vysledek["Xcount_max"];
	$kontrola_kolizi = $vysledek["kontrola_kolizi"] ?? null;
	$limit = $vysledek["limit"] ?? [];
	$podlozky = $vysledek["podlozky"] ?? [];
	// Rozměry objektů na každé podložce (u více podložek se rotace volí po podložkách).
	$objekty_podlozek = $vysledek["objekty_podlozek"] ?? [];
	// Více podložek: positions ([instance, X, Y]) neříká, na které podložce kus je - JSON v UI pak ukazuje pozice (s podlozka).
	if ($pocet_podlozek > 1) $datova_veta_json = json_encode($pozice, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);

	if ($pocet_instanci > 0 && $pocet_podlozek > 1) {
		$po_podlozkach = [];
		foreach ($podlozky as $souhrn_podlozky) $po_podlozkach[] = 'podložka '.$souhrn_podlozky["podlozka"].': '.$souhrn_podlozky["pocet_instanci"];
		$text_nad_tabulkou = 'Na <strong>'.$pocet_podlozek.'</strong> '.sklonovani($pocet_podlozek, "podložku", "podložky", "podložek").' se '.sklonovani($pocet_instanci, "vleze", "vlezou", "vleze").' celkem <strong>'.$pocet_instanci.'</strong> '.sklonovani($pocet_instanci, "instance", "instance", "instancí").' ('.implode(", ", $po_podlozkach).').';
	} elseif ($pocet_instanci > 0) {
		$Xcount_string = ($Xcount_min == $Xcount_max ? $Xcount_max : ($Xcount_min."&ndash;".$Xcount_max));
		$text_nad_tabulkou = 'Na podložku se '.sklonovani($pocet_instanci, "vleze", "vlezou", "vleze").' <strong>'.$pocet_instanci.'</strong> '.sklonovani($pocet_instanci, "instance", "instance", "instancí").' ('.$Xcount_string.' '.sklonovani($Xcount_max, "instance", "instance", "instancí").' '.sklonovani($pocet_rad, "v", "ve", "v").' '.$pocet_rad.' '.sklonovani($pocet_rad, "řadě", "řadách", "řadách").').';
	} elseif (!empty($limit)) {
		$text_nad_tabulkou = 'Na podložku se nevleze <strong>žádná</strong> instance.';
	}
}

/* JSON výstup (pro API / integrace) */
if (isset($_GET["format"]) && $_GET["format"] === "json") {
	header("Content-Type: application/json; charset=UTF-8");
	if ($chyba_tiskarny !== "") {
		if (PHP_SAPI !== "cli") http_response_code(400);
		echo json_encode(["error" => $chyba_tiskarny], JSON_UNESCAPED_UNICODE);
		exit;
	}
	$printer_json = [
		"x" => $tiskova_plocha["x"],
		"y" => $tiskova_plocha["y"],
		"z" => $tiskova_plocha["z"],
		"posun_zprava" => $posun_zprava,
		"Xr" => (float)$Xr,
		"Xl" => (float)$Xl,
		"Yr" => (float)$Yr,
		"Yl" => (float)$Yl,
		"vodici_tyce_Z" => (float)$vodici_tyce_Z,
		"vodici_tyce_Y" => (float)$vodici_tyce_Y,
		"smer_X" => $smer_X,
		"smer_Y" => $smer_Y
	];
	if (isset($vysledek) && is_array($vysledek) && isset($vysledek["printer"])) {
		$printer_json = $vysledek["printer"];
	}
	echo json_encode(
		[
			"positions" => $datova_veta_pole,
			"pozice" => $pozice,
			"pocet_instanci" => $pocet_instanci,
			"pocet_podlozek" => $pocet_podlozek,
			"objekty" => $objekty,
			"printer" => $printer_json,
			"kontrola_kolizi" => $kontrola_kolizi,
			"limit" => $limit,
			"otocene_objekty" => $otocene_objekty,
			"podlozky" => $podlozky,
			"tiskarna" => $tiskarna_id
		],
		JSON_UNESCAPED_UNICODE
	);
	exit;
}

/* Export 3MF (tlačítko „Stáhnout 3MF“): stejný výpočet jako stránka, každý kus samostatný objekt v pořadí tisku.
   Sítě z nahraného modelu podle sit_token a objekty[i][sit], jinak kvádry. Víc podložek = ZIP (nebo podlozka=N).
   Chyby ve formátu API v1 (JS je ukáže v hlášce). */
if (isset($_GET["format"]) && $_GET["format"] === "3mf") {
	Api::obsluz(function () use ($chyba_tiskarny, $sit_token, &$vysledek, &$objekty_input): void {
		if ($chyba_tiskarny !== "") throw new ApiChyba(404, "neznama_tiskarna", $chyba_tiskarny);
		if (!isset($vysledek) || !is_array($vysledek)) throw new ApiChyba(400, "nic_k_exportu", "Chybí objekty, není co exportovat.");
		$model = null;
		if (isset($_GET["sit_token"]) && $_GET["sit_token"] !== "") {
			$ulozeny = UlozisteModelu::najdi($sit_token);
			if ($ulozeny === null) {
				throw new ApiChyba(410, "model_vyprsel", "Nahraný model už na serveru není (drží se " . round(UlozisteModelu::ttl() / 3600) . " h). Nahraj soubor znovu.");
			}
			$model = ImportModelu::nacti($ulozeny["cesta"], $ulozeny["nazev"], true);
		}
		$export = Export3mf::zVysledku(SequentialPrintCalculator::vystupApi($vysledek), $objekty_input, $model, Api::cisloPodlozky($_GET["podlozka"] ?? null));
		Api::odeslatSoubor($export["obsah"], $export["typ"], $export["nazev"], ["Cache-Control" => "no-store"]);
	});
}

/* Účty (jen s DB): session se obnoví, jen když prohlížeč už cookie má (anonymní návštěva bez cookie i bez DB).
   Bez DB_DSN se v UI nic z účtů nezobrazí a vše ostatní funguje jako dřív. */
$ucty_zapnute = Relace::dbZapnuta();
Relace::startPokudExistuje();
$uzivatel = Relace::uzivatel();
// Jednorázová zpráva z účtu (např. po registraci) se ukáže u formuláře.
$zprava_uctu = $uzivatel ? Relace::vezmiZpravu() : null;
// Otevřený projekt (ucet.php?akce=otevrit přesměruje sem s parametry formuláře a projekt=id): jen vlastní projekt.
$aktualni_projekt = null;
if ($uzivatel && isset($_GET["projekt"]) && is_string($_GET["projekt"]) && ctype_digit($_GET["projekt"])) {
	try {
		$nacteny_projekt = Projekty::nacti(Db::pdo(), $uzivatel["id"], (int)$_GET["projekt"]);
		if ($nacteny_projekt !== null) $aktualni_projekt = ["id" => $nacteny_projekt["id"], "nazev" => $nacteny_projekt["name"]];
	} catch (Throwable $e) {
		error_log("Sekvenční tisk: nelze načíst projekt: ".$e->getMessage());
	}
}
// Návrat po přihlášení: zpět na stejný výpočet (jen index.php s parametry, viz ucet.php).
$zpet_po_prihlaseni = "index.php".(!empty($_SERVER["QUERY_STRING"]) ? "?".$_SERVER["QUERY_STRING"] : "");
$odkaz_prihlaseni = "ucet.php?stranka=prihlaseni".(strlen($zpet_po_prihlaseni) <= 4000 ? "&zpet=".rawurlencode($zpet_po_prihlaseni) : "");

// Cache busting / no-cache pro HTML (mobilní prohlížeče často agresivně cachují).
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

/* Vypsání HTML */
?>
<!DOCTYPE html>
<html lang="cs">
  <head>
    <title>Sekvenční tisk</title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<?php $APP_VERSION = (string)@filemtime(__FILE__); ?>
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.4/jquery.min.js?v=<?php echo htmlspecialchars($APP_VERSION, ENT_QUOTES, "UTF-8");?>"></script>
		<script>
			id_objektu = -1;
			// JSON_HEX_*: název objektu je uživatelský text - nesmí ukončit <script> ani nic vložit do HTML.
			objekty = <?php echo (!empty($objekty) ? json_encode(array_values($objekty), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) : "[]");?>;

			function reindex_rows () {
				// Přerovná name="objekty[i][...]" podle pořadí řádků, aby server dostal konzistentní pole.
				const rows = $("table#objekty tr[data-row='1']");
				rows.each(function(idx){
					const $tr = $(this);
					$tr.attr("id", "objekt_" + idx);
					$tr.attr("data-idx", idx);
					$tr.find("td.cell_id").text(idx + 1);
					$tr.find("input[name^='objekty']").each(function(){
						const name = $(this).attr("name") || "";
						const replaced = name.replace(/^objekty\[\d+\]/, "objekty[" + idx + "]");
						$(this).attr("name", replaced);
					});
				});
				id_objektu = rows.length - 1;
			}

			function smazat_radek_tabulky (objekt_id) {
				$("#objekt_"+objekt_id).remove();
				reindex_rows();
			}

			function escapeAttr(v) {
				if (v == null || v === undefined) return '';
				const s = String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
				return s;
			}
			function pridej_radek_do_tabulky (par_id_objektu) {
				id_objektu++;
				if (par_id_objektu) id_objektu = parseInt(par_id_objektu);
				const { x = "", y = "", z = "", nazev = "", rotace = false, sit = "" } = objekty[id_objektu] || {};
				// Odkaz na síť nahraného modelu (export 3MF); řádek bez něj se exportuje jako kvádr.
				const sitInput = (sit !== "" && sit !== null && /^\d+$/.test(String(sit)))
					? `<input class="sit" type="hidden" name="objekty[${id_objektu}][sit]" value="${escapeAttr(sit)}" />` : "";
				const rotacePovolena = (rotace === true || rotace === 1 || rotace === "1");
				instances = (objekty[id_objektu] ? objekty[id_objektu]["instances"]["d"] : "max");
				const vysledny_pocet_instanci = (objekty[id_objektu] && objekty[id_objektu]["instances"]["r"]) || "";
				const isMax = (instances === "max");
				// Pro default "max" nechci uživateli předvyplňovat 99.
				const instancesNum = (!objekty[id_objektu] && isMax) ? "" : (isMax ? "" : instances);
				$("table#objekty").append(
					`<tr id="objekt_${id_objektu}" data-row="1" data-idx="${id_objektu}">
						<td class="cell_id">${id_objektu + 1}</td>
						<td>${sitInput}<input class="nazev" type="text" name="objekty[${id_objektu}][nazev]" value="${escapeAttr(nazev)}" maxlength="<?php echo SequentialPrintCalculator::MAXIMALNI_DELKA_NAZVU;?>" placeholder="volitelný" /></td>
						<td><input class="dim" type="number" name="objekty[${id_objektu}][x]" value="${escapeAttr(x)}" step="0.01" min="0.1" max="<?php echo $plocha_tiskarny["x"];?>" required="required" /></td>
						<td><input class="dim" type="number" name="objekty[${id_objektu}][y]" value="${escapeAttr(y)}" step="0.01" min="0.1" max="<?php echo $plocha_tiskarny["y"];?>" required="required" /></td>
						<td><input class="dim" type="number" name="objekty[${id_objektu}][z]" value="${escapeAttr(z)}" step="0.01" min="0.1" max="<?php echo $plocha_tiskarny["z"];?>" required="required" /></td>
						<td class="instances_cell">
							<div class="instances_row" style="display:flex; gap:10px; align-items:center; justify-content:flex-end; flex-wrap:wrap;">
								<input class="instances instances_num" type="number" name="objekty[${id_objektu}][instances][d_num]" value="${escapeAttr(instancesNum)}" step="1" min="1" max="<?php echo MAXIMALNI_POCET_INSTANCI;?>" ${isMax ? "disabled" : "required"} />
								<label style="display:inline-flex; gap:6px; align-items:center; color: var(--muted); font-weight:700;">
									<input class="instances_max" type="checkbox" ${isMax ? "checked" : ""} />
									max
								</label>
								<input class="instances_d" type="hidden" name="objekty[${id_objektu}][instances][d]" value="${isMax ? "max" : escapeAttr(instancesNum)}" />
							</div>
						</td>
						<td class="rotace_cell"><input class="rotace" type="checkbox" name="objekty[${id_objektu}][rotace]" value="1" ${rotacePovolena ? "checked" : ""} title="Povolit otočení o 90° kolem Z" /></td>
						<td class="actions">
							<div style="display:flex; gap:6px; flex-wrap:wrap;">
								<button class="small row_action icon-only" type="button" data-action="up" title="Přesunout nahoru" aria-label="Přesunout nahoru"><?php echo ikona("arrow-up");?></button>
								<button class="small row_action icon-only" type="button" data-action="down" title="Přesunout dolů" aria-label="Přesunout dolů"><?php echo ikona("arrow-down");?></button>
								<button class="small row_action icon-only" type="button" data-action="dup" title="Duplikovat" aria-label="Duplikovat"><?php echo ikona("copy-plus");?></button>
								<button class="small row_action icon-only" type="button" data-action="del" title="Smazat" aria-label="Smazat"><?php echo ikona("trash");?></button>
							</div>
						</td>
						<td class="cell_result">${vysledny_pocet_instanci}</td>
					</tr>`
				);
				reindex_rows();
				nastav_max_rozmeru($("#objekt_" + id_objektu));
			}

			// Meze rozměrů objektu podle vybrané tiskárny (předvolby z config/tiskarny.json, x po korekci posun_zprava).
			// S povolenou rotací (u objektu nebo všem) stačí, když se objekt vejde otočený, proto x i y do většího rozměru.
			const PLOCHY_TISKAREN = <?php echo json_encode($plochy_tiskaren, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
			const TISKARNA_SERVER = <?php echo json_encode($tiskarna_id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
			function plocha_tiskarny () {
				const id = $("#tiskarna").val();
				return PLOCHY_TISKAREN[id] || PLOCHY_TISKAREN[TISKARNA_SERVER];
			}
			function nastav_max_rozmeru ($tr) {
				const plocha = plocha_tiskarny();
				if (!plocha || !$tr || !$tr.length) return;
				const rotace = $("#povolit_rotaci_vsem").prop("checked") || $tr.find("input.rotace").prop("checked");
				const xy = Math.max(plocha.x, plocha.y);
				$tr.find("input[name$='[x]']").attr("max", rotace ? xy : plocha.x);
				$tr.find("input[name$='[y]']").attr("max", rotace ? xy : plocha.y);
				$tr.find("input[name$='[z]']").attr("max", plocha.z);
			}

			function get_form_state () {
				const state = {
					version: 1,
					objekty: [],
					nastaveni: {
						rozprostrit_instance_v_ose_x: $("#rozprostrit_instance_v_ose_x").prop("checked") ? 1 : 0,
						rozprostrit_instance_v_ose_y: $("#rozprostrit_instance_v_ose_y").prop("checked") ? 1 : 0,
						rozprostreni_y_rezim: $("input[name='rozprostreni_y_rezim']:checked").val() || "po_instancich",
						povolit_rotaci_vsem: $("#povolit_rotaci_vsem").prop("checked") ? 1 : 0,
						tiskarna: $("#tiskarna").val() || "",
						vice_podlozek: $("#vice_podlozek").prop("checked") ? 1 : 0,
						max_podlozek: parseInt($("#max_podlozek").val(), 10) || <?php echo SequentialPrintCalculator::MAXIMALNI_POCET_PODLOZEK;?>,
						rozprostrit_instance_po_cele_podlozce: $("#rozprostrit_instance_po_cele_podlozce").prop("checked") ? 1 : 0,
						umistit_na_stred_v_ose_x: $("#umistit_na_stred_v_ose_x").prop("checked") ? 1 : 0,
						umistit_na_stred_v_ose_y: $("#umistit_na_stred_v_ose_y").prop("checked") ? 1 : 0
					}
				};
				$("table#objekty tr[data-row='1']").each(function(){
					const $tr = $(this);
					const x = $tr.find("input[name$='[x]']").val();
					const y = $tr.find("input[name$='[y]']").val();
					const z = $tr.find("input[name$='[z]']").val();
					const max = $tr.find("input.instances_max").prop("checked");
					const d = max ? "max" : $tr.find("input.instances_num").val();
					const nazev = ($tr.find("input.nazev").val() || "").trim();
					const objekt = { x: x, y: y, z: z, instances: { d: d } };
					if (nazev !== "") objekt.nazev = nazev;
					if ($tr.find("input.rotace").prop("checked")) objekt.rotace = true;
					state.objekty.push(objekt);
				});
				return state;
			}

			function set_form_state (state) {
				// Vyčistí tabulku a vloží řádky podle JSONu (bez automatického výpočtu).
				const objektyIn = Array.isArray(state?.objekty) ? state.objekty : [];
				objekty = objektyIn;
				id_objektu = -1;
				$("table#objekty tr[data-row='1']").remove();
				$.each(objektyIn, function(index){
					pridej_radek_do_tabulky(index);
				});
				if (objektyIn.length === 0) pridej_radek_do_tabulky();

				const n = state?.nastaveni || {};
				$("#rozprostrit_instance_v_ose_x").prop("checked", !!n.rozprostrit_instance_v_ose_x);
				$("#rozprostrit_instance_v_ose_y").prop("checked", !!n.rozprostrit_instance_v_ose_y);
				$("#rozprostreni_y_rezim_" + (n.rozprostreni_y_rezim === "po_radach" ? "po_radach" : "po_instancich")).prop("checked", true);
				$("#povolit_rotaci_vsem").prop("checked", !!n.povolit_rotaci_vsem);
				// Tiskárna: jen existující předvolba, jinak výchozí (starý export / neznámé id).
				const $tiskarna = $("#tiskarna");
				const znamaTiskarna = $tiskarna.find("option").filter(function () { return this.value === n.tiskarna; }).length > 0;
				$tiskarna.val(znamaTiskarna ? n.tiskarna : $tiskarna.attr("data-vychozi"));
				$("#vice_podlozek").prop("checked", !!n.vice_podlozek);
				$("#max_podlozek").val(parseInt(n.max_podlozek, 10) || <?php echo SequentialPrintCalculator::MAXIMALNI_POCET_PODLOZEK;?>);
				$("#rozprostrit_instance_po_cele_podlozce").prop("checked", !!n.rozprostrit_instance_po_cele_podlozce);
				$("#umistit_na_stred_v_ose_x").prop("checked", !!n.umistit_na_stred_v_ose_x);
				$("#umistit_na_stred_v_ose_y").prop("checked", !!n.umistit_na_stred_v_ose_y);
				// Meze rozměrů až po nastavení tiskárny a rotace.
				$("table#objekty tr[data-row='1']").each(function(){ nastav_max_rozmeru($(this)); });
			}

      $(document).ready(function(){
				// Load from hash logic moved before default init
				const hasHash = window.location.hash && window.location.hash.startsWith('#s=');
				
				if (!hasHash) {
					// Pokud není hash, použijeme data z PHP (objekty).
					// JS renderuje tabulku na základě proměnné 'objekty'.
					
					// Vyčistíme případné existující řádky (pro jistotu)
					$("table#objekty tr[data-row='1']").remove();
					id_objektu = -1;

					if (Array.isArray(objekty) && objekty.length == 0) {
						// Pokud nejsou žádná data, přidáme jeden prázdný řádek.
						pridej_radek_do_tabulky(); 
					} else {
						// Pokud jsou data, vyrenderujeme je.
						$.each(objekty, function(index, value) {
							pridej_radek_do_tabulky(index);
						});
					}
				}

				$('#nastaveni').hide();
				$('#zbyvajici_misto').hide();

				// --- Ukládání/načítání konfigurace bez DB (localStorage) ---
				const LS_KEY = 'sekvencni_tisk:last_query';

				// Tlačítko s ikonou: měnit jen text v .lbl (ikona zůstane), volitelně i ikonu (id symbolu bez i-).
				function setBtnLabel(btn, text, ikona) {
					if (!btn) return;
					const lbl = btn.querySelector('.lbl');
					if (lbl) lbl.textContent = text; else btn.textContent = text;
					if (ikona) {
						const use = btn.querySelector('svg.ico use');
						if (use) use.setAttribute('href', '#i-' + ikona);
					}
				}

				function setStatus(msg) {
					const el = document.getElementById('local_status');
					if (!el) return;
					el.textContent = msg || '';
					if (msg) setTimeout(() => { el.textContent = ''; }, 2500);
				}

				$('#save_local').on('click', function () {
					try {
						localStorage.setItem(LS_KEY, window.location.search || '');
						setStatus('Uloženo');
					} catch (e) {
						setStatus('Nelze uložit (localStorage)');
					}
				});

				$('#load_local').on('click', function () {
					const q = localStorage.getItem(LS_KEY);
					if (!q) return setStatus('Není co načíst');
					window.location.search = q;
				});

				$('#clear_local').on('click', function () {
					localStorage.removeItem(LS_KEY);
					setStatus('Smazáno');
				});

				$('#example_basic').on('click', function () {
					// rychlý příklad pro otestování
					const params = new URLSearchParams();
					params.set('objekty[0][x]', '50');
					params.set('objekty[0][y]', '50');
					params.set('objekty[0][z]', '100');
					params.set('objekty[0][instances][d]', 'max');
					window.location.search = '?' + params.toString();
				});

				// Akce v řádcích (delegace)
				$(document).on('click', 'button.row_action', function () {
					const action = $(this).data('action');
					const $tr = $(this).closest('tr');
					if (!$tr.length) return;
					if (action === 'del') {
						$tr.remove();
						if ($("table#objekty tr[data-row='1']").length === 0) pridej_radek_do_tabulky();
						reindex_rows();
						return;
					}
					if (action === 'dup') {
						const $clone = $tr.clone(true);
						$clone.insertAfter($tr);
						reindex_rows();
						return;
					}
					if (action === 'up') {
						const $prev = $tr.prevAll("tr[data-row='1']").first();
						if ($prev.length) $tr.insertBefore($prev);
						reindex_rows();
						return;
					}
					if (action === 'down') {
						const $next = $tr.nextAll("tr[data-row='1']").first();
						if ($next.length) $tr.insertAfter($next);
						reindex_rows();
						return;
					}
				});

				// Load hash on startup
				if (hasHash) {
					if (!loadFromHash()) {
						$("table#objekty tr[data-row='1']").remove();
						id_objektu = -1;
						pridej_radek_do_tabulky();
					}
				}

				// Fullscreen toggle
				const bedWrap = document.querySelector('.bed-wrap');
				const bedCard = bedWrap ? bedWrap.querySelector('.card') : null;
				const fsBtn = document.getElementById('fullscreen_toggle');

				if (bedCard && fsBtn) {
					function getFsElement() {
						return document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement;
					}
					fsBtn.addEventListener('click', function() {
						if (!getFsElement()) {
							if (bedCard.requestFullscreen) bedCard.requestFullscreen();
							else if (bedCard.webkitRequestFullscreen) bedCard.webkitRequestFullscreen();
							else if (bedCard.msRequestFullscreen) bedCard.msRequestFullscreen();
						} else {
							if (document.exitFullscreen) document.exitFullscreen();
							else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
							else if (document.msExitFullscreen) document.msExitFullscreen();
						}
					});

					document.addEventListener('fullscreenchange', handleFsChange);
					document.addEventListener('webkitfullscreenchange', handleFsChange);
					document.addEventListener('mozfullscreenchange', handleFsChange);
					document.addEventListener('MSFullscreenChange', handleFsChange);

					function handleFsChange() {
						const isFs = !!getFsElement();
						if (isFs) {
							bedCard.classList.add('is-fullscreen');
							setBtnLabel(fsBtn, 'Zavřít Fullscreen', 'minimize');
						} else {
							bedCard.classList.remove('is-fullscreen');
							setBtnLabel(fsBtn, 'Fullscreen', 'maximize');
						}
					}
				}

				// Max checkbox pro počet instancí
				$(document).on('change', 'input.instances_max', function () {
					const $tr = $(this).closest('tr');
					const isMax = $(this).prop('checked');
					const $num = $tr.find('input.instances_num');
					const $hidden = $tr.find('input.instances_d');
					if (isMax) {
						$num.val('');
						$num.prop('disabled', true);
						$num.prop('required', false);
						$hidden.val('max');
					} else {
						$num.prop('disabled', false);
						$num.prop('required', true);
						$hidden.val($num.val() || '');
						$num.focus();
					}
				});
				$(document).on('input', 'input.instances_num', function () {
					const $tr = $(this).closest('tr');
					const $max = $tr.find('input.instances_max');
					// Když uživatel začne psát číslo, automaticky vypni max.
					if ($max.prop('checked')) {
						$max.prop('checked', false);
						$(this).prop('disabled', false);
						$(this).prop('required', true);
					}
					$tr.find('input.instances_d').val($(this).val());
				});

				// Import / Export JSON vstupu (bez DB)
				function openModal() { $("#io_modal").removeAttr("hidden"); }
				function closeModal() { $("#io_modal").attr("hidden","hidden"); }

				function roundTo2(v) {
					if (v === null || v === undefined) return v;
					const s = String(v).replace(',', '.').trim();
					if (s === '') return '';
					const n = Number(s);
					if (!Number.isFinite(n)) return s;
					// Nechci vnucovat trailing nuly.
					return (Math.round(n * 100) / 100).toString();
				}

				// Validace (inline)
				function validateRow($tr) {
					let valid = true;
					$tr.find("input[type='number']").each(function(){
						const val = $(this).val();
						const isRequired = $(this).prop('required');
						const min = parseFloat($(this).attr('min'));
						const max = parseFloat($(this).attr('max'));
						const num = parseFloat(val);

						// Reset styles
						$(this).css('border-color', '');
						$(this).attr('title', '');

						if (isRequired && val === '') {
							$(this).css('border-color', 'var(--danger)');
							$(this).attr('title', 'Povinné pole');
							valid = false;
						} else if (val !== '' && !Number.isFinite(num)) {
							$(this).css('border-color', 'var(--danger)');
							$(this).attr('title', 'Musí být číslo');
							valid = false;
						} else if (val !== '' && !isNaN(min) && num < min) {
							$(this).css('border-color', 'var(--danger)');
							$(this).attr('title', 'Minimum je ' + min);
							valid = false;
						} else if (val !== '' && !isNaN(max) && num > max) {
							$(this).css('border-color', 'var(--danger)');
							$(this).attr('title', 'Maximum je ' + max);
							valid = false;
						}
					});
					return valid;
				}

				// Omezit rozměry na 2 desetinná místa (při opuštění polí + před submit)
				$(document).on('blur', "input[type='number'][name$='[x]'], input[type='number'][name$='[y]'], input[type='number'][name$='[z]']", function () {
					$(this).val(roundTo2($(this).val()));
					validateRow($(this).closest('tr'));
				});
				// Změna tiskárny nebo rotace: přepočítat meze rozměrů a znovu ověřit vyplněné řádky.
				$(document).on('change', "#tiskarna, #povolit_rotaci_vsem, table#objekty input.rotace", function () {
					$("table#objekty tr[data-row='1']").each(function(){
						const $tr = $(this);
						nastav_max_rozmeru($tr);
						const vyplneno = $tr.find("input.dim").filter(function(){ return $(this).val() !== ''; }).length > 0;
						if (vyplneno) validateRow($tr);
					});
				});
				$("form").on('submit', function (e) {
					$(this).find("input[type='number'][name$='[x]'], input[type='number'][name$='[y]'], input[type='number'][name$='[z]']").each(function(){
						$(this).val(roundTo2($(this).val()));
					});

					let allValid = true;
					$("table#objekty tr[data-row='1']").each(function(){
						if (!validateRow($(this))) allValid = false;
					});

					if (!allValid) {
						e.preventDefault();
						alert('Opravte prosím chyby ve formuláři (červeně orámované).');
						return false;
					}
					// Odstranit hash z historie před navigací – zabrání back-button trapu
					if (window.location.hash && window.location.hash.startsWith('#s=')) {
						history.replaceState(null, '', window.location.pathname + window.location.search);
					}
				});

				$('#export_input').on('click', function () {
					const st = get_form_state();
					$("#io_title").text("Export vstupu (JSON)");
					$("#io_text").val(JSON.stringify(st, null, 2));
					$("#io_hint").text("Tento JSON si můžeš uložit nebo poslat dál. Importem se jen vyplní formulář.");
					openModal();
				});
				$('#import_input').on('click', function () {
					$("#io_title").text("Import vstupu (JSON)");
					$("#io_text").val("");
					$("#io_hint").text("Vlož JSON z exportu a klikni na Importovat.");
					openModal();
				});
				$('#io_close').on('click', closeModal);
				// zavření klikem mimo panel
				$('#io_modal').on('click', function (e) {
					if (e.target && e.target.id === 'io_modal') closeModal();
				});
				// zavření klávesou ESC
				$(document).on('keydown', function (e) {
					if (e.key === 'Escape') closeModal();
				});

				// --- Nabídka „Další funkce“ (<details>): Escape a klik mimo ji zavřou, po volbě položky se zavře sama.
				const moreMenu = document.getElementById('more_menu');
				if (moreMenu) {
					const moreSummary = moreMenu.querySelector('summary');
					const morePanel = moreMenu.querySelector('.more-menu-panel');
					moreMenu.addEventListener('toggle', function () {
						moreSummary.setAttribute('aria-expanded', moreMenu.open ? 'true' : 'false');
						if (!moreMenu.open) return;
						// Na úzkém displeji panel nesmí vyjet vpravo mimo obrazovku: zarovnat k pravému okraji tlačítka.
						moreMenu.classList.remove('align-right');
						const r = morePanel.getBoundingClientRect();
						if (r.right > document.documentElement.clientWidth - 8) moreMenu.classList.add('align-right');
					});
					document.addEventListener('keydown', function (e) {
						if (e.key === 'Escape' && moreMenu.open) { moreMenu.open = false; moreSummary.focus(); }
					});
					document.addEventListener('click', function (e) {
						if (moreMenu.open && !moreMenu.contains(e.target)) moreMenu.open = false;
					});
					morePanel.addEventListener('click', function (e) {
						if (e.target.closest('button')) moreMenu.open = false;
					});
				}
				$('#io_copy').on('click', async function () {
					const el = document.getElementById('io_text');
					if (!el) return;
					try { await navigator.clipboard.writeText(el.value); }
					catch (e) { el.focus(); el.select(); document.execCommand('copy'); }
				});
				$('#io_import').on('click', function () {
					let parsed = null;
					try { parsed = JSON.parse($("#io_text").val() || ""); }
					catch (e) { return setStatus("Nevalidní JSON"); }
					set_form_state(parsed);
					setStatus("Načteno");
					closeModal();
					$('#nastaveni').show();
				});

				$('#copy_json').on('click', async function () {
					const el = document.getElementById('json_textarea');
					if (!el) return;
					try {
						await navigator.clipboard.writeText(el.value);
						setBtnLabel(this, 'Zkopírováno', 'check');
						setTimeout(() => setBtnLabel(this, 'Kopírovat JSON', 'copy'), 1200);
					} catch (e) {
						// fallback: select + copy
						el.focus();
						el.select();
						document.execCommand('copy');
					}
				});

				$('#copy_link').on('click', async function () {
					try {
						// Generování share linku v hashi
						const state = get_form_state();
						// Minimalizace JSONu pro URL
						// o: [x, y, z, d, název?, rotace?] (název jen když je vyplněný nebo je povolená rotace - staré odkazy mají 4 prvky;
						// rotace = 1 jen když je povolená), n[5]: 1 = rozprostření v ose Y po řadách (staré odkazy ho nemají = po instancích),
						// n[6]: 1 = povolit rotaci všem objektům, n[7]: 1 = více podložek, n[8]: max. počet podložek,
						// t: id předvolby tiskárny (jen když není výchozí).
						const minState = {
							o: state.objekty.map(o => {
								const arr = [o.x, o.y, o.z, (o.instances && o.instances.d === "max") ? "m" : o.instances.d];
								if (o.nazev || o.rotace) arr.push(o.nazev || "");
								if (o.rotace) arr.push(1);
								return arr;
							}),
							n: [
								state.nastaveni.rozprostrit_instance_v_ose_x ? 1 : 0,
								state.nastaveni.rozprostrit_instance_v_ose_y ? 1 : 0,
								state.nastaveni.rozprostrit_instance_po_cele_podlozce ? 1 : 0,
								state.nastaveni.umistit_na_stred_v_ose_x ? 1 : 0,
								state.nastaveni.umistit_na_stred_v_ose_y ? 1 : 0,
								state.nastaveni.rozprostreni_y_rezim === "po_radach" ? 1 : 0,
								state.nastaveni.povolit_rotaci_vsem ? 1 : 0,
								state.nastaveni.vice_podlozek ? 1 : 0,
								state.nastaveni.max_podlozek
							]
						};
						if (state.nastaveni.tiskarna && state.nastaveni.tiskarna !== $("#tiskarna").attr("data-vychozi")) minState.t = state.nastaveni.tiskarna;
						const jsonStr = JSON.stringify(minState);
						// btoa umí jen Latin-1: název s diakritikou nejdřív převést na UTF-8 bajty.
						const b64 = btoa(unescape(encodeURIComponent(jsonStr)));
						const url = window.location.origin + window.location.pathname + '#s=' + b64;
						
						await navigator.clipboard.writeText(url);
						// Nabídka Další funkce se po kliknutí zavře, potvrzení proto i ve stavovém řádku lišty.
						setStatus('Odkaz zkopírován');
						setBtnLabel(this, 'Odkaz zkopírován', 'check');
						setTimeout(() => setBtnLabel(this, 'Kopírovat odkaz', 'link'), 1200);
					} catch (e) {
						setStatus('Nelze zkopírovat odkaz');
					}
				});

				// Načtení stavu z hashe
				function loadFromHash() {
					if (!window.location.hash) return false;
					const hash = window.location.hash.substring(1); // remove #
					if (!hash.startsWith('s=')) return false;
					
					try {
						const b64 = hash.substring(2);
						const jsonStr = decodeURIComponent(escape(atob(b64)));
						const minState = JSON.parse(jsonStr);
						
						// Rekonstrukce plného stavu
						if (minState && Array.isArray(minState.o) && Array.isArray(minState.n)) {
							const fullState = {
								objekty: minState.o.map(arr => ({
									x: arr[0],
									y: arr[1],
									z: arr[2],
									instances: { d: (arr[3] === "m" ? "max" : arr[3]) },
									nazev: (typeof arr[4] === "string" ? arr[4] : ""),
									rotace: (arr[5] === 1)
								})),
								nastaveni: {
									rozprostrit_instance_v_ose_x: !!minState.n[0],
									rozprostrit_instance_v_ose_y: !!minState.n[1],
									rozprostreni_y_rezim: (minState.n[5] ? "po_radach" : "po_instancich"),
									povolit_rotaci_vsem: !!minState.n[6],
									tiskarna: (typeof minState.t === "string" ? minState.t : ""),
									vice_podlozek: !!minState.n[7],
									max_podlozek: minState.n[8],
									rozprostrit_instance_po_cele_podlozce: !!minState.n[2],
									umistit_na_stred_v_ose_x: !!minState.n[3],
									umistit_na_stred_v_ose_y: !!minState.n[4]
								}
							};
							
							set_form_state(fullState);
							// Po načtení z hashe rovnou odešleme formulář (aby se spustil výpočet)
							// Ale musíme počkat na překreslení DOMu
							// Hash neodstraňujeme před submit – pokud validace selže, uživatel musí mít
							// možnost obnovit stránku a zkusit znovu (hash zůstane v URL)
							setTimeout(() => {
								$("form").submit();
							}, 100);
							return true;
						}
					} catch (e) {
						console.error('Invalid share link', e);
					}
					return false;
				}

				$('#download_json').on('click', function () {
					const el = document.getElementById('json_textarea');
					if (!el || !el.value) return setStatus('Není co stáhnout');
					const blob = new Blob([el.value], { type: 'application/json;charset=utf-8' });
					const url = URL.createObjectURL(blob);
					const a = document.createElement('a');
					a.href = url;
					a.download = 'sekvencni-tisk-positions.json';
					document.body.appendChild(a);
					a.click();
					a.remove();
					URL.revokeObjectURL(url);
				});

				// --- Import 3MF/STL: soubor se nahraje na server (index.php?akce=import_modelu), odpověď předvyplní tabulku
				// objektů (rozměry, název, počet) a token nahraného modelu jde do skrytého pole sit_token (sítě pro export 3MF).
				const IMPORT_MAX_BAJTU = <?php echo (int)$import_max_bajtu;?>;
				function zpravaChyby(data, status) {
					return (data && data.error && data.error.message) ? data.error.message : ('HTTP ' + status);
				}
				function zobrazModel(text) {
					$("#model_info").text(text || "");
					$("#model_zapomenout").toggle(!!text);
				}
				$('#import_modelu').on('click', function () { $('#model_soubor').trigger('click'); });
				$('#model_soubor').on('change', async function () {
					const input = this;
					const soubor = input.files && input.files[0];
					if (!soubor) return;
					if (soubor.size > IMPORT_MAX_BAJTU) {
						alert('Soubor má ' + (soubor.size / 1048576).toFixed(1) + ' MB, server přijme nejvýš ' + (IMPORT_MAX_BAJTU / 1048576).toFixed(1) + ' MB.');
						input.value = '';
						return;
					}
					const fd = new FormData();
					fd.append('soubor', soubor);
					$("#local_status").text('Načítám ' + soubor.name + '…');
					try {
						const res = await fetch('./index.php?akce=import_modelu', { method: 'POST', body: fd });
						let data = null;
						try { data = await res.json(); } catch (e) { data = null; }
						if (!res.ok || !data || data.error) {
							$("#local_status").text('');
							alert('Import se nepovedl: ' + zpravaChyby(data, res.status));
							return;
						}
						const st = get_form_state();
						st.objekty = data.objekty;
						set_form_state(st);
						$('#sit_token').val(data.token || '');
						zobrazModel('Model: ' + soubor.name + ' (' + data.objekty.length + ' ' + (data.objekty.length === 1 ? 'objekt' : (data.objekty.length < 5 ? 'objekty' : 'objektů')) + '), export 3MF použije jeho sítě.');
						setStatus('Načteno z ' + soubor.name);
						if (Array.isArray(data.varovani) && data.varovani.length) alert('Upozornění:\n' + data.varovani.join('\n'));
					} catch (e) {
						$("#local_status").text('');
						alert('Import se nepovedl: ' + e);
					} finally {
						input.value = '';
					}
				});
				$('#model_zapomenout').on('click', function () {
					// Bez modelu: export 3MF udělá kvádry zadaných rozměrů.
					$('#sit_token').val('');
					$("table#objekty input.sit").remove();
					zobrazModel('');
				});

				// --- Export 3MF: stejné parametry jako zobrazený výsledek + format=3mf; chyba = hláška, jinak stažení souboru.
				$('#download_3mf').on('click', async function () {
					const $btn = $(this);
					const params = new URLSearchParams(window.location.search);
					params.set('format', '3mf');
					$btn.prop('disabled', true);
					try {
						const res = await fetch('./index.php?' + params.toString());
						if (!res.ok) {
							let data = null;
							try { data = await res.json(); } catch (e) { data = null; }
							alert('Export 3MF se nepovedl: ' + zpravaChyby(data, res.status));
							return;
						}
						const blob = await res.blob();
						const cd = res.headers.get('Content-Disposition') || '';
						const m = cd.match(/filename="([^"]+)"/);
						const url = URL.createObjectURL(blob);
						const a = document.createElement('a');
						a.href = url;
						a.download = m ? m[1] : 'sekvencni-tisk.3mf';
						document.body.appendChild(a);
						a.click();
						a.remove();
						setTimeout(() => URL.revokeObjectURL(url), 1000);
					} catch (e) {
						alert('Export 3MF se nepovedl: ' + e);
					} finally {
						$btn.prop('disabled', false);
					}
				});

				// U velmi malých instancí schovej popisky, aby nepřetékaly mimo obdélník (mobil).
				function adaptInstanceLabels() {
					const bed = document.getElementById('tiskova_podlozka');
					if (!bed) return;
					bed.querySelectorAll('.instance').forEach(inst => {
						const rect = inst.getBoundingClientRect();
						const label = inst.firstElementChild;
						if (!label) return;
						const tooSmall = rect.width < 26 || rect.height < 26;
						label.style.display = tooSmall ? 'none' : '';
					});
				}
				adaptInstanceLabels();
				window.addEventListener('resize', adaptInstanceLabels);

				// Header klik = návrat na homepage bez GET parametrů
				$('#app_header').on('click', function (e) {
					// Odkazy a tlačítka účtu v hlavičce mají vlastní akci.
					if (e.target.closest && e.target.closest('#ucet_panel')) return;
					window.location.href = window.location.pathname;
				});

				// --- Projekty (jen přihlášený uživatel s DB): uložení vstupu formuláře k účtu (ucet.php?akce=ulozit_projekt).
				const PROJEKTY_CSRF = <?php echo json_encode($uzivatel ? Relace::csrfToken() : "", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
				let aktualniProjekt = <?php echo json_encode($aktualni_projekt, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);?>;
				function otevriProjektModal() {
					const $nazev = $('#projekt_nazev_vstup');
					let navrh = aktualniProjekt ? aktualniProjekt.nazev : '';
					if (!navrh) {
						const prvni = ($("table#objekty input.nazev").first().val() || '').trim();
						navrh = prvni !== '' ? prvni : ('Projekt ' + new Date().toLocaleDateString('cs-CZ'));
					}
					$nazev.val(navrh);
					$('#projekt_chyba').text('');
					// Otevřený projekt: Uložit změny (přepíše) i Uložit jako nový; jinak jen uložit nový.
					$('#projekt_ulozit_zmeny').toggle(!!aktualniProjekt);
					$('#projekt_ulozit_novy').toggleClass('primary', !aktualniProjekt).toggleClass('ghost', !!aktualniProjekt);
					setBtnLabel(document.getElementById('projekt_ulozit_novy'), aktualniProjekt ? 'Uložit jako nový' : 'Uložit');
					$('#projekt_modal').removeAttr('hidden');
					$nazev.trigger('focus').trigger('select');
				}
				function zavriProjektModal() { $('#projekt_modal').attr('hidden', 'hidden'); }
				async function ulozProjekt(jakoNovy) {
					const nazev = ($('#projekt_nazev_vstup').val() || '').trim();
					if (nazev === '') { $('#projekt_chyba').text('Vyplň název projektu.'); return; }
					const telo = { nazev: nazev, data: get_form_state() };
					if (!jakoNovy && aktualniProjekt) telo.id = aktualniProjekt.id;
					const $tlacitka = $('#projekt_ulozit_zmeny, #projekt_ulozit_novy').prop('disabled', true);
					try {
						const odpoved = await fetch('ucet.php?akce=ulozit_projekt', {
							method: 'POST',
							credentials: 'same-origin',
							headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': PROJEKTY_CSRF },
							body: JSON.stringify(telo)
						});
						let data = null;
						try { data = await odpoved.json(); } catch (e) { data = null; }
						if (!odpoved.ok || !data || !data.projekt) {
							$('#projekt_chyba').text(zpravaChyby(data, odpoved.status));
							return;
						}
						aktualniProjekt = data.projekt;
						$('#projekt_id').val(String(aktualniProjekt.id)).prop('disabled', false);
						$('#projekt_nazev').text(aktualniProjekt.nazev);
						$('#projekt_info').removeAttr('hidden');
						// Obnovení stránky zůstane u projektu.
						try {
							const params = new URLSearchParams(window.location.search);
							params.set('projekt', String(aktualniProjekt.id));
							history.replaceState(null, '', window.location.pathname + '?' + params.toString() + window.location.hash);
						} catch (e) { /* jen pohodlí */ }
						zavriProjektModal();
						setStatus('Projekt uložen');
					} catch (e) {
						$('#projekt_chyba').text('Projekt se nepodařilo uložit (síť): ' + e);
					} finally {
						$tlacitka.prop('disabled', false);
					}
				}
				$('#ulozit_projekt').on('click', otevriProjektModal);
				$('#projekt_zavrit').on('click', zavriProjektModal);
				$('#projekt_modal').on('click', function (e) { if (e.target && e.target.id === 'projekt_modal') zavriProjektModal(); });
				$('#projekt_ulozit_zmeny').on('click', function () { ulozProjekt(false); });
				$('#projekt_ulozit_novy').on('click', function () { ulozProjekt(true); });
				$('#projekt_nazev_vstup').on('keydown', function (e) {
					if (e.key === 'Enter') { e.preventDefault(); ulozProjekt(!aktualniProjekt); }
				});
				$(document).on('keydown', function (e) { if (e.key === 'Escape') zavriProjektModal(); });

				// Mobil: detaily bez hover + „tisková hlava“
				let headEnabled = false;
				let selectedIdx = null;

				function setDetails(text) {
					const el = document.getElementById('instance_details');
					if (!el) return;
					el.textContent = text || '';
				}

				function pickHeadStep(headSteps, height) {
					if (!Array.isArray(headSteps) || headSteps.length === 0) return null;
					let best = headSteps[0];
					for (const s of headSteps) {
						if (Number(s.z) <= height) best = s;
						else break;
					}
					return best;
				}

				// Kusy právě zobrazené podložky (u více podložek jsou ostatní skryté třídou jina-podlozka).
				function instanceAktualniPodlozky(bed) {
					return bed ? Array.from(bed.querySelectorAll('.instance:not(.jina-podlozka)')) : [];
				}

				function renderHeadForSelection() {
					const bed = document.getElementById('tiskova_podlozka');
					if (!bed) return;
					const overlay = bed.querySelector('.overlay');
					if (!overlay) return;
					overlay.innerHTML = '';

					bed.querySelectorAll('.instance.selected').forEach(el => el.classList.remove('selected'));
					const instances = instanceAktualniPodlozky(bed);
					if (!headEnabled || selectedIdx === null || !instances[selectedIdx]) return;

					const el = instances[selectedIdx];
					el.classList.add('selected');

					const bedX = parseFloat(bed.style.getPropertyValue('--bed-x')) || 1;
					const bedY = parseFloat(bed.style.getPropertyValue('--bed-y')) || 1;

					const o = parseInt(el.dataset.o || '0', 10);
					const i = parseInt(el.dataset.i || '0', 10);
					const ox = parseFloat(el.dataset.ox || '0');
					const oy = parseFloat(el.dataset.oy || '0');
					const oz = parseFloat(el.dataset.oz || '0');
					const left = parseFloat(el.dataset.left || '0');
					const bottom = parseFloat(el.dataset.bottom || '0');

					let headSteps = [];
					let printer = {};
					try { headSteps = bed.dataset.headSteps ? JSON.parse(bed.dataset.headSteps) : []; } catch (e) { headSteps = []; }
					try { printer = bed.dataset.printer ? JSON.parse(bed.dataset.printer) : {}; } catch (e) { printer = {}; }

					// Tryska v nejvíc kolizním rohu podle globálního směru tisku.
					const smerX = (printer && printer.smer_X) ? printer.smer_X : 'zleva_doprava';
					const smerY = (printer && printer.smer_Y) ? printer.smer_Y : 'zepredu_dozadu';
					// Podle upřesnění: při tisku "zprava" je kolizní strana vpravo.
					const nozzleX = (smerX === 'zleva_doprava') ? left : (left + ox);
					const nozzleY = (smerY === 'zepredu_dozadu') ? bottom : (bottom + oy);

					const step = pickHeadStep(headSteps, oz) || { Xl: printer.Xl, Xr: printer.Xr, Yl: printer.Yl, Yr: printer.Yr };

					const hx0 = nozzleX - (parseFloat(step.Xl) || 0);
					const hx1 = nozzleX + (parseFloat(step.Xr) || 0);
					const hy0 = nozzleY - (parseFloat(step.Yl) || 0);
					const hy1 = nozzleY + (parseFloat(step.Yr) || 0);

					const head = document.createElement('div');
					head.className = 'head';
					head.style.left = (hx0 / bedX * 100) + '%';
					head.style.bottom = (hy0 / bedY * 100) + '%';
					head.style.width = ((hx1 - hx0) / bedX * 100) + '%';
					head.style.height = ((hy1 - hy0) / bedY * 100) + '%';
					overlay.appendChild(head);

					// Debug obdélník (čárkovaně): hlava v protějším X rohu objektu (kde tryska dojede na konec osy X)
					const debugHead = document.createElement('div');
					debugHead.className = 'head debug-head';
					const debugNozzleX = (smerX === 'zleva_doprava') ? (left + ox) : left;
					const debugNozzleY = nozzleY; // Zůstáváme na stejné Y úrovni (vedlejší roh v ose X)

					const dhx0 = debugNozzleX - (parseFloat(step.Xl) || 0);
					const dhx1 = debugNozzleX + (parseFloat(step.Xr) || 0);
					const dhy0 = debugNozzleY - (parseFloat(step.Yl) || 0);
					const dhy1 = debugNozzleY + (parseFloat(step.Yr) || 0);

					debugHead.style.left = (dhx0 / bedX * 100) + '%';
					debugHead.style.bottom = (dhy0 / bedY * 100) + '%';
					debugHead.style.width = ((dhx1 - dhx0) / bedX * 100) + '%';
					debugHead.style.height = ((dhy1 - dhy0) / bedY * 100) + '%';
					overlay.appendChild(debugHead);

					const noz = document.createElement('div');
					noz.className = 'nozzle';
					noz.style.left = (nozzleX / bedX * 100) + '%';
					noz.style.bottom = (nozzleY / bedY * 100) + '%';
					overlay.appendChild(noz);

					// Vodící tyč zobraz jen když nějaký dřívější objekt (v pořadí tisku) byl vyšší než prahová Z.
					const vodiciZ = (printer && printer.vodici_tyce_Z !== undefined) ? parseFloat(printer.vodici_tyce_Z) : null;
					let showRod = false;
					if (vodiciZ !== null && Number.isFinite(vodiciZ)) {
						const selectedSeq = parseInt(el.dataset.seq || '0', 10);
						for (const inst of instances) {
							const seq = parseInt(inst.dataset.seq || '0', 10);
							if (seq > 0 && seq < selectedSeq) {
								const zPrev = parseFloat(inst.dataset.oz || '0');
								if (zPrev > vodiciZ) { showRod = true; break; }
							}
						}
					}

					if (showRod && printer && printer.vodici_tyce_Y !== undefined) {
						const rod = document.createElement('div');
						rod.className = 'rod';
						const vodiciY = parseFloat(printer.vodici_tyce_Y);
						// Vodící tyče jsou na pohyblivé části – zobrazíme je relativně k trysce.
						const rodY = (smerY === 'zepredu_dozadu') ? (nozzleY + vodiciY) : (nozzleY - vodiciY);
						const rodYClamped = Math.max(0, Math.min(bedY, rodY));
						rod.style.bottom = (rodYClamped / bedY * 100) + '%';
						overlay.appendChild(rod);
					}

					const zInfo = (showRod && printer && printer.vodici_tyce_Z !== undefined) ? `, vodící tyče od Z=${printer.vodici_tyce_Z}mm` : '';
					const nazevInfo = el.dataset.nazev ? ` (${el.dataset.nazev})` : '';
					const otocenoInfo = el.dataset.otoceno ? ' (otočeno o 90°)' : '';
					setDetails(`Vybráno: Objekt ${o}${nazevInfo}${otocenoInfo}, instance ${i} — výška ${oz}mm${zInfo}`);
				}

				function setSelected(idx) {
					const bed = document.getElementById('tiskova_podlozka');
					if (!bed) return;
					const instances = instanceAktualniPodlozky(bed);
					if (!instances.length) return;
					selectedIdx = Math.max(0, Math.min(idx, instances.length - 1));
					renderHeadForSelection();
				}

				$(document).on('click', '#tiskova_podlozka .instance', function () {
					const bed = document.getElementById('tiskova_podlozka');
					const instances = instanceAktualniPodlozky(bed);
					const idx = instances.indexOf(this);
					if (idx >= 0) {
						// Na klik vždy vyberu instanci (a zobrazím detaily); hlava jen pokud je zapnutá.
						const o = this.dataset.o || '?';
						const i = this.dataset.i || '?';
						// textContent: název objektu se nevkládá jako HTML.
						setDetails(`Objekt ${o}, instance ${i}\n${this.getAttribute('title') || ''}`);
						setSelected(idx);
					}
				});

				$('#toggle_head').on('click', function () {
					headEnabled = !headEnabled;
					$('#head_controls').css('display', headEnabled ? 'inline-flex' : 'none');
					setBtnLabel(this, headEnabled ? 'Skrýt tiskovou hlavu' : 'Zobrazit tiskovou hlavu');
					if (headEnabled) {
						// Default: 2. instance (pokud existuje)
						const bed = document.getElementById('tiskova_podlozka');
						const instances = instanceAktualniPodlozky(bed);
						setSelected(instances.length >= 2 ? 1 : 0);
					} else {
						renderHeadForSelection();
					}
				});
				$('#head_prev').on('click', function () { if (selectedIdx !== null) setSelected(selectedIdx - 1); });
				$('#head_next').on('click', function () { if (selectedIdx !== null) setSelected(selectedIdx + 1); });

				// Více podložek: přepínání záložek „Podložka N“ (zobrazí jen kusy vybrané podložky).
				$(document).on('click', '.podlozka_tab', function () {
					const cislo = this.getAttribute('data-podlozka');
					$('.podlozka_tab').removeClass('active').attr('aria-selected', 'false');
					$(this).addClass('active').attr('aria-selected', 'true');
					const bed = document.getElementById('tiskova_podlozka');
					if (!bed) return;
					bed.querySelectorAll('.instance').forEach(el => el.classList.toggle('jina-podlozka', el.getAttribute('data-podlozka') !== cislo));
					setDetails('');
					selectedIdx = null;
					if (headEnabled) {
						const instances = instanceAktualniPodlozky(bed);
						setSelected(instances.length >= 2 ? 1 : 0);
					} else {
						renderHeadForSelection();
					}
					adaptInstanceLabels();
				});

				// Mobil: po výpočtu schovej celé sekce (včetně nadpisu) a nech jen tlačítka
				function applyMobileCollapse() {
					const isSmall = window.matchMedia && window.matchMedia('(max-width: 720px)').matches;
					const hasResult = document.querySelector('[data-has-result="1"]');
					if (!isSmall || !hasResult) return;
					['#section_form', '#section_positions', '#section_json'].forEach(sel => {
						const el = document.querySelector(sel);
						if (el) el.classList.add('mobile-hidden');
					});
					document.querySelectorAll('.mobile-section-toggle').forEach(btn => {
						const label = btn.getAttribute('data-label') || 'sekci';
						setBtnLabel(btn, 'Zobrazit ' + label, 'eye');
					});
				}
				applyMobileCollapse();
				$(document).on('click', '.mobile-section-toggle', function(){
					const target = this.getAttribute('data-target');
					const el = target ? document.querySelector(target) : null;
					if (!el) return;
					const label = this.getAttribute('data-label') || 'sekci';
					const isHidden = el.classList.contains('mobile-hidden');
					if (isHidden) {
						el.classList.remove('mobile-hidden');
						setBtnLabel(this, 'Skrýt ' + label, 'eye-off');
					} else {
						el.classList.add('mobile-hidden');
						setBtnLabel(this, 'Zobrazit ' + label, 'eye');
					}
				});
			});
		</script>
    <style>
			:root {
				/* --- PrusaSlicer Accurate Palette --- */
				--bg-body: #202020;       /* Main window background */
				--bg-panel: #292929;      /* Panels / Sidebar */
				--bg-input: #1a1a1a;      /* Inset inputs */
				--bg-header: #181818;     /* Top strip / Brand area */
				--bg-active: #383838;     /* Hover states / Active rows */

				--accent-primary: #fd6925; /* Official Prusa Orange */
				--accent-hover: #e0551a;
				--accent-text: #ffffff;

				--text-main: #eeeeee;
				--text-muted: #999999;

				--border-color: #444444;  /* Standard border */
				--border-input: #555555;  /* Input specific border */
				--border-focus: #fd6925;

				--success: #75b54b;
				--danger: #ff4d4d;

				/* Back-compat proměnné */
				--bg: var(--bg-body);
				--card: var(--bg-panel);
				--text: var(--text-main);
				--muted: var(--text-muted);
				--border: var(--border-color);
				--accent: var(--accent-primary);
				--accent-2: var(--accent-hover);
				--shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
				--radius: 14px;
			}

			* { box-sizing: border-box; }
			body {
				margin: 0;
				background: var(--bg);
				color: var(--text);
				font: 14px/1.45 system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Arial, sans-serif;
			}
			a { color: var(--accent); }
			.container { max-width: none; margin: 0 auto; padding: 18px; }
			/* Siroky monitor: vizualizace vpravo vedle formulare, pri posouvani zustava videt (Ondra 8. 11. 2026). */
			@media (min-width: 1100px) {
				.app-grid:has(> .app-right) { display: grid; grid-template-columns: minmax(540px, 1fr) auto; gap: 14px; align-items: start; }
				.app-right { position: sticky; top: 12px; }
				/* karta podle podlozky: texty (popis, detail instance pri najeti) se zalomi, sloupec nerozsiri */
				.app-right .card:not(.is-fullscreen) { width: min-content; }
				.app-grid:has(> .app-right) .layout { grid-template-columns: 1fr; }
				/* podlozka co nejvetsi, ale cela na vysku okna */
				.app-right .card:not(.is-fullscreen) #tiskova_podlozka { max-width: none; width: min(calc((100vh - 210px) * var(--bed-x) / var(--bed-y)), calc(100vw - 640px)); margin: 0; }
				/* pravy sloupec jen tak siroky jako podlozka (bez prazdneho mista po stranach), zbytek dostane formular */
			}
			.app-right { margin-top: 14px; }
			@media (min-width: 1100px) { .app-right { margin-top: 0; } }
			.header {
				display: flex;
				gap: 12px;
				align-items: baseline;
				justify-content: space-between;
				margin-bottom: 14px;
				background: var(--bg-header);
				border: 1px solid var(--border-color);
				border-radius: var(--radius);
				padding: 12px 14px;
				cursor: pointer;
			}
			.header h1 { margin: 0; font-size: 20px; }
			.header .sub { color: var(--muted); font-size: 13px; }
			/* Účet v hlavičce (jen s DB): vpravo, na úzkém displeji pod nadpisem. */
			.header { flex-wrap: wrap; }
			.header:has(.ucet-panel) .sub { margin-right: auto; }
			.ucet-panel { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; cursor: default; }
			.ucet-panel .ucet-email { display: inline-flex; gap: 0.4em; align-items: center; color: var(--muted); font-weight: 600; max-width: 22rem; overflow: hidden; }
			.ucet-panel .ucet-email span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.ucet-panel .ucet-odkaz { display: inline-flex; gap: 0.4em; align-items: center; font-weight: 600; text-decoration: none; }
			.ucet-panel .ucet-odkaz:hover { text-decoration: underline; }
			.ucet-panel form { margin: 0; }
			.modal .panel.panel-uzky { width: min(92vw, 480px); }
			.projekt-pole { display: grid; gap: 6px; font-weight: 600; }
			.projekt-pole input { width: 100%; }
			.projekt-chyba { color: var(--danger); font-size: 13px; min-height: 1em; margin-top: 6px; }

			h2 { margin: 18px 0 10px; font-size: 16px; }
			.card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 14px; }
			.card-title { margin: 0; font-size: 16px; }

			.toolbar { display: flex; gap: 10px; flex-wrap: wrap; margin: 12px 0 2px; }
			button, input[type=number], textarea, select {
				border-radius: 12px;
				border: 1px solid var(--border-input);
				background: var(--bg-input);
				color: var(--text);
				padding: 9px 12px;
				font: inherit;
			}
			button { cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 0.45em; }
			/* Ikony (SVG sprite, styl Lucide): velikost podle písma, barva podle textu tlačítka. */
			.ico { width: 1.1em; height: 1.1em; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
			button.icon-only { padding: 7px 8px; }
			button.icon-only .ico { width: 1.25em; height: 1.25em; }
			button:disabled { opacity: 0.6; cursor: progress; }
			/* Nabídka „Další funkce“ */
			.more-menu { position: relative; }
			.more-menu > summary {
				list-style: none; cursor: pointer; user-select: none;
				display: inline-flex; align-items: center; gap: 0.45em; height: 100%;
				border-radius: 12px; border: 1px solid var(--border-input); background: var(--card); color: var(--text); padding: 9px 12px;
			}
			.more-menu > summary::-webkit-details-marker { display: none; }
			.more-menu > summary:hover, .more-menu[open] > summary { background: var(--bg-active); }
			.more-menu > summary:focus-visible { outline: 2px solid var(--border-focus); outline-offset: 1px; }
			.more-menu > summary .chevron { width: 0.9em; height: 0.9em; transition: transform 0.12s; }
			.more-menu[open] > summary .chevron { transform: rotate(180deg); }
			.more-menu-panel {
				position: absolute; z-index: 50; top: calc(100% + 6px); left: 0;
				width: max-content; min-width: 240px; max-width: calc(100vw - 32px);
				display: flex; flex-direction: column; gap: 2px; padding: 6px;
				background: var(--card); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow);
			}
			.more-menu.align-right .more-menu-panel { left: auto; right: 0; }
			.more-menu-panel button { justify-content: flex-start; width: 100%; text-align: left; background: transparent; border-color: transparent; border-radius: 8px; }
			.more-menu-panel button:hover { background: var(--bg-active); }
			.more-menu-panel .sep { height: 1px; background: var(--border); margin: 4px 6px; }
			button.primary { background: var(--accent); border-color: var(--accent); color: var(--accent-text); font-weight: 700; }
			button.primary:hover { background: var(--accent-2); border-color: var(--accent-2); }
			button.ghost { background: var(--card); }
			button:hover { background: var(--bg-active); }
			button.small { padding: 7px 10px; border-radius: 10px; font-size: 13px; }
			button:active { transform: translateY(1px); }
			input[type=number] { width: 100%; min-width: 74px; text-align: right; }
			input[type=number]:focus, textarea:focus, button:focus {
				outline: 2px solid var(--border-focus);
				outline-offset: 1px;
			}
			/* Kompaktní inputy v tabulce objektů */
			table#objekty { width: max-content; }
			table#objekty th, table#objekty td { padding: 8px 8px; }
			table#objekty input.dim { max-width: 5.2rem; min-width: 4.2rem; }
			table#objekty input.instances_num { max-width: 4.6rem; min-width: 3.8rem; }
			table#objekty td.actions { text-align: left; white-space: nowrap; }
			table#objekty td.instances_cell { white-space: nowrap; }
			table#objekty td.instances_cell .instances_row { flex-wrap: nowrap !important; gap: 8px !important; }
			table#objekty td.instances_cell label { white-space: nowrap; }

			.table-wrap { overflow-x: auto; }
			table { width: 100%; border-collapse: separate; border-spacing: 0; }
			th, td { padding: 10px 10px; border-bottom: 1px solid var(--border-color); vertical-align: top; }
			th { text-align: left; color: var(--muted); font-weight: 700; font-size: 12px; }
			td { text-align: right; }
			/* Necháme výchozí: th vlevo, td vpravo (i 1. sloupec bývá často číslo). */
			tr:last-child td { border-bottom: none; }

			#nastaveni { margin-top: 12px; }
			#nastaveni { margin-top: 12px; max-width: 560px; }
			#nastaveni .grid { display: grid; grid-template-columns: 1fr; gap: 8px; }
			#nastaveni label {
				display: flex;
				align-items: center;
				gap: 10px;
				font-weight: 600;
				color: var(--text);
			}
			#nastaveni label .hint { color: var(--muted); font-weight: 600; }
			#nastaveni .sub-options { display: grid; gap: 6px; padding-left: 26px; }
			table#objekty input.nazev { width: 10rem; min-width: 6rem; }
			input[type=text] {
				border-radius: 12px;
				border: 1px solid var(--border-input);
				background: var(--bg-input);
				color: var(--text);
				padding: 9px 12px;
				font: inherit;
			}
			input[type=text]:focus { outline: 2px solid var(--border-focus); outline-offset: 1px; }

			.remaining-grid { display: grid; grid-template-columns: repeat(2, max-content); gap: 14px; align-items: start; }
			@media (max-width: 980px) { .remaining-grid { grid-template-columns: 1fr; } }
			table.compact { width: auto; }
			table.compact th, table.compact td { white-space: nowrap; }

			.layout { display: grid; grid-template-columns: 1.2fr 1fr; gap: 14px; align-items: start; }
			@media (max-width: 980px) { .layout { grid-template-columns: 1fr; } }

			.bed-wrap { display: grid; gap: 10px; }
			
			/* Fullscreen styles */
			.card.is-fullscreen {
				background: var(--bg-body);
				border-radius: 0;
				border: none;
				display: flex;
				flex-direction: column;
				justify-content: center;
				overflow: auto;
			}
			.card.is-fullscreen #tiskova_podlozka {
				max-width: none;
				/* Zachování poměru stran: výška bude max 85vh, šířka se dopočítá podle aspect-ratio. */
				width: auto; 
				height: auto;
				max-height: 85vh;
				/* Pokud by podložka byla moc široká, omezíme i šířku (např. 95vw) */
				max-width: 95vw;
				aspect-ratio: var(--bed-x) / var(--bed-y);
				margin: auto;
			}
			
			#tiskova_podlozka {
				position: relative;
				width: 100%;
				max-width: 720px;
				aspect-ratio: var(--bed-x) / var(--bed-y);
				border-radius: 0;
				border: 1px solid var(--border-color);
				background:
					linear-gradient(to right, rgba(255,255,255,0.06) 1px, transparent 1px) 0 0 / var(--grid-x) var(--grid-y),
					linear-gradient(to top, rgba(255,255,255,0.06) 1px, transparent 1px) 0 0 / var(--grid-x) var(--grid-y),
					var(--bg-input);
				box-shadow: var(--shadow);
				overflow: hidden;
				margin: 0 auto;
			}
			#tiskova_podlozka .overlay { position: absolute; inset: 0; pointer-events: none; z-index: 10; }
			#tiskova_podlozka .rod {
				position: absolute;
				left: 0;
				right: 0;
				height: 2px;
				background: rgba(238,238,238,0.35);
			}
			#tiskova_podlozka .head {
				position: absolute;
				border: 2px solid rgba(253,105,37,0.85);
				background: rgba(253,105,37,0.12);
			}
			#tiskova_podlozka .head.debug-head {
				border-style: dashed;
				background: rgba(253,105,37,0.05);
				border-color: rgba(253,105,37,0.5);
			}
			#tiskova_podlozka .nozzle {
				position: absolute;
				width: 8px;
				height: 8px;
				margin-left: -4px;
				margin-bottom: -4px;
				border-radius: 50%;
				background: rgba(253,105,37,0.95);
				box-shadow: 0 6px 16px rgba(253,105,37,0.35);
			}
			#tiskova_podlozka .instance {
				position: absolute;
				left: calc(var(--x) * 1%);
				bottom: calc(var(--y) * 1%);
				width: calc(var(--w) * 1%);
				height: calc(var(--h) * 1%);
				border-radius: 0;
				background: linear-gradient(135deg, rgba(253,105,37,0.95), rgba(224,85,26,0.92));
				color: #fff;
				display: grid;
				place-items: center;
				font-weight: 700;
				font-size: 12px;
				box-shadow: 0 8px 20px rgba(0,0,0,0.30);
				user-select: none;
				z-index: 2;
				overflow: hidden; /* klíčové pro malé instance na mobilu */
			}
			#tiskova_podlozka .instance > div {
				max-width: 100%;
				max-height: 100%;
				overflow: hidden;
				line-height: 1.05;
				font-size: clamp(9px, 2.3vw, 12px);
			}
			#tiskova_podlozka .instance:hover {
				outline: 3px solid rgba(253,105,37,0.45);
				outline-offset: 2px;
			}
			#tiskova_podlozka .instance.selected {
				outline: 3px solid rgba(253,105,37,0.70);
				outline-offset: 1px;
			}

			.modal {
				position: fixed;
				inset: 0;
				background: rgba(0, 0, 0, 0.65);
				display: grid;
				place-items: center;
				padding: 16px;
				z-index: 50;
			}
			.modal[hidden] { display: none !important; }
			.modal .panel {
				width: min(92vw, 900px);
				background: var(--bg-panel);
				border-radius: var(--radius);
				border: 1px solid var(--border-color);
				box-shadow: var(--shadow);
				padding: 14px;
			}
			.modal .panel .top {
				display: flex;
				gap: 10px;
				align-items: baseline;
				justify-content: space-between;
				margin-bottom: 8px;
			}
			.modal .panel h3 { margin: 0; font-size: 15px; }
			.modal .hint { color: var(--muted); font-size: 13px; margin: 6px 0 10px; }
			#io_text { width: 100%; min-height: 220px; }
			#tiskova_podlozka .instance small { opacity: 0.9; font-weight: 600; }
			/* Kus otočený o 90° kolem Z: šrafování, aby bylo vidět i bez tooltipu. */
			#tiskova_podlozka .instance.otocene {
				background: repeating-linear-gradient(45deg, rgba(253,105,37,0.95) 0 6px, rgba(200,70,15,0.95) 6px 12px);
			}
			table#objekty td.rotace_cell { text-align: center; }
			#nastaveni select { min-width: 12rem; }
			#nastaveni input#max_podlozek { width: 90px; min-width: 70px; }
			/* Více podložek: záložky „Podložka 1/2/…“, kusy ostatních podložek jsou skryté. */
			#podlozky_prepinac { margin: 0 0 10px; }
			#podlozky_prepinac .podlozka_tab.active { background: var(--accent); border-color: var(--accent); color: var(--accent-text); font-weight: 700; }
			#tiskova_podlozka .instance.jina-podlozka { display: none; }

			/* Mobile: po výpočtu schovej sekundární sekce (celá sekce včetně nadpisu) */
			.mobile-section-toggle { display: none; width: fit-content; }
			@media (max-width: 720px) {
				.mobile-section-toggle {
					display: inline-flex;
					width: fit-content;
					max-width: 100%;
					justify-content: flex-start;
					justify-self: start;
					align-self: start;
					place-self: start;
					margin: 10px 0;
				}
				.mobile-section { display: block; }
				.mobile-hidden { display: none !important; }
			}

			#json_textarea {
				width: 100%;
				min-height: 120px;
				border-radius: 12px;
				border: 1px solid var(--border-input);
				padding: 10px 12px;
				font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
				font-size: 12px;
				color: var(--text-main);
			}
    </style>
  </head>
  <body>
		<!-- Ikony: tvary z Lucide (https://lucide.dev, licence ISC), použití <svg class="ico"><use href="#i-…"/></svg> přes ikona() v PHP. -->
		<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" style="position:absolute; overflow:hidden;" aria-hidden="true" focusable="false">
			<symbol id="i-calculator" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="20" x="4" y="2" rx="2"/><line x1="8" x2="16" y1="6" y2="6"/><line x1="16" x2="16" y1="14" y2="18"/><path d="M16 10h.01"/><path d="M12 10h.01"/><path d="M8 10h.01"/><path d="M12 14h.01"/><path d="M8 14h.01"/><path d="M12 18h.01"/><path d="M8 18h.01"/></symbol>
			<symbol id="i-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></symbol>
			<symbol id="i-settings" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></symbol>
			<symbol id="i-file-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M12 12v6"/><path d="m15 15-3-3-3 3"/></symbol>
			<symbol id="i-more" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></symbol>
			<symbol id="i-chevron-down" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></symbol>
			<symbol id="i-lightbulb" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></symbol>
			<symbol id="i-save" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></symbol>
			<symbol id="i-folder-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2"/></symbol>
			<symbol id="i-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></symbol>
			<symbol id="i-share" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" x2="12" y1="2" y2="15"/></symbol>
			<symbol id="i-import" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m8 11 4 4 4-4"/><path d="M8 5H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-4"/></symbol>
			<symbol id="i-link" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></symbol>
			<symbol id="i-download" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></symbol>
			<symbol id="i-copy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></symbol>
			<symbol id="i-copy-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="15" x2="15" y1="12" y2="18"/><line x1="12" x2="18" y1="15" y2="15"/><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></symbol>
			<symbol id="i-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></symbol>
			<symbol id="i-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></symbol>
			<symbol id="i-arrow-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 7-7 7 7"/><path d="M12 19V5"/></symbol>
			<symbol id="i-arrow-down" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></symbol>
			<symbol id="i-chevron-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></symbol>
			<symbol id="i-chevron-right" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></symbol>
			<symbol id="i-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></symbol>
			<symbol id="i-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></symbol>
			<symbol id="i-maximize" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></symbol>
			<symbol id="i-minimize" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3"/><path d="M21 8h-3a2 2 0 0 1-2-2V3"/><path d="M3 16h3a2 2 0 0 1 2 2v3"/><path d="M16 21v-3a2 2 0 0 1 2-2h3"/></symbol>
			<symbol id="i-crosshair" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="22" x2="18" y1="12" y2="12"/><line x1="6" x2="2" y1="12" y2="12"/><line x1="12" x2="12" y1="6" y2="2"/><line x1="12" x2="12" y1="22" y2="18"/></symbol>
			<symbol id="i-square-dashed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3a2 2 0 0 0-2 2"/><path d="M19 3a2 2 0 0 1 2 2"/><path d="M21 19a2 2 0 0 1-2 2"/><path d="M5 21a2 2 0 0 1-2-2"/><path d="M9 3h1"/><path d="M9 21h1"/><path d="M14 3h1"/><path d="M14 21h1"/><path d="M3 9v1"/><path d="M21 9v1"/><path d="M3 14v1"/><path d="M21 14v1"/></symbol>
			<symbol id="i-box" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></symbol>
			<symbol id="i-cloud-upload" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 13v8"/><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m8 17 4-4 4 4"/></symbol>
			<symbol id="i-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol>
			<symbol id="i-log-in" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/></symbol>
			<symbol id="i-log-out" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></symbol>
			<symbol id="i-layers"viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/></symbol>
		</svg>
		<div class="container">
			<div id="app_header" class="header" title="Zpět na úvod (bez parametrů)">
				<h1>Sekvenční tisk</h1>
				<div class="sub">Výpočet rozložení instancí pro sekvenční tisk</div>
<?php if ($ucty_zapnute) {?>
				<div id="ucet_panel" class="ucet-panel">
<?php if ($uzivatel) {?>
					<span class="ucet-email" title="Přihlášený uživatel"><?php echo ikona("user");?><span><?php echo htmlspecialchars($uzivatel["email"], ENT_QUOTES, "UTF-8");?></span></span>
					<a class="ucet-odkaz" href="ucet.php?stranka=projekty"><?php echo ikona("folder-open");?>Moje projekty</a>
					<form method="post" action="ucet.php" class="ucet-odhlasit">
						<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(Relace::csrfToken(), ENT_QUOTES, "UTF-8");?>" />
						<input type="hidden" name="akce" value="odhlasit" />
						<button class="small ghost" type="submit"><?php echo ikona("log-out");?>Odhlásit</button>
					</form>
<?php } else {?>
					<a class="ucet-odkaz" href="<?php echo htmlspecialchars($odkaz_prihlaseni, ENT_QUOTES, "UTF-8");?>"><?php echo ikona("log-in");?>Přihlásit</a>
<?php if (Ucty::registraceZapnuta()) {?>
					<a class="ucet-odkaz" href="<?php echo htmlspecialchars(str_replace("stranka=prihlaseni", "stranka=registrace", $odkaz_prihlaseni), ENT_QUOTES, "UTF-8");?>">Registrovat</a>
<?php }?>
<?php }?>
				</div>
<?php }?>
			</div>
<?php $html_vizualizace = ''; // vizualizace vedle formulare: plni se v bloku vysledku, vypisuje v .app-right ?>
		<div class="app-grid">
		<div class="app-left">

		<button id="toggle_section_form" class="ghost small mobile-section-toggle" type="button" data-target="#section_form" data-label="formulář"><?php echo ikona("eye");?><span class="lbl">Zobrazit formulář</span></button>
		<div id="section_form" class="mobile-section">
		<form method="get" action="./index.php" class="card">
			<div class="toolbar" style="margin: 0 0 8px;">
				<h2 style="margin:0; flex:1;">Objekty</h2>
			</div>
			<div class="table-wrap">
			<table id="objekty">
				<tr>
					<th rowspan="2">ID<br />objektu</th>
					<th rowspan="2">Název</th>
					<th colspan="3">Rozměry objektu (mm)</th>
					<th rowspan="2">Požadovaný počet instancí</th>
					<th rowspan="2" title="Povolit otočení o 90° kolem Z, když se tak vejde víc kusů">Rotace<br />90°</th>
					<th rowspan="2">Akce</th>
					<th rowspan="2">Vypočtený<br />počet instancí</th>
				</tr>
				<tr>
					<th>x</th>
					<th>y</th>
					<th>z</th>
				</tr>
			</table>
			</div>

			<div id="nastaveni">
				<h2>Nastavení</h2>

				<div class="grid">
					<!-- TODO přidat nastavení:
					  rozměry tiskové plochy, hlavy, vodících tyčí
						tisk kalibračních objektů
						nerozprostření objektů, ale umístění na střed (X, Y)
						množství filamentu - nutno přidat k objektu jako údaj, aby se udělalo jen tolik instancí, kolik se vleze do celkového množství
						doba tisku - nutno přidat k objektu jako údaj, aby se udělalo jen tolik instancí, kolik se vleze do celkové doby
			    -->
					<label for="tiskarna">
						<span class="hint">Tiskárna</span>
						<select id="tiskarna" name="tiskarna" data-vychozi="<?php echo htmlspecialchars(PredvolbyTiskaren::vychoziId(), ENT_QUOTES, "UTF-8");?>">
<?php foreach ($predvolby_tiskaren as $id_predvolby => $nazev_predvolby) {?>
							<option value="<?php echo htmlspecialchars($id_predvolby, ENT_QUOTES, "UTF-8");?>"<?php if ($id_predvolby === $tiskarna_id) {?> selected="selected"<?php }?>><?php echo htmlspecialchars($nazev_predvolby, ENT_QUOTES, "UTF-8");?></option>
<?php }?>
						</select>
					</label>
					<label for="vice_podlozek">
						<input id="vice_podlozek" type="checkbox" name="vice_podlozek" value="1" <?php if($vice_podlozek) {?>checked="checked" <?php }?>/>
						<span class="hint">Více podložek (co se nevejde, pokračuje na další podložce; „max“ jen na první)</span>
					</label>
					<div class="sub-options">
						<label for="max_podlozek">
							<span class="hint">Nejvýš podložek</span>
							<input id="max_podlozek" type="number" name="max_podlozek" value="<?php echo (int)$max_podlozek;?>" step="1" min="1" max="<?php echo SequentialPrintCalculator::MAXIMALNI_POCET_PODLOZEK;?>" />
						</label>
					</div>
					<label for="rozprostrit_instance_v_ose_x">
						<input id="rozprostrit_instance_v_ose_x" type="checkbox" name="rozprostrit_instance_v_ose_x" value="1" <?php if($rozprostrit_instance_v_ose_x) {?>checked="checked" <?php }?>/>
						<span class="hint">Rozprostřít instance v ose X (rovnoměrně)</span>
					</label>
					<label for="rozprostrit_instance_v_ose_y">
						<input id="rozprostrit_instance_v_ose_y" type="checkbox" name="rozprostrit_instance_v_ose_y" value="1" <?php if($rozprostrit_instance_v_ose_y) {?>checked="checked" <?php }?>/>
						<span class="hint">Rozprostřít instance v ose Y (rovnoměrně)</span>
					</label>
					<div class="sub-options" role="radiogroup" aria-label="Způsob rozprostření v ose Y">
						<label for="rozprostreni_y_rezim_po_instancich">
							<input id="rozprostreni_y_rezim_po_instancich" type="radio" name="rozprostreni_y_rezim" value="po_instancich" <?php if($rozprostreni_y_rezim === "po_instancich") {?>checked="checked" <?php }?>/>
							<span class="hint">po instancích v pořadí tisku (každý další kus o krok dál)</span>
						</label>
						<label for="rozprostreni_y_rezim_po_radach">
							<input id="rozprostreni_y_rezim_po_radach" type="radio" name="rozprostreni_y_rezim" value="po_radach" <?php if($rozprostreni_y_rezim === "po_radach") {?>checked="checked" <?php }?>/>
							<span class="hint">po řadách (celá řada o stejný krok, mezery mezi řadami)</span>
						</label>
					</div>
					<label for="umistit_na_stred_v_ose_x">
						<input id="umistit_na_stred_v_ose_x" type="checkbox" name="umistit_na_stred_v_ose_x" value="1" <?php if($umistit_na_stred_v_ose_x) {?>checked="checked" <?php }?>/>
						<span class="hint">Umístit na střed v ose X</span>
					</label>
					<label for="umistit_na_stred_v_ose_y">
						<input id="umistit_na_stred_v_ose_y" type="checkbox" name="umistit_na_stred_v_ose_y" value="1" <?php if($umistit_na_stred_v_ose_y) {?>checked="checked" <?php }?>/>
						<span class="hint">Umístit na střed v ose Y</span>
					</label>
					<label for="povolit_rotaci_vsem">
						<input id="povolit_rotaci_vsem" type="checkbox" name="povolit_rotaci_vsem" value="1" <?php if($povolit_rotaci_vsem) {?>checked="checked" <?php }?>/>
						<span class="hint">Povolit rotaci o 90° všem objektům (otočí, když se tak vejde víc kusů)</span>
					</label>
					<label for="rozprostrit_instance_po_cele_podlozce">
						<input id="rozprostrit_instance_po_cele_podlozce" type="checkbox" name="rozprostrit_instance_po_cele_podlozce" value="1" <?php if($rozprostrit_instance_po_cele_podlozce) {?>checked="checked" <?php }?>/>
						<span class="hint">Rozprostřít po celé podložce (hledání řad)</span>
					</label>
				</div>
			</div>

			<div class="toolbar main-toolbar">
				<button class="primary" type="submit"><?php echo ikona("calculator");?>Vypočítat</button>
				<button class="ghost" type="button" onclick="javascript:pridej_radek_do_tabulky();"><?php echo ikona("plus");?>Přidat řádek</button>
				<button class="ghost" type="button" onclick="javascript:$('#nastaveni').toggle(120);"><?php echo ikona("settings");?>Nastavení</button>
				<button class="ghost" id="import_modelu" type="button" title="Načte objekty (rozměry, název, počet) z 3MF z PrusaSliceru nebo ze STL"><?php echo ikona("file-up");?>Import 3MF/STL</button>
				<input id="model_soubor" type="file" accept=".3mf,.stl" hidden="hidden" />
				<input id="sit_token" type="hidden" name="sit_token" value="<?php echo htmlspecialchars($sit_token, ENT_QUOTES, "UTF-8");?>" />
<?php if ($uzivatel) {?>
				<button class="ghost" id="ulozit_projekt" type="button" title="Uloží objekty a nastavení formuláře k tvému účtu"><?php echo ikona("cloud-upload");?>Uložit projekt</button>
				<input id="projekt_id" type="hidden" name="projekt" value="<?php echo $aktualni_projekt ? (int)$aktualni_projekt["id"] : "";?>"<?php if (!$aktualni_projekt) {?> disabled="disabled"<?php }?> />
<?php }?>
				<details id="more_menu" class="more-menu">
					<summary title="Příklad, uložení do prohlížeče, export a import vstupu, sdílení odkazu" aria-expanded="false"><?php echo ikona("more");?>Další funkce<svg class="ico chevron" aria-hidden="true" focusable="false"><use href="#i-chevron-down"/></svg></summary>
					<div class="more-menu-panel" role="group" aria-label="Další funkce">
<?php if ($uzivatel) {?>
						<button class="ghost" id="moje_projekty" type="button" title="Seznam uložených projektů: otevřít, přejmenovat, smazat" onclick="window.location.href='ucet.php?stranka=projekty';"><?php echo ikona("folder-open");?>Moje projekty</button>
						<div class="sep" role="separator"></div>
<?php } elseif ($ucty_zapnute) {?>
						<button class="ghost" id="prihlasit_projekty" type="button" title="Projekty se ukládají k účtu" onclick="window.location.href=<?php echo htmlspecialchars(json_encode($odkaz_prihlaseni, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8");?>;"><?php echo ikona("log-in");?>Uložit projekt (po přihlášení)</button>
						<div class="sep" role="separator"></div>
<?php }?>
						<button class="ghost" id="example_basic" type="button" title="Vyplní ukázková data"><?php echo ikona("lightbulb");?>Příklad (ukázková data)</button>
						<div class="sep" role="separator"></div>
						<button class="ghost" id="save_local" type="button" title="Uloží aktuální odkaz (query) do prohlížeče"><?php echo ikona("save");?>Uložit do prohlížeče</button>
						<button class="ghost" id="load_local" type="button" title="Načte uložený odkaz z prohlížeče"><?php echo ikona("folder-open");?>Načíst z prohlížeče</button>
						<button class="ghost" id="clear_local" type="button" title="Smaže uložené nastavení"><?php echo ikona("trash");?>Smazat uložené v prohlížeči</button>
						<div class="sep" role="separator"></div>
						<button class="ghost" id="export_input" type="button" title="Export vstupu (objekty + nastavení) do JSON"><?php echo ikona("share");?>Export vstupu (JSON)</button>
						<button class="ghost" id="import_input" type="button" title="Import vstupu (objekty + nastavení) z JSON"><?php echo ikona("import");?>Import vstupu (JSON)</button>
						<div class="sep" role="separator"></div>
						<button class="ghost" id="copy_link" type="button" title="Zkopíruje odkaz pro sdílení konfigurace"><?php echo ikona("link");?><span class="lbl">Kopírovat odkaz</span></button>
					</div>
				</details>
				<span id="local_status" style="color: var(--muted); font-weight: 600; align-self: center;"></span>
			</div>
			<div style="color: var(--muted); font-size: 13px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
<?php if ($zprava_uctu) {?>
				<span id="zprava_uctu" style="color: <?php echo $zprava_uctu["typ"] === "chyba" ? "var(--danger)" : "var(--success)";?>; font-weight: 600;"><?php echo htmlspecialchars($zprava_uctu["text"], ENT_QUOTES, "UTF-8");?></span>
<?php }?>
<?php if ($uzivatel) {?>
				<span id="projekt_info"<?php if (!$aktualni_projekt) {?> hidden="hidden"<?php }?>>Projekt: <strong id="projekt_nazev"><?php echo $aktualni_projekt ? htmlspecialchars($aktualni_projekt["nazev"], ENT_QUOTES, "UTF-8") : "";?></strong></span>
<?php }?>
				<span id="model_info"><?php if ($sit_token !== "") {?>Nahraný model: export 3MF použije jeho sítě.<?php }?></span>
				<button class="small ghost" id="model_zapomenout" type="button" title="Export 3MF udělá kvádry zadaných rozměrů"<?php if ($sit_token === "") {?> style="display:none;"<?php }?>><?php echo ikona("box");?>Bez sítí</button>
			</div>
		</form>
		</div>

		<div id="io_modal" class="modal" hidden="hidden">
			<div class="panel">
				<div class="top">
					<h3 id="io_title">Import/Export</h3>
					<button id="io_close" class="small" type="button"><?php echo ikona("x");?>Zavřít</button>
				</div>
				<div id="io_hint" class="hint"></div>
				<textarea id="io_text" placeholder='{"objekty":[...],"nastaveni":{...}}'></textarea>
				<div class="toolbar" style="margin-top: 10px;">
					<button id="io_copy" class="ghost" type="button"><?php echo ikona("copy");?>Kopírovat</button>
					<button id="io_import" class="primary" type="button"><?php echo ikona("import");?>Importovat</button>
				</div>
			</div>
		</div>
<?php if ($uzivatel) {?>

		<div id="projekt_modal" class="modal" hidden="hidden" role="dialog" aria-modal="true" aria-labelledby="projekt_modal_titulek">
			<div class="panel panel-uzky">
				<div class="top">
					<h3 id="projekt_modal_titulek">Uložit projekt</h3>
					<button id="projekt_zavrit" class="small" type="button"><?php echo ikona("x");?>Zavřít</button>
				</div>
				<div class="hint">Uloží objekty a nastavení formuláře (stejně jako export vstupu). Nahraný model 3MF/STL se neukládá.</div>
				<label for="projekt_nazev_vstup" class="projekt-pole">Název projektu
					<input id="projekt_nazev_vstup" type="text" maxlength="<?php echo Projekty::MAX_DELKA_NAZVU;?>" autocomplete="off" />
				</label>
				<div id="projekt_chyba" class="projekt-chyba" role="alert"></div>
				<div class="toolbar" style="margin-top: 10px;">
					<button id="projekt_ulozit_zmeny" class="primary" type="button"><?php echo ikona("cloud-upload");?>Uložit změny</button>
					<button id="projekt_ulozit_novy" class="ghost" type="button"><?php echo ikona("plus");?><span class="lbl">Uložit jako nový</span></button>
				</div>
			</div>
		</div>
<?php }?>

<?php
if ($chyba_tiskarny !== "") {
?>
		<div id="chyba_tiskarny" class="card" style="margin-top: 14px; color: var(--danger); font-weight: 600;"><?php echo htmlspecialchars($chyba_tiskarny, ENT_QUOTES, "UTF-8");?></div>
<?php
}
if (!empty($chyby_v_rozmerech)) {
	echo "Tyto objekty obsahují chyby v rozměrech:";
	print_r($chyby_v_rozmerech);
}

if (false and !empty($objekty)) {
?>
		<table>
			<tr>
				<th>ID objektu</th>
				<th>x</th>
				<th>y</th>
				<th>z</th>
				<th>Požadovaný počet instancí</th>
				<th>Výsledný počet instancí</th>
			</tr>
<?php
	foreach ($objekty as $id => $objekt) {
		$id_objektu = $id + 1;
		echo '<tr>';
		echo '<td>'.$id_objektu.'</td>';
		echo '<td>'.format_cislo($objekt["x"], false, 2).'&nbsp;mm</td>';
		echo '<td>'.format_cislo($objekt["y"], false, 2).'&nbsp;mm</td>';
		echo '<td>'.format_cislo($objekt["z"], false, 2).'&nbsp;mm</td>';
		echo '<td>'.$objekt["instances"]["d"].'</td>';
		echo '<td>'.$pocet_instanci_objektu[$id_objektu].'</td>';
		echo '</tr>';
	}
?>
    </table>
<?php
}
if ($text_nad_tabulkou) {
?>
		<div class="card" style="margin-top: 14px;" data-has-result="1">
			<?php echo $text_nad_tabulkou;?>
<?php if ($pocet_instanci > 0) {?>
			<div class="toolbar" style="margin: 8px 0 0;">
				<button id="download_3mf" class="small" type="button" title="Každý kus jako samostatný objekt v pořadí tisku; v PrusaSliceru zapni tisk po objektech (Complete individual objects)"><?php echo ikona("download");?>Stáhnout 3MF<?php if ($pocet_podlozek > 1) {?> (ZIP, <?php echo (int)$pocet_podlozek.' '.sklonovani($pocet_podlozek, "podložka", "podložky", "podložek");?>)<?php }?></button>
				<span style="color: var(--muted); font-size: 13px; align-self: center;"><?php echo $sit_token !== "" ? "se sítěmi nahraného modelu" : "kusy jako kvádry zadaných rozměrů";?></span>
			</div>
<?php }?>
<?php
	if (!empty($otocene_objekty)) {
		// Otočené objekty: v sliceru je nutné kusy otočit o 90° kolem Z (název escapovat).
		$otocene_popisy = [];
		foreach ($otocene_objekty as $id_otoceneho) {
			$nazev_otoceneho = $objekty[$id_otoceneho]["nazev"] ?? "";
			$otocene_popisy[] = 'objekt '.($id_otoceneho + 1).($nazev_otoceneho !== "" ? ' ('.$nazev_otoceneho.')' : '');
		}
?>
			<div id="otocene_objekty" style="margin-top: 6px; font-size: 13px;">
				Otočeno o 90°: <strong><?php echo htmlspecialchars(implode(", ", $otocene_popisy), ENT_QUOTES, "UTF-8");?></strong> - v sliceru tyto kusy otoč o 90° kolem osy Z (ve vizualizaci a v tabulce pozic označené ↻).
			</div>
<?php
	}
	if (!empty($limit)) {
		// Důvod limitu: proč se nevešly všechny požadované instance (text i název objektu escapovat).
?>
			<div id="limit" style="margin-top: 6px; font-size: 13px;">
				Nevešlo se vše:
				<ul style="margin: 4px 0 0; padding-left: 18px;">
<?php foreach ($limit as $limit_polozka) {?>
					<li data-duvod="<?php echo htmlspecialchars($limit_polozka["duvod"], ENT_QUOTES, "UTF-8");?>"><?php echo htmlspecialchars($limit_polozka["popis"], ENT_QUOTES, "UTF-8");?></li>
<?php }?>
				</ul>
			</div>
<?php
	}
	if (is_array($kontrola_kolizi)) {
		// Nezávislá kontrola kolizí (CollisionChecker) - krátce; detaily jen při kolizi nebo když výpočet rozmístění upravil.
		$kk_popisy = [];
		if (!$kontrola_kolizi["ok"]) {
			foreach ($kontrola_kolizi["konflikty"] as $konflikt) $kk_popisy[] = $konflikt["popis"];
		} elseif (!empty($kontrola_kolizi["upraveno"])) {
			foreach ($kontrola_kolizi["postup"] as $krok) $kk_popisy[] = $krok;
		}
?>
			<div id="kontrola_kolizi" style="margin-top: 6px; color: var(--muted); font-size: 13px;">
				Kontrola kolizí: <strong style="color: <?php echo $kontrola_kolizi["ok"] ? "var(--success)" : "var(--danger)";?>;"><?php echo $kontrola_kolizi["ok"] ? "OK" : "KOLIZE";?></strong><?php if ($kontrola_kolizi["ok"] && !empty($kontrola_kolizi["upraveno"])) echo " (rozmístění upraveno, aby kolize nevznikla)";?>
<?php if (!empty($kk_popisy)) {?>
				<ul style="margin: 4px 0 0; padding-left: 18px;">
<?php foreach ($kk_popisy as $kk_popis) {?>
					<li><?php echo htmlspecialchars($kk_popis, ENT_QUOTES, "UTF-8");?></li>
<?php }?>
				</ul>
<?php }?>
			</div>
<?php
	}
?>
		</div>

<?php
}
if (!empty($zbyva_v_ose_X) or $zbyva_v_ose_Y) {
?>
		<div class="toolbar" style="margin-top: 14px;">
		  <button class="ghost" type="button" onclick="javascript:$('#zbyvajici_misto').toggle(120);"><?php echo ikona("square-dashed");?>Zobrazit/skrýt zbývající místo</button>
		</div>

		<div id="zbyvajici_misto" style="margin-top: 10px;">
<?php
}
if (!empty($zbyva_v_ose_X)) {
?>
			<div class="remaining-grid">
			<div class="card" style="margin-top: 12px;">
			<div class="toolbar" style="margin: 0 0 8px;">
				<h2 class="card-title">Zbývá místa v ose X<?php if ($pocet_podlozek > 1) echo " (podložka 1)";?></h2>
			</div>
			<div class="table-wrap">
			<table class="compact">
				<tr>
					<th>Řada</th>
					<th>Zbývá</th>
					<th>Počet instancí</th>
					<th>Odsazení navíc mezi každými dvěmi instancemi</th>
				</tr>
<?php
	foreach ($zbyva_v_ose_X as $rada => $zbyva) {
		$pocet_objektu_v_rade = count($pos[1][$rada]);
		$pro_kazdou_bude_zvetseno_odsazeni_o = ($pocet_objektu_v_rade == 1 ? 0 : $zbyva / ($pocet_objektu_v_rade - 1));
		echo '<tr>';
		echo '<td>'.$rada.'</td>';
		echo '<td>'.format_cislo($zbyva, false, 2).'&nbsp;mm</td>';
		echo '<td>'.$pocet_objektu_v_rade.'</td>';
		echo '<td>'.format_cislo($pro_kazdou_bude_zvetseno_odsazeni_o, false, 2).'&nbsp;mm</td>';
		echo '</tr>';
	}
?>
    	</table>
			</div>
			</div>
<?php
}
if ($zbyva_v_ose_Y) {
?>
			<div class="card" style="margin-top: 12px;">
			<div class="toolbar" style="margin: 0 0 8px;">
				<h2 class="card-title">Zbývá místa v ose Y<?php if ($pocet_podlozek > 1) echo " (podložka 1)";?></h2>
			</div>
			<div class="table-wrap">
			<table class="compact">
				<tr>
					<th>Zbývá</th>
					<th>Počet řad</th>
					<th>Odsazení navíc mezi každými dvěmi řadami</th>
				</tr>
<?php
		$pro_kazdou_bude_zvetseno_odsazeni_o = ($pocet_rad == 1 ? 0 : $zbyva_v_ose_Y / ($pocet_rad - 1));
		echo '<tr>';
		echo '<td>'.format_cislo($zbyva_v_ose_Y, false, 2).'&nbsp;mm</td>';
		echo '<td>'.$pocet_rad.'</td>';
		echo '<td>'.format_cislo($pro_kazdou_bude_zvetseno_odsazeni_o, false, 2).'&nbsp;mm</td>';
		echo '</tr>';
?>
    	</table>
			</div>
			</div>
<?php
}
if (!empty($zbyva_v_ose_X) or $zbyva_v_ose_Y) {
?>
			</div>
		</div>
<?php
}
if (!empty($pos)) {
?>
		<div class="layout" style="margin-top: 14px;">
			<button id="toggle_section_positions" class="ghost small mobile-section-toggle" type="button" data-target="#section_positions" data-label="pozice instancí"><?php echo ikona("eye");?><span class="lbl">Zobrazit pozice instancí</span></button>
			<div id="section_positions" class="card mobile-section">
				<div class="toolbar" style="margin: 0 0 8px;">
					<h2 class="card-title">Pozice instancí</h2>
				</div>
			<div class="table-wrap">
			<table>
				<tr>
					<th>Podložka</th>
					<th title="Pořadí tisku na podložce">Pořadí</th>
					<th>Řada</th>
					<th>ID objektu</th>
					<th>Název</th>
					<th>Instance</th>
					<th>X</th>
					<th>Y</th>
					<th title="Kus otočený o 90° kolem Z - v sliceru ho otoč">Otočeno</th>
				</tr>
<?php
	foreach ($pos as $p => $podlozka) {
		// Počet kusů na podložce (rowspan) a objekty této podložky (rotace se u více podložek volí po podložkách).
		$pocet_instanci_na_podlozce = 0;
		foreach ($podlozka as $rada) $pocet_instanci_na_podlozce += count($rada);
		$objekty_na_podlozce = $objekty_podlozek[$p] ?? $objekty_serazene;
		$poradi_na_podlozce = 0;
		$prvni_na_podlozce = true;
		foreach ($podlozka as $y => $rada) {
			$pocet_instanci_v_rade = count($rada);
			$prvni_v_rade = true;
			foreach ($rada as $x => $instance) {
				$poradi_na_podlozce++;
				$o = $instance["o"];
				$i = $instance["i"];
				$ix = $instance["X"];
				$iy = $instance["Y"];
				echo '<tr data-podlozka="'.$p.'">';
				if ($prvni_na_podlozce) echo '<td rowspan="'.$pocet_instanci_na_podlozce.'">'.$p.'</td>';
				echo '<td>'.$poradi_na_podlozce.'</td>';
				if ($prvni_v_rade) echo '<td rowspan="'.$pocet_instanci_v_rade.'">'.$y.'</td>';
				$prvni_na_podlozce = $prvni_v_rade = false;
				echo '<td>'.$o.'</td>';
				echo '<td style="text-align: left;">'.htmlspecialchars($objekty_na_podlozce[($o - 1)]["nazev"] ?? "", ENT_QUOTES, "UTF-8").'</td>';
				echo '<td>'.$i.'</td>';
				echo '<td>'.format_cislo($ix, false, 2).'</td>';
				echo '<td>'.format_cislo($iy, false, 2).'</td>';
				echo '<td>'.(!empty($objekty_na_podlozce[($o - 1)]["otoceno"]) ? '↻ 90°' : '').'</td>';
				echo '</tr>';
			}
		}
	}
?>
			</table>
			</div>
			</div>

		<div class="bed-wrap">
<?php ob_start(); ?>
			<div class="card">
				<div class="toolbar" style="margin: 0 0 8px;">
					<h2 style="margin:0; flex:1;">Vizualizace</h2>
					<button id="fullscreen_toggle" class="small ghost" type="button" title="Celá obrazovka"><?php echo ikona("maximize");?><span class="lbl">Fullscreen</span></button>
					<button id="toggle_head" class="small ghost" type="button"><?php echo ikona("crosshair");?><span class="lbl">Zobrazit tiskovou hlavu</span></button>
					<span id="head_controls" style="display:none; gap:6px; align-items:center;">
						<button id="head_prev" class="small ghost icon-only" type="button" title="Předchozí instance" aria-label="Předchozí instance"><?php echo ikona("chevron-left");?></button>
						<button id="head_next" class="small ghost icon-only" type="button" title="Další instance" aria-label="Další instance"><?php echo ikona("chevron-right");?></button>
					</span>
				</div>
				<?php
					$vizX = (isset($vysledek) && isset($vysledek["printer"]["x"]) ? (float)$vysledek["printer"]["x"] : (float)$tiskova_plocha["x"] - (float)$posun_zprava);
					$vizY = (isset($vysledek) && isset($vysledek["printer"]["y"]) ? (float)$vysledek["printer"]["y"] : (float)$tiskova_plocha["y"]);
					$grid_x_pct = ($vizX > 0 ? (10 / $vizX) * 100 : 10);
					$grid_y_pct = ($vizY > 0 ? (10 / $vizY) * 100 : 10);
				?>
				<div style="color: var(--muted); font-size: 13px; margin: 0 0 10px;">
					Podložka: <?php echo format_cislo($vizX, false, 0);?>×<?php echo format_cislo($vizY, false, 0);?> mm • Mřížka 10 mm • Hover pro detaily
				</div>
<?php if (count($pos) > 1) {?>
				<div id="podlozky_prepinac" class="toolbar" role="tablist" aria-label="Podložky">
<?php foreach ($pos as $p => $podlozka) {
		$pocet_na_podlozce = 0;
		foreach ($podlozka as $rada) $pocet_na_podlozce += count($rada);
?>
					<button class="small ghost podlozka_tab<?php if ($p === 1) echo " active";?>" type="button" role="tab" aria-selected="<?php echo $p === 1 ? "true" : "false";?>" data-podlozka="<?php echo $p;?>" title="<?php echo $pocet_na_podlozce.' '.sklonovani($pocet_na_podlozce, "kus", "kusy", "kusů");?>"><?php echo ikona("layers");?>Podložka <?php echo $p;?> (<?php echo $pocet_na_podlozce;?>)</button>
<?php }?>
				</div>
<?php }?>
				<div id="tiskova_podlozka"
						 data-printer='<?php echo htmlspecialchars(json_encode($vysledek["printer"] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8");?>'
						 data-head-steps='<?php echo htmlspecialchars(json_encode(($vysledek["printer"]["head_steps"] ?? []), JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8");?>'
						 style="--bed-x: <?php echo format_cislo($vizX, false, 2, false, ".");?>; --bed-y: <?php echo format_cislo($vizY, false, 2, false, ".");?>; --grid-x: <?php echo number_format($grid_x_pct, 4, ".", "");?>%; --grid-y: <?php echo number_format($grid_y_pct, 4, ".", "");?>%;">
					<div class="overlay"></div>
<?php
	foreach ($pos as $p => $podlozka) {
		// Pořadí tisku (data-seq) se čísluje v rámci podložky; kusy dalších podložek jsou skryté (záložky).
		$seq = 0;
		$objekty_na_podlozce = $objekty_podlozek[$p] ?? $objekty_serazene;
		foreach ($podlozka as $y => $rada) {
			foreach ($rada as $x => $instance) {
				$seq++;
				$o = $instance["o"];
				$i = $instance["i"];
				$objekt = $objekty_na_podlozce[($o - 1)];
				$ox = $objekt["x"];
				$oy = $objekt["y"];
				$oz = $objekt["z"];
				$ix = $instance["X"];
				$iy = $instance["Y"];
				$ix_levy_dolni_roh = $ix - ($ox / 2);
				$iy_levy_dolni_roh = $iy - ($oy / 2);
				$left_pct = ($vizX > 0 ? ($ix_levy_dolni_roh / $vizX) * 100 : 0);
				$bottom_pct = ($vizY > 0 ? ($iy_levy_dolni_roh / $vizY) * 100 : 0);
				$w_pct = ($vizX > 0 ? ($ox / $vizX) * 100 : 0);
				$h_pct = ($vizY > 0 ? ($oy / $vizY) * 100 : 0);
				$nazev_objektu = $objekt["nazev"] ?? "";
				// Otočený objekt: rozměry x/y jsou už zaměněné (obdélník se kreslí otočený), navíc označení ↻.
				$otoceny = !empty($objekt["otoceno"]);
				$title = ($pocet_podlozek > 1 ? 'Podložka '.$p.' / ' : '').'Objekt '.$o.($nazev_objektu !== "" ? ' ('.$nazev_objektu.')' : '').' / instance '.$i.($otoceny ? ' / otočeno o 90°' : '')."\n".
					'Rozměr: '.format_cislo($ox, false, 2).'×'.format_cislo($oy, false, 2).'×'.format_cislo($oz, false, 2)." mm\n".
					'Pozice (levý přední roh): '.format_cislo($ix_levy_dolni_roh, false, 2).' ; '.format_cislo($iy_levy_dolni_roh, false, 2).' mm';
				echo '			  <div class="instance'.($otoceny ? ' otocene' : '').($p > 1 ? ' jina-podlozka' : '').'"'.($otoceny ? ' data-otoceno="1"' : '').' data-podlozka="'.$p.'" data-seq="'.$seq.'" data-o="'.$o.'" data-i="'.$i.'"'.($nazev_objektu !== "" ? ' data-nazev="'.htmlspecialchars($nazev_objektu, ENT_QUOTES, "UTF-8").'"' : '').' data-ox="'.htmlspecialchars(format_cislo($ox, false, 2, false, "."), ENT_QUOTES, "UTF-8").'" data-oy="'.htmlspecialchars(format_cislo($oy, false, 2, false, "."), ENT_QUOTES, "UTF-8").'" data-oz="'.htmlspecialchars(format_cislo($oz, false, 2, false, "."), ENT_QUOTES, "UTF-8").'" data-left="'.htmlspecialchars(format_cislo($ix_levy_dolni_roh, false, 2, false, "."), ENT_QUOTES, "UTF-8").'" data-bottom="'.htmlspecialchars(format_cislo($iy_levy_dolni_roh, false, 2, false, "."), ENT_QUOTES, "UTF-8").'" style="--x: '.number_format($left_pct, 4, ".", "").'; --y: '.number_format($bottom_pct, 4, ".", "").'; --w: '.number_format($w_pct, 4, ".", "").'; --h: '.number_format($h_pct, 4, ".", "").';" title="'.htmlspecialchars($title, ENT_QUOTES, "UTF-8").'"><div>'.$i.'<br /><small>O'.$o.($otoceny ? ' ↻' : '').'</small></div></div>';
			}
		}
	}
?>
				</div>
				<div id="instance_details" style="margin-top: 10px; color: var(--muted); font-size: 13px; white-space: pre-wrap;"></div>
			</div>
<?php $html_vizualizace = ob_get_clean(); // vizualizace se vypise v prave casti (vedle formulare) ?>

			<button id="toggle_section_json" class="ghost small mobile-section-toggle" type="button" data-target="#section_json" data-label="JSON"><?php echo ikona("eye");?><span class="lbl">Zobrazit JSON</span></button>
			<div id="section_json" class="card mobile-section">
				<div class="toolbar" style="margin: 0 0 8px;">
					<h2 class="card-title" style="flex:1;">JSON</h2>
					<button id="copy_json" class="small" type="button"><?php echo ikona("copy");?><span class="lbl">Kopírovat JSON</span></button>
					<button id="download_json" class="small" type="button"><?php echo ikona("download");?>Stáhnout JSON</button>
				</div>
				<textarea id="json_textarea" readonly="readonly"><?php echo($datova_veta_json);?></textarea>
			</div>
		</div>
		</div>
<?php
}
?>
		</div><!-- /app-left -->
<?php if ($html_vizualizace !== '') {?>
		<div class="app-right">
<?php echo $html_vizualizace;?>
		</div>
<?php }?>
		</div><!-- /app-grid -->
	</div>
  </body>
</html>
<?php
define("MAXIMALNI_POCET_INSTANCI", 99);
define("MAXIMALNI_POCET_ITERACI", 99);

require_once __DIR__ . "/src/SequentialPrintCalculator.php";

/* Funkce */

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

/* Nastavení tiskárny (výchozí – později půjde do DB) */

$tiskova_plocha = [
	"x" => 180,
	"y" => 180,
	"z" => 180
];
$posun_zprava = 1; // Korekce pro PRUSA MINI, kdy objekt umístění zcela vpravo má deformovanou stěnu
$Xr = "12"; // 10 - pro objekty krátké v ose Y
$Xl = "36.5";
$Yr = "15.5"; // 29
$Yl = "15.5";
$vodici_tyce_Z = 21;
$vodici_tyce_Y = "17.4";

$smer_X = ($Xl <= $Xr ? "zleva_doprava" : "zprava_doleva");
$smer_Y = ($Yl <= $Yr ? "zepredu_dozadu" : "zezadu_dopredu");

/* Nastavení (UI) */
$rozprostrit_instance_po_cele_podlozce = (empty($_GET) || (isset($_GET["rozprostrit_instance_po_cele_podlozce"]) && $_GET["rozprostrit_instance_po_cele_podlozce"]));
$rozprostrit_instance_v_ose_x = (empty($_GET) || (isset($_GET["rozprostrit_instance_v_ose_x"]) && $_GET["rozprostrit_instance_v_ose_x"]));
$rozprostrit_instance_v_ose_y = (empty($_GET) || (isset($_GET["rozprostrit_instance_v_ose_y"]) && $_GET["rozprostrit_instance_v_ose_y"]));
$umistit_na_stred_v_ose_x = (!empty($_GET) && (isset($_GET["umistit_na_stred_v_ose_x"]) && $_GET["umistit_na_stred_v_ose_x"]));
$umistit_na_stred_v_ose_y = (!empty($_GET) && (isset($_GET["umistit_na_stred_v_ose_y"]) && $_GET["umistit_na_stred_v_ose_y"]));

/* Objekty (z UI/GET) */
$objekty = [];
if (isset($_GET["objekty"]) && is_array($_GET["objekty"]) && !empty($_GET["objekty"])) {
	$objekty = $_GET["objekty"];
	foreach ($objekty as $key => $objekt) {
		foreach ($objekt as $key1 => $hodnota) {
			if (is_string($hodnota)) $objekty[$key][$key1] = str_replace(",", ".", $hodnota);
		}
		// Normalizace rozměrů na max 2 desetinná místa (bez přidávání 0.01).
		if (isset($objekty[$key]["x"])) $objekty[$key]["x"] = normalize_input_2dp($objekty[$key]["x"]);
		if (isset($objekty[$key]["y"])) $objekty[$key]["y"] = normalize_input_2dp($objekty[$key]["y"]);
		if (isset($objekty[$key]["z"])) $objekty[$key]["z"] = normalize_input_2dp($objekty[$key]["z"]);
	}
}

/* Výpočet */
$pos = $objekty_serazene = $datova_veta_pole = $Xcount_pole = $zbyva_v_ose_X = [];
$zbyva_v_ose_Y = 0;
$pocet_instanci = $pocet_rad = $pocet_podlozek = $Xcount = 0;
$Xcount_min = $Xcount_max = 0;
$text_nad_tabulkou = "";
$datova_veta_json = "[]";

if (!empty($objekty)) {
	$objekty_input = $objekty; // zachovat pro formulář (aby se hodnoty po odeslání neměnily)

	$calculator = new SequentialPrintCalculator(
		[
			"x" => $tiskova_plocha["x"],
			"y" => $tiskova_plocha["y"],
			"z" => $tiskova_plocha["z"],
			"posun_zprava" => $posun_zprava,
			"Xr" => $Xr,
			"Xl" => $Xl,
			"Yr" => $Yr,
			"Yl" => $Yl,
			"vodici_tyce_Z" => $vodici_tyce_Z,
			"vodici_tyce_Y" => $vodici_tyce_Y
		],
		[
			"rozprostrit_instance_po_cele_podlozce" => $rozprostrit_instance_po_cele_podlozce,
			"rozprostrit_instance_v_ose_x" => $rozprostrit_instance_v_ose_x,
			"rozprostrit_instance_v_ose_y" => $rozprostrit_instance_v_ose_y
		]
	);

	$vysledek = $calculator->calculate($objekty);

	// Do UI vrátím původní rozměry, ale doplním instances[r] z výpočtu.
	$objekty_vysledek = $vysledek["objekty"];
	$objekty = $objekty_input;
	foreach ($objekty as $id => $o) {
		if (!isset($objekty[$id]["instances"])) $objekty[$id]["instances"] = [];
		if (isset($objekty_vysledek[$id]["instances"]["r"])) $objekty[$id]["instances"]["r"] = $objekty_vysledek[$id]["instances"]["r"];
	}
	$objekty_serazene = $vysledek["objekty_serazene"];
	$pos = $vysledek["pos"];
	$datova_veta_pole = $vysledek["datova_veta_pole"];
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

	if ($pocet_instanci > 0) {
		$Xcount_string = ($Xcount_min == $Xcount_max ? $Xcount_max : ($Xcount_min."&ndash;".$Xcount_max));
		$text_nad_tabulkou = 'Na podložku se '.sklonovani($pocet_instanci, "vleze", "vlezou", "vleze").' <strong>'.$pocet_instanci.'</strong> '.sklonovani($pocet_instanci, "instance", "instance", "instancí").' ('.$Xcount_string.' '.sklonovani($Xcount_max, "instance", "instance", "instancí").' '.sklonovani($pocet_rad, "v", "ve", "v").' '.$pocet_rad.' '.sklonovani($pocet_rad, "řadě", "řadách", "řadách").').';
	}
}

/* JSON výstup (pro API / integrace) */
if (isset($_GET["format"]) && $_GET["format"] === "json") {
	header("Content-Type: application/json; charset=UTF-8");
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
			"pocet_instanci" => $pocet_instanci,
			"pocet_podlozek" => $pocet_podlozek,
			"objekty" => $objekty,
			"printer" => $printer_json
		],
		JSON_UNESCAPED_UNICODE
	);
	exit;
}

/* Vypsání HTML */
?>
<!DOCTYPE html>
<html>
  <head>
    <title>Sekvenční tisk</title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
		<script>
			id_objektu = -1;
			objekty = <?php echo (!empty($objekty) ? json_encode($objekty) : "[]");?>;

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

			function pridej_radek_do_tabulky (par_id_objektu) {
				id_objektu++;
				if (par_id_objektu) id_objektu = parseInt(par_id_objektu);
				const { x = "", y = "", z = "" } = objekty[id_objektu] || {};
				instances = (objekty[id_objektu] ? objekty[id_objektu]["instances"]["d"] : <?php echo MAXIMALNI_POCET_INSTANCI;?>);
				const vysledny_pocet_instanci = (objekty[id_objektu] && objekty[id_objektu]["instances"]["r"]) || "";
				$("table#objekty").append(
					`<tr id="objekt_${id_objektu}" data-row="1" data-idx="${id_objektu}">
						<td class="cell_id">${id_objektu + 1}</td>
						<td><input type="number" name="objekty[${id_objektu}][x]" value="${x}" step="0.01" min="0.1" max="180" required="required" /></td>
						<td><input type="number" name="objekty[${id_objektu}][y]" value="${y}" step="0.01" min="0.1" max="180" required="required" /></td>
						<td><input type="number" name="objekty[${id_objektu}][z]" value="${z}" step="0.01" min="0.1" max="180" required="required" /></td>
						<td><input class="instances" type="number" name="objekty[${id_objektu}][instances][d]" value="${instances}" step="1" min="1" max="<?php echo MAXIMALNI_POCET_INSTANCI;?>" required="required" /></td>
						<td style="text-align:left;">
							<div style="display:flex; gap:6px; flex-wrap:wrap;">
								<button class="small row_action" type="button" data-action="up" title="Přesunout nahoru">↑</button>
								<button class="small row_action" type="button" data-action="down" title="Přesunout dolů">↓</button>
								<button class="small row_action" type="button" data-action="dup" title="Duplikovat">Duplikovat</button>
								<button class="small row_action" type="button" data-action="del" title="Smazat">Smazat</button>
							</div>
						</td>
						${objekty[id_objektu] ? `<td>${vysledny_pocet_instanci}</td>` : ""}
					</tr>`
				);
				reindex_rows();
			}

			function get_form_state () {
				const state = {
					version: 1,
					objekty: [],
					nastaveni: {
						rozprostrit_instance_v_ose_x: $("#rozprostrit_instance_v_ose_x").prop("checked") ? 1 : 0,
						rozprostrit_instance_v_ose_y: $("#rozprostrit_instance_v_ose_y").prop("checked") ? 1 : 0,
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
					const d = $tr.find("input.instances").val();
					state.objekty.push({ x: x, y: y, z: z, instances: { d: d } });
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
				$("#rozprostrit_instance_po_cele_podlozce").prop("checked", !!n.rozprostrit_instance_po_cele_podlozce);
				$("#umistit_na_stred_v_ose_x").prop("checked", !!n.umistit_na_stred_v_ose_x);
				$("#umistit_na_stred_v_ose_y").prop("checked", !!n.umistit_na_stred_v_ose_y);
			}

      $(document).ready(function(){
				if (Array.isArray(objekty) && objekty.length == 0) pridej_radek_do_tabulky(); // platí pouze v případě "[]", protože předaný JSON není polem
				else $.each(objekty, function(index, value) {
					pridej_radek_do_tabulky(index);
				});
				$('#nastaveni').hide();
				$('#zbyvajici_misto').hide();

				// --- Ukládání/načítání konfigurace bez DB (localStorage) ---
				const LS_KEY = 'sekvencni_tisk:last_query';

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
					params.set('objekty[0][instances][d]', '99');
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

				// Omezit rozměry na 2 desetinná místa (při opuštění polí + před submit)
				$(document).on('blur', "input[type='number'][name$='[x]'], input[type='number'][name$='[y]'], input[type='number'][name$='[z]']", function () {
					$(this).val(roundTo2($(this).val()));
				});
				$("form").on('submit', function () {
					$(this).find("input[type='number'][name$='[x]'], input[type='number'][name$='[y]'], input[type='number'][name$='[z]']").each(function(){
						$(this).val(roundTo2($(this).val()));
					});
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
						$(this).text('Zkopírováno');
						setTimeout(() => $(this).text('Kopírovat JSON'), 1200);
					} catch (e) {
						// fallback: select + copy
						el.focus();
						el.select();
						document.execCommand('copy');
					}
				});
			});
		</script>
    <style>
			:root {
				--bg: #f6f7fb;
				--card: #ffffff;
				--text: #0f172a;
				--muted: #64748b;
				--border: #e2e8f0;
				--accent: #2563eb;
				--accent-2: #0ea5e9;
				--shadow: 0 10px 30px rgba(2, 8, 23, 0.08);
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
			.container { max-width: 1200px; margin: 0 auto; padding: 18px; }
			.header { display: flex; gap: 12px; align-items: baseline; justify-content: space-between; margin-bottom: 14px; }
			.header h1 { margin: 0; font-size: 20px; }
			.header .sub { color: var(--muted); font-size: 13px; }

			h2 { margin: 18px 0 10px; font-size: 16px; }
			.card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 14px; }

			.toolbar { display: flex; gap: 10px; flex-wrap: wrap; margin: 12px 0 2px; }
			button, input[type=number] {
				border-radius: 12px;
				border: 1px solid var(--border);
				background: #fff;
				color: var(--text);
				padding: 9px 12px;
				font: inherit;
			}
			button { cursor: pointer; }
			button.primary { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
			button.ghost { background: #fff; }
			button.small { padding: 7px 10px; border-radius: 10px; font-size: 13px; }
			button:active { transform: translateY(1px); }
			input[type=number] { width: 100%; min-width: 74px; text-align: right; }

			.table-wrap { overflow-x: auto; }
			table { width: 100%; border-collapse: separate; border-spacing: 0; }
			th, td { padding: 10px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
			th { text-align: left; color: var(--muted); font-weight: 600; font-size: 12px; }
			td { text-align: right; }
			td:first-child, th:first-child { text-align: left; }
			tr:last-child td { border-bottom: none; }

			#nastaveni { margin-top: 12px; }
			#nastaveni .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 14px; }
			#nastaveni label { display: inline-flex; gap: 10px; align-items: center; justify-content: space-between; font-weight: 600; color: var(--text); }
			#nastaveni label span { color: var(--muted); font-weight: 500; }
			@media (max-width: 720px) { #nastaveni .grid { grid-template-columns: 1fr; } }

			.layout { display: grid; grid-template-columns: 1.2fr 1fr; gap: 14px; align-items: start; }
			@media (max-width: 980px) { .layout { grid-template-columns: 1fr; } }

			.bed-wrap { display: grid; gap: 10px; }
			#tiskova_podlozka {
				position: relative;
				width: min(92vw, 720px);
				aspect-ratio: var(--bed-x) / var(--bed-y);
				border-radius: 18px;
				border: 1px solid var(--border);
				background:
					linear-gradient(to right, rgba(2,8,23,0.04) 1px, transparent 1px) 0 0 / 24px 24px,
					linear-gradient(to top, rgba(2,8,23,0.04) 1px, transparent 1px) 0 0 / 24px 24px,
					#ffffff;
				box-shadow: var(--shadow);
				overflow: hidden;
				margin: 0 auto;
			}
			#tiskova_podlozka .instance {
				position: absolute;
				left: calc(var(--x) * 1%);
				bottom: calc(var(--y) * 1%);
				width: calc(var(--w) * 1%);
				height: calc(var(--h) * 1%);
				border-radius: 0;
				background: linear-gradient(135deg, rgba(37,99,235,0.95), rgba(14,165,233,0.9));
				color: #fff;
				display: grid;
				place-items: center;
				font-weight: 700;
				font-size: 12px;
				box-shadow: 0 8px 20px rgba(37,99,235,0.25);
				user-select: none;
			}
			#tiskova_podlozka .instance:hover {
				outline: 3px solid rgba(14,165,233,0.35);
				outline-offset: 2px;
			}

			.modal {
				position: fixed;
				inset: 0;
				background: rgba(15, 23, 42, 0.55);
				display: grid;
				place-items: center;
				padding: 16px;
				z-index: 50;
			}
			.modal .panel {
				width: min(92vw, 900px);
				background: #fff;
				border-radius: var(--radius);
				border: 1px solid var(--border);
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

			#json_textarea {
				width: 100%;
				min-height: 120px;
				border-radius: 12px;
				border: 1px solid var(--border);
				padding: 10px 12px;
				font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
				font-size: 12px;
				color: #0b1220;
			}
    </style>
  </head>
  <body>
		<div class="container">
			<div class="header">
				<h1>Sekvenční tisk</h1>
				<div class="sub">Výpočet rozložení instancí pro sekvenční tisk</div>
			</div>

		<h2>Objekty</h2>

		<form method="get" action="./index.php" class="card">
			<div class="table-wrap">
			<table id="objekty">
				<tr>
					<th rowspan="2">ID<br />objektu</th>
					<th colspan="3">Rozměry objektu (mm)</th>
					<th rowspan="2">Požadovaný<br />počet instancí<br />(<?php echo MAXIMALNI_POCET_INSTANCI;?>=max)</th>
					<th rowspan="2">Smazat<br />řádek</th>
<?php
if (!empty($pocet_instanci_objektu)) {
?>
					<th rowspan="2">Výsledný<br />počet instancí</th>
<?php
}
?>
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
						přednastavení pro různé tiskárny (Prusa MINI, MK3...)
						tisk kalibračních objektů
						nerozprostření objektů, ale umístění na střed (X, Y)
						množství filamentu - nutno přidat k objektu jako údaj, aby se udělalo jen tolik instancí, kolik se vleze do celkového množství
						doba tisku - nutno přidat k objektu jako údaj, aby se udělalo jen tolik instancí, kolik se vleze do celkové doby
			    -->
					<label for="rozprostrit_instance_v_ose_x">Rozprostřít instance v ose X <span>(rovnoměrně)</span>
						<input id="rozprostrit_instance_v_ose_x" type="checkbox" name="rozprostrit_instance_v_ose_x" value="1" <?php if($rozprostrit_instance_v_ose_x) {?>checked="checked" <?php }?>/>
					</label>
					<label for="rozprostrit_instance_v_ose_y">Rozprostřít instance v ose Y <span>(rovnoměrně)</span>
						<input id="rozprostrit_instance_v_ose_y" type="checkbox" name="rozprostrit_instance_v_ose_y" value="1" <?php if($rozprostrit_instance_v_ose_y) {?>checked="checked" <?php }?>/>
					</label>
					<label for="umistit_na_stred_v_ose_x">Umístit na střed v ose X
						<input id="umistit_na_stred_v_ose_x" type="checkbox" name="umistit_na_stred_v_ose_x" value="1" <?php if($umistit_na_stred_v_ose_x) {?>checked="checked" <?php }?>/>
					</label>
					<label for="umistit_na_stred_v_ose_y">Umístit na střed v ose Y
						<input id="umistit_na_stred_v_ose_y" type="checkbox" name="umistit_na_stred_v_ose_y" value="1" <?php if($umistit_na_stred_v_ose_y) {?>checked="checked" <?php }?>/>
					</label>
					<label for="rozprostrit_instance_po_cele_podlozce">Rozprostřít po celé podložce <span>(hledání řad)</span>
						<input id="rozprostrit_instance_po_cele_podlozce" type="checkbox" name="rozprostrit_instance_po_cele_podlozce" value="1" <?php if($rozprostrit_instance_po_cele_podlozce) {?>checked="checked" <?php }?>/>
					</label>
				</div>
			</div>

			<div class="toolbar">
				<button class="ghost" type="button" onclick="javascript:pridej_radek_do_tabulky();">Přidat řádek</button>
				<button class="ghost" type="button" onclick="javascript:$('#nastaveni').toggle(120);">Nastavení</button>
				<button class="ghost" id="example_basic" type="button" title="Vyplní ukázková data">Příklad</button>
				<button class="ghost" id="save_local" type="button" title="Uloží aktuální odkaz (query) do prohlížeče">Uložit</button>
				<button class="ghost" id="load_local" type="button" title="Načte uložený odkaz z prohlížeče">Načíst</button>
				<button class="ghost" id="clear_local" type="button" title="Smaže uložené nastavení">Smazat</button>
				<button class="ghost" id="export_input" type="button" title="Export vstupu (objekty + nastavení) do JSON">Export</button>
				<button class="ghost" id="import_input" type="button" title="Import vstupu (objekty + nastavení) z JSON">Import</button>
			  <button class="primary" type="submit">Vypočítat</button>
				<span id="local_status" style="color: var(--muted); font-weight: 600; align-self: center;"></span>
			</div>
		</form>

		<div id="io_modal" class="modal" hidden="hidden">
			<div class="panel">
				<div class="top">
					<h3 id="io_title">Import/Export</h3>
					<button id="io_close" class="small" type="button">Zavřít</button>
				</div>
				<div id="io_hint" class="hint"></div>
				<textarea id="io_text" placeholder='{"objekty":[...],"nastaveni":{...}}'></textarea>
				<div class="toolbar" style="margin-top: 10px;">
					<button id="io_copy" class="ghost" type="button">Kopírovat</button>
					<button id="io_import" class="primary" type="button">Importovat</button>
				</div>
			</div>
		</div>

<?php
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
		<div class="card" style="margin-top: 14px;">
			<?php echo $text_nad_tabulkou;?>
		</div>

<?php
}
if (!empty($zbyva_v_ose_X) or $zbyva_v_ose_Y) {
?>
		<p>
		  <button type="button" onclick="javascript:$('#zbyvajici_misto').toggle(100);">Zobrazit/skrýt zbývající místo</button>
		</p>

		<div id="zbyvajici_misto">
<?php
}
if (!empty($zbyva_v_ose_X)) {
?>
			<h2>Zbývá místa v ose X</h2>

			<table>
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
<?php
}
if ($zbyva_v_ose_Y) {
?>
			<h2>Zbývá místa v ose Y</h2>

			<table>
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
<?php
}
if (!empty($zbyva_v_ose_X) or $zbyva_v_ose_Y) {
?>
		</div>
<?php
}
if (!empty($pos)) {
?>
		<div class="layout" style="margin-top: 14px;">
			<div class="card">
				<h2>Pozice instancí</h2>

			<div class="table-wrap">
			<table>
				<tr>
					<th>Podložka</th>
					<th>Řada</th>
					<th>ID objektu</th>
					<th>Instance</th>
					<th>X</th>
					<th>Y</th>
				</tr>
<?php
	foreach ($pos as $p => $podlozka) {
		$pocet_instanci_na_podlozce = $pocet_instanci;
		foreach ($podlozka as $y => $rada) {
			$pocet_instanci_v_rade = count($rada);
			foreach ($rada as $x => $instance) {
				$o = $instance["o"];
				$i = $instance["i"];
				$ix = $instance["X"];
				$iy = $instance["Y"];
				echo '<tr>';
				if ($y == 1 and $x == 1) echo '<td rowspan="'.$pocet_instanci_na_podlozce.'">'.$p.'</td>';
				if ($x == 1) echo '<td rowspan="'.$pocet_instanci_v_rade.'">'.$y.'</td>';
				echo '<td>'.$o.'</td>';
				echo '<td>'.$i.'</td>';
				echo '<td>'.format_cislo($ix, false, 2).'</td>';
				echo '<td>'.format_cislo($iy, false, 2).'</td>';
				echo '</tr>';
			}
		}
	}
?>
			</table>
			</div>
			</div>

		<div class="bed-wrap">
			<div class="card">
				<h2>Vizualizace</h2>
				<?php
					$vizX = (isset($vysledek) && isset($vysledek["printer"]["x"]) ? (float)$vysledek["printer"]["x"] : (float)$tiskova_plocha["x"] - (float)$posun_zprava);
					$vizY = (isset($vysledek) && isset($vysledek["printer"]["y"]) ? (float)$vysledek["printer"]["y"] : (float)$tiskova_plocha["y"]);
				?>
				<div style="color: var(--muted); font-size: 13px; margin: 0 0 10px;">
					Podložka: <?php echo format_cislo($vizX, false, 0);?>×<?php echo format_cislo($vizY, false, 0);?> mm • Hover pro detaily
				</div>
				<div id="tiskova_podlozka" style="--bed-x: <?php echo format_cislo($vizX, false, 2, false, ".");?>; --bed-y: <?php echo format_cislo($vizY, false, 2, false, ".");?>;">
<?php
	foreach ($pos as $p => $podlozka) {
		$pocet_instanci_na_podlozce = $pocet_instanci;
		foreach ($podlozka as $y => $rada) {
			$pocet_instanci_v_rade = count($rada);
			foreach ($rada as $x => $instance) {
				$o = $instance["o"];
				$i = $instance["i"];
				$objekt = $objekty_serazene[($o - 1)];
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
				$title = 'Objekt '.$o.' / instance '.$i."\n".
					'Rozměr: '.format_cislo($ox, false, 2).'×'.format_cislo($oy, false, 2).'×'.format_cislo($oz, false, 2)." mm\n".
					'Pozice (levý přední roh): '.format_cislo($ix_levy_dolni_roh, false, 2).' ; '.format_cislo($iy_levy_dolni_roh, false, 2).' mm';
				echo '			  <div class="instance" style="--x: '.number_format($left_pct, 4, ".", "").'; --y: '.number_format($bottom_pct, 4, ".", "").'; --w: '.number_format($w_pct, 4, ".", "").'; --h: '.number_format($h_pct, 4, ".", "").';" title="'.htmlspecialchars($title, ENT_QUOTES, "UTF-8").'"><div>'.$i.'<br /><small>O'.$o.'</small></div></div>';
			}
		}
	}
?>
				</div>
			</div>

			<div class="card">
				<h2>JSON</h2>
				<div class="toolbar" style="margin: 0 0 8px;">
					<button id="copy_json" class="small" type="button">Kopírovat JSON</button>
				</div>
				<textarea id="json_textarea" readonly="readonly"><?php echo($datova_veta_json);?></textarea>
			</div>
		</div>
		</div>
<?php
}
?>
	</div>
  </body>
</html>
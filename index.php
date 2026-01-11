<?php
define("MAXIMALNI_POCET_INSTANCI", 99);
define("MAXIMALNI_POCET_ITERACI", 99);

require_once __DIR__ . "/src/SequentialPrintCalculator.php";

/* Funkce */

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

	$objekty = $vysledek["objekty"];
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

/* Vypsání HTML */
?>
<!DOCTYPE html>
<html>
  <head>
    <title>Sekvenční tisk</title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
		<script>
			id_objektu = -1;
			objekty = <?php echo (!empty($objekty) ? json_encode($objekty) : "[]");?>;

			function smazat_radek_tabulky (objekt_id) {
				$("#objekt_"+objekt_id).remove();
			}

			function pridej_radek_do_tabulky (par_id_objektu) {
				id_objektu++;
				if (par_id_objektu) id_objektu = parseInt(par_id_objektu);
				const { x = "", y = "", z = "" } = objekty[id_objektu] || {};
				instances = (objekty[id_objektu] ? objekty[id_objektu]["instances"]["d"] : <?php echo MAXIMALNI_POCET_INSTANCI;?>);
				const vysledny_pocet_instanci = (objekty[id_objektu] && objekty[id_objektu]["instances"]["r"]) || "";
				$("table#objekty").append(
					`<tr id="objekt_${id_objektu}">
						<td>${id_objektu + 1}</td>
						<td><input type="number" name="objekty[${id_objektu}][x]" value="${x}" step="0.01" min="0.1" max="180" required="required" /></td>
						<td><input type="number" name="objekty[${id_objektu}][y]" value="${y}" step="0.01" min="0.1" max="180" required="required" /></td>
						<td><input type="number" name="objekty[${id_objektu}][z]" value="${z}" step="0.01" min="0.1" max="180" required="required" /></td>
						<td><input class="instances" type="number" name="objekty[${id_objektu}][instances][d]" value="${instances}" step="1" min="1" max="<?php echo MAXIMALNI_POCET_INSTANCI;?>" required="required" /></td>
						<td><button type="button" onclick='javascript:smazat_radek_tabulky(${id_objektu});'>Smazat</button></td>
						${objekty[id_objektu] ? `<td>${vysledny_pocet_instanci}</td>` : ""}
					</tr>`
				);
			}

      $(document).ready(function(){
				if (Array.isArray(objekty) && objekty.length == 0) pridej_radek_do_tabulky(); // platí pouze v případě "[]", protože předaný JSON není polem
				else $.each(objekty, function(index, value) {
					pridej_radek_do_tabulky(index);
				});
				$('#nastaveni').hide();
				$('#zbyvajici_misto').hide();
			});
		</script>
    <style>
			body { padding: 1em; font: 85% Verdana, 'DejaVu Sans', 'Arial CE', Arial, 'Helvetica CE', Helvetica, sans-serif; }
			h1 { margin-top: 0; }
			h2 { margin-top: 0; }
      table { margin-bottom: 1em; border-collapse: collapse; }
      table tr th { padding: 0.2em 0.3em; border: 1px solid black; }
      table tr td { padding: 0.2em 0.3em; border: 1px solid black; text-align: right; vertical-align: top; }
			table tr td input { width: 4em; text-align: right; }
			button[type=submit] { font-weight: bold; }
			label { font-weight: bold; width: 16em; display: inline-block; }
			#nastaveni { margin: 1em 0; padding: 1em; border: 1px solid black; }
			#nastaveni h2 { margin: 0 0 0.3em 0; }
			#nastaveni p { margin: 0; padding: 0; }
			#pozice_instanci { margin: 0 2em 1em 0; float: left; }
			#tiskova_podlozka { float: left; position: relative; width: <?php echo $tiskova_plocha["x"];?>px; height: <?php echo $tiskova_plocha["y"];?>px; border: 1px solid black; zoom: 2; }
			#tiskova_podlozka .instance { position: absolute; border: none; background: black; font-size: 0.5em; color: white; text-align: center; vertical-align: middle; }
			#json_h2 { clear: left; }
    </style>
  </head>
  <body>
		<h1>Sekvenční tisk</h1>

		<h2>Objekty</h2>

		<form method="get" action="./index.php">
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

			<div id="nastaveni">
				<h2>Nastavení</h2>

				<p>
					<!-- TODO přidat nastavení:
					  rozměry tiskové plochy, hlavy, vodících tyčí
						přednastavení pro různé tiskárny (Prusa MINI, MK3...)
						tisk kalibračních objektů
						nerozprostření objektů, ale umístění na střed (X, Y)
						množství filamentu - nutno přidat k objektu jako údaj, aby se udělalo jen tolik instancí, kolik se vleze do celkového množství
						doba tisku - nutno přidat k objektu jako údaj, aby se udělalo jen tolik instancí, kolik se vleze do celkové doby
			    -->
					<label for="rozprostrit_instance_v_ose_x">Rozprostřít instance v ose X</label>
					<input id="rozprostrit_instance_v_ose_x" type="checkbox" name="rozprostrit_instance_v_ose_x" value="1" <?php if($rozprostrit_instance_v_ose_x) {?>checked="checked" <?php }?>/><br />
					<label for="rozprostrit_instance_v_ose_y">Rozprostřít instance v ose Y</label>
					<input id="rozprostrit_instance_v_ose_y" type="checkbox" name="rozprostrit_instance_v_ose_y" value="1" <?php if($rozprostrit_instance_v_ose_y) {?>checked="checked" <?php }?>/><br />
					<label for="umistit_na_stred_v_ose_x">Umístit na střed v ose X</label>
					<input id="umistit_na_stred_v_ose_x" type="checkbox" name="umistit_na_stred_v_ose_x" value="1" <?php if($umistit_na_stred_v_ose_x) {?>checked="checked" <?php }?>/><br />
					<label for="umistit_na_stred_v_ose_y">Umístit na střed v ose Y</label>
					<input id="umistit_na_stred_v_ose_y" type="checkbox" name="umistit_na_stred_v_ose_y" value="1" <?php if($umistit_na_stred_v_ose_y) {?>checked="checked" <?php }?>/><br />
					<label for="rozprostrit_instance_po_cele_podlozce">Rozprostřít instance po celé podložce</label>
					<input id="rozprostrit_instance_po_cele_podlozce" type="checkbox" name="rozprostrit_instance_po_cele_podlozce" value="1" <?php if($rozprostrit_instance_po_cele_podlozce) {?>checked="checked" <?php }?>/>
				</p>
			</div>

			<p>
				<button type="button" onclick="javascript:pridej_radek_do_tabulky();">Přidat řádek</button>
				<button type="button" onclick="javascript:$('#nastaveni').toggle(100);">Nastavení</button>
			  <button type="submit">Vypočítat</button>
			</p>
		</form>

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
		<p><?php echo $text_nad_tabulkou;?></p>

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
		<div id="pozice_instanci">
			<h2>Pozice instancí</h2>

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

		<div id="vizualizace">
			<h2>Vizualizace</h2>

			<div id="tiskova_podlozka">
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
				echo '			  <div class="instance" style="width: '.format_cislo($ox, false, 2, false, ".").'px; height: '.format_cislo($oy, false, 2, false, ".").'px; line-height: '.format_cislo($oy, false, 2, false, ".").'px; left: '.format_cislo($ix_levy_dolni_roh, false, 2, false, ".").'px; bottom: '.format_cislo($iy_levy_dolni_roh, false, 2, false, ".").'px;" title="'.$o.' - '.$i.'">'.$i.'</div>';
			}
		}
	}
?>
			</div>
		</div>

		<h2 id="json_h2">JSON</h2>

		<p id="json"><?php echo($datova_veta_json);?></p>
<?php
}
?>
  </body>
</html>
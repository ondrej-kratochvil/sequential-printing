<?php
define("MAXIMALNI_POCET_INSTANCI", 99);
define("MAXIMALNI_POCET_ITERACI", 99);

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

function nastav_zbyva_v_ose_Y () {
	global $zbyva_v_ose_Y, $Y, $pozice_hrany_nejvzdalenejsiho_objektu;
	$zbyva_v_ose_Y = ($Y - $pozice_hrany_nejvzdalenejsiho_objektu);
}

function vypocitej_pozici_instanci () {
	global $objekty, $objekty_serazene, $smer_X, $smer_Y, $X, $Y, $Xr, $Yr, $rozprostrit_instance_v_ose_x, $rozprostrit_instance_v_ose_y, $omezeny_pocet_instanci_v_rade, $vodici_tyce_Z, $pos1, $pos2, $zbyva_v_ose_X, $vodici_tyce_Y, $datova_veta_pole, $pocet_instanci_objektu, $Xcount_pole, $posun_x1, $posun_y1, $pocet_instanci1, $pocet_rad1, $pozice_hrany_prvniho_objektu_v_rade, $pozice_hrany_nejvzdalenejsiho_objektu, $zbyva_v_ose_X_maximalne, $zbyva_v_ose_Y, $pocet_podlozek1;
	$pos1 = $pos2 = $zbyva_v_ose_X = $datova_veta_pole = $pocet_instanci_objektu = $Xcount_pole = [];
	$posun_x1 = $posun_y1 = $pocet_instanci1 = $pocet_rad1 = $pozice_hrany_prvniho_objektu_v_rade = $pozice_hrany_nejvzdalenejsiho_objektu = $zbyva_v_ose_X_maximalne = $zbyva_v_ose_Y = 0;
	$pocet_podlozek1 = $p1 = $x1 = $y1 = 1; // TODO předělat v závislosti na ID objektu; přidat možnost umístění na více podložek
	foreach ($objekty_serazene as $id => $objekt) {
		$i1 = $i2 = 0;
		$o1 = $id + 1;
		/* Uložím si rozměry objektu do proměnných */
		$ox = $objekt["x"];
		$oy = $objekt["y"];
		$oz = $objekt["z"];
		$pozadovany_pocet_instanci = (isset($objekt["instances"]["d"]) ? ($objekt["instances"]["d"] === "max" ? MAXIMALNI_POCET_INSTANCI : $objekt["instances"]["d"]) : 1);
		$pocet_instanci_objektu[$o1] = 0;
		for ($ci = 1; $ci <= $pozadovany_pocet_instanci; $ci++) {
			$i1 = $i2;// Nastavím dočasné počítadlo instancí podle finálního počítadla instancí
			$i1++; // Navýším dočasné počítadlo instancí
			/* Nastavím pozici X */
			if ($smer_X == "zleva_doprava") { // TODO udělat stejným způsobem (jen opačným směrem) jako v opačném směru (else)
				$ix = ($ox / 2) + $posun_x1; // Nastavím pozici X
			}
			else {
				if (($X - $ox - $posun_x1) < 0 or ($omezeny_pocet_instanci_v_rade and $x1 > $omezeny_pocet_instanci_v_rade)) { // Pokud by již instance přesahovala podložku v ose X
					$y1++; // Navýším řadu
					$x1 = 1; // Nastavím sloupec na počítační hodnotu
					$posun_x1 = 0; // Vynuluji posun v ose X
					$posun_y1 = (($pozice_hrany_nejvzdalenejsiho_objektu - $vodici_tyce_Y) > ($pozice_hrany_prvniho_objektu_v_rade + $Yr) ? ($pozice_hrany_nejvzdalenejsiho_objektu - $vodici_tyce_Y) : ($pozice_hrany_prvniho_objektu_v_rade + $Yr)); // Nastavím posun nové řady podle toho, co je dál, aby nenarazila hlava do prvního objektu v předchozí řadě, nebo vodící tyče do posledního objektu v předchozí řadě
					//echo "(pozice_hrany_prvniho_objektu_v_rade + Yr) = ".($pozice_hrany_prvniho_objektu_v_rade + $Yr)."<br />";
					//echo "(pozice_hrany_nejvzdalenejsiho_objektu - vodici_tyce_Y) = ".($pozice_hrany_nejvzdalenejsiho_objektu - $vodici_tyce_Y)."<br />";
					$pozice_prave_hrany_x_druheho_objektu_v_predchozi_rade = $pos1[$p1][($y1 - 1)][2]["X"] + ($pos1[$p1][($y1 - 1)][2]["x"] / 2);
					echo "pozice_hrany_x_druheho_objektu_v_predchozi_rade = ".$pozice_prave_hrany_x_druheho_objektu_v_predchozi_rade."<br />";
					$pozice_leve_hrany_x_tohoto_objektu = $X - $ox - $posun_x1;
					echo "pozice_leve_hrany_x_tohoto_objektu = ".$pozice_leve_hrany_x_tohoto_objektu."<br />";
					if ($pozice_prave_hrany_x_druheho_objektu_v_predchozi_rade < ($pozice_leve_hrany_x_tohoto_objektu - $Xl)) $posun_y1 = ($pozice_hrany_nejvzdalenejsiho_objektu + $Yr); // Pokud by hlava narazila do druhého objektu v předchozí řadě, raději nastavím posun podle posledního objektu // TODO udělat posun Y v cyklu, aby se nastavil podle druhého objektu v předchozí řadě a kontroloval se třetí objekt atd. // TODO rosprostřední v ose X je nutné dělat druhým průchodem funkce, aby se zajistil dostatečný prostor mezi objekty pro Xl, nebo i bez rozprostření udělat další průchod, kdy se Xl použije místo Xr
					//echo "posun_y1 = ".$posun_y1."<br />";
				}
				$ix = $X - ($ox / 2) - $posun_x1; // Nastavím pozici X
			}
			//echo 'ix = '.$ix.'<br />';
			/* Nastavím pozici Y */
			if ($i1 > 1 and $x1 > 1 and $pos1[$p1][$y1][($x1 - 1)]["z"] > $vodici_tyce_Z and $pos1[$p1][$y1][($x1 - 1)]["y"] > $vodici_tyce_Y) { // Pokud by vodící tyče narazily do předchozího objektu
				//echo "posun_y1 = ".$posun_y1."<br />";
				$posun_y1 += ($pos1[$p1][$y1][($x1 - 1)]["y"] - $vodici_tyce_Y); // Nastavím posun v ose Y
				//echo "posun_y1 = ".$posun_y1."<br />";
			}
			if ($smer_Y == "zepredu_dozadu") $iy = ($oy / 2) + $posun_y1;
			else $iy = $Y - ($oy / 2) - $posun_y1; // TODO máme jen pozici vodících tyčí zepředu - potřebovali bychom pozici zezadu
			//echo 'iy = ".$iy."<br />';
			/* Pokud by objekt přesahoval podložku v ose Y, ukončím cyklus */
			if (($iy + ($oy / 2)) > $Y) {
				//echo "iy = ".$iy."<br />";
				nastav_zbyva_v_ose_Y();
				break(2);
			}
			$i2++; // Navýším finální počítadlo instancí
			$pocet_instanci1++;
			if ($y1 == 1) $Xcount1 = $pocet_instanci1; // Zajímá mě počet instancí v 1. řadě
			/* Uložím hodnoty do pole */
			$pos1[$p1][$y1][$x1] = [
				"o" => $o1,
				"i" => $i2,
				"X" => $ix,
				"Y" => $iy,
				"x" => $ox,
				"y" => $oy,
				"z" => $oz
			];
			if (!isset($Xcount_pole[$y1])) $Xcount_pole[$y1] = 0;
			$Xcount_pole[$y1]++;
			if ($x1 == 1) $pocet_rad1++;
			$posun_x1 += ($ox + $Xr); // Nastavím posun v ose X pro následující instanci
			if ($smer_X == "zleva_doprava") { // TODO udělat stejným způsobem (jen opačným směrem) jako v opačném směru (else)
			}
			else {
				$zbyva_v_ose_X[$y1] = ($X - ($posun_x1 - $Xr));
				if ($zbyva_v_ose_X[$y1] > $zbyva_v_ose_X_maximalne) $zbyva_v_ose_X_maximalne = $zbyva_v_ose_X[$y1];
			}
			if ($x1 == 1) $pozice_hrany_prvniho_objektu_v_rade = ($iy + ($oy / 2)); // Ukládám si pozici hrany prvního objektu v této řadě, abych ji uplatnil pro posuv následující řady
			if ($pozice_hrany_nejvzdalenejsiho_objektu < ($iy + ($oy / 2))) $pozice_hrany_nejvzdalenejsiho_objektu = ($iy + ($oy / 2)); // Ukládám si pozici nejvzdálenější hrany v této řadě, abych ji uplatnil pro posuv následující řady
			nastav_zbyva_v_ose_Y();
			$pocet_instanci_objektu[$o1]++;
			$objekty[$id]["instances"]["r"] = $pocet_instanci_objektu[$o1];
			$x1++; // Navýším počítadlo instancí v ose X
		}
	}
	//print_r($zbyva_v_ose_X);
	//print_r($zbyva_v_ose_Y);
	$pos2 = $pos1;
	if ($zbyva_v_ose_X_maximalne or $zbyva_v_ose_Y) {
		$pripocitam_v_ose_Y = ($zbyva_v_ose_Y ? ($zbyva_v_ose_Y / ($pocet_rad1 - 1)) : 0);
		foreach ($pos2[$p1] as $y1 => $rada) {
			$pocet_instanci_v_rade = count($rada);
			$suda_rada = ($y1 % 2 == 0);
			$cik_cak = ($omezeny_pocet_instanci_v_rade and $pocet_instanci_v_rade == 1 and $suda_rada); // dělat i v případě rovnoměrného rozprostření instancí po celé podložce, když je jen jeden objekt v sudé řadě
			$pripocitam_v_ose_X = ($zbyva_v_ose_X_maximalne ? ($zbyva_v_ose_X[$y1] / ($cik_cak ? 1 : ($pocet_instanci_v_rade - 1))) : 0);
			foreach ($rada as $x1 => $objekt1) {
				if ($rozprostrit_instance_v_ose_x and ($x1 > 1 or $cik_cak)) {
					if ($smer_X == "zleva_doprava") $pos2[$p1][$y1][$x1]["X"] += $pripocitam_v_ose_X * ($cik_cak ? 1 : ($x1 - 1));
					else $pos2[$p1][$y1][$x1]["X"] -= $pripocitam_v_ose_X * ($cik_cak ? 1 : ($x1 - 1));
				}
				if ($rozprostrit_instance_v_ose_y and $y1 > 1) {
					if ($smer_Y == "zepredu_dozadu") $pos2[$p1][$y1][$x1]["Y"] += $pripocitam_v_ose_Y * ($y1 - 1);
					else $pos2[$p1][$y1][$x1]["Y"] -= $pripocitam_v_ose_Y * ($y1 - 1);
				}
				$datova_veta_pole[] = [$pos2[$p1][$y1][$x1]["i"], round($pos2[$p1][$y1][$x1]["X"], 2), round($pos2[$p1][$y1][$x1]["Y"], 2)];
			}
		}
	}
}

function prepocitej_s_rozprostrenim_po_cele_tiskove_podlozce ($par_pocet_instanci, $par_pocet_rad) {
	global $omezeny_pocet_instanci_v_rade;
	$omezeny_pocet_instanci_v_rade = ceil($par_pocet_instanci / $par_pocet_rad);
	vypocitej_pozici_instanci();
}

function prepocitej_s_upravou_pozadovaneho_poctu_instanci_objektu ($par_pocet_instanci, $krome_techto_objektu, $nastavit_pomerny_pocet_vyslednych_instanci = false) {
	global $objekty_serazene, $pomerny_pocet_vyslednych_instanci;
	foreach ($objekty_serazene as $id => $objekt) {
		$nastavovany_pocet_pozadovanych_instanci = ($nastavit_pomerny_pocet_vyslednych_instanci ? ($pomerny_pocet_vyslednych_instanci[$id] - $par_pocet_instanci) : $par_pocet_instanci);
		if (!in_array($id, $krome_techto_objektu)) $objekty_serazene[$id]["instances"]["d"] = ($objekty[$id]["instances"]["d"] < $nastavovany_pocet_pozadovanych_instanci ? $objekty[$id]["instances"]["d"] : $nastavovany_pocet_pozadovanych_instanci);
	}
	vypocitej_pozici_instanci();
}

function vypocitej_prumerny_pocet_instanci () {
	global $objekty, $pocet_objektu, $celkovy_pocet_pozadovanych_instanci, $celkovy_pocet_vyslednych_instanci, $prumerny_pocet_pozadovanych_instanci, $prumerny_pocet_vyslednych_instanci;
  $celkovy_pocet_pozadovanych_instanci = $celkovy_pocet_vyslednych_instanci = 0;
	foreach ($objekty as $id => $objekt) {
		$celkovy_pocet_pozadovanych_instanci += $objekt["instances"]["d"];
		$celkovy_pocet_vyslednych_instanci += $objekt["instances"]["r"];
	}
	$prumerny_pocet_pozadovanych_instanci = $celkovy_pocet_pozadovanych_instanci / $pocet_objektu;
	$prumerny_pocet_vyslednych_instanci = $celkovy_pocet_vyslednych_instanci / $pocet_objektu;
}

function nastav_pomerny_pocet_vyslednych_instanci () {
	global $objekty, $prumerny_pocet_pozadovanych_instanci, $prumerny_pocet_vyslednych_instanci, $pomerny_pocet_vyslednych_instanci;
	foreach ($objekty as $id => $objekt) {
		$pomerny_pocet_vyslednych_instanci[$id] = $objekt["instances"]["d"] / $prumerny_pocet_pozadovanych_instanci * $prumerny_pocet_vyslednych_instanci;
	}
}

function nastav_pole_krome_techto_objektu () {
	global $byla_umistena_alespon_jedna_instance_objektu, $krome_techto_objektu;
	$krome_techto_objektu = [];
	foreach ($byla_umistena_alespon_jedna_instance_objektu as $id => $objekt) {
		$krome_techto_objektu[] = $id;
	}
}

/* Nastavení tiskárny */

$tiskova_plocha["x"] = $X = 180;
$tiskova_plocha["y"] = $Y = 180;
$tiskova_plocha["z"] = $Z = 180;
$posun_zprava = 1; // Korekce pro PRUSA MINI, kdy objekt umístění zcela vpravo má deformovanou stěnu
$X = $X - $posun_zprava;
$Xr = "12";//10 - pro objekty krátké v ose Y
$Xl = "36.5";
$Yr = "15.5";//29
$Yl = "15.5";
$vodici_tyce_Z = 21;
$vodici_tyce_Y = "17.4";

$smer_X = ($Xl <= $Xr ? "zleva_doprava" : "zprava_doleva");
$smer_Y = ($Yl <= $Yr ? "zepredu_dozadu" : "zezadu_dopredu");

$rozprostrit_instance_po_cele_podlozce = (empty($_GET) or (isset($_GET["rozprostrit_instance_po_cele_podlozce"]) and $_GET["rozprostrit_instance_po_cele_podlozce"]));
$rozprostrit_instance_v_ose_x = (empty($_GET) or (isset($_GET["rozprostrit_instance_v_ose_x"]) and $_GET["rozprostrit_instance_v_ose_x"]));
$rozprostrit_instance_v_ose_y = (empty($_GET) or (isset($_GET["rozprostrit_instance_v_ose_y"]) and $_GET["rozprostrit_instance_v_ose_y"]));

/* Objekty - TODO předělat na zadávání přes formulář (hotovo), možná i uložení do DB (přes Adinistraci stran - 1) Vložení objektů, Vložení sady, Provazba mezi sadou, objektem a zadání počtu instancí */

$objekty = [];

/* Načtení objektů z GETu */
if (isset($_GET["objekty"]) and is_array($_GET["objekty"]) and !empty($_GET["objekty"])) {
	$objekty = $_GET["objekty"];
	/* Ošetření vstupních hodnot */
	foreach ($objekty as $key => $objekt) {
		foreach ($objekt as $key1 => $hodnota) {
			if (is_string($hodnota)) $objekty[$key][$key1] = str_replace(",", ".", $hodnota);
		}
	}
}
//print_r($objekty);

$pocet_objektu = count($objekty);

/* Upravení objektů */
if ($pocet_objektu > 0) {
	$objekty_upravene = $chyby_v_rozmerech = [];
	foreach ($objekty as $id => $objekt) {
		foreach ($objekt as $parametr => $hodnota) {
			$hodnota_upravena = $hodnota;
			if (in_array($parametr, ["x", "y", "z"])) {
				$hodnota_upravena += "0.01"; // kvůli možnému zaokrouhlení rozměru objektu v PrusaSliceru směrem dolů
				if ($hodnota_upravena > $tiskova_plocha[$parametr]) {
					$chyby_v_rozmerech[$id][$parametr] = $hodnota_upravena;
					continue(2);
				}
			}
			$objekty_upravene[$id][$parametr] = $hodnota_upravena;
		}
	}
}

if (!empty($objekty_upravene)) {
	/* Seřazení objektů od nejnižších - TODO řadit od nejnižších v rámci řady, další řady mohou začínat nižším objektem než je poslední objekt v předešlé řadě */
	$objekty_serazene = array_msort($objekty_upravene, array('z' => SORT_ASC, 'y' => SORT_ASC, 'x' => SORT_ASC));

	$W = $objekty_serazene[0]["x"];
	$L = $objekty_serazene[0]["y"];
	$H = $objekty_serazene[0]["z"];

	/* Počet objektů v ose X */
	$Xcount = 0;
	$Xcount = (int)(($X - $W) / ($W + $Xr)) + 1;
	//echo 'Xcount = '.$Xcount.'<br />';

	/* Počet objektů v ose Y */
	$Ycount = 0;
	$Ycount = (int)(($Y - $L) / ($L + $Yr)) + 1;
	//echo 'Ycount = '.$Ycount.'<br />';
	$pocet_instanci0 = $Xcount * $Ycount;

	/* Rovnoměrné rozložení objektů v ose X - minimalizuje se možnost kolize tiskové hlavy s již vytištěnými objekty */
	$Xr1 = ($X - ($W * $Xcount)) / ($Xcount - 1);
	$Xr1 = (int)($Xr1 * 100) / 100;
	//echo 'Xr1 = '.$Xr1.'<br />';

	/* Rovnoměrné rozložení objektů v ose Y - minimalizuje se možnost kolize tiskové hlavy s již vytištěnými objekty */
	$Yr1 = ($Y - ($L * $Ycount)) / ($Ycount - 1);
	$Yr1 = (int)($Yr1 * 100) / 100;
	//echo 'Yr1 = '.$Yr1.'<br />';

	/* Zjištění pozic objektů/instancí a počtu instancí */
	$pos = [];
	$i = $posun_y = $pocet_instanci = $pocet_rad = 0;
	$pocet_podlozek = $p = $o = 1; // TODO předělat v závislosti na ID objektu; přidat možnost umístění na více podložek
	for ($y = 1; $y <= $Ycount; $y++) {
		//echo 'y = '.$y.'<br />';
		for ($x = 1; $x <= $Xcount; $x++) {
			$i++;
			if ($i > 1 and $x > 1 and $H > $vodici_tyce_Z and $L > $vodici_tyce_Y) {
				$posun_y += ($L - $vodici_tyce_Y);
			}
			if ($smer_X == "zleva_doprava") $ix = ($W / 2) + ($x - 1) * ($W + $Xr1);
			else $ix = $X - ($W / 2) - ($x - 1) * ($W + $Xr1);
			//echo 'ix = '.$ix.'<br />';
			if ($smer_Y == "zepredu_dozadu") $iy = ($L / 2) + ($y - 1) * ($L + ($posun_y > 0 ? $Yr : $Yr1)) + $posun_y;
			else $iy = $Y - ($L / 2) - ($y - 1) * ($L + ($posun_y > 0 ? $Yr : $Yr1)) - $posun_y;
			//echo 'iy = ".$iy."<br />';
			if (($iy + ($L / 2)) > $Y) {
				break(2);
			}
			$pocet_instanci++;
			$pos[$p][$y][$x] = [
				"o" => $o,
				"i" => $i,
				"X" => $ix,
				"Y" => $iy
			];
			if ($x == 1) $pocet_rad++;
		}
	}

	$omezeny_pocet_instanci_v_rade = 0;

	vypocitej_pozici_instanci();

	$id_prvniho_objektu = array_key_first($objekty);
	$byly_umisteny_vsechny_pozadovane_instance_vsech_objektu = $byly_umisteny_vsechny_pozadovane_nemaximalni_instance_prvniho_objektu = true;
	$byly_umisteny_vsechny_pozadovane_instance_objektu = $byla_umistena_alespon_jedna_instance_objektu = [];
	foreach ($objekty as $id => $objekt) {
		if ($objekt["instances"]["r"] < $objekt["instances"]["d"]) {
			$byly_umisteny_vsechny_pozadovane_instance_vsech_objektu = false;
			if ($id == $id_prvniho_objektu and $objekt["instances"]["d"] < MAXIMALNI_POCET_INSTANCI) $byly_umisteny_vsechny_pozadovane_nemaximalni_instance_prvniho_objektu = false;
			$byly_umisteny_vsechny_pozadovane_instance_objektu[$id] = false;
			if ($objekt["instances"]["r"] == 0) $byla_umistena_alespon_jedna_instance_objektu[$id] = false;
		}
	}
	if (!$byly_umisteny_vsechny_pozadovane_nemaximalni_instance_prvniho_objektu) {
		$krome_techto_objektu = [$id_prvniho_objektu];
		prepocitej_s_upravou_pozadovaneho_poctu_instanci_objektu(0, $krome_techto_objektu); // nastavím ostatním objektům nulový počet požadovaných instancí
		$maximalni_mozny_pocet_instanci_prvniho_objektu = $objekty[$id_prvniho_objektu]["instances"]["r"];
		if ($maximalni_mozny_pocet_instanci_prvniho_objektu > 0) { // už se mi podařilo umístit všechny možné instance prvního objektu
			for ($i = 1; $i <= MAXIMALNI_POCET_ITERACI; $i++) { // budu dalším objektům postupně přidávat počet požadovaných instancí, zda se ještě vlezou na podložku
				prepocitej_s_upravou_pozadovaneho_poctu_instanci_objektu($i, $krome_techto_objektu);
			  if ($objekty[$id_prvniho_objektu]["instances"]["r"] < $maximalni_mozny_pocet_instanci_prvniho_objektu) { // narazil jsem na limit, už mám méně instancí prvního objektu, vrátím se o krok zpět
					prepocitej_s_upravou_pozadovaneho_poctu_instanci_objektu(($i - 1), $krome_techto_objektu);
					break;
				}
			}
		}
	}
	elseif (!empty($byla_umistena_alespon_jedna_instance_objektu)) {
		vypocitej_prumerny_pocet_instanci();
		nastav_pomerny_pocet_vyslednych_instanci();
		nastav_pole_krome_techto_objektu();
		prepocitej_s_upravou_pozadovaneho_poctu_instanci_objektu(0, $krome_techto_objektu, true); // nastavím ostatním objektům nulový počet požadovaných instancí
		for ($i = 1; $i <= MAXIMALNI_POCET_ITERACI; $i++) { // budu dalším objektům postupně snižovat počet požadovaných instancí, zda dosáhnu poměrného počtu vysledných instancí
			vypocitej_prumerny_pocet_instanci();
			nastav_pomerny_pocet_vyslednych_instanci();
		  //var_dump($i);
			//var_dump($pomerny_pocet_vyslednych_instanci[$id]);
			$dosahuje_kazdy_objekt_pomerneho_poctu_vyslednych_instanci = true;
			$objekt_jiz_presahuje_pomerny_pocet_vyslednych_instanci = false;
			foreach ($byla_umistena_alespon_jedna_instance_objektu as $id => $objekt) {
				if ($objekty[$id]["instances"]["r"] < $pomerny_pocet_vyslednych_instanci[$id]) {
					//var_dump($objekty[$id]["instances"]["r"]);
					//var_dump($pomerny_pocet_vyslednych_instanci[$id]);
					$dosahuje_kazdy_objekt_pomerneho_poctu_vyslednych_instanci = false;
					break;
				}
				elseif ($objekty[$id]["instances"]["r"] > $pomerny_pocet_vyslednych_instanci[$id]) {
					//var_dump($objekty[$id]["instances"]["r"]);
					//var_dump($pomerny_pocet_vyslednych_instanci[$id]);
					$objekt_jiz_presahuje_pomerny_pocet_vyslednych_instanci = true;
					break;
				}
			}
			if ($dosahuje_kazdy_objekt_pomerneho_poctu_vyslednych_instanci) {
				if ($objekt_jiz_presahuje_pomerny_pocet_vyslednych_instanci) {
					//prepocitej_s_upravou_pozadovaneho_poctu_instanci_objektu(($i - 1), $krome_techto_objektu, true); // vrátim se o krok zpět
					prepocitej_s_upravou_pozadovaneho_poctu_instanci_objektu(0, $krome_techto_objektu, true); // vrátim se o krok zpět
					//var_dump("ano");
				}
				break;
			}
			else prepocitej_s_upravou_pozadovaneho_poctu_instanci_objektu($i, $krome_techto_objektu, true); // snížím ostatním objektům počet požadovaných instancí
		}
	}

	if ($rozprostrit_instance_po_cele_podlozce) {
		$pocet_instanci_zaloha = $pocet_instanci1;
		for ($i = $pocet_rad1; $i <= MAXIMALNI_POCET_ITERACI; $i++) { // začnu vypočtenými řadami a budu přidávat
			prepocitej_s_rozprostrenim_po_cele_tiskove_podlozce($pocet_instanci_zaloha, $i); // přepočítám
			if ($pocet_instanci1 < $pocet_instanci_zaloha) { // jakmile narazím, že už se na podložku nevleze tolik instancí, kolik na začátku, vrátím se o jednu řadu zpět a ukončím přepočet
				prepocitej_s_rozprostrenim_po_cele_tiskove_podlozce($pocet_instanci_zaloha, ($i - 1));
				break;
			}
		}
	}

	$datova_veta_json = json_encode($datova_veta_pole);
	//print_r($objekty);
	//print_r($pos);
	//print_r($pos1);
	//print_r($pos2);
	//$pos = $pos1; // Použiji nový způsob výpočtu
	$pos = $pos2; // Použiji nový způsob výpočtu včetně rovnoměrného rozmístění objektů po celé podložce
	$pocet_instanci = $pocet_instanci1;
	$pocet_rad = $pocet_rad1;
	$pocet_podlozek = $pocet_podlozek1;
	$Xcount = $Xcount1;
	$Xcount_min = min($Xcount_pole);
	$Xcount_max = max($Xcount_pole);
	$Xcount_string = ($Xcount_min == $Xcount_max ? $Xcount_max : ($Xcount_min."&ndash;".$Xcount_max));
	$text_nad_tabulkou = 'Na podložku se '.sklonovani($pocet_instanci, "vleze", "vlezou", "vleze").' <strong>'.$pocet_instanci.'</strong> '.sklonovani($pocet_instanci, "instance", "instance", "instancí").' ('.$Xcount_string.' '.sklonovani($Xcount_max, "instance", "instance", "instancí").' '.sklonovani($pocet_rad, "v", "ve", "v").' '.$pocet_rad.' '.sklonovani($pocet_rad, "řadě", "řadách", "řadách").').';
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

		<form method="get" action="./index1.php">
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
<?php
/**
 * API v1: výpočet rozmístění a export do 3MF pro PrusaSlicer (každý kus samostatný objekt v pořadí tisku).
 *
 * POST application/json:  stejné tělo jako arrange.php (bez modelu = kusy jako kvádry zadaných rozměrů)
 * GET                     stejné parametry jako arrange.php (kvádry)
 * POST multipart:         `soubor` = 3MF/STL, volitelně `vstup` = JSON jako u arrange.php. Bez `objekty` ve vstupu
 *                         se objekty vezmou z modelu; s `objekty` na sítě modelu odkazuje `sit` (index z import.php).
 *                         Import, výpočet i export proběhnou v jednom požadavku, nic se na serveru neukládá.
 * `?podlozka=N`           jen podložka N (u více podložek); bez něj 1 podložka = .3mf, víc podložek = ZIP s 3MF na podložku.
 *
 * Úspěch 200: model/3mf nebo application/zip (Content-Disposition attachment), hlavičky X-Pocet-Kusu, X-Pocet-Podlozek,
 * X-Kontrola-Kolizi (ok | kolize). Chyby ve formátu v1 jako arrange.php a import.php, navíc 400 nic_k_exportu
 * (nevešel se žádný kus) a neplatny_vstup u `sit` (neexistující index, síť větší než zadané rozměry) a `podlozka`.
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';

Api::obsluz(function (): void {
    $metoda = Api::povolMetody(['GET', 'POST']);
    Api::overKlic();
    $soubor = null;
    if ($metoda === 'POST' && Api::jeMultipart()) {
        $soubor = Api::nahranySoubor('soubor', false);
        $text = isset($_POST['vstup']) && is_string($_POST['vstup']) ? $_POST['vstup'] : '';
        $vstup = trim($text) !== '' ? Api::jsonObjekt($text, "Pole 'vstup'") : [];
        $zDotazu = false;
        $podlozka = Api::cisloPodlozky($_GET['podlozka'] ?? ($_POST['podlozka'] ?? null));
        if ($soubor === null && !isset($vstup['objekty'])) {
            throw new ApiChyba(400, 'chybi_soubor', "Chybí soubor modelu (pole 'soubor') i objekty ve vstupu (pole 'vstup').");
        }
    } else {
        [$vstup, $zDotazu] = Api::vstupVypoctu();
        $podlozka = Api::cisloPodlozky($_GET['podlozka'] ?? null);
    }
    $export = Api::export($vstup, $zDotazu, $soubor, $podlozka);
    Api::odeslatSoubor($export['obsah'], $export['typ'], $export['nazev'], $export['hlavicky']);
});

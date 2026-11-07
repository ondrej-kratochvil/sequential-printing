<?php
/**
 * ZASTARALÉ (legacy) JSON API pro výpočet rozložení. Nové integrace: /api/v1/arrange.php
 * (stejný výstup, jednotný formát chyb a validace vstupu, viz README, API v1).
 *
 * Chování a výstup zůstávají jako dřív kvůli stávajícím klientům: chyby {"error": "<text>"},
 * neplatný JSON v POST = použije se GET, rozměr větší než podložka = `limit` s důvodem `rozmer`
 * (v1 ho odmítne jako chybný vstup). Navíc jen hlavičky Retry-After (429) a X-RateLimit-*.
 *
 * Vstup (POST application/json):
 * {
 *   "objekty": [{ "x": 50, "y": 50, "z": 100, "instances": { "d": 3 } }],
 *   "printer": { ... },   // volitelné
 *   "options": { ... }    // volitelné
 * }
 *
 * Alternativně lze poslat i přes GET ve stejném tvaru jako UI (objekty[..]).
 */

require_once __DIR__ . "/src/Api.php";

// Autorizace + rate-limit (API klíče)
try {
	Api::overKlic(true);
} catch (ApiChyba $e) {
	Api::odeslatLegacyChybu($e);
}

$input = null;
if (Api::metoda() === "POST") {
	$decoded = json_decode(Api::surovyVstup(), true);
	if (is_array($decoded)) $input = $decoded;
}
if (!is_array($input)) {
	$input = $_GET;
}

$chyba = function (string $zprava): void {
	Api::odeslatLegacyChybu(new ApiChyba(400, "neplatny_vstup", $zprava));
};

$objekty = isset($input["objekty"]) && is_array($input["objekty"]) ? $input["objekty"] : [];
if (empty($objekty)) {
	$chyba("Chybí pole 'objekty'.");
}

// Chybějící hodnoty tiskárny a nastavení doplní předvolba (výchozí Prusa MINI) / VYCHOZI_NASTAVENI
// (stejné výchozí hodnoty jako UI a CLI).
// Předvolba tiskárny (config/tiskarny.json): "tiskarna": "<id>" nebo "printer": "<id>"; objekt "printer" ji přepíše.
$idTiskarny = (isset($input["tiskarna"]) && $input["tiskarna"] !== "") ? $input["tiskarna"] : null;
if (isset($input["printer"]) && is_string($input["printer"]) && $input["printer"] !== "") $idTiskarny = $input["printer"];
$printer = (isset($input["printer"]) && is_array($input["printer"])) ? $input["printer"] : [];
$options = (isset($input["options"]) && is_array($input["options"])) ? $input["options"] : [];
if ($idTiskarny !== null && !PredvolbyTiskaren::existuje($idTiskarny)) {
	$chyba("Neznámá tiskárna '" . (is_scalar($idTiskarny) ? (string)$idTiskarny : "?") . "'. Dostupné předvolby: " . implode(", ", array_keys(PredvolbyTiskaren::seznam())) . ".");
}

Api::odeslat(200, Api::vypocet([
	"tiskarna" => PredvolbyTiskaren::sestav($idTiskarny, $printer),
	"options" => $options,
	"objekty" => $objekty,
]));

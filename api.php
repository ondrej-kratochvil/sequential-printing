<?php
/**
 * Jednoduché JSON API pro výpočet rozložení.
 *
 * Vstup (POST application/json):
 * {
 *   "objekty": [{ "x": 50, "y": 50, "z": 100, "instances": { "d": 3 } }],
 *   "printer": { ... },   // volitelné
 *   "options": { ... }    // volitelné
 * }
 *
 * Alternativně lze poslat i přes GET ve stejném tvaru jako UI (objekty[..]).
 *
 * Pozn.: autentizace / API klíče budou další krok.
 */

require_once __DIR__ . "/src/SequentialPrintCalculator.php";
require_once __DIR__ . "/src/ApiAuth.php";

header("Content-Type: application/json; charset=UTF-8");

$jsonError = function (int $status, string $message): void {
    http_response_code($status);
    echo json_encode(["error" => $message], JSON_UNESCAPED_UNICODE);
    exit;
};

// Autorizace + rate-limit (API klíče)
try {
    ApiAuth::requireApiKey();
} catch (ApiAuthHttpException $e) {
    $jsonError($e->statusCode, $e->getMessage());
} catch (Throwable $e) {
    $jsonError(500, "Chyba při ověření API klíče.");
}

$method = isset($_SERVER["REQUEST_METHOD"]) ? $_SERVER["REQUEST_METHOD"] : "GET";
$input = null;

if ($method === "POST") {
    $raw = file_get_contents("php://input");
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $input = $decoded;
}

if (!is_array($input)) {
    $input = $_GET;
}

$objekty = isset($input["objekty"]) && is_array($input["objekty"]) ? $input["objekty"] : [];
if (empty($objekty)) {
    $jsonError(400, "Chybí pole 'objekty'.");
}

// Chybějící hodnoty tiskárny a nastavení doplní předvolba (výchozí Prusa MINI) / VYCHOZI_NASTAVENI
// (stejné výchozí hodnoty jako UI a CLI).
// Předvolba tiskárny (config/tiskarny.json): "tiskarna": "<id>" nebo "printer": "<id>"; objekt "printer" ji přepíše.
$idTiskarny = (isset($input["tiskarna"]) && $input["tiskarna"] !== "") ? $input["tiskarna"] : null;
if (isset($input["printer"]) && is_string($input["printer"]) && $input["printer"] !== "") $idTiskarny = $input["printer"];
$printer = (isset($input["printer"]) && is_array($input["printer"])) ? $input["printer"] : [];
$options = (isset($input["options"]) && is_array($input["options"])) ? $input["options"] : [];
if ($idTiskarny !== null && !PredvolbyTiskaren::existuje($idTiskarny)) {
	$jsonError(400, "Neznámá tiskárna '" . (is_scalar($idTiskarny) ? (string)$idTiskarny : "?") . "'. Dostupné předvolby: " . implode(", ", array_keys(PredvolbyTiskaren::seznam())) . ".");
}

$calculator = new SequentialPrintCalculator(PredvolbyTiskaren::sestav($idTiskarny, $printer), $options);
$vysledek = $calculator->calculate($objekty);

echo json_encode(SequentialPrintCalculator::vystupApi($vysledek), JSON_UNESCAPED_UNICODE);

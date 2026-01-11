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

header("Content-Type: application/json; charset=UTF-8");

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
    http_response_code(400);
    echo json_encode(["error" => "Chybí pole 'objekty'."], JSON_UNESCAPED_UNICODE);
    exit;
}

// Výchozí tiskárna odpovídá současnému index.php
$printer = [
    "x" => 180,
    "y" => 180,
    "z" => 180,
    "posun_zprava" => 1,
    "Xr" => 12,
    "Xl" => 36.5,
    "Yr" => 15.5,
    "Yl" => 15.5,
    "vodici_tyce_Z" => 21,
    "vodici_tyce_Y" => 17.4,
];
if (isset($input["printer"]) && is_array($input["printer"])) {
    $printer = array_merge($printer, $input["printer"]);
}

$options = [
    "rozprostrit_instance_po_cele_podlozce" => true,
    "rozprostrit_instance_v_ose_x" => true,
    "rozprostrit_instance_v_ose_y" => true,
];
if (isset($input["options"]) && is_array($input["options"])) {
    $options = array_merge($options, $input["options"]);
}

$calculator = new SequentialPrintCalculator($printer, $options);
$vysledek = $calculator->calculate($objekty);

echo json_encode(
    [
        "positions" => $vysledek["datova_veta_pole"],
        "pocet_instanci" => $vysledek["pocet_instanci"],
        "pocet_podlozek" => $vysledek["pocet_podlozek"],
        "objekty" => $vysledek["objekty"],
        "printer" => $vysledek["printer"],
    ],
    JSON_UNESCAPED_UNICODE
);


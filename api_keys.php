<?php
/**
 * ODSTRANĚNO: původní (legacy) správa API klíčů. Náhrada: /api/v1/keys.php
 * (stejné operace GET / POST / DELETE ?id=, chyby ve formátu API v1, viz README, Změny rozhraní).
 *
 * Soubor zůstává jen proto, aby starý klient dostal srozumitelnou odpověď místo 404:
 * jakákoli metoda = 410 Gone, chyba ve formátu API v1 s kódem `odstraneno` a hlavička Link na náhradu.
 * Klíč ani DB se neověřují.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';

Api::obsluz(function (): void {
    throw new ApiChyba(410, 'odstraneno', "Endpoint api_keys.php byl odstraněn. Použij api/v1/keys.php (stejné operace, chyby ve formátu API v1).", [
        'odstraneno' => 'api_keys.php',
        'nahrada' => 'api/v1/keys.php',
    ], ['Link' => '<api/v1/keys.php>; rel="successor-version"']);
});

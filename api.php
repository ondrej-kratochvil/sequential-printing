<?php
/**
 * ODSTRANĚNO: původní (legacy) JSON API pro výpočet rozložení. Náhrada: /api/v1/arrange.php
 * (stejný výstup, jednotný formát chyb, přísná validace vstupu, viz README, Změny rozhraní).
 *
 * Soubor zůstává jen proto, aby starý klient dostal srozumitelnou odpověď místo 404:
 * jakákoli metoda = 410 Gone, chyba ve formátu API v1 s kódem `odstraneno` a hlavička Link na náhradu.
 * Klíč ani DB se neověřují.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Api.php';

Api::obsluz(function (): void {
	throw new ApiChyba(410, 'odstraneno', "Endpoint api.php byl odstraněn. Použij api/v1/arrange.php (stejné parametry, chyby ve formátu API v1).", [
		'odstraneno' => 'api.php',
		'nahrada' => 'api/v1/arrange.php',
	], ['Link' => '<api/v1/arrange.php>; rel="successor-version"']);
});

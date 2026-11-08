<?php
/**
 * Vzhled stránek účtu (ucet.php): hlavička, navigace, styly a pár ikon (tvary z Lucide, licence ISC).
 * Barvy a tvary jsou stejné jako v index.php (paleta PrusaSliceru), žádná externí knihovna se nenačítá.
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

class StrankaUctu
{
    /** Položky navigace účtu: stránka => [popis, ikona, jen pro admina]. */
    public const NAVIGACE = [
        'projekty' => ['Moje projekty', 'folder-open', false],
        'klice' => ['API klíče', 'key', false],
    ];

    /** Ikony použité na stránkách účtu (symboly i-*). */
    private const IKONY = [
        'calculator' => '<rect width="16" height="20" x="4" y="2" rx="2"/><line x1="8" x2="16" y1="6" y2="6"/><line x1="16" x2="16" y1="14" y2="18"/><path d="M16 10h.01"/><path d="M12 10h.01"/><path d="M8 10h.01"/><path d="M12 14h.01"/><path d="M8 14h.01"/><path d="M12 18h.01"/><path d="M8 18h.01"/>',
        'folder-open' => '<path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2"/>',
        'log-in' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
        'pencil' => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'copy' => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'key' => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
        'printer' => '<path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8" rx="1"/>',
        'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'send' => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
        'undo' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11"/>',
    ];

    /** Escapování do HTML. */
    public static function h($text): string
    {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }

    /** Ikona ze spritu (dekorativní, aria-hidden). */
    public static function ikona(string $nazev): string
    {
        return '<svg class="ico" aria-hidden="true" focusable="false"><use href="#i-' . self::h($nazev) . '"/></svg>';
    }

    /** Skryté pole s CSRF tokenem do formuláře. */
    public static function csrfPole(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::h(Relace::csrfToken()) . '" />';
    }

    /** Datum a čas z DB (Y-m-d H:i:s) česky: 8. 11. 2026 14:05. */
    public static function datum($hodnota): string
    {
        if (!is_string($hodnota) || $hodnota === '') return '';
        $t = strtotime($hodnota);
        return $t === false ? $hodnota : date('j. n. Y H:i', $t);
    }

    /** Začátek stránky: HTML hlavička, styly, ikony, horní lišta s uživatelem a navigací. */
    public static function zacatek(string $titulek, ?array $uzivatel, string $aktivni = ''): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        echo '<!DOCTYPE html>' . "\n" . '<html lang="cs"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />';
        echo '<title>' . self::h($titulek) . ' | Sekvenční tisk</title><style>' . self::styly() . '</style></head><body>';
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" style="position:absolute; overflow:hidden;" aria-hidden="true" focusable="false">';
        foreach (self::IKONY as $nazev => $tvar) {
            echo '<symbol id="i-' . $nazev . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $tvar . '</symbol>';
        }
        echo '</svg><div class="container">';
        echo '<header class="header"><a class="brand" href="index.php"><h1>Sekvenční tisk</h1></a><div class="ucet">';
        if ($uzivatel) {
            echo '<span class="email" title="Přihlášený uživatel">' . self::h($uzivatel['email']) . ($uzivatel['is_admin'] ? ' <span class="stitek">admin</span>' : '') . '</span>';
            echo '<form method="post" action="ucet.php" class="inline">' . self::csrfPole() . '<input type="hidden" name="akce" value="odhlasit" /><button class="small ghost" type="submit">' . self::ikona('log-out') . 'Odhlásit</button></form>';
        }
        echo '</div></header>';
        if ($uzivatel) {
            echo '<nav class="nav" aria-label="Účet"><a class="tab" href="index.php">' . self::ikona('calculator') . 'Výpočet</a>';
            foreach (self::NAVIGACE as $stranka => [$popis, $ikona, $jenAdmin]) {
                if ($jenAdmin && !$uzivatel['is_admin']) continue;
                echo '<a class="tab' . ($stranka === $aktivni ? ' active' : '') . '" href="ucet.php?stranka=' . self::h($stranka) . '"' . ($stranka === $aktivni ? ' aria-current="page"' : '') . '>' . self::ikona($ikona) . self::h($popis) . '</a>';
            }
            echo '</nav>';
        }
        $zprava = Relace::vezmiZpravu();
        if ($zprava) echo '<div class="zprava ' . ($zprava['typ'] === 'chyba' ? 'chyba' : 'ok') . '" role="status">' . self::h($zprava['text']) . '</div>';
    }

    /** Konec stránky (volitelně inline skript). */
    public static function konec(string $skript = ''): void
    {
        echo '</div>';
        if ($skript !== '') echo '<script>' . $skript . '</script>';
        echo '</body></html>';
    }

    /** Styly stránek účtu (stejné proměnné jako index.php). */
    private static function styly(): string
    {
        return <<<'CSS'
:root { --bg: #202020; --card: #292929; --bg-input: #1a1a1a; --bg-header: #181818; --bg-active: #383838;
  --accent: #fd6925; --accent-2: #e0551a; --accent-text: #fff; --text: #eee; --muted: #999; --border: #444; --border-input: #555;
  --success: #75b54b; --danger: #ff4d4d; --shadow: 0 10px 30px rgba(0,0,0,0.35); --radius: 14px; }
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.45 system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Arial, sans-serif; }
a { color: var(--accent); }
.container { max-width: 1100px; margin: 0 auto; padding: 18px; }
.header { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 14px; background: var(--bg-header); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 14px; }
.header .brand { color: var(--text); text-decoration: none; }
.header h1 { margin: 0; font-size: 20px; }
.ucet { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.ucet .email { color: var(--muted); font-weight: 600; overflow-wrap: anywhere; }
.stitek { display: inline-block; padding: 1px 7px; border-radius: 999px; border: 1px solid var(--border-input); font-size: 12px; color: var(--text); }
.stitek.ok { border-color: var(--success); color: var(--success); }
.stitek.ceka { border-color: var(--accent); color: var(--accent); }
.nav { display: flex; gap: 8px; flex-wrap: wrap; margin: 0 0 14px; }
.nav .tab { display: inline-flex; align-items: center; gap: 0.45em; padding: 8px 12px; border-radius: 12px; border: 1px solid var(--border-input); background: var(--card); color: var(--text); text-decoration: none; }
.nav .tab:hover { background: var(--bg-active); }
.nav .tab.active { background: var(--accent); border-color: var(--accent); color: var(--accent-text); font-weight: 700; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 14px; margin-bottom: 14px; }
.card.uzka { max-width: 440px; }
h2 { margin: 0 0 10px; font-size: 16px; }
h3 { margin: 14px 0 8px; font-size: 14px; }
p { margin: 0 0 10px; }
.muted { color: var(--muted); }
.toolbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin: 10px 0 0; }
form.inline { display: inline-flex; gap: 6px; align-items: center; margin: 0; flex-wrap: wrap; }
.pole { display: grid; gap: 4px; margin: 0 0 10px; }
.pole label { font-weight: 600; }
.pole .chyba, .chyba-text { color: var(--danger); font-size: 13px; }
.pole .hint { color: var(--muted); font-size: 13px; }
input[type=text], input[type=email], input[type=password], input[type=number], select, textarea { border-radius: 12px; border: 1px solid var(--border-input); background: var(--bg-input); color: var(--text); padding: 9px 12px; font: inherit; max-width: 100%; }
input[type=number] { text-align: right; }
input:focus, select:focus, textarea:focus, button:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }
input[aria-invalid=true] { border-color: var(--danger); }
button, .button { border-radius: 12px; border: 1px solid var(--border-input); background: var(--card); color: var(--text); padding: 9px 12px; font: inherit; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 0.45em; text-decoration: none; }
button:hover, .button:hover { background: var(--bg-active); }
button.primary, .button.primary { background: var(--accent); border-color: var(--accent); color: var(--accent-text); font-weight: 700; }
button.primary:hover, .button.primary:hover { background: var(--accent-2); }
button.danger { border-color: var(--danger); color: var(--danger); }
button.small, .button.small { padding: 6px 10px; border-radius: 10px; font-size: 13px; }
.ico { width: 1.1em; height: 1.1em; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: separate; border-spacing: 0; }
th, td { padding: 9px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; text-align: left; }
th { color: var(--muted); font-weight: 700; font-size: 12px; }
td.cislo, th.cislo { text-align: right; }
tr:last-child td { border-bottom: none; }
.zprava { padding: 10px 14px; border-radius: 12px; margin: 0 0 14px; border: 1px solid var(--success); color: var(--success); background: rgba(117,181,75,0.08); font-weight: 600; }
.zprava.chyba { border-color: var(--danger); color: var(--danger); background: rgba(255,77,77,0.08); }
details > summary { list-style: none; cursor: pointer; }
details > summary::-webkit-details-marker { display: none; }
.tajne { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; padding: 10px; border-radius: 10px; border: 1px dashed var(--accent); background: var(--bg-input); overflow-wrap: anywhere; user-select: all; }
CSS;
    }
}

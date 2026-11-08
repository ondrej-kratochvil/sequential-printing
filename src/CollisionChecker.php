<?php
/**
 * Sekvenční tisk - nezávislá kontrola kolizí hotového rozmístění.
 *
 * Kontrola nepoužívá žádný mezivýpočet kalkulátoru, jen výsledné pozice (střed X/Y v mm),
 * rozměry kusů a parametry tiskárny. Pro každý kus v pořadí tisku ověří:
 *
 * 1) Hlava: obdélník hlavy kolem trysky je Xl vlevo, Xr vpravo, Yl dopředu, Yr dozadu
 *    (stejný význam jako v kalkulátoru a ve vizualizaci; při head_steps se bere obálka hlavy, viz obalkaHlavy()).
 *    Tryska během tisku kusu projede celý jeho půdorys, takže hlava zasáhne obdélník
 *    [levá hrana − Xl, pravá hrana + Xr] × [přední hrana − Yl, zadní hrana + Yr].
 *    Do tohoto obdélníku nesmí zasahovat ŽÁDNÝ dříve vytištěný kus (ne jen předchozí řada).
 *    Rozměry hlavy se berou jako obálka do výšky H dřívějšího kusu: při tisku první vrstvy aktuálního
 *    kusu je nad dřívějším kusem celá část hlavy od trysky do výšky H (ve výškách 0..H nad tryskou),
 *    takže rozhoduje po složkách největší Xl, Xr, Yl, Yr ze všech schodů se z <= H, ne jen schod pro H.
 *
 * 2) Vodicí tyče: tyče jsou na pohyblivé části ve vzdálenosti vodici_tyce_Y od trysky
 *    (za tryskou při tisku zepředu dozadu, před tryskou při tisku zezadu dopředu) ve výšce
 *    vodici_tyce_Z nad tryskou a vedou přes celou šířku podložky (osa X).
 *    Při tisku kusu tyč projede pásem Y [přední hrana ± vodici_tyce_Y, zadní hrana ± vodici_tyce_Y].
 *    Dřívější kus vyšší než vodici_tyce_Z nesmí do tohoto pásu zasahovat.
 *
 * 3) Podložka: kus musí ležet celý na podložce (0..x, 0..y; x už je po korekci posun_zprava).
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

class CollisionChecker
{
    /** Tolerance v mm (pozice jsou zaokrouhlené na 0,01 mm). */
    public const TOLERANCE = 0.02;

    /** @var array */
    private $printer;

    /**
     * @param array $printer Normalizovaná tiskárna z kalkulátoru (x, y, Xl, Xr, Yl, Yr,
     *                       head_steps, vodici_tyce_Z, vodici_tyce_Y, smer_Y).
     */
    public function __construct(array $printer)
    {
        $this->printer = $printer;
    }

    /**
     * Sestaví seznam kusů pro kontrolu z výstupu kalkulátoru (pole `pozice` + rozměry z `objekty`).
     *
     * @param array $pozice  [['poradi','objekt','instance','X','Y'], ...]
     * @param array $objekty Klíč = `objekt` z pozice, hodnota obsahuje x, y, z.
     * @return array
     */
    public static function kusyZVysledku(array $pozice, array $objekty): array
    {
        $kusy = [];
        foreach ($pozice as $p) {
            $o = isset($objekty[$p['objekt']]) ? $objekty[$p['objekt']] : null;
            if (!is_array($o)) continue;
            $kusy[] = [
                'poradi' => (int)$p['poradi'],
                'objekt' => $p['objekt'],
                'instance' => (int)$p['instance'],
                'X' => (float)$p['X'],
                'Y' => (float)$p['Y'],
                'x' => (float)$o['x'],
                'y' => (float)$o['y'],
                'z' => (float)$o['z'],
            ];
        }
        return $kusy;
    }

    /**
     * Zkontroluje rozmístění.
     *
     * @param array $kusy [['poradi','objekt','instance','X','Y','x','y','z'], ...] (X/Y = střed)
     * @return array ['ok' => bool, 'pocet_kusu' => int, 'konflikty' => [...]]
     */
    public function check(array $kusy): array
    {
        usort($kusy, function ($a, $b) {
            return $a['poradi'] <=> $b['poradi'];
        });

        $konflikty = [];
        foreach ($kusy as $k => $kus) {
            $mimo = $this->presahMimoPodlozku($kus);
            if ($mimo > self::TOLERANCE) {
                $konflikty[] = $this->konflikt('podlozka', $kus, null, $mimo);
            }
            for ($j = 0; $j < $k; $j++) {
                $drivejsi = $kusy[$j];
                $presah = $this->presahHlavy($kus, $drivejsi);
                if ($presah > self::TOLERANCE) {
                    $konflikty[] = $this->konflikt('hlava', $kus, $drivejsi, $presah);
                }
                $presah = $this->presahTyci($kus, $drivejsi);
                if ($presah > self::TOLERANCE) {
                    $konflikty[] = $this->konflikt('tyce', $kus, $drivejsi, $presah);
                }
            }
        }

        return [
            'ok' => empty($konflikty),
            'pocet_kusu' => count($kusy),
            'konflikty' => $konflikty,
        ];
    }

    /**
     * Obdélník, kterým projede hlava při tisku kusu (pro profil hlavy $head).
     *
     * @return array [xMin, xMax, yMin, yMax]
     */
    public static function obdelnikHlavy(array $kus, array $head): array
    {
        $r = self::obdelnikKusu($kus);
        return [
            $r[0] - (float)$head['Xl'],
            $r[1] + (float)$head['Xr'],
            $r[2] - (float)$head['Yl'],
            $r[3] + (float)$head['Yr'],
        ];
    }

    /** @return array [xMin, xMax, yMin, yMax] půdorysu kusu */
    public static function obdelnikKusu(array $kus): array
    {
        return [
            $kus['X'] - ($kus['x'] / 2.0),
            $kus['X'] + ($kus['x'] / 2.0),
            $kus['Y'] - ($kus['y'] / 2.0),
            $kus['Y'] + ($kus['y'] / 2.0),
        ];
    }

    /**
     * O kolik mm zasahuje hlava při tisku $kus do dřívějšího kusu (0 = nezasahuje).
     * Vrací menší z průniků v X a Y (o tolik by stačilo kus posunout).
     */
    public function presahHlavy(array $kus, array $drivejsi): float
    {
        $head = self::obalkaHlavy($this->printer, (float)$drivejsi['z']);
        $h = self::obdelnikHlavy($kus, $head);
        $d = self::obdelnikKusu($drivejsi);
        $pruX = min($h[1], $d[1]) - max($h[0], $d[0]);
        $pruY = min($h[3], $d[3]) - max($h[2], $d[2]);
        if ($pruX <= 0 || $pruY <= 0) return 0.0;
        return round(min($pruX, $pruY), 2);
    }

    /**
     * O kolik mm zasahuje pás, kterým projedou vodicí tyče při tisku $kus, do dřívějšího kusu
     * vyššího než vodici_tyce_Z (0 = nezasahuje).
     */
    public function presahTyci(array $kus, array $drivejsi): float
    {
        $tyceZ = isset($this->printer['vodici_tyce_Z']) ? (float)$this->printer['vodici_tyce_Z'] : 0.0;
        if ((float)$drivejsi['z'] <= $tyceZ) return 0.0;
        $pas = $this->pasTyci($kus);
        if ($pas === null) return 0.0;
        $d = self::obdelnikKusu($drivejsi);
        $pru = min($pas[1], $d[3]) - max($pas[0], $d[2]);
        if ($pru <= 0) return 0.0;
        return round($pru, 2);
    }

    /**
     * Pás v ose Y, kterým projedou vodicí tyče při tisku kusu, nebo null, když tiskárna tyče nemá.
     *
     * @return array|null [yMin, yMax]
     */
    public function pasTyci(array $kus): ?array
    {
        $tyceY = isset($this->printer['vodici_tyce_Y']) ? (float)$this->printer['vodici_tyce_Y'] : 0.0;
        $tyceZ = isset($this->printer['vodici_tyce_Z']) ? (float)$this->printer['vodici_tyce_Z'] : 0.0;
        if ($tyceY <= 0 && $tyceZ <= 0) return null;
        $r = self::obdelnikKusu($kus);
        $znamenko = ($this->smerY() === 'zepredu_dozadu') ? 1.0 : -1.0;
        return [$r[2] + $znamenko * $tyceY, $r[3] + $znamenko * $tyceY];
    }

    /**
     * O kolik mm je potřeba posunout $kus ve směru tisku v ose Y (zepředu dozadu = +Y,
     * zezadu dopředu = −Y), aby nekolidoval s dřívějším kusem (hlava i vodicí tyče).
     * 0 = nekoliduje. Kalkulátor to používá pro opravu rozmístění.
     */
    public function posunProVyreseni(array $kus, array $drivejsi): float
    {
        $zepredu = ($this->smerY() === 'zepredu_dozadu');
        $r = self::obdelnikKusu($kus);
        $d = self::obdelnikKusu($drivejsi);
        $s = 0.0;
        if ($this->presahHlavy($kus, $drivejsi) > self::TOLERANCE) {
            // Hlava musí celá minout dřívější kus: přední okraj hlavy za jeho zadní hranou (resp. naopak).
            $head = self::obalkaHlavy($this->printer, (float)$drivejsi['z']);
            $s = max($s, $zepredu ? ($d[3] - ($r[2] - (float)$head['Yl'])) : (($r[3] + (float)$head['Yr']) - $d[2]));
        }
        if ($this->presahTyci($kus, $drivejsi) > self::TOLERANCE) {
            // Pás vodicích tyčí musí začínat až za dřívějším kusem (resp. končit před ním).
            $pas = $this->pasTyci($kus);
            $s = max($s, $zepredu ? ($d[3] - $pas[0]) : ($pas[1] - $d[2]));
        }
        return max(0.0, $s);
    }

    /** O kolik mm kus přečnívá mimo podložku (0 = celý na podložce). */
    public function presahMimoPodlozku(array $kus): float
    {
        $r = self::obdelnikKusu($kus);
        $bedX = (float)$this->printer['x'];
        $bedY = (float)$this->printer['y'];
        $presah = max(0.0, -$r[0], $r[1] - $bedX, -$r[2], $r[3] - $bedY);
        return round($presah, 2);
    }

    /**
     * Obálka hlavy do výšky H (jediné místo pro rozměry hlavy podle výšky: kontrola kolizí, kalkulátor
     * i vizualizace). Po složkách maximum Xl, Xr, Yl, Yr ze všech schodů se z <= H včetně nejnižšího schodu
     * (pod nejnižším schodem platí nejnižší schod, stejně jako dřív). Bez schodů Xl/Xr/Yl/Yr tiskárny.
     * U profilu, který se směrem nahoru nezužuje, je obálka totéž co schod pro výšku H.
     *
     * @param array $printer Tiskárna s Xl, Xr, Yl, Yr a volitelně head_steps ([['z','Xl','Xr','Yl','Yr'], ...]).
     * @return array ['Xl','Xr','Yl','Yr'] (se schody i 'z' schodu, od kterého obálka platí)
     */
    public static function obalkaHlavy(array $printer, float $h): array
    {
        $steps = isset($printer['head_steps']) && is_array($printer['head_steps']) ? $printer['head_steps'] : [];
        $obalka = empty($steps) ? [] : self::obalkaSchodu($steps);
        if (empty($obalka)) {
            return [
                'Xl' => (float)$printer['Xl'],
                'Xr' => (float)$printer['Xr'],
                'Yl' => (float)$printer['Yl'],
                'Yr' => (float)$printer['Yr'],
            ];
        }
        $best = $obalka[0];
        foreach ($obalka as $s) {
            if ($s['z'] <= $h) $best = $s;
            else break;
        }
        return $best;
    }

    /**
     * Schody převedené na obálku: každý schod (seřazené podle z) má po složkách maximum ze sebe
     * a všech schodů pod ním. Výběr schodu pro výšku H (nejbližší z <= H) z obálky = obalkaHlavy().
     * Vizualizace dostává tyto schody, aby kreslila stejnou hlavu, s jakou počítá kontrola.
     *
     * @param array $steps [['z','Xl','Xr','Yl','Yr'], ...]
     * @return array [['z','Xl','Xr','Yl','Yr'], ...] seřazené podle z
     */
    public static function obalkaSchodu(array $steps): array
    {
        $serazene = [];
        foreach (array_values($steps) as $i => $s) {
            if (!is_array($s)) continue;
            $serazene[] = [$i, $s];
        }
        usort($serazene, function ($a, $b) {
            $porovnani = ((float)$a[1]['z'] <=> (float)$b[1]['z']);
            return $porovnani !== 0 ? $porovnani : ($a[0] <=> $b[0]);
        });
        $obalka = [];
        $max = null;
        foreach ($serazene as [$_i, $s]) {
            $schod = ['z' => (float)$s['z']];
            foreach (['Xl', 'Xr', 'Yl', 'Yr'] as $klic) {
                $v = (float)$s[$klic];
                $schod[$klic] = ($max === null) ? $v : max($max[$klic], $v);
            }
            $max = $schod;
            $obalka[] = $schod;
        }
        return $obalka;
    }

    private function smerY(): string
    {
        if (isset($this->printer['smer_Y'])) return $this->printer['smer_Y'];
        return ((float)$this->printer['Yl'] <= (float)$this->printer['Yr']) ? 'zepredu_dozadu' : 'zezadu_dopredu';
    }

    private function konflikt(string $typ, array $kus, ?array $drivejsi, float $presah): array
    {
        $popisKusu = function (array $k): string {
            return '#' . $k['poradi'] . ' (objekt ' . ((int)$k['objekt'] + 1) . ', instance ' . $k['instance'] . ')';
        };
        if ($typ === 'hlava') {
            $popis = 'Hlava při tisku kusu ' . $popisKusu($kus) . ' zasáhne do kusu ' . $popisKusu($drivejsi) . ' o ' . $presah . ' mm.';
        } elseif ($typ === 'tyce') {
            $popis = 'Vodicí tyče při tisku kusu ' . $popisKusu($kus) . ' zasáhnou do kusu ' . $popisKusu($drivejsi) . ' o ' . $presah . ' mm.';
        } else {
            $popis = 'Kus ' . $popisKusu($kus) . ' přečnívá mimo podložku o ' . $presah . ' mm.';
        }
        return [
            'typ' => $typ,
            'poradi' => $kus['poradi'],
            'objekt' => $kus['objekt'],
            'instance' => $kus['instance'],
            's_poradi' => $drivejsi !== null ? $drivejsi['poradi'] : null,
            's_objekt' => $drivejsi !== null ? $drivejsi['objekt'] : null,
            's_instance' => $drivejsi !== null ? $drivejsi['instance'] : null,
            'presah_mm' => $presah,
            'popis' => $popis,
        ];
    }
}

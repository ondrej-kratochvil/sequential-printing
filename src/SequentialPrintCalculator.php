<?php
/**
 * Sekvenční tisk – výpočet rozložení instancí.
 *
 * Cíl refaktoru: oddělit výpočet od HTML, aby šel jednoduše testovat a volat z API.
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'CollisionChecker.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'PredvolbyTiskaren.php';

class SequentialPrintCalculator
{
    public const MAXIMALNI_POCET_INSTANCI = 99;
    public const MAXIMALNI_POCET_ITERACI = 99;
    /** Maximální délka volitelného názvu objektu (znaky). */
    public const MAXIMALNI_DELKA_NAZVU = 60;

    /** Rozprostření v ose Y: každá další instance v pořadí tisku o krok dál (přední řada je schodovitá). */
    public const ROZPROSTRENI_Y_PO_INSTANCICH = 'po_instancich';
    /** Rozprostření v ose Y: celá řada se posune o stejný krok, zbylé místo se rozdělí mezi řady. */
    public const ROZPROSTRENI_Y_PO_RADACH = 'po_radach';
    public const ROZPROSTRENI_Y_REZIMY = [self::ROZPROSTRENI_Y_PO_INSTANCICH, self::ROZPROSTRENI_Y_PO_RADACH];

    /** Strop počtu podložek při volbě `vice_podlozek` (a výchozí `max_podlozek`). */
    public const MAXIMALNI_POCET_PODLOZEK = 20;

    /**
     * Výchozí tiskárna (Prusa MINI) - jediné místo, odkud ji berou UI (index.php), API v1 i CLI (bin/arrange.php).
     * Hodnoty jsou v předvolbě config/tiskarny.json (výchozí předvolba, viz PredvolbyTiskaren), tady se jen čtou.
     * Xl/Xr/Yl/Yr = vzdálenost trysky od levé/pravé/přední/zadní hrany hlavy (mm).
     * posun_zprava = korekce pro Prusa MINI, kdy objekt umístěný zcela vpravo má deformovanou stěnu.
     */
    public static function vychoziTiskarna(): array
    {
        return PredvolbyTiskaren::parametry(PredvolbyTiskaren::vychoziId());
    }

    /** Výchozí nastavení výpočtu (stejné jako UI bez parametrů). */
    public const VYCHOZI_NASTAVENI = [
        'rozprostrit_instance_po_cele_podlozce' => true,
        'rozprostrit_instance_v_ose_x' => true,
        'rozprostrit_instance_v_ose_y' => true,
        'rozprostreni_y_rezim' => self::ROZPROSTRENI_Y_PO_INSTANCICH,
        // Rotace o 90° kolem Z povolená všem objektům (jinak jen objektům s `rotace`). Výchozí vypnuto kvůli kompatibilitě.
        'povolit_rotaci_vsem' => false,
        // Více podložek: co se nevejde na jednu podložku, pokračuje na další (nejvýš max_podlozek). Výchozí vypnuto.
        'vice_podlozek' => false,
        'max_podlozek' => self::MAXIMALNI_POCET_PODLOZEK,
    ];

    /** @var array */
    private $printer;

    /** @var array */
    private $options;

    /** @var array */
    private $objekty = [];

    /** @var array */
    private $objektySerazene = [];

    /** @var array */
    private $pos1 = [];

    /** @var array */
    private $pos2 = [];

    /** @var array */
    private $zbyvaVOseX = [];

    /** @var float */
    private $zbyvaVOseY = 0.0;

    /** @var float */
    private $zbyvaVOseXMaximalne = 0.0;

    /** @var array */
    private $datovaVetaPole = [];
    /** Pozice s metadaty v poradi tisku: poradi, objekt (klic ve vstupu), instance, rada, X, Y. */
    private $poziceDetail = [];

    /** @var array */
    private $pocetInstanciObjektu = [];

    /** @var array */
    private $XcountPole = [];

    /** @var float */
    private $posunX1 = 0.0;

    /** @var float */
    private $posunY1 = 0.0;

    /** @var int */
    private $pocetInstanci1 = 0;

    /** @var int */
    private $pocetRad1 = 0;

    /** @var float */
    private $poziceHranyPrvnihoObjektuVRade = 0.0;

    /** @var float */
    private $poziceHranyNejvzdalenejsihoObjektu = 0.0;

    /** @var int */
    private $pocetPodlozek1 = 1;

    /** @var int */
    private $Xcount1 = 0;

    /** @var int */
    private $omezenyPocetInstanciVRade = 0;

    /** @var array */
    private $pomernyPocetVyslednychInstanci = [];

    /** @var array */
    private $bylaUmistenaAlesponJednaInstanceObjektu = [];

    /** @var array */
    private $kromeTechtoObjektu = [];

    /** @var bool Posun řady počítat s hlavou přes celou šířku instance (bezpečnější varianta při kolizi). */
    private $hlavaPresCelouSirku = false;

    /** @var int Kolik kusů vynechala poslední oprava kolizí. */
    private $pocetVynechanychKusu = 0;

    /** @var array Kolik kusů kterého objektu (klíč ve vstupu) vynechala poslední oprava kolizí. */
    private $vynechaneKusyObjektu = [];

    /** @var array Objekty vyřazené už při kontrole vstupu (rozměr větší než podložka): klíč => [pozadovano, osa, rozmer, limit, nazev]. */
    private $vyrazeneObjekty = [];

    /** @var array Objekty zadané s počtem "max" (klíč ve vstupu => true). */
    private $pozadovanoMax = [];

    /** @var array|null Kde a proč se poslední průchod výpočtu zastavil: ['objekt' => klíč, 'duvod' => misto_y|hlava|tyce]. */
    private $zastaveni = null;

    /** @var bool Výpočet dal přednost prvnímu objektu (nevešel se jeho přesný počet), ostatní dostaly zbytek místa. */
    private $prednostPrvnihoObjektu = false;

    public function __construct(array $printer, array $options = [])
    {
        $this->printer = $this->normalizePrinter($printer);
        $this->options = $this->normalizeOptions($options);
    }

    /**
     * Výpočet rozložení + nezávislá kontrola kolizí (CollisionChecker).
     *
     * Když kontrola najde kolizi, zkouší bezpečnější varianty (bezpecnejsiVarianty()): nastavení
     * uživatele, totéž s hlavou přes celou šířku kusu, pak postupně bez rozprostření v ose X,
     * v X ani Y a po celé podložce. U každé varianty se kolidující kusy posunou ve směru tisku
     * v ose Y o potřebnou vzdálenost; kus, který by se pak nevešel na podložku, se vynechá.
     * Vezme se první varianta bez kolize, která udrží původní počet instancí bez vynechání,
     * jinak varianta bez kolize s nejvíce instancemi.
     *
     * Rotace o 90° (objekt `rotace` nebo nastavení `povolit_rotaci_vsem`): totéž se spočítá i pro
     * varianty s otočenými objekty (x a y zaměněné) a vezme se nejlepší (viz jeLepsiVarianta()).
     * Výsledek má navíc `otocene_objekty` a u pozic `otoceno`.
     *
     * Více podložek (nastavení `vice_podlozek`): co se nevejde na první podložku, pokračuje
     * na další podložce (viz vypocitejVicePodlozek()).
     *
     * @param array $objekty Input objekty ve tvaru jako z GET (včetně instances[d]).
     * @return array Výsledek výpočtu (pozice, metadata, aktualizované objekty, kontrola_kolizi, podlozky).
     */
    public function calculate(array $objekty): array
    {
        $prvni = $this->vypocitejJednuPodlozku($objekty);
        if ($this->options['vice_podlozek']) return $this->vypocitejVicePodlozek($objekty, $prvni);
        $prvni['podlozky'] = [self::souhrnPodlozky(1, $prvni)];
        return $prvni;
    }

    /**
     * Více podložek: dokud zbývají požadované instance (objekty s přesným počtem), počítá další
     * podložku jen se zbylými kusy, stejnými pravidly a se samostatnou kontrolou kolizí. Končí,
     * když se vešlo vše, když se na novou podložku nevešel žádný kus, nebo na stropu `max_podlozek`.
     *
     * Objekt s počtem "max" je jen na první podložce (jinak by zaplnil všechny podložky až do stropu).
     * Rotace se volí pro každou podložku zvlášť (pozice[].otoceno, podlozky[].otocene_objekty).
     *
     * Výstup: pos[podložka][řada][kus], pozice s `podlozka`, `poradi` (v rámci podložky)
     * a `poradi_celkem`, instance objektu číslované průběžně přes podložky, pocet_instanci celkem,
     * pocet_podlozek, kontrola_kolizi souhrnně (konflikty s `podlozka`, kroky s předponou „Podložka N:“),
     * podlozky = souhrn každé podložky. Ostatní údaje (řady, zbývající místo, objekty_serazene) jsou z 1. podložky.
     */
    private function vypocitejVicePodlozek(array $objekty, array $prvni): array
    {
        $maxPodlozek = $this->options['max_podlozek'];
        $pozadovano = [];
        foreach ($prvni['objekty'] as $id => $o) {
            if (isset($this->pozadovanoMax[$id])) continue;
            $pozadovano[$id] = (int)$o['instances']['d'];
        }

        $podlozky = [$prvni];
        $umisteno = self::poctyZPozic($prvni['pozice']);
        $stropDosazen = false;
        while (true) {
            $zbyva = [];
            foreach ($pozadovano as $id => $d) {
                $n = $d - ($umisteno[$id] ?? 0);
                if ($n > 0) $zbyva[$id] = $n;
            }
            if (empty($zbyva)) break;
            if (count($podlozky) >= $maxPodlozek) {
                $stropDosazen = true;
                break;
            }
            $vstup = [];
            foreach ($zbyva as $id => $n) {
                $vstup[$id] = $objekty[$id];
                $vstup[$id]['instances'] = ['d' => $n];
            }
            $dalsi = $this->vypocitejJednuPodlozku($vstup);
            if (empty($dalsi['pozice'])) break;
            // Instance objektu číslovat průběžně přes podložky (na 2. podložce pokračuje za 1. podložkou).
            $dalsi = self::posunCislaInstanci($dalsi, $umisteno);
            foreach (self::poctyZPozic($dalsi['pozice']) as $id => $n) {
                $umisteno[$id] = ($umisteno[$id] ?? 0) + $n;
            }
            $podlozky[] = $dalsi;
        }

        if (count($podlozky) === 1) {
            // Na další podložku se nic nedostalo (vše se vešlo, nebo strop 1): výsledek stejný jako bez volby,
            // jen limit na stropu max_podlozek řekne, že se to nevešlo kvůli stropu.
            if ($stropDosazen) $prvni['limit'] = $this->limitVicePodlozek($prvni['limit'], $podlozky, $pozadovano, $umisteno, true);
            $prvni['podlozky'] = [self::souhrnPodlozky(1, $prvni)];
            return $prvni;
        }
        return $this->slozPodlozky($podlozky, $pozadovano, $umisteno, $stropDosazen);
    }

    /** Počet umístěných kusů podle objektu (klíč ve vstupu) z pole pozice. */
    private static function poctyZPozic(array $pozice): array
    {
        $pocty = [];
        foreach ($pozice as $p) {
            $pocty[$p['objekt']] = ($pocty[$p['objekt']] ?? 0) + 1;
        }
        return $pocty;
    }

    /** Přičte k číslům instancí (pos, pozice, datova_veta_pole) počet kusů objektu z dřívějších podložek. */
    private static function posunCislaInstanci(array $vysledek, array $umisteno): array
    {
        foreach ($vysledek['pozice'] as $k => $p) {
            $posun = $umisteno[$p['objekt']] ?? 0;
            $vysledek['pozice'][$k]['instance'] += $posun;
            $vysledek['datova_veta_pole'][$k][0] += $posun;
        }
        if (!empty($vysledek['pos'][1])) {
            foreach ($vysledek['pos'][1] as $y => $rada) {
                foreach ($rada as $x => $inst) {
                    $vysledek['pos'][1][$y][$x]['i'] += $umisteno[$inst['o'] - 1] ?? 0;
                }
            }
        }
        return $vysledek;
    }

    /** Souhrn jedné podložky pro výstup `podlozky`. */
    private static function souhrnPodlozky(int $cislo, array $vysledek): array
    {
        return [
            'podlozka' => $cislo,
            'pocet_instanci' => $vysledek['pocet_instanci'],
            'pocet_rad' => $vysledek['pocet_rad'],
            'otocene_objekty' => $vysledek['otocene_objekty'] ?? [],
            'kontrola_kolizi' => $vysledek['kontrola_kolizi'] ?? null,
        ];
    }

    /**
     * Složí výsledky jednotlivých podložek do jednoho výsledku (popis viz vypocitejVicePodlozek()).
     *
     * @param array $podlozky     Výsledky vypocitejJednuPodlozku() v pořadí podložek
     * @param array $pozadovano   Požadovaný počet objektů s přesným počtem (klíč => d)
     * @param array $umisteno     Umístěno celkem (klíč => počet)
     * @param bool  $stropDosazen Výpočet skončil na stropu max_podlozek
     */
    private function slozPodlozky(array $podlozky, array $pozadovano, array $umisteno, bool $stropDosazen): array
    {
        $prvni = $podlozky[0];
        $vysledek = $prvni;
        $vysledek['pos'] = $vysledek['pozice'] = $vysledek['datova_veta_pole'] = $vysledek['podlozky'] = [];
        // Rozměry objektů na každé podložce (rotace se volí po podložkách) - pro vizualizaci a kontrolu po podložkách.
        $vysledek['objekty_podlozek'] = [];
        $kontrola = ['ok' => true, 'pocet_kusu' => 0, 'konflikty' => [], 'upraveno' => false, 'postup' => [], 'puvodni_konflikty' => []];
        $otocene = [];
        $poradiCelkem = 0;

        foreach ($podlozky as $index => $podlozka) {
            $cislo = $index + 1;
            $vysledek['pos'][$cislo] = $podlozka['pos'][1] ?? [];
            $vysledek['objekty_podlozek'][$cislo] = $podlozka['objekty'];
            foreach ($podlozka['pozice'] as $k => $p) {
                $p['poradi_celkem'] = ++$poradiCelkem;
                $p['podlozka'] = $cislo;
                $vysledek['pozice'][] = $p;
                $vysledek['datova_veta_pole'][] = $podlozka['datova_veta_pole'][$k];
            }
            $vysledek['podlozky'][] = self::souhrnPodlozky($cislo, $podlozka);
            $otocene = array_merge($otocene, $podlozka['otocene_objekty'] ?? []);

            $kk = $podlozka['kontrola_kolizi'] ?? ['ok' => false, 'pocet_kusu' => 0, 'konflikty' => []];
            $kontrola['ok'] = $kontrola['ok'] && !empty($kk['ok']);
            $kontrola['pocet_kusu'] += (int)($kk['pocet_kusu'] ?? 0);
            foreach ($kk['konflikty'] ?? [] as $konflikt) $kontrola['konflikty'][] = ['podlozka' => $cislo] + $konflikt;
            if (!empty($kk['upraveno'])) {
                $kontrola['upraveno'] = true;
                foreach ($kk['postup'] ?? [] as $krok) $kontrola['postup'][] = 'Podložka ' . $cislo . ': ' . $krok;
                foreach ($kk['puvodni_konflikty'] ?? [] as $konflikt) $kontrola['puvodni_konflikty'][] = ['podlozka' => $cislo] + $konflikt;
            }
        }
        if (!$kontrola['upraveno']) unset($kontrola['puvodni_konflikty']);

        $otocene = array_values(array_unique($otocene));
        sort($otocene);
        $vysledek['otocene_objekty'] = $otocene;
        $vysledek['kontrola_kolizi'] = $kontrola;
        $vysledek['pocet_instanci'] = count($vysledek['pozice']);
        $vysledek['pocet_podlozek'] = count($podlozky);
        foreach ($vysledek['objekty'] as $id => $_) {
            $vysledek['objekty'][$id]['instances']['r'] = $umisteno[$id] ?? 0;
        }
        $vysledek['limit'] = $this->limitVicePodlozek($prvni['limit'], $podlozky, $pozadovano, $umisteno, $stropDosazen);
        return $vysledek;
    }

    /**
     * Limit u více podložek: z 1. podložky zůstanou objekty vyřazené kvůli rozměru a objekty "max"
     * (ty jsou jen na 1. podložce). U objektů s přesným počtem jen to, co se nevešlo ani na všechny
     * podložky: na stropu max_podlozek `duvod` = `max_podlozek`, jinak důvod z poslední podložky.
     */
    private function limitVicePodlozek(array $limitPrvni, array $podlozky, array $pozadovano, array $umisteno, bool $stropDosazen): array
    {
        $limit = [];
        foreach ($limitPrvni as $polozka) {
            if (!isset($pozadovano[$polozka['objekt']])) $limit[$polozka['objekt']] = $polozka;
        }
        $posledni = $podlozky[count($podlozky) - 1];
        $limitPosledni = [];
        foreach ($posledni['limit'] as $polozka) $limitPosledni[$polozka['objekt']] = $polozka;
        $pocetPodlozek = count($podlozky);
        $naPodlozkach = 'na ' . $pocetPodlozek . ' ' . ($pocetPodlozek === 1 ? 'podložce' : 'podložkách');

        foreach ($pozadovano as $id => $d) {
            $u = $umisteno[$id] ?? 0;
            if ($u >= $d) continue;
            $nazev = $podlozky[0]['objekty'][$id]['nazev'] ?? '';
            $oznaceni = 'Objekt ' . $this->cisloObjektu($id) . ($nazev !== '' ? ' (' . $nazev . ')' : '');
            if ($stropDosazen) {
                $duvod = 'max_podlozek';
                $popisDuvodu = 'další podložka by překročila strop max_podlozek (' . $pocetPodlozek . ')';
            } elseif (isset($limitPosledni[$id])) {
                // Důvod z poslední podložky (bez jejího „umístěno X z Y“).
                $duvod = $limitPosledni[$id]['duvod'];
                $popisDuvodu = rtrim(preg_replace('/^.*?: (umístěno \d+ z \d+|nevešel se ani jeden kus) - /u', '', $limitPosledni[$id]['popis']), '.');
            } else {
                $duvod = 'misto_y';
                $popisDuvodu = 'na další podložku se už nevešel žádný kus';
            }
            $polozka = ['objekt' => $id];
            if ($nazev !== '') $polozka['nazev'] = $nazev;
            $limit[$id] = $polozka + [
                'pozadovano' => $d,
                'umisteno' => $u,
                'duvod' => $duvod,
                'popis' => $oznaceni . ': umístěno ' . $u . ' z ' . $d . ' ' . $naPodlozkach . ' - ' . $popisDuvodu . '.',
            ];
        }
        ksort($limit);
        return array_values($limit);
    }

    /** Jedna podložka: výpočet rozložení + kontrola kolizí + varianty rotace (popis viz calculate()). */
    private function vypocitejJednuPodlozku(array $objekty): array
    {
        // `otoceno` nastavuje jen výpočet (varianta s otočeným objektem), ze vstupu se nepřebírá.
        foreach ($objekty as $id => $objekt) {
            if (is_array($objekt)) unset($objekty[$id]['otoceno']);
        }

        $kandidati = $this->objektySPovolenouRotaci($objekty);
        $zakladni = $this->vypocitejSKontrolou($objekty);
        $zakladni['otocene_objekty'] = [];
        if (empty($kandidati) || $this->vesloSeVse($zakladni)) return $zakladni;

        // Rotace o 90° (ROADMAP B2): varianty s otočenými objekty (záměna x a y), vybere se nejlepší.
        // 1) nic neotočeno, 2) všechny povolené otočené, 3) greedy: od lepší z 1/2 postupně u každého
        // povoleného objektu (v pořadí vstupu) zkusí opačnou orientaci a nechá ji, když je výsledek lepší.
        // Výpočtů je nejvýš 2 + počet povolených objektů (ne všechny kombinace).
        $spocitane = [json_encode([]) => $zakladni];
        $spocitej = function (array $otocene) use ($objekty, &$spocitane): array {
            sort($otocene);
            $klic = json_encode($otocene);
            if (!isset($spocitane[$klic])) {
                $spocitane[$klic] = $this->vypocitejSOtocenim($objekty, $otocene);
            }
            return $spocitane[$klic];
        };

        $nejlepsi = $zakladni;
        $vsechny = $spocitej($kandidati);
        if (self::jeLepsiVarianta($vsechny, $nejlepsi)) $nejlepsi = $vsechny;
        foreach ($kandidati as $id) {
            $otocene = $nejlepsi['otocene_objekty'];
            $otocene = in_array($id, $otocene, true) ? array_values(array_diff($otocene, [$id])) : array_merge($otocene, [$id]);
            $varianta = $spocitej($otocene);
            if (self::jeLepsiVarianta($varianta, $nejlepsi)) $nejlepsi = $varianta;
        }
        return $nejlepsi;
    }

    /**
     * Klíče objektů, u kterých má smysl zkoušet rotaci o 90°: povolená rotace (objekt `rotace`
     * nebo nastavení `povolit_rotaci_vsem`) a různé rozměry x a y (u čtvercového půdorysu se nic nezmění).
     */
    private function objektySPovolenouRotaci(array $objekty): array
    {
        $kandidati = [];
        foreach ($objekty as $id => $objekt) {
            if (!is_array($objekt)) continue;
            $povoleno = $this->options['povolit_rotaci_vsem'] || self::jePovolenaRotace($objekt['rotace'] ?? null);
            if (!$povoleno) continue;
            $x = round($this->toFloat($objekt['x'] ?? null), 2);
            $y = round($this->toFloat($objekt['y'] ?? null), 2);
            if ($x <= 0 || $y <= 0 || $x == $y) continue;
            $kandidati[] = $id;
        }
        return $kandidati;
    }

    /** Hodnota volby `rotace` (bool, 1/0, "1", "true", "on", "ano"). */
    public static function jePovolenaRotace($hodnota): bool
    {
        if (is_bool($hodnota)) return $hodnota;
        if (is_int($hodnota) || is_float($hodnota)) return $hodnota != 0;
        if (!is_string($hodnota)) return false;
        return in_array(strtolower(trim($hodnota)), ['1', 'true', 'on', 'ano', 'yes'], true);
    }

    /** Vešel se požadovaný počet všech objektů bez úprav kontrolou kolizí? Pak rotace nemůže nic zlepšit. */
    private function vesloSeVse(array $vysledek): bool
    {
        if (!empty($this->pozadovanoMax) || !empty($this->vyrazeneObjekty) || !empty($vysledek['limit'])) return false;
        return !empty($vysledek['kontrola_kolizi']['ok']) && empty($vysledek['kontrola_kolizi']['upraveno']);
    }

    /** Výpočet s otočenými objekty (záměna x a y); výsledek nese `otocene_objekty`. */
    private function vypocitejSOtocenim(array $objekty, array $otocene): array
    {
        foreach ($otocene as $id) {
            $x = $objekty[$id]['x'] ?? null;
            $objekty[$id]['x'] = $objekty[$id]['y'] ?? null;
            $objekty[$id]['y'] = $x;
            $objekty[$id]['otoceno'] = true;
        }
        $vysledek = $this->vypocitejSKontrolou($objekty);
        sort($otocene);
        $vysledek['otocene_objekty'] = array_values($otocene);
        return $vysledek;
    }

    /**
     * Pořadí variant rotace: bez kolize > víc instancí > bez úprav kontrolou kolizí > méně kroků úprav
     * > méně otočených objektů (při shodě zůstane objekt neotočený). Při úplné shodě vyhrává dřívější varianta.
     */
    private static function jeLepsiVarianta(array $a, array $b): bool
    {
        $okA = !empty($a['kontrola_kolizi']['ok']);
        $okB = !empty($b['kontrola_kolizi']['ok']);
        if ($okA !== $okB) return $okA;
        if ($a['pocet_instanci'] !== $b['pocet_instanci']) return $a['pocet_instanci'] > $b['pocet_instanci'];
        $upravA = empty($a['kontrola_kolizi']['upraveno']) ? 0 : 1 + count($a['kontrola_kolizi']['postup'] ?? []);
        $upravB = empty($b['kontrola_kolizi']['upraveno']) ? 0 : 1 + count($b['kontrola_kolizi']['postup'] ?? []);
        if ($upravA !== $upravB) return $upravA < $upravB;
        return count($a['otocene_objekty']) < count($b['otocene_objekty']);
    }

    /** Jeden výpočet rozložení + kontrola kolizí v dané orientaci objektů (popis viz calculate()). */
    private function vypocitejSKontrolou(array $objekty): array
    {
        $nastaveniUzivatele = $this->options;

        $this->vypocitejRozlozeni($objekty);
        $kontrola = $this->zkontrolujKolize();
        if ($kontrola['ok']) {
            $vysledek = $this->result($this->pos2);
            $vysledek['kontrola_kolizi'] = $kontrola + ['upraveno' => false, 'postup' => []];
            return $vysledek;
        }

        $puvodniVysledek = $this->result($this->pos2);
        $puvodniPocet = $this->pocetInstanci1;
        $puvodniKonflikty = $kontrola['konflikty'];
        $nejlepsi = null;
        $nejlepsiVynechano = 0;

        foreach ($this->bezpecnejsiVarianty($nastaveniUzivatele) as $varianta) {
            $this->options = $varianta['options'];
            $this->hlavaPresCelouSirku = $varianta['hlava_pres_celou_sirku'];
            $this->vypocitejRozlozeni($objekty);
            $postup = $varianta['popis'];
            $postup = array_merge($postup, $this->opravKolizePosunemNeboVynechanim());
            $kontrola = $this->zkontrolujKolize();
            if (!$kontrola['ok']) continue;

            // Lepší = víc instancí; při shodě ta, která nemusela kusy vynechávat (jinak dřívější = bližší nastavení uživatele).
            $lepsi = ($nejlepsi === null)
                || ($this->pocetInstanci1 > $nejlepsi['pocet_instanci'])
                || ($this->pocetInstanci1 === $nejlepsi['pocet_instanci'] && $this->pocetVynechanychKusu < $nejlepsiVynechano);
            if ($lepsi) {
                $nejlepsiVynechano = $this->pocetVynechanychKusu;
                $nejlepsi = $this->result($this->pos2);
                $nejlepsi['kontrola_kolizi'] = $kontrola + [
                    'upraveno' => true,
                    'postup' => $postup,
                    'puvodni_konflikty' => $puvodniKonflikty,
                ];
            }
            if ($this->pocetInstanci1 >= $puvodniPocet && $this->pocetVynechanychKusu === 0) break;
        }
        $this->options = $nastaveniUzivatele;
        $this->hlavaPresCelouSirku = false;

        if ($nejlepsi === null) {
            // Pojistka: sem by se výpočet neměl dostat (oprava posunem/vynecháním vždy skončí bez kolize).
            $puvodniVysledek['kontrola_kolizi'] = [
                'ok' => false,
                'pocet_kusu' => $puvodniPocet,
                'konflikty' => $puvodniKonflikty,
                'upraveno' => false,
                'postup' => ['Kolizi se nepodařilo odstranit.'],
            ];
            return $puvodniVysledek;
        }
        return $nejlepsi;
    }

    /** Zkontroluje aktuální výsledek (pozice + rozměry objektů) nezávislou kontrolou kolizí. */
    private function zkontrolujKolize(): array
    {
        $checker = new CollisionChecker($this->printer);
        return $checker->check(CollisionChecker::kusyZVysledku($this->poziceDetail, $this->objekty));
    }

    /**
     * Varianty pro opakovaný výpočet při kolizi (od nejbližší nastavení uživatele):
     * 1) stejné nastavení, 2) stejné nastavení + hlava přes celou šířku instance,
     * 3) navíc bez rozprostření v X, 4) bez rozprostření v X ani Y, 5) bez rozprostření po celé podložce.
     *
     * @return array [['options' => [...], 'hlava_pres_celou_sirku' => bool, 'popis' => string[]], ...]
     */
    private function bezpecnejsiVarianty(array $nastaveni): array
    {
        $hlava = 'Posun řad počítán s hlavou přes celou šířku kusu.';
        $kroky = [
            ['zmena' => [], 'hlava' => false, 'popis' => []],
            ['zmena' => [], 'hlava' => true, 'popis' => [$hlava]],
            ['zmena' => ['rozprostrit_instance_v_ose_x' => false], 'hlava' => true, 'popis' => [$hlava, 'Vypnuto rozprostření v ose X.']],
            ['zmena' => ['rozprostrit_instance_v_ose_x' => false, 'rozprostrit_instance_v_ose_y' => false], 'hlava' => true, 'popis' => [$hlava, 'Vypnuto rozprostření v osách X a Y.']],
            ['zmena' => ['rozprostrit_instance_v_ose_x' => false, 'rozprostrit_instance_v_ose_y' => false, 'rozprostrit_instance_po_cele_podlozce' => false], 'hlava' => true, 'popis' => [$hlava, 'Vypnuto rozprostření v osách X a Y i po celé podložce.']],
        ];
        $varianty = [];
        $videne = [];
        foreach ($kroky as $krok) {
            $options = array_merge($nastaveni, $krok['zmena']);
            $klic = json_encode([$options, $krok['hlava']]);
            if (isset($videne[$klic])) continue;
            $videne[$klic] = true;
            $popis = $krok['popis'];
            if ($options === $nastaveni) {
                // Popis vypnutí rozprostření jen u skutečné změny proti nastavení uživatele.
                $popis = $krok['hlava'] ? [$hlava] : [];
            }
            $varianty[] = ['options' => $options, 'hlava_pres_celou_sirku' => $krok['hlava'], 'popis' => $popis];
        }
        return $varianty;
    }

    /**
     * Projde kusy v pořadí tisku a každý, který koliduje s dříve vytištěným kusem, posune
     * ve směru tisku v ose Y o potřebnou vzdálenost (CollisionChecker::posunProVyreseni).
     * Kus, který by po posunu přečníval z podložky, vynechá. Přepočítá pozice, počty i řady.
     *
     * @return array Popis provedených změn (pro výstup kontrola_kolizi.postup).
     */
    private function opravKolizePosunemNeboVynechanim(): array
    {
        $this->pocetVynechanychKusu = 0;
        $this->vynechaneKusyObjektu = [];
        if (empty($this->pos2[1])) return [];

        $checker = new CollisionChecker($this->printer);
        $znamenko = ($this->printer['smer_Y'] === 'zepredu_dozadu') ? 1.0 : -1.0;
        $ponechane = [];
        $noveRady = [];
        $postup = [];
        $poradi = 0;

        foreach ($this->pos2[1] as $yIdx => $rada) {
            foreach ($rada as $inst) {
                $poradi++;
                $kus = [
                    'poradi' => $poradi,
                    'objekt' => $inst['o'] - 1,
                    'instance' => $inst['i'],
                    'X' => $inst['X'],
                    'Y' => $inst['Y'],
                    'x' => $inst['x'],
                    'y' => $inst['y'],
                    'z' => $inst['z'],
                ];
                $nazev = 'objekt ' . $inst['o'] . ', instance ' . $inst['i'];

                $posunCelkem = 0.0;
                $vyreseno = false;
                for ($pokus = 0; $pokus < self::MAXIMALNI_POCET_ITERACI; $pokus++) {
                    $posun = 0.0;
                    foreach ($ponechane as $drivejsi) {
                        $posun = max($posun, $checker->posunProVyreseni($kus, $drivejsi));
                    }
                    if ($posun <= CollisionChecker::TOLERANCE) {
                        $vyreseno = true;
                        break;
                    }
                    $kus['Y'] += $znamenko * $posun;
                    $posunCelkem += $posun;
                    if ($checker->presahMimoPodlozku($kus) > CollisionChecker::TOLERANCE) break;
                }

                if (!$vyreseno || $checker->presahMimoPodlozku($kus) > CollisionChecker::TOLERANCE) {
                    $postup[] = 'Vynechán kus (' . $nazev . '): po posunu mimo kolizi by se nevešel na podložku.';
                    $this->pocetVynechanychKusu++;
                    $idObjektu = $inst['o'] - 1;
                    $this->vynechaneKusyObjektu[$idObjektu] = ($this->vynechaneKusyObjektu[$idObjektu] ?? 0) + 1;
                    continue;
                }
                if ($posunCelkem > 0.0) {
                    $postup[] = 'Kus (' . $nazev . ') posunut v ose Y o ' . round($posunCelkem, 2) . ' mm.';
                }
                $inst['Y'] = $kus['Y'];
                $noveRady[$yIdx][] = $inst;
                $ponechane[] = $kus;
            }
        }

        if (empty($postup)) return [];
        $this->prestavVysledek($noveRady);
        return $postup;
    }

    /**
     * Po opravě kolizí znovu sestaví pos2 (řady i instance číslované od 1), pozice, počty a zbývající místo.
     *
     * @param array $noveRady [puvodniIndexRady => [instance, ...]] v pořadí tisku
     */
    private function prestavVysledek(array $noveRady): void
    {
        $this->datovaVetaPole = $this->poziceDetail = $this->XcountPole = [];
        $zbyvaVOseX = [];
        $pos = [];
        $novyIdx = 0;
        foreach ($noveRady as $staryIdx => $rada) {
            $novyIdx++;
            $x = 0;
            foreach ($rada as $inst) {
                $pos[$novyIdx][++$x] = $inst;
            }
            $this->XcountPole[$novyIdx] = $x;
            if (isset($this->zbyvaVOseX[$staryIdx])) $zbyvaVOseX[$novyIdx] = $this->zbyvaVOseX[$staryIdx];
        }
        $this->pos2 = empty($pos) ? [] : [1 => $pos];
        $this->zbyvaVOseX = $zbyvaVOseX;

        $this->nastavPoctyInstanciZPozic();
        $this->pocetInstanci1 = 0;
        $nejvzdalenejsiHrana = 0.0;
        foreach ($pos as $yIdx => $rada) {
            foreach ($rada as $xIdx => $inst) {
                $this->pocetInstanci1++;
                $hrana = $this->hranaOdStartuY($inst);
                if ($hrana > $nejvzdalenejsiHrana) $nejvzdalenejsiHrana = $hrana;
                $this->pridejPozici($yIdx, $xIdx);
            }
        }
        $this->pocetRad1 = count($pos);
        $this->Xcount1 = isset($this->XcountPole[1]) ? $this->XcountPole[1] : 0;
        $this->zbyvaVOseY = empty($pos) ? 0.0 : max(0.0, $this->printer['y'] - $nejvzdalenejsiHrana);
    }

    /**
     * Vlastní výpočet rozložení (bez kontroly kolizí); výsledek zůstane ve stavu instance.
     *
     * @param array $objekty Input objekty ve tvaru jako z GET (včetně instances[d]).
     */
    private function vypocitejRozlozeni(array $objekty): void
    {
        $this->resetState();

        $this->objekty = $this->sanitizeObjects($objekty);
        $this->objektySerazene = $this->sortObjectsStableByZyx($this->objekty);

        if (empty($this->objektySerazene)) {
            return;
        }

        // Směr tisku je globální – odvoď ho podle profilu hlavy pro nejvyšší objekt v zadání.
        $maxH = 0.0;
        foreach ($this->objektySerazene as $o) {
            if ($o['z'] > $maxH) $maxH = $o['z'];
        }
        $headForMax = $this->getHeadForHeight($maxH);
        $this->printer['smer_X'] = ($headForMax['Xl'] <= $headForMax['Xr'] ? 'zleva_doprava' : 'zprava_doleva');
        $this->printer['smer_Y'] = ($headForMax['Yl'] <= $headForMax['Yr'] ? 'zepredu_dozadu' : 'zezadu_dopredu');

        // Inicializační výpočet (nastaví instances[r] v $this->objekty a pozice)
        $this->vypocitejPoziciInstanci();

        $idPrvnihoObjektu = $this->firstKey($this->objekty);
        $bylyUmistenyVsechnyPozadovaneInstanceVsechObjektu = true;
        $bylyUmistenyVsechnyPozadovaneNemaximalniInstancePrvnihoObjektu = true;
        $bylyUmistenyVsechnyPozadovaneInstanceObjektu = [];
        $this->bylaUmistenaAlesponJednaInstanceObjektu = [];

        foreach ($this->objekty as $id => $objekt) {
            $d = isset($objekt['instances']['d']) ? $objekt['instances']['d'] : 1;
            $r = isset($objekt['instances']['r']) ? $objekt['instances']['r'] : 0;
            if ($r < $d) {
                $bylyUmistenyVsechnyPozadovaneInstanceVsechObjektu = false;
                if ($id === $idPrvnihoObjektu && $d < self::MAXIMALNI_POCET_INSTANCI) {
                    $bylyUmistenyVsechnyPozadovaneNemaximalniInstancePrvnihoObjektu = false;
                }
                $bylyUmistenyVsechnyPozadovaneInstanceObjektu[$id] = false;
                if ($r === 0) $this->bylaUmistenaAlesponJednaInstanceObjektu[$id] = false;
            }
        }

        // 1) Když se nevejde přesný počet prvního objektu, maximalizuj první a zkoušej přidávat další.
        if (!$bylyUmistenyVsechnyPozadovaneNemaximalniInstancePrvnihoObjektu) {
            $this->kromeTechtoObjektu = [$idPrvnihoObjektu];
            $this->prednostPrvnihoObjektu = true;
            $this->prepocitejSUpravouPozadovanehoPoctuInstanciObjektu(0, $this->kromeTechtoObjektu);
            $maximalniMoznyPocetInstanciPrvnihoObjektu = $this->getObjektR($idPrvnihoObjektu);
            if ($maximalniMoznyPocetInstanciPrvnihoObjektu > 0) {
                for ($i = 1; $i <= self::MAXIMALNI_POCET_ITERACI; $i++) {
                    $this->prepocitejSUpravouPozadovanehoPoctuInstanciObjektu($i, $this->kromeTechtoObjektu);
                    if ($this->getObjektR($idPrvnihoObjektu) < $maximalniMoznyPocetInstanciPrvnihoObjektu) {
                        $this->prepocitejSUpravouPozadovanehoPoctuInstanciObjektu(($i - 1), $this->kromeTechtoObjektu);
                        break;
                    }
                }
            }
        }
        // 2) Jinak – poměrné rozdělení (pokud se něco nevešlo).
        elseif (!empty($this->bylaUmistenaAlesponJednaInstanceObjektu)) {
            $this->vypocitejPrumernyPocetInstanci();
            $this->nastavPomernyPocetVyslednychInstanci();
            $this->nastavPoleKromeTechtoObjektu();
            $this->prepocitejSUpravouPozadovanehoPoctuInstanciObjektu(0, $this->kromeTechtoObjektu, true);

            for ($i = 1; $i <= self::MAXIMALNI_POCET_ITERACI; $i++) {
                $this->vypocitejPrumernyPocetInstanci();
                $this->nastavPomernyPocetVyslednychInstanci();

                $dosahujeKazdyObjektPomernehoPoctuVyslednychInstanci = true;
                $objektJizPresahujePomernyPocetVyslednychInstanci = false;

                foreach ($this->bylaUmistenaAlesponJednaInstanceObjektu as $id => $_) {
                    $r = $this->getObjektR($id);
                    $target = isset($this->pomernyPocetVyslednychInstanci[$id]) ? $this->pomernyPocetVyslednychInstanci[$id] : 0;
                    if ($r < $target) {
                        $dosahujeKazdyObjektPomernehoPoctuVyslednychInstanci = false;
                        break;
                    } elseif ($r > $target) {
                        $objektJizPresahujePomernyPocetVyslednychInstanci = true;
                        break;
                    }
                }

                if ($dosahujeKazdyObjektPomernehoPoctuVyslednychInstanci) {
                    if ($objektJizPresahujePomernyPocetVyslednychInstanci) {
                        $this->prepocitejSUpravouPozadovanehoPoctuInstanciObjektu(0, $this->kromeTechtoObjektu, true);
                    }
                    break;
                }

                $this->prepocitejSUpravouPozadovanehoPoctuInstanciObjektu($i, $this->kromeTechtoObjektu, true);
            }
        }

        // Rozprostření po celé podložce
        if ($this->options['rozprostrit_instance_po_cele_podlozce']) {
            $pocetInstanciZaloha = $this->pocetInstanci1;
            for ($i = $this->pocetRad1; $i <= self::MAXIMALNI_POCET_ITERACI; $i++) {
                $this->prepocitejSRozprostrenimPoCeleTiskovePodlozce($pocetInstanciZaloha, $i);
                if ($this->pocetInstanci1 < $pocetInstanciZaloha) {
                    $this->prepocitejSRozprostrenimPoCeleTiskovePodlozce($pocetInstanciZaloha, ($i - 1));
                    break;
                }
            }
        }

        // instances[r] se během iterací nenuluje (objekt, z něhož se v posledním průchodu nic nevešlo,
        // by si nechal starý počet) - pro výstup ho přepočítej z výsledných pozic.
        $this->nastavPoctyInstanciZPozic();
    }

    /** Nastaví instances[r] všech objektů podle výsledných pozic (pos2). */
    private function nastavPoctyInstanciZPozic(): void
    {
        foreach ($this->objekty as $id => $_) {
            $this->objekty[$id]['instances']['r'] = 0;
        }
        if (empty($this->pos2[1])) return;
        foreach ($this->pos2[1] as $rada) {
            foreach ($rada as $inst) {
                $id = $inst['o'] - 1;
                if (isset($this->objekty[$id])) $this->objekty[$id]['instances']['r']++;
            }
        }
    }

    private function resetState(): void
    {
        $this->pos1 = $this->pos2 = $this->zbyvaVOseX = $this->datovaVetaPole = $this->poziceDetail = $this->pocetInstanciObjektu = $this->XcountPole = [];
        $this->posunX1 = $this->posunY1 = 0.0;
        $this->pocetInstanci1 = $this->pocetRad1 = 0;
        $this->poziceHranyPrvnihoObjektuVRade = $this->poziceHranyNejvzdalenejsihoObjektu = 0.0;
        $this->zbyvaVOseXMaximalne = $this->zbyvaVOseY = 0.0;
        $this->pocetPodlozek1 = 1;
        $this->Xcount1 = 0;
        $this->omezenyPocetInstanciVRade = 0;
        $this->pomernyPocetVyslednychInstanci = [];
        $this->bylaUmistenaAlesponJednaInstanceObjektu = [];
        $this->kromeTechtoObjektu = [];
        $this->vynechaneKusyObjektu = [];
        $this->zastaveni = null;
        $this->prednostPrvnihoObjektu = false;
    }

    private function normalizePrinter(array $printer): array
    {
        // Chybějící hodnoty doplní výchozí tiskárna (vychoziTiskarna() = předvolba Prusa MINI).
        $printer = array_merge(self::vychoziTiskarna(), array_filter($printer, function ($v) { return $v !== null; }));
        $x = (float)$printer['x'];
        $y = (float)$printer['y'];
        $z = (float)$printer['z'];
        $posunZprava = (float)$printer['posun_zprava'];

        // Head clearances (od trysky k hranám)
        $Xr = (float)$printer['Xr'];
        $Xl = (float)$printer['Xl'];
        $Yr = (float)$printer['Yr'];
        $Yl = (float)$printer['Yl'];
        $vodiciTyceZ = (float)$printer['vodici_tyce_Z'];
        $vodiciTyceY = (float)$printer['vodici_tyce_Y'];

        // Zohlednění korekce zprava
        $xEff = $x - $posunZprava;

        // Volitelné "schody" profilu hlavy. Formát:
        // [ ["z"=>0,"xl"=>...,"xr"=>...,"yl"=>...,"yr"=>...], ... ]
        $headSteps = [];
        if (isset($printer['head_steps']) && is_array($printer['head_steps'])) {
            foreach ($printer['head_steps'] as $step) {
                if (!is_array($step)) continue;
                $headSteps[] = [
                    'z' => $this->toFloat(isset($step['z']) ? $step['z'] : (isset($step['z_mm']) ? $step['z_mm'] : 0)),
                    'Xl' => $this->toFloat(isset($step['xl']) ? $step['xl'] : (isset($step['xl_mm']) ? $step['xl_mm'] : (isset($step['Xl']) ? $step['Xl'] : 0))),
                    'Xr' => $this->toFloat(isset($step['xr']) ? $step['xr'] : (isset($step['xr_mm']) ? $step['xr_mm'] : (isset($step['Xr']) ? $step['Xr'] : 0))),
                    'Yl' => $this->toFloat(isset($step['yl']) ? $step['yl'] : (isset($step['yl_mm']) ? $step['yl_mm'] : (isset($step['Yl']) ? $step['Yl'] : 0))),
                    'Yr' => $this->toFloat(isset($step['yr']) ? $step['yr'] : (isset($step['yr_mm']) ? $step['yr_mm'] : (isset($step['Yr']) ? $step['Yr'] : 0))),
                ];
            }
        }
        usort($headSteps, function ($a, $b) {
            if ($a['z'] < $b['z']) return -1;
            if ($a['z'] > $b['z']) return 1;
            return 0;
        });

        // Výchozí směr se může přepsat později podle max výšky v zadání.
        $smerX = ($Xl <= $Xr ? 'zleva_doprava' : 'zprava_doleva');
        $smerY = ($Yl <= $Yr ? 'zepredu_dozadu' : 'zezadu_dopredu');

        return [
            'x' => $xEff,
            'y' => $y,
            'z' => $z,
            'posun_zprava' => $posunZprava,
            'Xr' => $Xr,
            'Xl' => $Xl,
            'Yr' => $Yr,
            'Yl' => $Yl,
            'head_steps' => $headSteps,
            'vodici_tyce_Z' => $vodiciTyceZ,
            'vodici_tyce_Y' => $vodiciTyceY,
            'smer_X' => $smerX,
            'smer_Y' => $smerY,
        ];
    }

    private function normalizeOptions(array $options): array
    {
        $out = [];
        foreach (self::VYCHOZI_NASTAVENI as $klic => $vychozi) {
            if ($klic === 'rozprostreni_y_rezim') {
                // Neznámá hodnota = výchozí režim (kompatibilita).
                $rezim = isset($options[$klic]) ? $options[$klic] : $vychozi;
                $out[$klic] = in_array($rezim, self::ROZPROSTRENI_Y_REZIMY, true) ? $rezim : $vychozi;
                continue;
            }
            if ($klic === 'max_podlozek') {
                // Celé číslo 1..MAXIMALNI_POCET_PODLOZEK; neplatná hodnota = výchozí strop.
                $max = isset($options[$klic]) && is_numeric($options[$klic]) ? (int)$options[$klic] : $vychozi;
                $out[$klic] = max(1, min(self::MAXIMALNI_POCET_PODLOZEK, $max));
                continue;
            }
            $out[$klic] = isset($options[$klic]) ? (bool)$options[$klic] : $vychozi;
        }
        return $out;
    }

    private function sanitizeObjects(array $objekty): array
    {
        $out = [];
        $this->vyrazeneObjekty = [];
        $this->pozadovanoMax = [];
        foreach ($objekty as $id => $objekt) {
            if (!is_array($objekt)) continue;
            $nazev = self::upravNazev(isset($objekt['nazev']) ? $objekt['nazev'] : null);
            $x =$this->toFloat(isset($objekt['x']) ? $objekt['x'] : null);
            $y = $this->toFloat(isset($objekt['y']) ? $objekt['y'] : null);
            $z = $this->toFloat(isset($objekt['z']) ? $objekt['z'] : null);

            // Normalizace: max 2 desetinná místa.
            // (Bez umělého navyšování typu +0.01 – to se uživateli plete ve formuláři.)
            $x = round($x, 2);
            $y = round($y, 2);
            $z = round($z, 2);

            // clamp valid
            if ($x <= 0 || $y <= 0 || $z <= 0) continue;

            $instancesD = 1;
            $jeMax = false;
            if (isset($objekt['instances']) && is_array($objekt['instances']) && array_key_exists('d', $objekt['instances'])) {
                $instancesD = $objekt['instances']['d'];
                if ($instancesD === 'max') {
                    $jeMax = true;
                    $instancesD = self::MAXIMALNI_POCET_INSTANCI;
                }
                $instancesD = (int)$instancesD;
                if ($instancesD < 1) $instancesD = 1;
                if ($instancesD > self::MAXIMALNI_POCET_INSTANCI) $instancesD = self::MAXIMALNI_POCET_INSTANCI;
            }

            // Větší než tisková plocha: objekt se nepočítá, ale důvod se objeví v `limit`.
            foreach (['x' => $x, 'y' => $y, 'z' => $z] as $osa => $rozmer) {
                if ($rozmer > $this->printer[$osa]) {
                    $this->vyrazeneObjekty[$id] = [
                        'pozadovano' => $jeMax ? 'max' : $instancesD,
                        'osa' => $osa,
                        'rozmer' => $rozmer,
                        'limit' => round($this->printer[$osa], 2),
                        'nazev' => $nazev,
                    ];
                    continue 2;
                }
            }
            if ($jeMax) $this->pozadovanoMax[$id] = true;

            $out[$id] = [
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'instances' => [
                    'd' => $instancesD,
                    'r' => 0,
                ],
            ];
            if ($nazev !== '') $out[$id]['nazev'] = $nazev;
            if (self::jePovolenaRotace($objekt['rotace'] ?? null)) $out[$id]['rotace'] = true;
            // Objekt otočený o 90° výpočtem (x a y už jsou zaměněné), viz calculate().
            if (!empty($objekt['otoceno'])) $out[$id]['otoceno'] = true;
        }
        return $out;
    }

    /**
     * Volitelný název objektu: text bez řídicích znaků, oříznutý, max MAXIMALNI_DELKA_NAZVU znaků.
     * Neplatné UTF-8 nebo jiný typ než text/číslo = bez názvu. Escapování je až na výstupu (HTML).
     */
    public static function upravNazev($nazev): string
    {
        if (is_int($nazev) || is_float($nazev)) $nazev = (string)$nazev;
        if (!is_string($nazev) || !preg_match('//u', $nazev)) return '';
        $nazev = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $nazev));
        if (mb_strlen($nazev, 'UTF-8') > self::MAXIMALNI_DELKA_NAZVU) {
            $nazev = rtrim(mb_substr($nazev, 0, self::MAXIMALNI_DELKA_NAZVU, 'UTF-8'));
        }
        return $nazev;
    }

    private function sortObjectsStableByZyx(array $objekty): array
    {
        $items = [];
        foreach ($objekty as $id => $o) {
            $items[] = ['id' => $id, 'o' => $o];
        }

        usort($items, function ($a, $b) {
            $az = $a['o']['z']; $bz = $b['o']['z'];
            if ($az < $bz) return -1;
            if ($az > $bz) return 1;
            $ay = $a['o']['y']; $by = $b['o']['y'];
            if ($ay < $by) return -1;
            if ($ay > $by) return 1;
            $ax = $a['o']['x']; $bx = $b['o']['x'];
            if ($ax < $bx) return -1;
            if ($ax > $bx) return 1;
            return 0;
        });

        $sorted = [];
        foreach ($items as $it) {
            $sorted[$it['id']] = $it['o'];
        }
        return $sorted;
    }

    private function nastavZbyvaVOseY(): void
    {
        $this->zbyvaVOseY = ($this->printer['y'] - $this->poziceHranyNejvzdalenejsihoObjektu);
    }

    private function vypocitejPoziciInstanci(): void
    {
        $this->pos1 = $this->pos2 = $this->zbyvaVOseX = $this->datovaVetaPole = $this->poziceDetail = $this->pocetInstanciObjektu = $this->XcountPole = [];
        $this->posunX1 = $this->posunY1 = 0.0;
        $this->pocetInstanci1 = $this->pocetRad1 = 0;
        $this->poziceHranyPrvnihoObjektuVRade = $this->poziceHranyNejvzdalenejsihoObjektu = 0.0;
        $this->zbyvaVOseXMaximalne = $this->zbyvaVOseY = 0.0;
        $this->pocetPodlozek1 = 1;
        $this->Xcount1 = 0;
        $this->zastaveni = null;

        $p1 = 1;
        $x1 = 1;
        $y1 = 1;

        foreach ($this->objektySerazene as $id => $objekt) {
            $i1 = 0;
            $i2 = 0;
            $o1 = (int)$id + 1;

            $ox = $objekt['x'];
            $oy = $objekt['y'];
            $oz = $objekt['z'];
            $head = $this->getHeadForHeight($oz);
            $pozadovanyPocetInstanci = isset($objekt['instances']['d']) ? (int)$objekt['instances']['d'] : 1;
            $this->pocetInstanciObjektu[$o1] = 0;

            for ($ci = 1; $ci <= $pozadovanyPocetInstanci; $ci++) {
                $i1 = $i2;
                $i1++;

                // mezera v ose X závisí na směru tisku + profilu hlavy pro výšku objektu
                $XMezeraMeziInstancemi = ($this->printer['smer_X'] === 'zleva_doprava' ? $head['Xl'] : $head['Xr']);

                $zacalNovouRadu = false;
                $posunHlavou = false; // posun kvůli hlavě (pro důvod limitu)
                $posunTycemi = false; // posun kvůli vodicím tyčím (pro důvod limitu)
                // X
                if ($this->printer['smer_X'] === 'zleva_doprava') {
                    $presahuje = (($ox + $this->posunX1) > $this->printer['x']);
                    if ($presahuje || ($this->omezenyPocetInstanciVRade && $x1 > $this->omezenyPocetInstanciVRade)) {
                        $y1++;
                        $x1 = 1;
                        $this->posunX1 = 0.0;
                        $this->posunY1 = (($this->poziceHranyNejvzdalenejsihoObjektu - $this->printer['vodici_tyce_Y']) > ($this->poziceHranyPrvnihoObjektuVRade + $head['Yr'])
                            ? ($this->poziceHranyNejvzdalenejsihoObjektu - $this->printer['vodici_tyce_Y'])
                            : ($this->poziceHranyPrvnihoObjektuVRade + $head['Yr']));
                        $zacalNovouRadu = true;
                    }
                    $ix = ($ox / 2.0) + $this->posunX1;
                } else {
                    $presahuje = (($this->printer['x'] - $ox - $this->posunX1) < 0);
                    if ($presahuje || ($this->omezenyPocetInstanciVRade && $x1 > $this->omezenyPocetInstanciVRade)) {
                        $y1++;
                        $x1 = 1;
                        $this->posunX1 = 0.0;
                        $this->posunY1 = (($this->poziceHranyNejvzdalenejsihoObjektu - $this->printer['vodici_tyce_Y']) > ($this->poziceHranyPrvnihoObjektuVRade + $head['Yr'])
                            ? ($this->poziceHranyNejvzdalenejsihoObjektu - $this->printer['vodici_tyce_Y'])
                            : ($this->poziceHranyPrvnihoObjektuVRade + $head['Yr']));
                        $zacalNovouRadu = true;

                        // TODO sjednotit / zpřesnit kolizní logiku – převzato z původního skriptu (omezujeme pouze pokud existuje 2. objekt v předchozí řadě)
                        if (isset($this->pos1[$p1][($y1 - 1)][2])) {
                            $pozicePraveHranyXDruhehoObjektu = $this->pos1[$p1][($y1 - 1)][2]['X'] + ($this->pos1[$p1][($y1 - 1)][2]['x'] / 2.0);
                            $poziceLeveHranyTohotoObjektu = $this->printer['x'] - $ox - $this->posunX1;
                            if ($pozicePraveHranyXDruhehoObjektu < ($poziceLeveHranyTohotoObjektu - $head['Xl'])) {
                                $this->posunY1 = ($this->poziceHranyNejvzdalenejsihoObjektu + $head['Yr']);
                            }
                        }
                    }
                    $ix = $this->printer['x'] - ($ox / 2.0) - $this->posunX1;
                }

                // Kolize hlavy s předchozí řadou může nastat i u 2. a dalších objektů v řadě:
                // pro aktuální X dopočti minimální posun Y tak, aby obdélník hlavy nezasahoval do předchozí řady.
                if ($y1 > 1 && isset($this->pos1[$p1][($y1 - 1)]) && is_array($this->pos1[$p1][($y1 - 1)])) {
                    $requiredPosunY = $this->minPosunYForHeadClearance(
                        $this->pos1[$p1][($y1 - 1)],
                        $ix,
                        $ox,
                        $oy,
                        $head
                    );
                    if ($requiredPosunY > $this->posunY1) {
                        $delta = $requiredPosunY - $this->posunY1;
                        // Když by posun řady vystrčil už umístěné instance z podložky, další instance se nevejde.
                        foreach ($this->pos1[$p1][$y1] ?? [] as $umistena) {
                            if (($this->hranaOdStartuY($umistena) + $delta) > $this->printer['y']) {
                                $this->nastavZbyvaVOseY();
                                $this->zastaveni = ['objekt' => $id, 'duvod' => 'hlava'];
                                break 3;
                            }
                        }
                        $this->posunY1 = $requiredPosunY;
                        $posunHlavou = true;
                        // posuň už umístěné instance v aktuální řadě, aby zůstaly konzistentní
                        $this->shiftCurrentRowY($p1, $y1, $delta);
                        // posuň i evidované hrany řady
                        $this->poziceHranyPrvnihoObjektuVRade += $delta;
                        $this->poziceHranyNejvzdalenejsihoObjektu += $delta;
                    }
                }

                // Střídání stran: když je v řadě jen 1 objekt, sudé řady začnu z opačné strany.
                if ($this->omezenyPocetInstanciVRade == 1 && $x1 == 1 && ($y1 % 2 == 0)) {
                    $ix = ($this->printer['smer_X'] === 'zleva_doprava' ? ($this->printer['x'] - ($ox / 2.0)) : ($ox / 2.0));
                }

                // Y: vodicí tyče nesmí při tisku další instance v řadě přejet přes předchozí vysokou instanci
                // (platí i když je předchozí instance z jiného objektu).
                if ($x1 > 1
                    && isset($this->pos1[$p1][$y1][($x1 - 1)])
                    && $this->pos1[$p1][$y1][($x1 - 1)]['z'] > $this->printer['vodici_tyce_Z']
                    && $this->pos1[$p1][$y1][($x1 - 1)]['y'] > $this->printer['vodici_tyce_Y']) {
                    $this->posunY1 += ($this->pos1[$p1][$y1][($x1 - 1)]['y'] - $this->printer['vodici_tyce_Y']);
                    $posunTycemi = true;
                }

                if ($this->printer['smer_Y'] === 'zepredu_dozadu') $iy = ($oy / 2.0) + $this->posunY1;
                else $iy = $this->printer['y'] - ($oy / 2.0) - $this->posunY1;

                // Vzdálenost vzdálenější hrany instance od startu směru tisku v ose Y (vpředu, resp. vzadu).
                // Pro tisk zepředu je to zadní hrana (iy + oy/2), pro tisk zezadu vzdálenost přední hrany od zadního okraje.
                $hranaOdStartu = $this->posunY1 + $oy;

                if ($hranaOdStartu > $this->printer['y']) {
                    $this->nastavZbyvaVOseY();
                    $this->zastaveni = ['objekt' => $id, 'duvod' => $posunTycemi ? 'tyce' : ($posunHlavou ? 'hlava' : 'misto_y')];
                    break 2;
                }

                $i2++;
                $this->pocetInstanci1++;
                if ($y1 === 1) $this->Xcount1 = $this->pocetInstanci1;

                $this->pos1[$p1][$y1][$x1] = [
                    'o' => $o1,
                    'i' => $i2,
                    'X' => $ix,
                    'Y' => $iy,
                    'x' => $ox,
                    'y' => $oy,
                    'z' => $oz,
                ];

                if (!isset($this->XcountPole[$y1])) $this->XcountPole[$y1] = 0;
                $this->XcountPole[$y1]++;
                if ($x1 === 1) $this->pocetRad1++;

                $this->posunX1 += ($ox + $XMezeraMeziInstancemi);
                $this->zbyvaVOseX[$y1] = ($this->printer['x'] - ($this->posunX1 - $XMezeraMeziInstancemi));
                if ($this->zbyvaVOseX[$y1] > $this->zbyvaVOseXMaximalne) $this->zbyvaVOseXMaximalne = $this->zbyvaVOseX[$y1];

                if ($x1 === 1) $this->poziceHranyPrvnihoObjektuVRade = $hranaOdStartu;
                if ($this->poziceHranyNejvzdalenejsihoObjektu < $hranaOdStartu) $this->poziceHranyNejvzdalenejsihoObjektu = $hranaOdStartu;
                $this->nastavZbyvaVOseY();

                $this->pocetInstanciObjektu[$o1]++;
                $this->objekty[$id]['instances']['r'] = $this->pocetInstanciObjektu[$o1];
                $x1++;
            }
        }

        $this->pos2 = $this->pos1;

        if ($this->zbyvaVOseXMaximalne || $this->zbyvaVOseY) {
            // Rozprostření v ose Y (požadavek):
            // - 1. instance zůstane na startu směru tisku (vpředu / vzadu)
            // - zbývající místo (zbyvaVOseY) se rovnoměrně rozdělí mezi další instance v pořadí tisku
            // => shift(seq) = (zbyvaVOseY / (N - 1)) * (seq - 1), pro seq > 1
            // Režim po_radach (rozprostreni_y_rezim): celá řada se posune o stejný krok, zbylé místo
            // se rozdělí mezi řady => shift(rada) = (zbyvaVOseY / (R - 1)) * (rada - 1).
            // Mezery mezi řadami jen rostou a v rámci řady se nic nemění, takže kolize nepřibudou.
            $poRadach = ($this->options['rozprostreni_y_rezim'] === self::ROZPROSTRENI_Y_PO_RADACH);
            $pocetRad = isset($this->pos2[1]) ? count($this->pos2[1]) : 0;
            $delitel = $poRadach ? ($pocetRad - 1) : ($this->pocetInstanci1 - 1);
            $stepVOseY = ($this->options['rozprostrit_instance_v_ose_y'] && $this->zbyvaVOseY > 0 && $delitel > 0)
                ? ($this->zbyvaVOseY / $delitel)
                : 0.0;

            $seq = 0;
            foreach ($this->pos2[1] as $yIdx => $rada) {
                $pocetInstanciVRade = count($rada);
                $sudaRada = ($yIdx % 2 === 0);
                $cikCak = ($this->omezenyPocetInstanciVRade && $pocetInstanciVRade === 1 && $sudaRada);
                $pripocitamVOseX = ($this->zbyvaVOseXMaximalne && $pocetInstanciVRade > 1)
                    ? ($this->zbyvaVOseX[$yIdx] / ($pocetInstanciVRade - 1))
                    : 0.0;

                foreach ($rada as $xIdx => $_objekt1) {
                    $seq++;
                    if ($this->options['rozprostrit_instance_v_ose_x'] && ($xIdx > 1 || $cikCak)) {
                        if ($this->printer['smer_X'] === 'zleva_doprava') $this->pos2[1][$yIdx][$xIdx]['X'] += $pripocitamVOseX * ($cikCak ? 1 : ($xIdx - 1));
                        else $this->pos2[1][$yIdx][$xIdx]['X'] -= $pripocitamVOseX * ($cikCak ? 1 : ($xIdx - 1));
                    }
                    $krokuY = $poRadach ? ($yIdx - 1) : ($seq - 1);
                    if ($stepVOseY > 0.0 && $krokuY > 0) {
                        $shift = $stepVOseY * $krokuY;
                        if ($this->printer['smer_Y'] === 'zepredu_dozadu') $this->pos2[1][$yIdx][$xIdx]['Y'] += $shift;
                        else $this->pos2[1][$yIdx][$xIdx]['Y'] -= $shift;
                    }
                    $this->pridejPozici($yIdx, $xIdx);
                }
            }
        } else {
            // i bez rozprostření chci mít JSON výstup
            if (isset($this->pos2[1])) {
                foreach ($this->pos2[1] as $yIdx => $rada) {
                    foreach ($rada as $xIdx => $_objekt1) {
                        $this->pridejPozici($yIdx, $xIdx);
                    }
                }
            }
        }
    }

    /**
     * Ulozi vyslednou pozici instance: do datova_veta_pole (zpetne kompatibilni [i, X, Y])
     * a do pozice (s objektem, poradim tisku a radou, aby integrace vedely, ktery kus je ktery).
     */
    private function pridejPozici(int $yIdx, int $xIdx): void
    {
        $p = $this->pos2[1][$yIdx][$xIdx];
        $X = round($p['X'], 2);
        $Y = round($p['Y'], 2);
        $this->datovaVetaPole[] = [$p['i'], $X, $Y];
        $poradi = count($this->poziceDetail) + 1;
        $this->poziceDetail[] = [
            'poradi' => $poradi,
            // poradi_celkem a podlozka: u více podložek je přečísluje calculate() (poradi je v rámci podložky).
            'poradi_celkem' => $poradi,
            'podlozka' => 1,
            'objekt' => $p['o'] - 1,
            'instance' => $p['i'],
            'rada' => $yIdx,
            'X' => $X,
            'Y' => $Y,
        ];
        $idObjektu = $p['o'] - 1;
        if (isset($this->objekty[$idObjektu]['nazev'])) {
            $this->poziceDetail[count($this->poziceDetail) - 1]['nazev'] = $this->objekty[$idObjektu]['nazev'];
        }
        // Otočený kus je nutné v sliceru otočit o 90° kolem Z (rozměry x/y jsou zaměněné).
        $this->poziceDetail[count($this->poziceDetail) - 1]['otoceno'] = !empty($this->objekty[$idObjektu]['otoceno']);
    }

    private function prepocitejSRozprostrenimPoCeleTiskovePodlozce(int $parPocetInstanci, int $parPocetRad): void
    {
        $this->omezenyPocetInstanciVRade = (int)ceil($parPocetInstanci / $parPocetRad);
        $this->vypocitejPoziciInstanci();
    }

    private function prepocitejSUpravouPozadovanehoPoctuInstanciObjektu(int $parPocetInstanci, array $kromeTechtoObjektu, bool $nastavitPomerny = false): void
    {
        foreach ($this->objektySerazene as $id => $objekt) {
            $nastavovany = $nastavitPomerny
                ? ((isset($this->pomernyPocetVyslednychInstanci[$id]) ? $this->pomernyPocetVyslednychInstanci[$id] : 0) - $parPocetInstanci)
                : $parPocetInstanci;

            if (!in_array($id, $kromeTechtoObjektu, true)) {
                $dOriginal = isset($this->objekty[$id]['instances']['d']) ? (int)$this->objekty[$id]['instances']['d'] : 1;
                $this->objektySerazene[$id]['instances']['d'] = ($dOriginal < $nastavovany ? $dOriginal : (int)$nastavovany);
            }
        }
        $this->vypocitejPoziciInstanci();
    }

    private function vypocitejPrumernyPocetInstanci(): void
    {
        $pocetObjektu = count($this->objekty);
        if ($pocetObjektu <= 0) return;
        $celkemPozadovanych = 0.0;
        $celkemVyslednych = 0.0;
        foreach ($this->objekty as $objekt) {
            $celkemPozadovanych += (float)$objekt['instances']['d'];
            $celkemVyslednych += (float)$objekt['instances']['r'];
        }
        $this->printer['_prumerny_pocet_pozadovanych'] = $celkemPozadovanych / $pocetObjektu;
        $this->printer['_prumerny_pocet_vyslednych'] = $celkemVyslednych / $pocetObjektu;
    }

    private function nastavPomernyPocetVyslednychInstanci(): void
    {
        $prumPoz = isset($this->printer['_prumerny_pocet_pozadovanych']) ? (float)$this->printer['_prumerny_pocet_pozadovanych'] : 0.0;
        $prumVys = isset($this->printer['_prumerny_pocet_vyslednych']) ? (float)$this->printer['_prumerny_pocet_vyslednych'] : 0.0;
        if ($prumPoz <= 0) return;

        foreach ($this->objekty as $id => $objekt) {
            $this->pomernyPocetVyslednychInstanci[$id] = ((float)$objekt['instances']['d'] / $prumPoz) * $prumVys;
        }
    }

    private function nastavPoleKromeTechtoObjektu(): void
    {
        $this->kromeTechtoObjektu = [];
        foreach ($this->bylaUmistenaAlesponJednaInstanceObjektu as $id => $_) {
            $this->kromeTechtoObjektu[] = $id;
        }
    }

    private function getObjektR($id): int
    {
        return isset($this->objekty[$id]['instances']['r']) ? (int)$this->objekty[$id]['instances']['r'] : 0;
    }

    private function firstKey(array $arr)
    {
        foreach ($arr as $k => $_) return $k;
        return null;
    }

    private function toFloat($v): float
    {
        if ($v === null) return 0.0;
        if (is_string($v)) $v = str_replace(',', '.', $v);
        return (float)$v;
    }

    /**
     * Spočítá minimální posun řady v ose Y (posunY1) tak, aby obdélník hlavy (v nejvíc kolizním rohu)
     * nezasahoval do žádného objektu v předchozí řadě.
     *
     * Pozn.: toto je cílená oprava bugů odhalených vizualizací; dále se dá zpřesnit (např. kontrola více objektů v řadě).
     *
     * @param array $prevRow pole instancí z předchozí řady (pos1[p][row])
     * @param float $ix center X aktuální instance
     * @param float $ox šířka aktuální instance
     * @param float $oy hloubka aktuální instance
     * @param array $head profil hlavy (Xl/Xr/Yl/Yr)
     * @return float minimální posunY1
     */
    private function minPosunYForHeadClearance(array $prevRow, float $ix, float $ox, float $oy, array $head): float
    {
        $smerX = $this->printer['smer_X'];
        $smerY = $this->printer['smer_Y'];
        $bedY = (float)$this->printer['y'];

        $leftX = $ix - ($ox / 2.0);
        $rightX = $ix + ($ox / 2.0);
        if ($this->hlavaPresCelouSirku) {
            // Přesný model (stejný jako CollisionChecker): tryska projede celou šířku instance,
            // takže hlava v ose X zasáhne od levé hrany − Xl po pravou hranu + Xr.
            $headXmin = $leftX - (float)$head['Xl'];
            $headXmax = $rightX + (float)$head['Xr'];
        } else {
            // Původní model: tryska v "kolizním" rohu v ose X (při tisku "zprava" je kolizní strana vpravo).
            // Bývá hustší, protože rozprostření v ose X pak kusy stejně rozestoupí; kolize ověří CollisionChecker.
            $nozzleX = ($smerX === 'zleva_doprava') ? $leftX : $rightX;
            $headXmin = $nozzleX - (float)$head['Xl'];
            $headXmax = $nozzleX + (float)$head['Xr'];
        }

        $minPosunY = $this->posunY1;

        foreach ($prevRow as $prev) {
            $prevLeft = $prev['X'] - ($prev['x'] / 2.0);
            $prevRight = $prev['X'] + ($prev['x'] / 2.0);
            $xOverlap = ($headXmax > $prevLeft) && ($headXmin < $prevRight);
            if (!$xOverlap) continue;

            $prevFront = $prev['Y'] - ($prev['y'] / 2.0);
            $prevBack = $prev['Y'] + ($prev['y'] / 2.0);

            if ($smerY === 'zepredu_dozadu') {
                // Nozzle na přední hraně nové řady => posunY1 je přímo yFrontNew.
                $required = $prevBack + (float)$head['Yl'];
                if ($required > $minPosunY) $minPosunY = $required;
            } else {
                // Nozzle na zadní hraně => nozzleY = bedY - posunY1.
                // Chceme, aby hlava (dozadu) nezasáhla do předchozí řady (za námi): nozzleY + Yr <= prevFront
                $requiredPosun = $bedY - ($prevFront - (float)$head['Yr']);
                if ($requiredPosun > $minPosunY) $minPosunY = $requiredPosun;
            }
        }

        return $minPosunY;
    }

    /** Vzdálenost vzdálenější hrany instance (v ose Y) od startu směru tisku. */
    private function hranaOdStartuY(array $inst): float
    {
        if ($this->printer['smer_Y'] === 'zepredu_dozadu') return $inst['Y'] + ($inst['y'] / 2.0);
        return $this->printer['y'] - ($inst['Y'] - ($inst['y'] / 2.0));
    }

    private function shiftCurrentRowY(int $p1, int $y1, float $delta): void
    {
        if ($delta == 0.0) return;
        if (!isset($this->pos1[$p1][$y1]) || !is_array($this->pos1[$p1][$y1])) return;
        foreach ($this->pos1[$p1][$y1] as $xIdx => $inst) {
            // posunY1 roste ve směru tisku: zepředu dozadu = +Y, zezadu dopředu = −Y
            $this->pos1[$p1][$y1][$xIdx]['Y'] = ($this->printer['smer_Y'] === 'zepredu_dozadu') ? ($inst['Y'] + $delta) : ($inst['Y'] - $delta);
        }
    }

    /**
     * Vrátí profil hlavy pro danou výšku objektu.
     * Pravidlo: vezmi nejbližší nižší nebo stejný schod (z <= H).
     */
    private function getHeadForHeight(float $h): array
    {
        $steps = isset($this->printer['head_steps']) && is_array($this->printer['head_steps']) ? $this->printer['head_steps'] : [];
        if (empty($steps)) {
            return [
                'z' => $h,
                'Xl' => $this->printer['Xl'],
                'Xr' => $this->printer['Xr'],
                'Yl' => $this->printer['Yl'],
                'Yr' => $this->printer['Yr'],
            ];
        }

        $best = null;
        foreach ($steps as $s) {
            if ($s['z'] <= $h) $best = $s;
            else break;
        }
        if ($best === null) $best = $steps[0];
        return $best;
    }

    /**
     * Veřejný JSON kontrakt výsledku - stejné klíče vrací api/v1/arrange.php i bin/arrange.php.
     *
     * @param array $vysledek Výstup calculate()
     */
    public static function vystupApi(array $vysledek): array
    {
        return [
            'positions' => $vysledek['datova_veta_pole'],
            'pozice' => $vysledek['pozice'],
            'pocet_instanci' => $vysledek['pocet_instanci'],
            'pocet_podlozek' => $vysledek['pocet_podlozek'],
            'objekty' => $vysledek['objekty'],
            'printer' => $vysledek['printer'],
            'kontrola_kolizi' => isset($vysledek['kontrola_kolizi']) ? $vysledek['kontrola_kolizi'] : null,
            'limit' => isset($vysledek['limit']) ? $vysledek['limit'] : [],
            'otocene_objekty' => isset($vysledek['otocene_objekty']) ? $vysledek['otocene_objekty'] : [],
            'podlozky' => isset($vysledek['podlozky']) ? $vysledek['podlozky'] : [],
        ];
    }

    private function result(array $pos): array
    {
        $XcountMin = !empty($this->XcountPole) ? min($this->XcountPole) : 0;
        $XcountMax = !empty($this->XcountPole) ? max($this->XcountPole) : 0;

        return [
            'printer' => $this->printer,
            'options' => $this->options,
            'objekty' => $this->objekty,
            'objekty_serazene' => $this->objektySerazene,
            'pos' => $pos,
            'datova_veta_pole' => $this->datovaVetaPole,
            'pozice' => $this->poziceDetail,
            'pocet_instanci' => $this->pocetInstanci1,
            'pocet_rad' => $this->pocetRad1,
            'pocet_podlozek' => $this->pocetPodlozek1,
            'Xcount' => $this->Xcount1,
            'Xcount_pole' => $this->XcountPole,
            'Xcount_min' => $XcountMin,
            'Xcount_max' => $XcountMax,
            'zbyva_v_ose_X' => $this->zbyvaVOseX,
            'zbyva_v_ose_Y' => $this->zbyvaVOseY,
            'limit' => $this->vypocitejLimit(),
        ];
    }

    /**
     * Důvod limitu (ROADMAP C1 7.b): u každého objektu, ze kterého se nevešel požadovaný počet
     * instancí, řekne kolik požadováno/umístěno a proč. Objekty s počtem "max" jen když se nevešel
     * ani jeden kus. Pořadí = pořadí objektů ve vstupu.
     *
     * duvod: rozmer | kolize | hlava | tyce | misto_y | sdilene_misto
     *
     * @return array [['objekt', 'nazev'?, 'pozadovano', 'umisteno', 'duvod', 'popis'], ...]
     */
    private function vypocitejLimit(): array
    {
        $popisy = [
            'misto_y' => 'další řada se nevejde v ose Y (řady jsou v ose X plné)',
            'hlava' => 'tisková hlava by zasáhla už vytištěné kusy a po odsunutí by se kus nevešel na podložku',
            'tyce' => 'vodicí tyče by přejely přes vyšší vytištěný kus a po odsunutí by se kus nevešel na podložku',
            'sdilene_misto' => $this->prednostPrvnihoObjektu
                ? 'místo na podložce dostal přednostně objekt ' . $this->cisloObjektu($this->firstKey($this->objekty)) . ' (jeho požadovaný počet se nevešel), na další kusy tohoto objektu už nezbylo'
                : 'zbylé místo na podložce připadlo ostatním objektům (rozdělení podle požadovaných počtů)',
        ];
        $osy = ['x' => 'X', 'y' => 'Y', 'z' => 'Z'];

        // Pořadí ve výpočtu (podle výšky): objekty za tím, u kterého výpočet skončil, už se na řadu nedostaly.
        $poradiVeVypoctu = array_flip(array_keys($this->objektySerazene));
        $zastaveniPoradi = ($this->zastaveni !== null && isset($poradiVeVypoctu[$this->zastaveni['objekt']]))
            ? $poradiVeVypoctu[$this->zastaveni['objekt']] : null;

        $klice = array_keys($this->objekty + $this->vyrazeneObjekty);
        sort($klice);
        $limit = [];
        foreach ($klice as $id) {
            if (isset($this->vyrazeneObjekty[$id])) {
                $v = $this->vyrazeneObjekty[$id];
                $nazev = $v['nazev'];
                $pozadovano = $v['pozadovano'];
                $umisteno = 0;
                $duvod = 'rozmer';
                $popisDuvodu = 'rozměr v ose ' . $osy[$v['osa']] . ' (' . $v['rozmer'] . ' mm) je větší než tisková plocha (' . $v['limit'] . ' mm)';
            } else {
                $o = $this->objekty[$id];
                $d = (int)$o['instances']['d'];
                $umisteno = (int)$o['instances']['r'];
                $jeMax = isset($this->pozadovanoMax[$id]);
                if ($jeMax ? ($umisteno > 0) : ($umisteno >= $d)) continue;
                $nazev = isset($o['nazev']) ? $o['nazev'] : '';
                $pozadovano = $jeMax ? 'max' : $d;

                $vynechano = $this->vynechaneKusyObjektu[$id] ?? 0;
                if ($vynechano > 0) {
                    $duvod = 'kolize';
                    $popisDuvodu = 'kontrola kolizí vynechala ' . $vynechano . ' ' . ($vynechano === 1 ? 'kus' : ($vynechano < 5 ? 'kusy' : 'kusů'))
                        . ' (po posunu mimo kolizi by se ' . ($vynechano === 1 ? 'nevešel' : 'nevešly') . ' na podložku)';
                } elseif ($zastaveniPoradi !== null && $poradiVeVypoctu[$id] >= $zastaveniPoradi) {
                    // U objektu, kde výpočet skončil, i u všech dalších v pořadí platí stejný důvod.
                    $duvod = $this->zastaveni['duvod'];
                    $popisDuvodu = $popisy[$duvod];
                    if ($poradiVeVypoctu[$id] > $zastaveniPoradi) $popisDuvodu .= '; podložku zaplnily dříve tisknuté objekty';
                } else {
                    $duvod = 'sdilene_misto';
                    $popisDuvodu = $popisy[$duvod];
                }
            }

            $oznaceni = 'Objekt ' . $this->cisloObjektu($id) .($nazev !== '' ? ' (' . $nazev . ')' : '')
                . (!empty($this->objekty[$id]['otoceno']) ? ' otočený o 90°' : '');
            $pocty = ($pozadovano === 'max') ? 'nevešel se ani jeden kus' : 'umístěno ' . $umisteno . ' z ' . $pozadovano;
            $polozka = ['objekt' => $id];
            if ($nazev !== '') $polozka['nazev'] = $nazev;
            $limit[] = $polozka + [
                'pozadovano' => $pozadovano,
                'umisteno' => $umisteno,
                'duvod' => $duvod,
                'popis' => $oznaceni . ': ' . $pocty . ' - ' . $popisDuvodu . '.',
            ];
        }
        return $limit;
    }

    /** Číslo objektu pro text (jako ID v UI: klíč ve vstupu + 1). */
    private function cisloObjektu($id): string
    {
        return is_int($id) ? (string)($id + 1) : (string)$id;
    }
}


<?php
/**
 * Sekvenční tisk – výpočet rozložení instancí.
 *
 * Cíl refaktoru: oddělit výpočet od HTML, aby šel jednoduše testovat a volat z API.
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

class SequentialPrintCalculator
{
    public const MAXIMALNI_POCET_INSTANCI = 99;
    public const MAXIMALNI_POCET_ITERACI = 99;

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

    public function __construct(array $printer, array $options = [])
    {
        $this->printer = $this->normalizePrinter($printer);
        $this->options = $this->normalizeOptions($options);
    }

    /**
     * @param array $objekty Input objekty ve tvaru jako z GET (včetně instances[d]).
     * @return array Výsledek výpočtu (pozice, metadata, aktualizované objekty).
     */
    public function calculate(array $objekty): array
    {
        $this->resetState();

        $this->objekty = $this->sanitizeObjects($objekty);
        $this->objektySerazene = $this->sortObjectsStableByZyx($this->objekty);

        if (empty($this->objektySerazene)) {
            return $this->result([]);
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

        // Finální pos
        $pos = $this->pos2;

        return $this->result($pos);
    }

    private function resetState(): void
    {
        $this->pos1 = $this->pos2 = $this->zbyvaVOseX = $this->datovaVetaPole = $this->pocetInstanciObjektu = $this->XcountPole = [];
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
    }

    private function normalizePrinter(array $printer): array
    {
        $x = isset($printer['x']) ? (float)$printer['x'] : 180.0;
        $y = isset($printer['y']) ? (float)$printer['y'] : 180.0;
        $z = isset($printer['z']) ? (float)$printer['z'] : 180.0;
        $posunZprava = isset($printer['posun_zprava']) ? (float)$printer['posun_zprava'] : 1.0;

        // Head clearances (od trysky k hranám) – default z původního skriptu
        $Xr = isset($printer['Xr']) ? (float)$printer['Xr'] : 12.0;
        $Xl = isset($printer['Xl']) ? (float)$printer['Xl'] : 36.5;
        $Yr = isset($printer['Yr']) ? (float)$printer['Yr'] : 15.5;
        $Yl = isset($printer['Yl']) ? (float)$printer['Yl'] : 15.5;
        $vodiciTyceZ = isset($printer['vodici_tyce_Z']) ? (float)$printer['vodici_tyce_Z'] : 21.0;
        $vodiciTyceY = isset($printer['vodici_tyce_Y']) ? (float)$printer['vodici_tyce_Y'] : 17.4;

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
        return [
            'rozprostrit_instance_po_cele_podlozce' => isset($options['rozprostrit_instance_po_cele_podlozce']) ? (bool)$options['rozprostrit_instance_po_cele_podlozce'] : true,
            'rozprostrit_instance_v_ose_x' => isset($options['rozprostrit_instance_v_ose_x']) ? (bool)$options['rozprostrit_instance_v_ose_x'] : true,
            'rozprostrit_instance_v_ose_y' => isset($options['rozprostrit_instance_v_ose_y']) ? (bool)$options['rozprostrit_instance_v_ose_y'] : true,
        ];
    }

    private function sanitizeObjects(array $objekty): array
    {
        $out = [];
        foreach ($objekty as $id => $objekt) {
            if (!is_array($objekt)) continue;
            $x = $this->toFloat(isset($objekt['x']) ? $objekt['x'] : null);
            $y = $this->toFloat(isset($objekt['y']) ? $objekt['y'] : null);
            $z = $this->toFloat(isset($objekt['z']) ? $objekt['z'] : null);

            // Normalizace: max 2 desetinná místa.
            // (Bez umělého navyšování typu +0.01 – to se uživateli plete ve formuláři.)
            $x = round($x, 2);
            $y = round($y, 2);
            $z = round($z, 2);

            // clamp valid
            if ($x <= 0 || $y <= 0 || $z <= 0) continue;
            if ($x > $this->printer['x'] || $y > $this->printer['y'] || $z > $this->printer['z']) continue;

            $instancesD = 1;
            if (isset($objekt['instances']) && is_array($objekt['instances']) && array_key_exists('d', $objekt['instances'])) {
                $instancesD = $objekt['instances']['d'];
                if ($instancesD === 'max') $instancesD = self::MAXIMALNI_POCET_INSTANCI;
                $instancesD = (int)$instancesD;
                if ($instancesD < 1) $instancesD = 1;
                if ($instancesD > self::MAXIMALNI_POCET_INSTANCI) $instancesD = self::MAXIMALNI_POCET_INSTANCI;
            }

            $out[$id] = [
                'x' => $x,
                'y' => $y,
                'z' => $z,
                'instances' => [
                    'd' => $instancesD,
                    'r' => 0,
                ],
            ];
        }
        return $out;
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
        $this->pos1 = $this->pos2 = $this->zbyvaVOseX = $this->datovaVetaPole = $this->pocetInstanciObjektu = $this->XcountPole = [];
        $this->posunX1 = $this->posunY1 = 0.0;
        $this->pocetInstanci1 = $this->pocetRad1 = 0;
        $this->poziceHranyPrvnihoObjektuVRade = $this->poziceHranyNejvzdalenejsihoObjektu = 0.0;
        $this->zbyvaVOseXMaximalne = $this->zbyvaVOseY = 0.0;
        $this->pocetPodlozek1 = 1;
        $this->Xcount1 = 0;

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
                        $this->posunY1 = $requiredPosunY;
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

                // Y
                if ($i1 > 1 && $x1 > 1
                    && isset($this->pos1[$p1][$y1][($x1 - 1)])
                    && $this->pos1[$p1][$y1][($x1 - 1)]['z'] > $this->printer['vodici_tyce_Z']
                    && $this->pos1[$p1][$y1][($x1 - 1)]['y'] > $this->printer['vodici_tyce_Y']) {
                    $this->posunY1 += ($this->pos1[$p1][$y1][($x1 - 1)]['y'] - $this->printer['vodici_tyce_Y']);
                }

                if ($this->printer['smer_Y'] === 'zepredu_dozadu') $iy = ($oy / 2.0) + $this->posunY1;
                else $iy = $this->printer['y'] - ($oy / 2.0) - $this->posunY1;

                if (($iy + ($oy / 2.0)) > $this->printer['y']) {
                    $this->nastavZbyvaVOseY();
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

                if ($x1 === 1) $this->poziceHranyPrvnihoObjektuVRade = ($iy + ($oy / 2.0));
                if ($this->poziceHranyNejvzdalenejsihoObjektu < ($iy + ($oy / 2.0))) $this->poziceHranyNejvzdalenejsihoObjektu = ($iy + ($oy / 2.0));
                $this->nastavZbyvaVOseY();

                $this->pocetInstanciObjektu[$o1]++;
                $this->objekty[$id]['instances']['r'] = $this->pocetInstanciObjektu[$o1];
                $x1++;
            }
        }

        $this->pos2 = $this->pos1;

        if ($this->zbyvaVOseXMaximalne || $this->zbyvaVOseY) {
            // Rozprostření v ose Y:
            // - dříve se "doplňoval" jen rozestup mezi řadami (řada 1 zůstala na místě)
            // - požadavek: posunout rovnoměrně všechny řady (včetně 1.), tj. centrovat v Y
            //   => použijeme zbylé místo jako (pocetRad1 + 1) mezer (před první řadou, mezi řadami, za poslední řadou)
            $gapVOseY = ($this->options['rozprostrit_instance_v_ose_y'] && $this->zbyvaVOseY > 0 && $this->pocetRad1 > 0)
                ? ($this->zbyvaVOseY / ($this->pocetRad1 + 1))
                : 0.0;

            foreach ($this->pos2[1] as $yIdx => $rada) {
                $pocetInstanciVRade = count($rada);
                $sudaRada = ($yIdx % 2 === 0);
                $cikCak = ($this->omezenyPocetInstanciVRade && $pocetInstanciVRade === 1 && $sudaRada);
                $pripocitamVOseX = ($this->zbyvaVOseXMaximalne && $pocetInstanciVRade > 1)
                    ? ($this->zbyvaVOseX[$yIdx] / ($pocetInstanciVRade - 1))
                    : 0.0;

                foreach ($rada as $xIdx => $_objekt1) {
                    if ($this->options['rozprostrit_instance_v_ose_x'] && ($xIdx > 1 || $cikCak)) {
                        if ($this->printer['smer_X'] === 'zleva_doprava') $this->pos2[1][$yIdx][$xIdx]['X'] += $pripocitamVOseX * ($cikCak ? 1 : ($xIdx - 1));
                        else $this->pos2[1][$yIdx][$xIdx]['X'] -= $pripocitamVOseX * ($cikCak ? 1 : ($xIdx - 1));
                    }
                    if ($gapVOseY > 0.0) {
                        // posuň i 1. řadu (yIdx=1 => +gap)
                        $shift = $gapVOseY * $yIdx;
                        if ($this->printer['smer_Y'] === 'zepredu_dozadu') $this->pos2[1][$yIdx][$xIdx]['Y'] += $shift;
                        else $this->pos2[1][$yIdx][$xIdx]['Y'] -= $shift;
                    }
                    $this->datovaVetaPole[] = [
                        $this->pos2[1][$yIdx][$xIdx]['i'],
                        round($this->pos2[1][$yIdx][$xIdx]['X'], 2),
                        round($this->pos2[1][$yIdx][$xIdx]['Y'], 2),
                    ];
                }
            }
        } else {
            // i bez rozprostření chci mít JSON výstup
            if (isset($this->pos2[1])) {
                foreach ($this->pos2[1] as $yIdx => $rada) {
                    foreach ($rada as $xIdx => $_objekt1) {
                        $this->datovaVetaPole[] = [
                            $this->pos2[1][$yIdx][$xIdx]['i'],
                            round($this->pos2[1][$yIdx][$xIdx]['X'], 2),
                            round($this->pos2[1][$yIdx][$xIdx]['Y'], 2),
                        ];
                    }
                }
            }
        }
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

        // Nozzle X je "kolizní" hrana v ose X.
        $leftX = $ix - ($ox / 2.0);
        $rightX = $ix + ($ox / 2.0);
        // Upřesnění: při tisku "zprava" je kolizní strana vpravo.
        $nozzleX = ($smerX === 'zleva_doprava') ? $leftX : $rightX;

        $headXmin = $nozzleX - (float)$head['Xl'];
        $headXmax = $nozzleX + (float)$head['Xr'];

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

    private function shiftCurrentRowY(int $p1, int $y1, float $delta): void
    {
        if ($delta == 0.0) return;
        if (!isset($this->pos1[$p1][$y1]) || !is_array($this->pos1[$p1][$y1])) return;
        foreach ($this->pos1[$p1][$y1] as $xIdx => $inst) {
            $this->pos1[$p1][$y1][$xIdx]['Y'] = $inst['Y'] + $delta;
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
            'pocet_instanci' => $this->pocetInstanci1,
            'pocet_rad' => $this->pocetRad1,
            'pocet_podlozek' => $this->pocetPodlozek1,
            'Xcount' => $this->Xcount1,
            'Xcount_pole' => $this->XcountPole,
            'Xcount_min' => $XcountMin,
            'Xcount_max' => $XcountMax,
            'zbyva_v_ose_X' => $this->zbyvaVOseX,
            'zbyva_v_ose_Y' => $this->zbyvaVOseY,
        ];
    }
}


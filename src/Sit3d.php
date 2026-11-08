<?php
/**
 * Trojúhelníková síť a afinní transformace pro import a export 3MF/STL.
 *
 * Síť je kvůli paměti uložená v binárních řetězcích, ne v polích PHP:
 *   ['vrcholy' => pack('g*') po třech (x, y, z, float32 LE), 'pocet_vrcholu' => int,
 *    'trojuhelniky' => pack('V*') po třech indexech (uint32 LE) nebo null (jen rozměry), 'pocet_trojuhelniku' => int]
 * Pole PHP by u sítě s milionem trojúhelníků potřebovalo stovky MB, řetězec 12 B na vrchol a 12 B na trojúhelník.
 *
 * Transformace je afinní matice ve sloupcovém tvaru (12 čísel):
 *   [a00, a01, a02, a10, a11, a12, a20, a21, a22, t0, t1, t2], x' = a00*x + a01*y + a02*z + t0 atd.
 * 3MF zapisuje matici v řádkovém tvaru (vektor zleva), převod dělají zTransformu3mf() a naTransform3mf().
 *
 * Kompatibilita: PHP 7.4+ (bez Composeru).
 */

class Sit3d
{
    /** Kolik vrcholů se rozbalí najednou (unpack po kusech, aby se nezdvojila paměť). */
    private const DAVKA = 4096;

    /** Jednotková matice. */
    public static function jednotkova(): array
    {
        return [1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0];
    }

    /**
     * Matice z atributu transform v 3MF ("m00 m01 m02 m10 m11 m12 m20 m21 m22 m30 m31 m32", x' = x*m00 + y*m10 + z*m20 + m30).
     * Prázdný atribut = jednotková matice, neplatný = null.
     */
    public static function zTransformu3mf(?string $text): ?array
    {
        if ($text === null || trim($text) === '') return self::jednotkova();
        $m = preg_split('/\s+/', trim($text));
        if (count($m) !== 12) return null;
        foreach ($m as $hodnota) {
            if (!is_numeric($hodnota) || !is_finite((float)$hodnota)) return null;
        }
        $m = array_map('floatval', $m);
        return [$m[0], $m[3], $m[6], $m[1], $m[4], $m[7], $m[2], $m[5], $m[8], $m[9], $m[10], $m[11]];
    }

    /** Matice jako atribut transform v 3MF (opak zTransformu3mf()). */
    public static function naTransform3mf(array $a): string
    {
        $m = [$a[0], $a[3], $a[6], $a[1], $a[4], $a[7], $a[2], $a[5], $a[8], $a[9], $a[10], $a[11]];
        return implode(' ', array_map([self::class, 'cislo'], $m));
    }

    /** Složení: nejdřív $b, pak $a (výsledek = A·B). */
    public static function slozit(array $a, array $b): array
    {
        $c = [];
        for ($r = 0; $r < 3; $r++) {
            for ($s = 0; $s < 3; $s++) {
                $c[$r * 3 + $s] = $a[$r * 3] * $b[$s] + $a[$r * 3 + 1] * $b[3 + $s] + $a[$r * 3 + 2] * $b[6 + $s];
            }
        }
        for ($r = 0; $r < 3; $r++) {
            $c[9 + $r] = $a[$r * 3] * $b[9] + $a[$r * 3 + 1] * $b[10] + $a[$r * 3 + 2] * $b[11] + $a[9 + $r];
        }
        return $c;
    }

    /** Jen lineární část (rotace, měřítko), posun nulový. */
    public static function bezPosunu(array $a): array
    {
        $a[9] = $a[10] = $a[11] = 0.0;
        return $a;
    }

    /** Posun o (x, y, z). */
    public static function posun(float $x, float $y, float $z): array
    {
        return [1.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 1.0, $x, $y, $z];
    }

    /** Rotace o 90° kolem osy Z (proti směru hodinových ručiček při pohledu shora). */
    public static function rotaceZ90(): array
    {
        return [0.0, -1.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0];
    }

    /** Je matice jednotková (s tolerancí)? */
    public static function jeJednotkova(array $a): bool
    {
        foreach (self::jednotkova() as $i => $hodnota) {
            if (abs($a[$i] - $hodnota) > 1e-9) return false;
        }
        return true;
    }

    /** Liší se lineární části dvou matic (s tolerancí)? */
    public static function stejnaLinearniCast(array $a, array $b): bool
    {
        for ($i = 0; $i < 9; $i++) {
            if (abs($a[$i] - $b[$i]) > 1e-6) return false;
        }
        return true;
    }

    /**
     * Ohraničující kvádr vrcholů po transformaci: ['min' => [x, y, z], 'max' => [x, y, z]], prázdná síť = null.
     */
    public static function ohraniceni(string $vrcholy, int $pocet, ?array $matice = null): ?array
    {
        if ($pocet <= 0) return null;
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        $jednotkova = $matice === null || self::jeJednotkova($matice);
        for ($od = 0; $od < $pocet; $od += self::DAVKA) {
            $n = min(self::DAVKA, $pocet - $od);
            $v = array_values(unpack('g' . ($n * 3), $vrcholy, $od * 12));
            for ($i = 0; $i < $n * 3; $i += 3) {
                if ($jednotkova) {
                    $x = $v[$i]; $y = $v[$i + 1]; $z = $v[$i + 2];
                } else {
                    $x = $matice[0] * $v[$i] + $matice[1] * $v[$i + 1] + $matice[2] * $v[$i + 2] + $matice[9];
                    $y = $matice[3] * $v[$i] + $matice[4] * $v[$i + 1] + $matice[5] * $v[$i + 2] + $matice[10];
                    $z = $matice[6] * $v[$i] + $matice[7] * $v[$i + 1] + $matice[8] * $v[$i + 2] + $matice[11];
                }
                if ($x < $min[0]) $min[0] = $x;
                if ($y < $min[1]) $min[1] = $y;
                if ($z < $min[2]) $min[2] = $z;
                if ($x > $max[0]) $max[0] = $x;
                if ($y > $max[1]) $max[1] = $y;
                if ($z > $max[2]) $max[2] = $z;
            }
        }
        return ['min' => $min, 'max' => $max];
    }

    /** Vrcholy po afinní transformaci (nový binární řetězec). */
    public static function transformujVrcholy(string $vrcholy, int $pocet, array $matice): string
    {
        if (self::jeJednotkova($matice)) return $vrcholy;
        $vystup = '';
        for ($od = 0; $od < $pocet; $od += self::DAVKA) {
            $n = min(self::DAVKA, $pocet - $od);
            $v = array_values(unpack('g' . ($n * 3), $vrcholy, $od * 12));
            $nove = [];
            for ($i = 0; $i < $n * 3; $i += 3) {
                $nove[] = $matice[0] * $v[$i] + $matice[1] * $v[$i + 1] + $matice[2] * $v[$i + 2] + $matice[9];
                $nove[] = $matice[3] * $v[$i] + $matice[4] * $v[$i + 1] + $matice[5] * $v[$i + 2] + $matice[10];
                $nove[] = $matice[6] * $v[$i] + $matice[7] * $v[$i + 1] + $matice[8] * $v[$i + 2] + $matice[11];
            }
            $vystup .= pack('g*', ...$nove);
        }
        return $vystup;
    }

    /** Indexy trojúhelníků posunuté o $o (při spojování sítí). */
    public static function posunIndexy(string $trojuhelniky, int $pocet, int $o): string
    {
        if ($o === 0) return $trojuhelniky;
        $vystup = '';
        for ($od = 0; $od < $pocet; $od += self::DAVKA) {
            $n = min(self::DAVKA, $pocet - $od);
            $t = unpack('V' . ($n * 3), $trojuhelniky, $od * 12);
            foreach ($t as $k => $index) $t[$k] = $index + $o;
            $vystup .= pack('V*', ...array_values($t));
        }
        return $vystup;
    }

    /** Kvádr x × y × z s rohem v počátku (12 trojúhelníků, normály ven). */
    public static function kvadr(float $x, float $y, float $z): array
    {
        $v = [
            0, 0, 0,  $x, 0, 0,  $x, $y, 0,  0, $y, 0,
            0, 0, $z, $x, 0, $z, $x, $y, $z, 0, $y, $z,
        ];
        $t = [
            0, 2, 1, 0, 3, 2,   // spodek (normála -z)
            4, 5, 6, 4, 6, 7,   // vršek (+z)
            0, 1, 5, 0, 5, 4,   // předek (-y)
            1, 2, 6, 1, 6, 5,   // pravý bok (+x)
            2, 3, 7, 2, 7, 6,   // zadek (+y)
            3, 0, 4, 3, 4, 7,   // levý bok (-x)
        ];
        return [
            'vrcholy' => pack('g*', ...array_map('floatval', $v)),
            'pocet_vrcholu' => 8,
            'trojuhelniky' => pack('V*', ...$t),
            'pocet_trojuhelniku' => 12,
        ];
    }

    /** XML elementu <mesh> (vrcholy a trojúhelníky) pro 3MF. */
    public static function meshXml(array $sit): string
    {
        $xml = "<mesh>\n<vertices>\n";
        for ($od = 0; $od < $sit['pocet_vrcholu']; $od += self::DAVKA) {
            $n = min(self::DAVKA, $sit['pocet_vrcholu'] - $od);
            $v = array_values(unpack('g' . ($n * 3), $sit['vrcholy'], $od * 12));
            $radky = [];
            for ($i = 0; $i < $n * 3; $i += 3) {
                $radky[] = '<vertex x="' . self::cislo($v[$i]) . '" y="' . self::cislo($v[$i + 1]) . '" z="' . self::cislo($v[$i + 2]) . '"/>';
            }
            $xml .= implode("\n", $radky) . "\n";
        }
        $xml .= "</vertices>\n<triangles>\n";
        for ($od = 0; $od < $sit['pocet_trojuhelniku']; $od += self::DAVKA) {
            $n = min(self::DAVKA, $sit['pocet_trojuhelniku'] - $od);
            $t = array_values(unpack('V' . ($n * 3), $sit['trojuhelniky'], $od * 12));
            $radky = [];
            for ($i = 0; $i < $n * 3; $i += 3) {
                $radky[] = '<triangle v1="' . $t[$i] . '" v2="' . $t[$i + 1] . '" v3="' . $t[$i + 2] . '"/>';
            }
            $xml .= implode("\n", $radky) . "\n";
        }
        return $xml . "</triangles>\n</mesh>";
    }

    /** Číslo do XML: nejvýš 6 desetinných míst, bez zbytečných nul, bez "-0". */
    public static function cislo(float $x): string
    {
        $text = rtrim(rtrim(sprintf('%.6F', $x), '0'), '.');
        return ($text === '-0' || $text === '') ? '0' : $text;
    }
}

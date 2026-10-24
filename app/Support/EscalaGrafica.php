<?php

namespace App\Support;

/** Eje con marcas redondeadas ($0, $250k, $500k…) para las gráficas SVG. */
final class EscalaGrafica
{
    /** @return array{marcas: list<float>, min: float, max: float} */
    public static function marcas(float $min, float $max, int $objetivo = 4): array
    {
        $min = min(0.0, $min);
        $max = max(0.0, $max);
        if ($max - $min < 1) {
            return ['marcas' => [0.0, 250.0, 500.0, 750.0, 1000.0], 'min' => 0.0, 'max' => 1000.0];
        }
        $crudo = ($max - $min) / $objetivo;
        $magnitud = 10 ** floor(log10($crudo));
        $paso = $magnitud;
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            $paso = $m * $magnitud;
            if ($paso >= $crudo) {
                break;
            }
        }
        $lo = floor($min / $paso) * $paso;
        $hi = ceil($max / $paso) * $paso;
        $marcas = [];
        for ($x = $lo; $x <= $hi + $paso / 2; $x += $paso) {
            $marcas[] = round($x, 2);
        }

        return ['marcas' => $marcas, 'min' => (float) $lo, 'max' => (float) $hi];
    }

    /** $0, $250k, $1.5M */
    public static function corto(float $n): string
    {
        $signo = $n < 0 ? '-' : '';
        $a = abs($n);
        if ($a >= 1_000_000) {
            return $signo.'$'.rtrim(rtrim(number_format($a / 1_000_000, 2), '0'), '.').'M';
        }
        if ($a >= 1000) {
            return $signo.'$'.rtrim(rtrim(number_format($a / 1000, 1), '0'), '.').'k';
        }

        return $signo.'$'.number_format($a, 0);
    }
}

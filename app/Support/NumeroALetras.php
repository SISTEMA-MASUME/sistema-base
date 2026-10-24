<?php

namespace App\Support;

/** Convierte importes a texto en español: 47753.22 → "Cuarenta y siete mil ... pesos 22/100 M.N." */
final class NumeroALetras
{
    private const UNIDADES = [
        '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez',
        'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
        'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis',
        'veintisiete', 'veintiocho', 'veintinueve',
    ];

    private const DECENAS = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];

    private const CENTENAS = [
        1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos',
        6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos',
    ];

    public static function pesos(float|int|string $importe): string
    {
        $centavosTotales = (int) round(abs((float) $importe) * 100);
        $enteros = intdiv($centavosTotales, 100);
        $centavos = $centavosTotales % 100;

        $texto = $enteros === 0 ? 'cero' : self::apocope(self::entero($enteros));
        $moneda = $enteros === 1 ? 'peso' : (($enteros % 1000000 === 0 && $enteros > 0) ? 'de pesos' : 'pesos');

        return mb_strtoupper(mb_substr($texto, 0, 1)).mb_substr($texto, 1)
            .sprintf(' %s %02d/100 M.N.', $moneda, $centavos);
    }

    private static function entero(int $n): string
    {
        if ($n >= 1000000) {
            $millones = intdiv($n, 1000000);
            $resto = $n % 1000000;
            $txt = $millones === 1 ? 'un millón' : self::entero($millones).' millones';

            return $resto ? $txt.' '.self::entero($resto) : $txt;
        }

        if ($n >= 1000) {
            $miles = intdiv($n, 1000);
            $resto = $n % 1000;
            $txt = $miles === 1 ? 'mil' : self::apocope(self::centenas($miles)).' mil';

            return $resto ? $txt.' '.self::centenas($resto) : $txt;
        }

        return self::centenas($n);
    }

    /** "uno"→"un", "veintiuno"→"veintiún" antes de mil/millones. */
    private static function apocope(string $t): string
    {
        return preg_replace(['/veintiuno$/u', '/uno$/u'], ['veintiún', 'un'], $t);
    }

    private static function centenas(int $n): string
    {
        if ($n === 100) {
            return 'cien';
        }
        $c = intdiv($n, 100);
        $r = $n % 100;
        $txt = $c ? self::CENTENAS[$c] : '';
        if ($r === 0) {
            return $txt;
        }

        return trim($txt.' '.self::decenas($r));
    }

    private static function decenas(int $n): string
    {
        if ($n < 30) {
            return self::UNIDADES[$n];
        }
        $d = intdiv($n, 10);
        $u = $n % 10;

        return self::DECENAS[$d].($u ? ' y '.self::UNIDADES[$u] : '');
    }
}

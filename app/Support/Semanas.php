<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Semanas de comisión y nómina: de LUNES a SÁBADO (el sábado se paga). Un cobro que llega en domingo
 * cuenta para la semana siguiente. La semana N del año es la que termina en el N-ésimo sábado del año
 * (así la semana 39 de 2026 es del lunes 21 al sábado 26 de septiembre, igual que en el Sheets) y la
 * semana que cruza de año pertenece al año de su sábado (no se parte en dos).
 */
final class Semanas
{
    /** Sábado (día de pago) de la semana a la que pertenece la fecha. */
    public static function sabado(Carbon|string $fecha): Carbon
    {
        $f = Carbon::parse($fecha)->startOfDay();

        return $f->addDays(6 - $f->dayOfWeek);   // domingo (0) → sábado siguiente; sábado (6) → el mismo
    }

    /** @return array{0: int, 1: int} [año, semana] de la fecha */
    public static function deFecha(Carbon|string $fecha): array
    {
        $sabado = self::sabado($fecha);

        return [$sabado->year, intdiv($sabado->dayOfYear - 1, 7) + 1];
    }

    /** Primer sábado del año. */
    private static function primerSabado(int $anio): Carbon
    {
        $enero = Carbon::create($anio, 1, 1)->startOfDay();

        return $enero->addDays((6 - $enero->dayOfWeek + 7) % 7);
    }

    /** @return array{0: Carbon, 1: Carbon} lunes y sábado de la semana (lo que se le dice a Daniel) */
    public static function rango(int $anio, int $semana): array
    {
        $sabado = self::primerSabado($anio)->addWeeks($semana - 1);

        return [$sabado->copy()->subDays(5), $sabado];
    }

    /** @return array{0: Carbon, 1: Carbon} domingo previo y sábado: los cobros que cuentan para la semana */
    public static function rangoCobro(int $anio, int $semana): array
    {
        [$lunes, $sabado] = self::rango($anio, $semana);

        return [$lunes->copy()->subDay(), $sabado];
    }

    /** Cuántas semanas tiene el año (52 o 53). */
    public static function delAnio(int $anio): int
    {
        return self::deFecha(Carbon::create($anio, 12, 31))[0] === $anio
            ? self::deFecha(Carbon::create($anio, 12, 31))[1]
            : self::deFecha(Carbon::create($anio, 12, 31)->subWeek())[1];
    }

    /** "Semana 39 · lun 21 al sáb 26 sep 2026" */
    public static function etiqueta(int $anio, int $semana): string
    {
        [$lunes, $sabado] = self::rango($anio, $semana);
        $mes = fn (Carbon $f) => ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][$f->month - 1];
        $desde = 'lun '.$lunes->day.($lunes->month !== $sabado->month ? ' '.$mes($lunes) : '');

        return "Semana {$semana} · {$desde} al sáb {$sabado->day} {$mes($sabado)} {$sabado->year}";
    }
}

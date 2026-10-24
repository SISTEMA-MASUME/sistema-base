<?php

namespace App\Support;

use App\Models\User;
use App\Models\Vacacion;
use Illuminate\Support\Carbon;

/**
 * Vacaciones por antigüedad (LFT, reforma 2023). El periodo va de un aniversario de ingreso al día anterior al siguiente.
 * Saldo del periodo = días que le tocan − tomadas (registradas, con `desde` dentro del periodo) + ajustes con fecha dentro del
 * periodo. Lo anticipado antes de cumplir el primer año (y lo anterior al primer aniversario) cuenta en el primer periodo.
 * Sin fecha de ingreso y sin días propios no se calcula nada. Con días propios y sin fecha de ingreso el periodo es el año
 * natural.
 */
class Vacaciones
{
    /** Días por ley según años cumplidos (0 si aún no cumple un año). */
    public static function diasPorLey(int $anios): int
    {
        if ($anios < 1) {
            return 0;
        }
        if ($anios <= 5) {
            return 10 + 2 * $anios;
        }

        return 22 + 2 * intdiv($anios - 6, 5);
    }

    /** Días que le tocan en un periodo que empieza con $aniosAlInicio años cumplidos. */
    public static function diasDelPeriodo(User $u, int $aniosAlInicio): int
    {
        return $u->vacaciones_dias_anuales !== null ? (int) $u->vacaciones_dias_anuales : self::diasPorLey($aniosAlInicio);
    }

    public static function calculable(User $u): bool
    {
        return $u->fecha_ingreso !== null || $u->vacaciones_dias_anuales !== null;
    }

    /** Años cumplidos de antigüedad a una fecha. */
    public static function anios(User $u, Carbon $hoy): int
    {
        if (! $u->fecha_ingreso || $hoy->lt($u->fecha_ingreso)) {
            return 0;
        }

        return (int) $u->fecha_ingreso->copy()->startOfDay()->diffInYears($hoy->copy()->startOfDay(), true);
    }

    /** "3 años 2 meses" */
    public static function antiguedadTexto(User $u, ?Carbon $hoy = null): string
    {
        $hoy = ($hoy ?? now())->copy()->startOfDay();
        if (! $u->fecha_ingreso) {
            return '—';
        }
        if ($hoy->lt($u->fecha_ingreso)) {
            return 'Aún no ingresa';
        }
        $anios = self::anios($u, $hoy);
        $meses = (int) $u->fecha_ingreso->copy()->addYears($anios)->diffInMonths($hoy, true);
        $partes = [];
        if ($anios > 0) {
            $partes[] = $anios.($anios === 1 ? ' año' : ' años');
        }
        if ($meses > 0 || ! $anios) {
            $partes[] = $meses.($meses === 1 ? ' mes' : ' meses');
        }

        return implode(' ', $partes);
    }

    /**
     * Resumen del periodo en que cae $hoy.
     *
     * @return array{calculable:bool, mensaje:?string, desde:?Carbon, hasta:?Carbon, dias_periodo:int, usados:float, ajustes:float,
     *               saldo:?float, proximo_aniversario:?Carbon, proximos_dias:?int, anios:int}
     */
    public static function resumen(User $u, ?Carbon $hoy = null): array
    {
        $hoy = ($hoy ?? now())->copy()->startOfDay();
        $vacio = ['calculable' => false, 'mensaje' => 'Falta la fecha de ingreso', 'desde' => null, 'hasta' => null, 'dias_periodo' => 0,
            'usados' => 0.0, 'ajustes' => 0.0, 'saldo' => null, 'proximo_aniversario' => null, 'proximos_dias' => null, 'anios' => 0];
        if (! self::calculable($u)) {
            return $vacio;
        }

        if ($u->fecha_ingreso) {
            $ingreso = $u->fecha_ingreso->copy()->startOfDay();
            $anios = self::anios($u, $hoy);
            $inicioPeriodo = $anios >= 1 ? $ingreso->copy()->addYearsNoOverflow($anios) : $ingreso;
            $siguiente = $ingreso->copy()->addYearsNoOverflow($anios + 1);
            $fin = $siguiente->copy()->subDay();
            // El primer periodo con días (el que empieza en el primer aniversario) recibe también lo anterior.
            $sinTope = $anios <= 1;
            $proximo = $siguiente;
            $proximosDias = self::diasDelPeriodo($u, $anios + 1);
            $aniosPeriodo = $anios;
        } else {
            $inicioPeriodo = $hoy->copy()->startOfYear();
            $fin = $hoy->copy()->endOfYear()->startOfDay();
            $sinTope = false;
            $proximo = null;
            $proximosDias = null;
            $aniosPeriodo = 0;
            $anios = 0;
        }

        $base = Vacacion::where('user_id', $u->id)->where('estatus', 'registrada')->where('desde', '<=', $fin->toDateString());
        if (! $sinTope) {
            $base->where('desde', '>=', $inicioPeriodo->toDateString());
        }
        $filas = $base->get(['tipo', 'dias']);
        $usados = (float) $filas->where('tipo', 'tomadas')->sum('dias');
        $ajustes = (float) $filas->where('tipo', 'ajuste')->sum('dias');
        $diasPeriodo = self::diasDelPeriodo($u, $aniosPeriodo);

        return ['calculable' => true, 'mensaje' => null, 'desde' => $inicioPeriodo, 'hasta' => $fin, 'dias_periodo' => $diasPeriodo,
            'usados' => $usados, 'ajustes' => $ajustes, 'saldo' => $diasPeriodo - $usados + $ajustes,
            'proximo_aniversario' => $proximo, 'proximos_dias' => $proximosDias, 'anios' => $anios];
    }

    // ===== Días hábiles =====

    /** Festivos oficiales de México (los que descansan por ley) de un año, como 'Y-m-d' => nombre. */
    public static function festivos(int $anio): array
    {
        $f = [
            Carbon::create($anio, 1, 1)->toDateString() => 'Año Nuevo',
            Carbon::create($anio, 2, 1)->nthOfMonth(1, Carbon::MONDAY)->toDateString() => 'Día de la Constitución',
            Carbon::create($anio, 3, 1)->nthOfMonth(3, Carbon::MONDAY)->toDateString() => 'Natalicio de Benito Juárez',
            Carbon::create($anio, 5, 1)->toDateString() => 'Día del Trabajo',
            Carbon::create($anio, 9, 16)->toDateString() => 'Independencia',
            Carbon::create($anio, 11, 1)->nthOfMonth(3, Carbon::MONDAY)->toDateString() => 'Revolución Mexicana',
            Carbon::create($anio, 12, 25)->toDateString() => 'Navidad',
        ];
        // Cambio de presidente: 1 de octubre cada seis años (2024, 2030, 2036...).
        if ($anio >= 2024 && ($anio - 2024) % 6 === 0) {
            $f[Carbon::create($anio, 10, 1)->toDateString()] = 'Cambio de Poder Ejecutivo';
        }

        return $f;
    }

    public static function esFestivo(Carbon $dia): bool
    {
        return isset(self::festivos($dia->year)[$dia->toDateString()]);
    }

    /** ¿Ese día cuenta como día de vacaciones para esa persona? Nunca domingo ni festivo; sábado solo si trabaja sábados. */
    public static function esHabil(User $u, Carbon $dia): bool
    {
        if ($dia->isSunday() || self::esFestivo($dia)) {
            return false;
        }

        return ! $dia->isSaturday() || (bool) $u->trabaja_sabados;
    }

    public static function diasHabiles(User $u, Carbon $desde, Carbon $hasta): int
    {
        $n = 0;
        for ($d = $desde->copy()->startOfDay(); $d->lte($hasta); $d->addDay()) {
            if (self::esHabil($u, $d)) {
                $n++;
            }
        }

        return $n;
    }
}

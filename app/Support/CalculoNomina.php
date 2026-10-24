<?php

namespace App\Support;

use App\Models\Ajuste;
use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\Nomina;
use App\Models\NominaLinea;
use App\Models\Prestamo;
use App\Models\PrestamoAbono;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Nómina semanal y comisiones (reglas de Daniel, 1-oct-2026):
 * - Semana de lunes a sábado (Support\Semanas); se paga el sábado.
 * - Comisión = 10% de la utilidad bruta (venta − costo, sin IVA) de lo COBRADO. Entra todo lo cobrado hasta el
 *   sábado que todavía no se haya comisionado (ventas.nomina_id nulo): si un pago se registra tarde con fecha de
 *   una semana ya pagada, entra en la siguiente y no se pierde ni se paga dos veces. Lo cobrado antes de
 *   `comisiones_desde` ya se pagó con el Sheets, salvo que su pago se registre en el sistema después de esa fecha
 *   (`ventas.pago_registrado_at`: complemento hecho tarde con la fecha real del pago).
 * - Reparto de la comisión por % de cada empleado (Alberto 45, Rosa 35, Ernesto 20), o monto a mano.
 * - Préstamos: se sugiere el descuento semanal (sin pasar del saldo) y al registrar el pago se abona.
 * - Al registrar, la semana queda cerrada: cambios posteriores no mueven lo pagado. El admin puede reabrirla.
 * - Reparto mensual al socio: 20% de (utilidad bruta cobrada del mes − gastos MASUME del mes), mínimo 0.
 */
final class CalculoNomina
{
    public const COMISION = 0.10;

    public const DESDE = 'comisiones_desde';

    public const SOCIO = 'reparto_socio_user_id';

    public const SOCIO_PCT = 20;

    /** Cuenta con la que se paga la nómina (para el gasto que se crea al registrarla). */
    public const CUENTA_PAGO = 'cuenta_pago_nomina';

    /** Primer día de cobros que se comisionan en el sistema (la semana 39 de 2026 ya se pagó con el Sheets). */
    public static function desde(): string
    {
        return (string) Ajuste::obtener(self::DESDE, '2026-09-27');
    }

    /** Ventas cobradas cuya comisión toca en esta semana. */
    public static function cobros(int $anio, int $semana): Builder
    {
        [, $sabado] = Semanas::rango($anio, $semana);

        return Venta::query()->where('estatus', 'pagada')->whereNull('nomina_id')->whereNull('comision_excluida_at')
            ->whereDoesntHave('depositoCobro', fn ($q) => $q->where('estatus', 'revisar'))
            ->whereDoesntHave('complementoPago', fn ($q) => $q->where('cancelado', true)->orWhere('requiere_revision', true))
            // Lo cobrado antes del arranque se pagó con el Sheets, salvo que el pago se haya registrado en el sistema
            // después (complemento hecho tarde con la fecha real del pago): ese nunca se comisionó.
            ->where(fn ($q) => $q->where('fecha_pago', '>=', self::desde())->orWhere('pago_registrado_at', '>=', self::desde()))
            ->where('fecha_pago', '<=', $sabado->toDateString().' 23:59:59');
    }

    public static function utilidad(Builder $ventas): float
    {
        $t = (clone $ventas)->toBase()->selectRaw('SUM(subtotal) v, SUM(costo) c')->first();

        return round((float) ($t->v ?? 0) - (float) ($t->c ?? 0), 2);
    }

    /** Lo que se le sugiere descontar de sus préstamos esta semana (sin pasar del saldo). */
    public static function descuentoSugerido(int $userId): float
    {
        return round(Prestamo::activos()->where('user_id', $userId)->get()
            ->sum(fn (Prestamo $p) => min((float) $p->descuento_semanal, $p->saldo())), 2);
    }

    /** La nómina de la semana (la crea como borrador con quienes están en nómina) ya recalculada. */
    public static function semana(int $anio, int $semana): Nomina
    {
        [$lunes, $sabado] = Semanas::rango($anio, $semana);
        $n = Nomina::firstOrCreate(['tipo' => 'semanal', 'anio' => $anio, 'semana' => $semana],
            ['desde' => $lunes->toDateString(), 'hasta' => $sabado->toDateString(), 'estatus' => 'borrador']);
        if ($n->pagada()) {
            return $n;
        }
        // Las personas se agregan solo al crear la semana: si después quitan a alguien, no vuelve a aparecer solo.
        $ya = $n->wasRecentlyCreated || ! $n->lineas()->exists() ? $n->lineas()->pluck('user_id')->filter()->all() : null;
        foreach ($ya === null ? [] : User::where('en_nomina', true)->where('activo', true)->whereNull('eliminado_at')->whereNotIn('id', $ya)->orderBy('id')->get() as $u) {
            $n->lineas()->create([
                'user_id' => $u->id, 'nombre' => $u->nombreReal(), 'sueldo_base' => $u->sueldo_base, 'comision_pct' => $u->comision_pct,
                'descuento_prestamo' => self::descuentoSugerido($u->id), 'cuenta_id' => $u->cuenta_nomina_id,
            ]);
        }
        self::recalcular($n);

        return $n->fresh();
    }

    /** Utilidad cobrada, comisión total y comisión/total de cada línea (las de monto a mano se respetan). */
    public static function recalcular(Nomina $n): void
    {
        if ($n->pagada() || $n->tipo !== 'semanal') {
            return;
        }
        $utilidad = self::utilidad(self::cobros($n->anio, $n->semana));
        $bolsa = round(max(0, $utilidad) * self::COMISION, 2);
        $n->update(['utilidad' => $utilidad, 'bolsa' => $bolsa]);
        foreach ($n->lineas()->get() as $l) {
            if (! $l->comision_manual) {
                $l->comision = round($bolsa * (float) $l->comision_pct / 100, 2);
            }
            $l->calcularTotal();
            $l->save();
        }
    }

    /** Registra el pago: marca las ventas comisionadas, abona préstamos y cierra la semana. */
    public static function registrar(Nomina $n): void
    {
        DB::transaction(function () use ($n) {
            $n = Nomina::lockForUpdate()->findOrFail($n->id);
            if ($n->pagada()) {
                return;
            }
            // Primero se bloquean las ventas por comisionar: si otra semana se está registrando al mismo tiempo,
            // esta espera y ya no ve las que la otra se llevó (una venta se comisiona una sola vez).
            $ids = $n->tipo === 'semanal' ? self::cobros($n->anio, $n->semana)->orderBy('id')->lockForUpdate()->pluck('id')->all() : [];
            self::recalcular($n);
            if ($ids !== []) {
                Venta::whereIn('id', $ids)->whereNull('nomina_id')->update(['nomina_id' => $n->id]);
            }
            foreach ($n->lineas()->where('descuento_prestamo', '>', 0)->get() as $l) {
                $resta = (float) $l->descuento_prestamo;
                foreach (Prestamo::activos()->where('user_id', $l->user_id)->orderBy('fecha')->orderBy('id')->get() as $p) {
                    $abono = round(min($resta, $p->saldo()), 2);
                    if ($abono <= 0) {
                        continue;
                    }
                    PrestamoAbono::create(['prestamo_id' => $p->id, 'nomina_id' => $n->id, 'fecha' => $n->hasta, 'monto' => $abono,
                        'nota' => 'Descuento en nómina', 'created_at' => now()]);
                    $p->revisarSaldo();
                    $resta = round($resta - $abono, 2);
                    if ($resta <= 0) {
                        break;
                    }
                }
            }
            $n->update(['estatus' => 'pagada', 'pagada_at' => now(), 'user_id' => auth()->id()]);

            // El pago queda como gasto MASUME (antes el Sheets lo agregaba a GASTOS): uno por cada cuenta de la que salió el
            // dinero (a unos se les paga desde BBVA y a otros desde Hey Banco), y cada línea guarda de dónde se le pagó.
            $grupos = self::porCuenta($n);
            foreach ($grupos as $g) {
                NominaLinea::whereIn('id', $g['lineas'])->update(['cuenta_id' => $g['cuenta']?->id]);
            }
            $grupos = $grupos->filter(fn ($g) => $g['monto'] > 0);
            foreach ($grupos as $g) {
                $descripcion = $n->tipo === 'semanal' ? "PAGO NOMINA SEMANA {$n->semana}" : "REPARTO SOCIO {$n->mes}/{$n->anio}";
                Gasto::create([
                    'fecha' => $n->tipo === 'semanal' ? $n->hasta : today(),
                    'monto' => $g['monto'], 'de' => 'masume', 'tipo' => 'gasto',
                    'categoria' => $n->tipo === 'semanal' ? 'NOMINA' : 'REPARTO SOCIO',
                    'descripcion' => $grupos->count() > 1 ? mb_substr($descripcion.' · '.implode(', ', $g['nombres']), 0, 250) : $descripcion,
                    'cuenta_id' => $g['cuenta']?->id,
                    'nomina_id' => $n->id, 'origen' => 'nomina', 'user_id' => auth()->id(),
                ]);
            }
        });
    }

    /** La cuenta general de la nómina: de ahí se paga a quien no tenga otra elegida. */
    public static function cuentaGeneral(): ?Cuenta
    {
        return Cuenta::obtener((string) Ajuste::obtener(self::CUENTA_PAGO, 'Hey Banco (Débito)'));
    }

    /** Cuentas de las que se puede pagar la nómina (débito y efectivo activas). */
    public static function cuentasDePago(): Collection
    {
        return Cuenta::where('activa', true)->whereIn('tipo', ['debito', 'efectivo'])->orderBy('orden')->orderBy('nombre')->get(['id', 'nombre', 'tipo']);
    }

    /**
     * Cuánto sale de cada cuenta en esta nómina: la de la línea, si no la del empleado, si no la general.
     *
     * @return Collection<int, array{cuenta: ?Cuenta, monto: float, nombres: list<string>, lineas: list<int>}>
     */
    public static function porCuenta(Nomina $n): Collection
    {
        $general = null;
        $grupos = [];
        foreach ($n->lineas()->with(['cuenta', 'user.cuentaNomina'])->get() as $l) {
            $cuenta = $l->cuenta ?? $l->user?->cuentaNomina ?? ($general ??= self::cuentaGeneral());
            $g = &$grupos[$cuenta?->id ?? 0];
            $g ??= ['cuenta' => $cuenta, 'monto' => 0.0, 'nombres' => [], 'lineas' => []];
            $g['monto'] = round($g['monto'] + (float) $l->total, 2);
            $g['nombres'][] = $l->user?->nombreReal() ?? $l->nombre;
            $g['lineas'][] = $l->id;
            unset($g);
        }

        return collect($grupos)->sortByDesc('monto')->values();
    }

    /**
     * Lo que va a salir de cada cuenta en la nómina de esa semana, sin crearla si todavía nadie la abre (vista previa con
     * quienes están en nómina). Vacío si ya está pagada.
     *
     * @return Collection<int, array{cuenta: ?Cuenta, monto: float, nombres: list<string>}>
     */
    public static function estimadoPorCuenta(int $anio, int $semana): Collection
    {
        $n = Nomina::where(['tipo' => 'semanal', 'anio' => $anio, 'semana' => $semana])->first();
        if ($n) {
            self::recalcular($n);

            return $n->pagada() ? collect() : self::porCuenta($n->fresh());
        }
        $bolsa = round(max(0, self::utilidad(self::cobros($anio, $semana))) * self::COMISION, 2);
        $general = null;
        $grupos = [];
        foreach (User::with('cuentaNomina')->where('en_nomina', true)->where('activo', true)->whereNull('eliminado_at')->orderBy('id')->get() as $u) {
            $cuenta = $u->cuentaNomina ?? ($general ??= self::cuentaGeneral());
            $g = &$grupos[$cuenta?->id ?? 0];
            $g ??= ['cuenta' => $cuenta, 'monto' => 0.0, 'nombres' => []];
            $g['monto'] = round($g['monto'] + (float) $u->sueldo_base + round($bolsa * (float) $u->comision_pct / 100, 2) - self::descuentoSugerido($u->id), 2);
            $g['nombres'][] = $u->nombreReal();
            unset($g);
        }

        return collect($grupos)->sortByDesc('monto')->values();
    }

    /** Reabre una nómina pagada por error: suelta las ventas y quita los abonos que hizo. */
    public static function reabrir(Nomina $n): void
    {
        DB::transaction(function () use ($n) {
            $n = Nomina::lockForUpdate()->findOrFail($n->id);
            $ventas = Venta::where('nomina_id', $n->id)->orderBy('id')->lockForUpdate()->get();
            $conciliadas = DB::table('deposito_facturas')->join('depositos_cobro', 'depositos_cobro.id', '=', 'deposito_facturas.deposito_id')
                ->whereIn('deposito_facturas.venta_id', $ventas->pluck('id'))
                ->whereIn('depositos_cobro.estatus', ['conciliado', 'revisar', 'anulado'])->exists();
            if ($conciliadas) {
                ComplementosSicar::error('Esta nómina contiene comisiones de depósitos conciliados, corregidos o anulados. No se puede reabrir y borrar ese registro de pago.');
            }
            Venta::where('nomina_id', $n->id)->update(['nomina_id' => null]);
            Gasto::withTrashed()->where('nomina_id', $n->id)->forceDelete();
            $prestamos = PrestamoAbono::where('nomina_id', $n->id)->pluck('prestamo_id')->unique();
            PrestamoAbono::where('nomina_id', $n->id)->delete();
            Prestamo::whereIn('id', $prestamos)->get()->each->revisarSaldo();
            $n->update(['estatus' => 'borrador', 'pagada_at' => null]);
        });
        self::recalcular($n->fresh());
    }

    // ---------------------------------------------------------------- reparto mensual al socio

    public static function socio(): ?User
    {
        $id = (int) Ajuste::obtener(self::SOCIO, '0');

        return ($id ? User::find($id) : null)
            ?? User::where('en_nomina', true)->where('name', 'like', 'Ernesto%')->orderBy('id')->first();
    }

    /** Utilidad bruta de lo cobrado en el mes (por fecha de pago). */
    public static function utilidadDelMes(int $anio, int $mes): float
    {
        $desde = Carbon::create($anio, $mes, 1);

        return self::utilidad(Venta::query()->where('estatus', 'pagada')
            ->where('fecha_pago', '>=', $desde->toDateString())
            ->where('fecha_pago', '<=', $desde->copy()->endOfMonth()->toDateString().' 23:59:59'));
    }

    /** El reparto del mes (borrador con la utilidad del mes; gastos los pone Daniel hasta que exista Gastos). */
    public static function reparto(int $anio, int $mes, ?float $gastos = null): Nomina
    {
        $desde = Carbon::create($anio, $mes, 1);
        $n = Nomina::firstOrCreate(['tipo' => 'reparto', 'anio' => $anio, 'mes' => $mes],
            ['desde' => $desde->toDateString(), 'hasta' => $desde->copy()->endOfMonth()->toDateString(), 'estatus' => 'borrador']);
        if ($n->pagada()) {
            return $n;
        }
        $utilidad = self::utilidadDelMes($anio, $mes);
        // Gastos: los que escriba Daniel o, si no, los gastos MASUME del mes del módulo de Gastos.
        $gastos ??= $n->gastos !== null ? (float) $n->gastos : Gasto::delMesParaReparto($anio, $mes);
        $neta = max(0, round($utilidad - $gastos, 2));
        $n->update(['utilidad' => $utilidad, 'gastos' => $gastos, 'bolsa' => round($neta * self::SOCIO_PCT / 100, 2),
            'notas' => 'Utilidad neta (base del reparto): $'.number_format($neta, 2).' · capital retenido: $'.number_format($neta - $neta * self::SOCIO_PCT / 100, 2)]);
        $socio = self::socio();
        $l = $n->lineas()->first() ?? new NominaLinea(['nomina_id' => $n->id, 'user_id' => $socio?->id,
            'nombre' => ($socio?->nombreReal() ?? 'Socio').' (socio '.self::SOCIO_PCT.'%)', 'comision_pct' => self::SOCIO_PCT,
            'cuenta_id' => $socio?->cuenta_nomina_id]);
        if (! $l->comision_manual) {
            $l->comision = $n->bolsa;
        }
        $l->calcularTotal();
        $l->save();

        return $n->fresh();
    }
}

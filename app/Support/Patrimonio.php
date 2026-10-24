<?php

namespace App\Support;

use App\Models\ComplementoPago;
use App\Models\Cuenta;
use App\Models\CuentaPorPagar;
use App\Models\SaldoCorte;
use App\Models\SaldoLinea;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Mis finanzas: patrimonio a partir de "cortes" de saldos (no se registra movimiento por movimiento).
 * patrimonio neto = disponible + me deben + cartera MASUME − deuda de tarjetas − deuda de créditos − deuda a proveedores
 * (compras a crédito de "A quién le debo", en vivo).
 * La cartera MASUME no se captura: sale en vivo de Ventas pendientes (misma regla que Cobranza).
 */
final class Patrimonio
{
    /** Utilización desde la que se limita el uso de una tarjeta, y desde la que está saturada. */
    public const LIMITAR = 0.70;

    public const SATURADA = 0.90;

    /**
     * Último saldo de cada cuenta (del corte más reciente que la incluya), por id de cuenta.
     *
     * @return Collection<int, SaldoLinea> con la relación `corte` cargada
     */
    public static function ultimosSaldos(): Collection
    {
        return SaldoLinea::with('corte')->get()
            ->sortByDesc(fn (SaldoLinea $l) => sprintf('%s-%010d-%010d', $l->corte->fecha->format('Y-m-d'), $l->saldo_corte_id, $l->id))
            ->unique('cuenta_id')
            ->keyBy('cuenta_id');
    }

    /**
     * Cobros que llegaron a una cuenta DESPUÉS de su último saldo capturado: complementos de pago de SICAR cuya "Referencia"
     * dice a qué banco llegó el dinero ("HEY", "BBVA"; `CuentaPorReferencia`). Se suman a su saldo hasta el siguiente
     * "Actualizar saldos", que ya los trae incluidos. Si el pago es del mismo día que el saldo, cuenta solo si el
     * complemento llegó después de capturar ese saldo. Una cuenta sin ningún saldo capturado no suma nada.
     *
     * @return Collection<int, array{total: float, complementos: Collection<int, ComplementoPago>}> por id de cuenta
     */
    public static function cobrosDespuesDelCorte(?Collection $ultimos = null): Collection
    {
        $ultimos ??= self::ultimosSaldos();
        if ($ultimos->isEmpty()) {
            return collect();
        }

        return ComplementoPago::query()->whereNotNull('cuenta_id')->where('cancelado', false)->where('moneda', 'MXN')
            ->whereIn('cuenta_id', $ultimos->keys())->orderBy('fecha_pago')->orderBy('id')->get()
            ->filter(function (ComplementoPago $rep) use ($ultimos) {
                $linea = $ultimos[$rep->cuenta_id];
                $corte = $linea->corte->fecha->toDateString();
                $pago = $rep->fecha_pago->toDateString();

                return $pago > $corte || ($pago === $corte && $rep->created_at?->gt($linea->updated_at ?? $linea->created_at));
            })
            ->groupBy('cuenta_id')
            ->map(fn (Collection $reps) => ['total' => round((float) $reps->sum('importe'), 2), 'complementos' => $reps->values()]);
    }

    /**
     * En dónde está lo disponible: primero el débito (lo que se puede usar ya), luego efectivo y apartados, y al final la
     * inversión (GBM está en acciones). Cada grupo con sus cuentas de mayor a menor saldo; las que están en cero no salen.
     * El saldo incluye los cobros que llegaron después del último corte, igual que el resumen.
     *
     * @return list<array{titulo: string, total: float, cuentas: list<array{nombre: string, saldo: float}>}>
     */
    public static function desgloseDisponible(?Collection $ultimos = null, ?Collection $cobros = null): array
    {
        $ultimos ??= self::ultimosSaldos();
        $cobros ??= self::cobrosDespuesDelCorte($ultimos);
        $grupos = [['En débito', ['debito']], ['En efectivo y apartados', ['efectivo', 'apartado']], ['En inversión', ['inversion']]];
        $cuentas = Cuenta::where('activa', true)->whereIn('tipo', Cuenta::DISPONIBLES)->get();
        $desglose = [];
        foreach ($grupos as [$titulo, $tipos]) {
            $lista = $cuentas->whereIn('tipo', $tipos)
                ->map(fn (Cuenta $c) => ['nombre' => $c->nombre,
                    'saldo' => round((float) ($ultimos[$c->id]->saldo ?? 0) + ($cobros[$c->id]['total'] ?? 0.0), 2)])
                ->filter(fn ($c) => abs($c['saldo']) >= 0.01)
                ->sortByDesc('saldo')->values()->all();
            if ($lista !== []) {
                $desglose[] = ['titulo' => $titulo, 'total' => round(array_sum(array_column($lista, 'saldo')), 2), 'cuentas' => $lista];
            }
        }

        return $desglose;
    }

    /** Lo que MASUME tiene por cobrar (facturas y notas pendientes, con IVA): igual que la pantalla Cobranza. */
    public static function carteraMasume(): float
    {
        return (float) Venta::query()->where('estatus', 'pendiente')->where('total', '>', 0)->sum('total');
    }

    /** Lo que MASUME le debe a proveedores por compras a crédito (pantalla "A quién le debo"; ya no se captura como cuenta). */
    public static function proveedores(): float
    {
        return CuentasPorPagar::totalPendiente();
    }

    /** Suma por grupo con los saldos dados (cuenta_id => saldo) solo de las cuentas indicadas. */
    private static function sumar(Collection $cuentas, callable $saldoDe, float $cartera, float $proveedores = 0.0): array
    {
        $suma = fn (array $tipos) => round((float) $cuentas->whereIn('tipo', $tipos)->sum(fn (Cuenta $c) => $saldoDe($c)), 2);
        $disponible = $suma(Cuenta::DISPONIBLES);
        $tarjetas = $suma(['credito']);
        $creditos = $suma(['prestamo']);
        $meDeben = $suma(['por_cobrar']);

        return [
            'disponible' => $disponible, 'tarjetas' => $tarjetas, 'creditos' => $creditos,
            'me_deben' => $meDeben, 'cartera' => round($cartera, 2), 'proveedores' => round($proveedores, 2),
            'neto' => round($disponible + $meDeben + $cartera - $tarjetas - $creditos - $proveedores, 2),
        ];
    }

    /**
     * Resumen con el último saldo de cada cuenta activa.
     *
     * @return array{disponible: float, tarjetas: float, creditos: float, me_deben: float, cartera: float, neto: float, ultima: ?Carbon, dias: ?int}
     */
    public static function resumen(?Carbon $hoy = null): array
    {
        $hoy = ($hoy ?? today())->copy()->startOfDay();
        $ultimos = self::ultimosSaldos();
        $cobros = self::cobrosDespuesDelCorte($ultimos);
        $r = self::sumar(Cuenta::where('activa', true)->get(),
            fn (Cuenta $c) => (float) ($ultimos[$c->id]->saldo ?? 0) + ($cobros[$c->id]['total'] ?? 0.0), self::carteraMasume(), self::proveedores());
        $ultima = $ultimos->isEmpty() ? null : $ultimos->map(fn ($l) => $l->corte->fecha)->max();
        $r['ultima'] = $ultima?->copy()->startOfDay();
        $r['dias'] = $ultima ? (int) $ultima->copy()->startOfDay()->diffInDays($hoy) : null;

        return $r;
    }

    /**
     * Próxima vez que cae un día del mes: este mes si todavía no pasa (hoy cuenta), si no el siguiente.
     * Si el mes no tiene ese día (31 en febrero) cae el último día del mes.
     */
    public static function proximoDia(int $dia, ?Carbon $hoy = null): Carbon
    {
        $hoy = ($hoy ?? today())->copy()->startOfDay();
        $dia = max(1, min(31, $dia));
        $esteMes = $hoy->copy()->startOfMonth();
        $fecha = $esteMes->copy()->day(min($dia, $esteMes->daysInMonth));
        if ($fecha->lt($hoy)) {
            $sig = $esteMes->copy()->addMonthNoOverflow();
            $fecha = $sig->copy()->day(min($dia, $sig->daysInMonth));
        }

        return $fecha;
    }

    /** Semáforo de una tarjeta según lo usado de su línea. */
    public static function semaforo(?float $utilizacion): ?array
    {
        if ($utilizacion === null) {
            return null;
        }
        if ($utilizacion >= self::SATURADA) {
            return ['clave' => 'rojo', 'texto' => 'Saturada'];
        }
        if ($utilizacion >= self::LIMITAR) {
            return ['clave' => 'amarillo', 'texto' => 'Limitar'];
        }

        return ['clave' => 'verde', 'texto' => 'Abierta'];
    }

    /**
     * Tarjetas activas con su último saldo, ordenadas por la fecha de pago más próxima (las que no tienen día de pago, al final).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function tarjetas(?Carbon $hoy = null): Collection
    {
        $hoy = ($hoy ?? today())->copy()->startOfDay();
        $ultimos = self::ultimosSaldos();

        return Cuenta::where('activa', true)->where('tipo', 'credito')->get()
            ->map(function (Cuenta $c) use ($hoy, $ultimos) {
                $l = $ultimos[$c->id] ?? null;
                $saldo = (float) ($l->saldo ?? 0);
                $limite = $c->limite !== null ? (float) $c->limite : 0.0;
                $util = $limite > 0 ? $saldo / $limite : null;
                $pago = $c->dia_pago ? self::proximoDia((int) $c->dia_pago, $hoy) : null;

                return [
                    'cuenta' => $c, 'saldo' => $saldo, 'limite' => $limite > 0 ? $limite : null,
                    'sin_saldo' => $l === null, 'saldo_fecha' => $l?->corte->fecha,
                    'corte' => $c->dia_corte ? self::proximoDia((int) $c->dia_corte, $hoy) : null,
                    'pago' => $pago, 'dias_pago' => $pago ? (int) $hoy->diffInDays($pago) : null,
                    'utilizacion' => $util, 'semaforo' => self::semaforo($util),
                    'disponible70' => $limite > 0 ? round(max(0.0, $limite * self::LIMITAR - $saldo), 2) : null,
                    'pago_sin_intereses' => $l?->pago_sin_intereses !== null ? (float) $l->pago_sin_intereses : null,
                    'fecha_limite' => $l?->fecha_limite,
                ];
            })
            ->sortBy(fn ($t) => ($t['pago']?->format('Ymd') ?? '99999999').'-'.$t['cuenta']->nombre_llave)
            ->values();
    }

    /**
     * Patrimonio neto de cada corte, de más viejo a más nuevo. Cada corte usa el último saldo conocido de cada cuenta a esa
     * fecha. La cartera MASUME de cada día no se puede reconstruir: se usa la de hoy en todos (aproximación).
     *
     * @return list<array{fecha: Carbon, neto: float, disponible: float, tarjetas: float, creditos: float, me_deben: float, cartera: float}>
     */
    public static function historia(): array
    {
        $cuentas = Cuenta::where('activa', true)->get()->keyBy('id');
        $cartera = self::carteraMasume();
        $proveedores = self::proveedores();   // como la cartera: la de hoy en todos los cortes (aproximación)
        $actual = [];
        $historia = [];
        foreach (SaldoCorte::with('lineas')->orderBy('fecha')->orderBy('id')->get() as $corte) {
            foreach ($corte->lineas as $l) {
                $actual[$l->cuenta_id] = (float) $l->saldo;
            }
            $r = self::sumar($cuentas, fn (Cuenta $c) => $actual[$c->id] ?? 0.0, $cartera, $proveedores);
            $historia[] = ['fecha' => $corte->fecha->copy()->startOfDay()] + $r;
        }

        return $historia;
    }

    /**
     * Lo que hay que pagar en los próximos días, de la fecha más cercana a la más lejana: tarjetas con deuda (lo que hay
     * que pagar para no generar intereses, o lo que se debe) y compras a crédito a proveedores con fecha de vencimiento
     * (las vencidas también salen).
     *
     * @return list<array{fecha: Carbon, dias: int, nombre: string, detalle: string, monto: float, tipo: string}>
     */
    public static function pagosProximos(int $dias = 21, ?Carbon $hoy = null, bool $conNomina = false): array
    {
        $hoy = ($hoy ?? today())->copy()->startOfDay();
        $limite = $hoy->copy()->addDays($dias);
        $pagos = [];
        // La nómina del sábado (solo para quien puede ver la nómina): cuánto sale de cada cuenta. Es un estimado: la
        // comisión cambia con lo que se cobre en la semana.
        if ($conNomina) {
            [$anio, $semana] = Semanas::deFecha($hoy);
            $sabado = Semanas::rango($anio, $semana)[1]->copy()->startOfDay();
            $porCuenta = CalculoNomina::estimadoPorCuenta($anio, $semana)->filter(fn ($g) => $g['monto'] > 0);
            if ($porCuenta->isNotEmpty()) {
                $pagos[] = [
                    'fecha' => $sabado, 'dias' => (int) $hoy->diffInDays($sabado),
                    'nombre' => "Nómina de la semana {$semana}", 'tipo' => 'nomina',
                    'detalle' => $porCuenta->map(fn ($g) => ($g['cuenta']?->nombre ?? 'Cuenta general').' '.self::pesos($g['monto']))->implode(' · ')
                        .' (puede cambiar con lo que se cobre)',
                    'monto' => round((float) $porCuenta->sum('monto'), 2),
                ];
            }
        }
        foreach (self::tarjetas($hoy) as $t) {
            $fecha = $t['fecha_limite'] && $t['fecha_limite']->gte($hoy) ? $t['fecha_limite']->copy()->startOfDay() : $t['pago'];
            if (! $fecha || $t['saldo'] <= 0 || $fecha->gt($limite)) {
                continue;
            }
            $sinIntereses = $t['pago_sin_intereses'] !== null && $t['pago_sin_intereses'] > 0;
            $uso = $t['utilizacion'] !== null ? ' · línea usada al '.round($t['utilizacion'] * 100).'%' : '';
            $pagos[] = [
                'fecha' => $fecha, 'dias' => (int) $hoy->diffInDays($fecha), 'nombre' => $t['cuenta']->nombre, 'tipo' => 'tarjeta',
                'detalle' => ($sinIntereses ? 'Para no generar intereses' : 'Lo que debes en la tarjeta').$uso,
                'monto' => $sinIntereses ? (float) $t['pago_sin_intereses'] : (float) $t['saldo'],
            ];
        }
        foreach (CuentaPorPagar::with('proveedor:id,nombre')->where('estatus', 'pendiente')->whereNotNull('vence')
            ->where('vence', '<=', $limite->toDateString())->get() as $d) {
            $pagos[] = [
                'fecha' => $d->vence->copy()->startOfDay(), 'dias' => (int) $hoy->diffInDays($d->vence->copy()->startOfDay(), false),
                'nombre' => $d->proveedor->nombre.($d->folio ? ' · factura '.$d->folio : ''), 'tipo' => 'proveedor',
                'detalle' => 'Compra a crédito a proveedor', 'monto' => (float) $d->importe,
            ];
        }
        usort($pagos, fn ($a, $b) => [$a['fecha']->format('Ymd'), $a['nombre']] <=> [$b['fecha']->format('Ymd'), $b['nombre']]);

        return $pagos;
    }

    private static function pesos(float $n): string
    {
        return ($n < 0 ? '-$' : '$').number_format(abs($n), 2);
    }

    /** Número escrito a mano ("$1,234.50", "1234.5"): null si está vacío, false si no es un número. */
    public static function numero(mixed $texto): float|null|false
    {
        $t = trim(str_replace(['$', ',', ' '], '', (string) $texto));
        if ($t === '') {
            return null;
        }

        return is_numeric($t) ? (float) $t : false;
    }
}

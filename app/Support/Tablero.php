<?php

namespace App\Support;

use App\Models\Gasto;
use App\Models\NominaLinea;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cálculos del Dashboard de finanzas (como el Sheets de Daniel). Todo se agrupa en PHP para que funcione igual en
 * SQLite y MariaDB. Venta = subtotal (sin IVA) de las ventas no canceladas por fecha de factura; compra = costo;
 * gastos = gastos reales de MASUME (sin movimientos entre cuentas ni gastos personales) por fecha del gasto.
 */
final class Tablero
{
    public const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    public const MESES_LARGOS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** @var array<string, Collection> */
    private array $ventas = [];

    /** @var array<string, Collection> */
    private array $gastos = [];

    /** @return list<int> años con ventas, más el actual, del más nuevo al más viejo */
    public static function anios(): array
    {
        $anios = Venta::query()->whereNotNull('fecha')->get(['fecha'])->toBase()->map(fn ($v) => (int) $v->fecha->year)->push((int) today()->year)->unique()->sortDesc()->values();

        return $anios->all();
    }

    private function ventasDelAnio(int $anio): Collection
    {
        return $this->ventas[$anio] ??= Venta::vigentes()
            ->whereBetween('fecha', [$anio.'-01-01', $anio.'-12-31 23:59:59'])
            ->get(['id', 'cliente_id', 'cliente_nombre', 'fecha', 'subtotal', 'costo', 'total', 'estatus', 'fecha_pago']);
    }

    private function gastosDelAnio(int $anio): Collection
    {
        // El reparto al socio (utilidad) y la compra de vehículos/equipo (inversión) no son gasto operativo.
        return $this->gastos[$anio] ??= Gasto::reales()->where('de', 'masume')
            ->where(fn ($q) => $q->whereNull('categoria')->orWhereNotIn('categoria', Gasto::NO_OPERATIVOS))
            ->whereBetween('fecha', [$anio.'-01-01', $anio.'-12-31 23:59:59'])
            ->get(['id', 'fecha', 'monto', 'categoria']);
    }

    private static function delMes(Collection $filas, ?int $mes): Collection
    {
        return $mes === null ? $filas : $filas->filter(fn ($f) => $f->fecha->month === $mes);
    }

    private static function pct(float $a, float $b): ?float
    {
        return $b == 0.0 ? null : round($a / $b * 100, 1);
    }

    /** Todas las métricas del periodo (año completo si $mes es null). */
    public function resumen(int $anio, ?int $mes = null): array
    {
        $v = self::delMes($this->ventasDelAnio($anio), $mes);
        $venta = round((float) $v->sum('subtotal'), 2);
        $compra = round((float) $v->sum('costo'), 2);
        $bruta = round($venta - $compra, 2);
        $gastos = round((float) self::delMes($this->gastosDelAnio($anio), $mes)->sum('monto'), 2);
        $neta = round($bruta - $gastos, 2);

        $pagadas = $v->where('estatus', 'pagada');
        $pendientes = $v->where('estatus', 'pendiente');
        $docs = $pagadas->count() + $pendientes->count();
        $conPago = $pagadas->filter(fn ($x) => $x->fecha_pago !== null);

        $desde = Carbon::create($anio, $mes ?? 1, 1)->startOfDay();
        $hasta = $mes === null ? Carbon::create($anio, 12, 31) : $desde->copy()->endOfMonth();
        $comisiones = (float) NominaLinea::query()->whereHas('nomina', fn ($q) => $q->where('tipo', 'semanal')->where('estatus', 'pagada')
            ->whereBetween('hasta', [$desde->toDateString(), $hasta->toDateString().' 23:59:59']))->sum('comision');

        return [
            'venta' => $venta, 'compra' => $compra, 'bruta' => $bruta, 'gastos' => $gastos, 'neta' => $neta,
            'margen_neto' => self::pct($neta, $venta),
            'cobradas_pct' => self::pct($pagadas->count(), $docs),
            'gastos_venta' => self::pct($gastos, $venta),
            'documentos' => $docs, 'cobrados' => $pagadas->count(), 'pendientes' => $pendientes->count(),
            'monto_pendiente' => round((float) $pendientes->sum('total'), 2),
            'ticket' => $docs ? round($venta / $docs, 2) : null,
            'dias_cobro' => $conPago->isEmpty() ? null : round($conPago->avg(fn ($x) => $x->fecha->diffInDays($x->fecha_pago, false)), 1),
            'clientes' => $v->map(fn ($x) => self::clave($x))->unique()->count(),
            'comisiones' => round($comisiones, 2),
            'costo_documento' => $docs ? round($gastos / $docs, 2) : null,
            'roi' => $gastos > 0 ? round($neta / $gastos * 100, 1) : null,
        ];
    }

    private static function clave($v): string
    {
        return $v->cliente_id ? 'c'.$v->cliente_id : 'n'.mb_strtoupper(trim((string) $v->cliente_nombre));
    }

    /** @return list<array{nombre: string, venta: float, utilidad: float, margen: ?float, documentos: int, concentracion: ?float}> */
    public function topClientes(int $anio, ?int $mes = null, int $limite = 10): array
    {
        return self::top(self::delMes($this->ventasDelAnio($anio), $mes), $limite);
    }

    /** Top de clientes de cualquier rango de fechas (p. ej. una semana). */
    public function topClientesRango(Carbon $desde, Carbon $hasta, int $limite = 5): array
    {
        return self::top($this->ventasRango($desde, $hasta), $limite);
    }

    // ------------------------------------------------------------------ corte de cualquier periodo (semana, mes o año)

    private function ventasRango(Carbon $desde, Carbon $hasta): Collection
    {
        return Venta::vigentes()->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString().' 23:59:59'])
            ->get(['id', 'cliente_id', 'cliente_nombre', 'fecha', 'subtotal', 'costo', 'total', 'estatus', 'fecha_pago']);
    }

    private function gastosRango(Carbon $desde, Carbon $hasta): Collection
    {
        return Gasto::reales()->where('de', 'masume')
            ->where(fn ($q) => $q->whereNull('categoria')->orWhereNotIn('categoria', Gasto::NO_OPERATIVOS))
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString().' 23:59:59'])
            ->get(['id', 'fecha', 'monto', 'categoria']);
    }

    /**
     * El corte de un periodo: venta − compra = bruta − gastos = neta (sin IVA), lo cobrado en el periodo (con IVA, por
     * fecha de pago), comisiones pagadas y documentos emitidos.
     *
     * @return array{venta: float, compra: float, bruta: float, gastos: float, neta: float, margen_bruto: ?float, margen_neto: ?float, cobrado: float, comisiones: float, documentos: int}
     */
    public function corte(Carbon $desde, Carbon $hasta): array
    {
        $v = $this->ventasRango($desde, $hasta);
        $venta = round((float) $v->sum('subtotal'), 2);
        $compra = round((float) $v->sum('costo'), 2);
        $bruta = round($venta - $compra, 2);
        $gastos = round((float) $this->gastosRango($desde, $hasta)->sum('monto'), 2);
        $neta = round($bruta - $gastos, 2);
        $cobrado = (float) Venta::vigentes()->where('estatus', 'pagada')
            ->whereBetween('fecha_pago', [$desde->toDateString(), $hasta->toDateString().' 23:59:59'])->sum('total');
        $comisiones = (float) NominaLinea::query()->whereHas('nomina', fn ($q) => $q->where('tipo', 'semanal')->where('estatus', 'pagada')
            ->whereBetween('hasta', [$desde->toDateString(), $hasta->toDateString().' 23:59:59']))->sum('comision');

        return [
            'venta' => $venta, 'compra' => $compra, 'bruta' => $bruta, 'gastos' => $gastos, 'neta' => $neta,
            'margen_bruto' => self::pct($bruta, $venta), 'margen_neto' => self::pct($neta, $venta),
            'cobrado' => round($cobrado, 2), 'comisiones' => round($comisiones, 2), 'documentos' => $v->count(),
        ];
    }

    /**
     * Las 12 semanas (lunes–sábado; la venta del domingo cuenta para la siguiente) que terminan en el sábado dado.
     *
     * @return list<array{sabado: Carbon, etiqueta: string, venta: float, neta: float}>
     */
    public function semanasHasta(Carbon $sabado, int $cuantas = 12): array
    {
        $desde = $sabado->copy()->subWeeks($cuantas - 1)->subDays(6);
        $ventas = $this->ventasRango($desde, $sabado)->groupBy(fn ($f) => Semanas::sabado($f->fecha)->toDateString());
        $gastos = $this->gastosRango($desde, $sabado)->groupBy(fn ($f) => Semanas::sabado($f->fecha)->toDateString());
        $filas = [];
        for ($s = $sabado->copy()->subWeeks($cuantas - 1); $s->lte($sabado); $s->addWeek()) {
            $v = $ventas->get($s->toDateString(), collect());
            $venta = round((float) $v->sum('subtotal'), 2);
            $filas[] = [
                'sabado' => $s->copy(), 'venta' => $venta,
                'neta' => round($venta - (float) $v->sum('costo') - (float) $gastos->get($s->toDateString(), collect())->sum('monto'), 2),
                'etiqueta' => $s->copy()->subDays(5)->day.' '.strtolower(self::MESES[$s->copy()->subDays(5)->month - 1]),
            ];
        }

        return $filas;
    }

    /** @return list<array{nombre: string, venta: float, utilidad: float, margen: ?float, documentos: int, concentracion: ?float}> */
    private static function top(Collection $v, int $limite): array
    {
        $total = (float) $v->sum('subtotal');

        return $v->groupBy(fn ($x) => self::clave($x))->map(function (Collection $g) use ($total) {
            $venta = round((float) $g->sum('subtotal'), 2);
            $utilidad = round($venta - (float) $g->sum('costo'), 2);

            return ['nombre' => (string) ($g->first()->cliente_nombre ?: 'Sin nombre'), 'venta' => $venta, 'utilidad' => $utilidad,
                'margen' => self::pct($utilidad, $venta), 'documentos' => $g->count(), 'concentracion' => self::pct($venta, $total)];
        })->sortByDesc('venta')->take($limite)->values()->all();
    }

    /** @return array{filas: list<array>, total: array} los 12 meses del año */
    public function mensual(int $anio): array
    {
        $filas = [];
        for ($m = 1; $m <= 12; $m++) {
            $filas[] = ['mes' => $m] + $this->fila($anio, $m);
        }
        $venta = array_sum(array_column($filas, 'venta'));
        $compra = array_sum(array_column($filas, 'compra'));
        $gastos = array_sum(array_column($filas, 'gastos'));

        return ['filas' => $filas, 'total' => ['mes' => 0] + self::armarFila($venta, $compra, $gastos)];
    }

    private function fila(int $anio, int $mes): array
    {
        $v = self::delMes($this->ventasDelAnio($anio), $mes);

        return self::armarFila((float) $v->sum('subtotal'), (float) $v->sum('costo'), (float) self::delMes($this->gastosDelAnio($anio), $mes)->sum('monto'));
    }

    private static function armarFila(float $venta, float $compra, float $gastos): array
    {
        $utilidad = round($venta - $compra, 2);

        return ['venta' => round($venta, 2), 'compra' => round($compra, 2), 'utilidad' => $utilidad, 'margen' => self::pct($utilidad, $venta),
            'gastos' => round($gastos, 2), 'neta' => round($utilidad - $gastos, 2)];
    }

    /** Venta mes a mes de un año (para la línea del año anterior). @return list<float> */
    public function ventaPorMes(int $anio): array
    {
        return array_map(fn ($m) => round((float) self::delMes($this->ventasDelAnio($anio), $m)->sum('subtotal'), 2), range(1, 12));
    }

    /**
     * Mismo periodo del año anterior. Para gastos, subir es empeorar.
     *
     * @return array<string, array{actual: float, anterior: float, cambio: ?float, mejora: ?bool}>
     */
    public function comparativo(int $anio, ?int $mes = null): array
    {
        if ($mes === null && $anio === (int) now()->year) {
            // Año en curso: se compara contra el año anterior hasta el mismo día (si no, siempre se vería bajo).
            $corte = now()->format('m-d');
            $hasta = fn (Collection $f) => $f->filter(fn ($x) => $x->fecha->format('m-d') <= $corte);
            $suma = function (int $y) use ($hasta) {
                $v = $hasta($this->ventasDelAnio($y));
                $venta = (float) $v->sum('subtotal');
                $bruta = $venta - (float) $v->sum('costo');
                $gastos = (float) $hasta($this->gastosDelAnio($y))->sum('monto');

                return ['venta' => round($venta, 2), 'bruta' => round($bruta, 2), 'gastos' => round($gastos, 2), 'neta' => round($bruta - $gastos, 2)];
            };
            $a = $suma($anio);
            $b = $suma($anio - 1);
        } else {
            $a = $this->resumen($anio, $mes);
            $b = $this->resumen($anio - 1, $mes);
        }
        $res = [];
        foreach (['venta' => 'Venta', 'bruta' => 'Utilidad bruta', 'gastos' => 'Gastos', 'neta' => 'Utilidad neta'] as $k => $nombre) {
            $dif = $a[$k] - $b[$k];
            $res[$k] = [
                'nombre' => $nombre, 'actual' => $a[$k], 'anterior' => $b[$k],
                // Con base negativa o cero el % no dice nada claro.
                'cambio' => $b[$k] > 0 ? round($dif / $b[$k] * 100, 1) : null,
                'mejora' => abs($dif) < 0.005 ? null : ($k === 'gastos' ? $dif < 0 : $dif > 0),
            ];
        }

        return $res;
    }

    /**
     * Semanas lunes–sábado (el domingo cuenta para la siguiente): las del mes, o las últimas 12 si es todo el año.
     *
     * @return list<array{sabado: Carbon, etiqueta: string, venta: float, utilidad: float, gastos: float}>
     */
    public function semanal(int $anio, ?int $mes = null): array
    {
        if ($mes !== null) {
            $primero = Carbon::create($anio, $mes, 1)->startOfDay();
            $desde = Semanas::sabado($primero);
            $hasta = Semanas::sabado($primero->copy()->endOfMonth()->startOfDay());
        } else {
            $hasta = $anio === (int) today()->year ? Semanas::sabado(today()) : Semanas::sabado(Carbon::create($anio, 12, 31));
            $desde = $hasta->copy()->subWeeks(11);
        }
        $filtro = fn (Collection $c) => $c->filter(fn ($f) => $mes === null || ($f->fecha->year === $anio && $f->fecha->month === $mes))
            ->groupBy(fn ($f) => Semanas::sabado($f->fecha)->toDateString());
        $ventas = $filtro($this->ventasDelAnio($anio)->concat($this->ventasDelAnio($anio - 1)));
        $gastos = $filtro($this->gastosDelAnio($anio)->concat($this->gastosDelAnio($anio - 1)));

        $filas = [];
        for ($s = $desde->copy(); $s->lte($hasta); $s->addWeek()) {
            $k = $s->toDateString();
            $v = $ventas->get($k, collect());
            $venta = round((float) $v->sum('subtotal'), 2);
            $filas[] = [
                'sabado' => $s->copy(), 'venta' => $venta, 'utilidad' => round($venta - (float) $v->sum('costo'), 2),
                'gastos' => round((float) $gastos->get($k, collect())->sum('monto'), 2),
                'etiqueta' => $s->day.' '.strtolower(self::MESES[$s->month - 1]),
            ];
        }

        return $filas;
    }

    /** @return list<array{nombre: string, monto: float}> top 8 de mayor a menor + "Otras" */
    public function gastosPorCategoria(int $anio, ?int $mes = null): array
    {
        $g = self::delMes($this->gastosDelAnio($anio), $mes)
            ->groupBy(fn ($x) => filled($x->categoria) ? $x->categoria : 'SIN CATEGORÍA')
            ->map(fn (Collection $c, string $nombre) => ['nombre' => $nombre, 'monto' => round((float) $c->sum('monto'), 2)])
            ->filter(fn ($x) => $x['monto'] > 0)->sortByDesc('monto')->values();
        $filas = $g->take(8)->all();
        if ($g->count() > 8) {
            $filas[] = ['nombre' => 'OTRAS', 'monto' => round((float) $g->slice(8)->sum('monto'), 2)];
        }

        return $filas;
    }

    /** Colores fijos por persona (orden de alta), validados para daltonismo; del 9.º en adelante van a "Otros". */
    public const COLORES_EMPLEADOS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

    /**
     * Costo de nómina por empleado y mes (nóminas semanales PAGADAS, por mes del sábado de pago). Costo = lo pagado + lo que
     * se le descontó de préstamos (el descuento es abono a su deuda, no ahorro de nómina). No incluye el reparto al socio.
     *
     * @return array{empleados: list<array{nombre: string, color: string, meses: list<float>, total: float}>, total_mes: list<float>, total: float}
     */
    public function nominaPorEmpleado(int $anio): array
    {
        $lineas = NominaLinea::query()
            ->join('nominas', 'nominas.id', '=', 'nomina_lineas.nomina_id')
            ->where('nominas.tipo', 'semanal')->where('nominas.estatus', 'pagada')
            ->whereBetween('nominas.hasta', [$anio.'-01-01', $anio.'-12-31 23:59:59'])
            ->get(['nomina_lineas.user_id', 'nomina_lineas.nombre', 'nomina_lineas.total', 'nomina_lineas.descuento_prestamo', 'nominas.hasta']);

        // Una persona = su usuario; las líneas viejas sin usuario se juntan por primer nombre.
        $grupos = $lineas->groupBy(fn ($l) => $l->user_id ? 'u'.$l->user_id : 'n'.mb_strtoupper(strtok(trim((string) $l->nombre), ' ') ?: '?'));
        $empleados = $grupos->map(function (Collection $ls, string $llave) {
            $meses = array_fill(0, 12, 0.0);
            foreach ($ls as $l) {
                $meses[Carbon::parse($l->hasta)->month - 1] += (float) $l->total + (float) $l->descuento_prestamo;
            }
            $nombre = mb_convert_case(mb_strtolower(strtok(trim((string) $ls->sortByDesc('hasta')->first()->nombre), ' ') ?: 'Sin nombre'), MB_CASE_TITLE);

            return ['llave' => $llave, 'orden' => $ls->min('user_id') ?? PHP_INT_MAX, 'nombre' => $nombre,
                'meses' => array_map(fn ($v) => round($v, 2), $meses)];
        })->filter(fn ($e) => array_sum($e['meses']) != 0.0)->sortBy([['orden', 'asc'], ['nombre', 'asc']])->values();

        if ($empleados->count() > 8) {
            $resto = $empleados->slice(7);
            $empleados = $empleados->take(7)->push(['nombre' => 'Otros', 'meses' => array_map(
                fn ($i) => round((float) $resto->sum(fn ($e) => $e['meses'][$i]), 2), range(0, 11))]);
        }
        $empleados = $empleados->values()->map(fn ($e, $i) => ['nombre' => $e['nombre'], 'color' => self::COLORES_EMPLEADOS[$i],
            'meses' => $e['meses'], 'total' => round(array_sum($e['meses']), 2)])->all();
        $totalMes = array_map(fn ($i) => round(array_sum(array_map(fn ($e) => $e['meses'][$i], $empleados)), 2), range(0, 11));

        return ['empleados' => $empleados, 'total_mes' => $totalMes, 'total' => round(array_sum($totalMes), 2)];
    }
}

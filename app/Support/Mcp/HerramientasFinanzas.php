<?php

namespace App\Support\Mcp;

use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\Nomina;
use App\Models\Prestamo;
use App\Models\User;
use App\Models\Vacacion;
use App\Models\Venta;
use App\Support\CalculoNomina;
use App\Support\Patrimonio;
use App\Support\Semanas;
use App\Support\Tablero;
use App\Support\Vacaciones;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Consultas SOLO LECTURA de finanzas y personal para el MCP (cobranza, nómina, gastos, dashboard, mis finanzas,
 * vacaciones). Reutilizan los mismos cálculos que las pantallas; nunca crean ni cambian nada (la nómina de una semana
 * sin abrir se calcula como vista previa, sin guardarla).
 */
final class HerramientasFinanzas
{
    /** @return list<array> */
    public static function definiciones(): array
    {
        $texto = fn (string $d) => ['type' => 'string', 'description' => $d];
        $entero = fn (string $d) => ['type' => 'integer', 'description' => $d];
        $lectura = ['readOnlyHint' => true, 'openWorldHint' => false];

        return [
            ['name' => 'cobranza', 'title' => 'Cobranza (quién me debe)',
                'description' => 'Cartera por cobrar con IVA: total, vencido, por vencer en 7 y 14 días y lo que debe cada cliente (vencido y su factura más atrasada). '
                    .'Con "cliente" devuelve sus documentos pendientes uno por uno (folio, fecha, vence, días de atraso, monto, portal).',
                'inputSchema' => ['type' => 'object', 'properties' => ['cliente' => $texto('Parte del nombre del cliente (opcional)'), 'limite' => $entero('Clientes a listar, máximo 100 (30 por defecto)')]],
                'annotations' => $lectura],
            ['name' => 'nomina', 'title' => 'Nómina y comisiones',
                'description' => 'Nómina semanal (lunes a sábado): utilidad cobrada, bolsa de comisión (10%) y por empleado sueldo, comisión, descuento de préstamo y total. '
                    .'Sin semana = la actual; si aún no se abre en el sistema se calcula como vista previa. También préstamos activos con su saldo y las nóminas pagadas del año.',
                'inputSchema' => ['type' => 'object', 'properties' => ['anio' => $entero('Año (actual por defecto)'), 'semana' => $entero('Número de semana (la actual por defecto)')]],
                'annotations' => $lectura],
            ['name' => 'gastos', 'title' => 'Gastos',
                'description' => 'Gastos reales (sin movimientos entre cuentas) de un mes o año: total, por categoría y por de quién (MASUME o personal). '
                    .'Con "detalle" lista cada gasto (fecha, monto, categoría, descripción, cuenta).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'anio' => $entero('Año (actual por defecto)'), 'mes' => $entero('Mes 1-12 (opcional; sin mes = todo el año)'),
                    'de' => ['type' => 'string', 'enum' => ['masume', 'personal']], 'categoria' => $texto('Categoría (opcional)'),
                    'texto' => $texto('Buscar en la descripción (opcional)'), 'detalle' => ['type' => 'boolean', 'description' => 'Listar cada gasto (máx. 200)'],
                ]], 'annotations' => $lectura],
            ['name' => 'tablero', 'title' => 'Dashboard del negocio',
                'description' => 'Métricas del año o de un mes: venta y compra sin IVA, utilidad bruta y neta, margen, gastos, cobrado, pendiente, ticket, días de cobro, '
                    .'comparativo contra el año anterior, top 10 clientes y desglose mes por mes.',
                'inputSchema' => ['type' => 'object', 'properties' => ['anio' => $entero('Año (actual por defecto)'), 'mes' => $entero('Mes 1-12 (opcional)')]],
                'annotations' => $lectura],
            ['name' => 'mis_finanzas', 'title' => 'Mis finanzas (patrimonio)',
                'description' => 'Patrimonio de Daniel con el último saldo capturado de cada cuenta: disponible, me deben, cartera MASUME, tarjetas y créditos, neto; '
                    .'tarjetas con corte, fecha de pago, días que faltan, pago para no generar intereses y uso de la línea (semáforo).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass], 'annotations' => $lectura],
            ['name' => 'vacaciones', 'title' => 'Vacaciones del equipo',
                'description' => 'Por empleado: antigüedad, días que le tocan en su periodo, usados y saldo; y las vacaciones registradas de un mes (o las próximas).',
                'inputSchema' => ['type' => 'object', 'properties' => ['empleado' => $texto('Nombre (opcional)'), 'anio' => $entero('Año del mes a listar (opcional)'), 'mes' => $entero('Mes 1-12 a listar (opcional; sin mes = próximas)')]],
                'annotations' => $lectura],
        ];
    }

    public static function maneja(string $nombre): bool
    {
        return in_array($nombre, ['cobranza', 'nomina', 'gastos', 'tablero', 'mis_finanzas', 'vacaciones'], true);
    }

    public static function ejecutar(string $nombre, array $a): array
    {
        return self::plano(match ($nombre) {
            'cobranza' => self::cobranza($a),
            'nomina' => self::nomina($a),
            'gastos' => self::gastos($a),
            'tablero' => self::tablero($a),
            'mis_finanzas' => self::misFinanzas(),
            'vacaciones' => self::vacaciones($a),
        });
    }

    /** Fechas a texto y modelos a arreglo para que todo salga como JSON simple. */
    private static function plano(mixed $v): mixed
    {
        return match (true) {
            $v instanceof CarbonInterface => $v->toDateString(),
            $v instanceof Collection => self::plano($v->all()),
            is_array($v) => array_map(fn ($x) => self::plano($x), $v),
            is_float($v) => round($v, 2),
            default => $v,
        };
    }

    private static function anio(array $a): int
    {
        $anio = (int) ($a['anio'] ?? 0);

        return $anio >= 2000 && $anio <= 2100 ? $anio : (int) today()->year;
    }

    private static function mes(array $a): ?int
    {
        $mes = (int) ($a['mes'] ?? 0);

        return $mes >= 1 && $mes <= 12 ? $mes : null;
    }

    // ---------------------------------------------------------------- cobranza

    private static function cobranza(array $a): array
    {
        $hoy = today();
        $p = Venta::query()->where('estatus', 'pendiente')->where('total', '>', 0)->with('cliente:id,nombre,dias_credito')->get()
            ->map(function (Venta $v) use ($hoy) {
                $vence = $v->vence();

                return ['v' => $v, 'vence' => $vence, 'vencida' => $vence->lt($hoy), 'dias' => (int) abs($vence->diffInDays($hoy)), 'monto' => (float) $v->total];
            });
        $nombre = fn ($x) => $x['v']->cliente?->nombre ?? $x['v']->cliente_nombre;
        $filtro = mb_strtoupper(trim((string) ($a['cliente'] ?? '')));
        if ($filtro !== '') {
            $p = $p->filter(fn ($x) => str_contains(mb_strtoupper((string) $nombre($x)), $filtro));
        }
        $porVencer = fn (int $d) => round($p->filter(fn ($x) => ! $x['vencida'] && $x['vence']->lte($hoy->copy()->addDays($d)))->sum('monto'), 2);
        $clientes = $p->groupBy(fn ($x) => $x['v']->cliente_id ? 'c'.$x['v']->cliente_id : 'n'.$x['v']->cliente_nombre)
            ->map(fn (Collection $g) => [
                'cliente' => $nombre($g->first()), 'debe' => round($g->sum('monto'), 2), 'vencido' => round($g->where('vencida', true)->sum('monto'), 2),
                'documentos' => $g->count(), 'dias_credito' => $g->first()['v']->diasCredito(), 'mas_atrasada_dias' => (int) $g->where('vencida', true)->max('dias'),
            ])->sortByDesc('debe')->values()->take(min(100, max(1, (int) ($a['limite'] ?? 30))));

        $r = [
            'hoy' => $hoy, 'por_cobrar' => round($p->sum('monto'), 2), 'documentos' => $p->count(),
            'vencido' => round($p->where('vencida', true)->sum('monto'), 2), 'documentos_vencidos' => $p->where('vencida', true)->count(),
            'vence_7_dias' => $porVencer(7), 'vence_14_dias' => $porVencer(14), 'clientes' => $clientes,
        ];
        if ($filtro !== '') {
            $r['documentos_pendientes'] = $p->sortBy(fn ($x) => $x['vence']->format('Ymd'))->values()->map(fn ($x) => [
                'folio' => $x['v']->folio, 'tipo' => $x['v']->tipo, 'cliente' => $nombre($x), 'fecha' => $x['v']->fecha, 'vence' => $x['vence'],
                'vencida' => $x['vencida'], 'dias' => $x['dias'], 'monto' => $x['monto'], 'portal' => Venta::PORTAL[$x['v']->estatus_portal] ?? null,
            ]);
        }

        return $r;
    }

    // ---------------------------------------------------------------- nómina

    private static function nomina(array $a): array
    {
        [$anioHoy, $semanaHoy] = Semanas::deFecha(today());
        $anio = isset($a['anio']) ? self::anio($a) : $anioHoy;
        $semana = (int) ($a['semana'] ?? 0) ?: ($anio === $anioHoy ? $semanaHoy : 1);
        [$lunes, $sabado] = Semanas::rango($anio, $semana);
        $n = Nomina::with('lineas')->where('tipo', 'semanal')->where('anio', $anio)->where('semana', $semana)->first();

        if ($n) {
            $semanaInfo = ['estatus' => $n->estatus, 'utilidad_cobrada' => (float) $n->utilidad, 'bolsa_comision' => (float) $n->bolsa, 'total' => $n->total(),
                'lineas' => $n->lineas->map(fn ($l) => ['empleado' => $l->nombre, 'sueldo_base' => (float) $l->sueldo_base, 'comision_pct' => (float) $l->comision_pct,
                    'comision' => (float) $l->comision, 'ajuste' => (float) $l->ajuste, 'descuento_prestamo' => (float) $l->descuento_prestamo, 'total' => (float) $l->total])];
            $porCuenta = $n->pagada() && $n->lineas->contains(fn ($l) => ! $l->cuenta_id) ? collect() : CalculoNomina::porCuenta($n);
        } else {
            // Vista previa sin guardar nada.
            $utilidad = CalculoNomina::utilidad(CalculoNomina::cobros($anio, $semana));
            $bolsa = round(max(0, $utilidad) * CalculoNomina::COMISION, 2);
            $lineas = User::where('en_nomina', true)->where('activo', true)->whereNull('eliminado_at')->orderBy('id')->get()
                ->map(function (User $u) use ($bolsa) {
                    $comision = round($bolsa * (float) $u->comision_pct / 100, 2);
                    $desc = CalculoNomina::descuentoSugerido($u->id);

                    return ['empleado' => $u->nombreReal(), 'sueldo_base' => (float) $u->sueldo_base, 'comision_pct' => (float) $u->comision_pct,
                        'comision' => $comision, 'descuento_prestamo' => $desc, 'total' => round((float) $u->sueldo_base + $comision - $desc, 2)];
                });
            $semanaInfo = ['estatus' => 'vista previa (aún no se abre en el sistema)', 'utilidad_cobrada' => $utilidad, 'bolsa_comision' => $bolsa,
                'total' => round($lineas->sum('total'), 2), 'lineas' => $lineas];
            $porCuenta = CalculoNomina::estimadoPorCuenta($anio, $semana);
        }
        // De qué cuenta sale (BBVA, Hey Banco…): la elegida para cada empleado o la general de la nómina.
        $semanaInfo['sale_de'] = $porCuenta->map(fn ($g) => ['cuenta' => $g['cuenta']?->nombre ?? 'Cuenta general', 'monto' => $g['monto'], 'empleados' => $g['nombres']])->values();

        return [
            'semana' => $semana, 'anio' => $anio, 'etiqueta' => Semanas::etiqueta($anio, $semana), 'desde' => $lunes, 'hasta' => $sabado,
            'comisiones_desde' => CalculoNomina::desde(),
        ] + $semanaInfo + [
            'prestamos_activos' => Prestamo::activos()->get()->map(fn (Prestamo $p) => ['empleado' => $p->nombre ?: User::find($p->user_id)?->nombreReal(),
                'monto' => (float) $p->monto, 'saldo' => $p->saldo(), 'descuento_semanal' => (float) $p->descuento_semanal, 'fecha' => $p->fecha]),
            'pagadas_del_anio' => Nomina::where('tipo', 'semanal')->where('anio', $anio)->where('estatus', 'pagada')->orderBy('semana')->with('lineas')->get()
                ->map(fn (Nomina $x) => ['semana' => $x->semana, 'hasta' => $x->hasta, 'total' => $x->total(), 'comision' => (float) $x->lineas->sum('comision')]),
        ];
    }

    // ---------------------------------------------------------------- gastos

    private static function gastos(array $a): array
    {
        $anio = self::anio($a);
        $mes = self::mes($a);
        $desde = Carbon::create($anio, $mes ?? 1, 1);
        $hasta = $mes ? $desde->copy()->endOfMonth() : Carbon::create($anio, 12, 31);
        $q = Gasto::reales()->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString().' 23:59:59']);
        if (in_array($a['de'] ?? null, ['masume', 'personal'], true)) {
            $q->where('de', $a['de']);
        }
        if (filled($a['categoria'] ?? null)) {
            $q->where('categoria', mb_strtoupper(trim((string) $a['categoria'])));
        }
        if (filled($a['texto'] ?? null)) {
            $q->where('descripcion', 'like', '%'.str_replace(['%', '_'], '', (string) $a['texto']).'%');
        }
        $filas = $q->with('cuenta:id,nombre')->orderBy('fecha')->get();

        $r = [
            'periodo' => $mes ? sprintf('%02d/%d', $mes, $anio) : (string) $anio, 'total' => round((float) $filas->sum('monto'), 2), 'gastos' => $filas->count(),
            'por_categoria' => $filas->groupBy(fn ($g) => $g->categoria ?: 'SIN CATEGORÍA')->map(fn ($g, $k) => ['categoria' => $k, 'total' => round((float) $g->sum('monto'), 2), 'gastos' => $g->count()])
                ->sortByDesc('total')->values(),
            'por_de' => $filas->groupBy('de')->map(fn ($g, $k) => ['de' => $k, 'total' => round((float) $g->sum('monto'), 2)])->values(),
            'nota' => 'La compra de vehículos/equipo y el reparto al socio no cuentan como gasto operativo en el Dashboard.',
        ];
        if (! empty($a['detalle'])) {
            $r['detalle'] = $filas->take(200)->map(fn ($g) => ['fecha' => $g->fecha, 'monto' => (float) $g->monto, 'de' => $g->de, 'categoria' => $g->categoria,
                'descripcion' => $g->descripcion, 'cuenta' => $g->cuenta?->nombre])->values();
        }

        return $r;
    }

    // ---------------------------------------------------------------- dashboard

    private static function tablero(array $a): array
    {
        $anio = self::anio($a);
        $mes = self::mes($a);
        $t = new Tablero;
        $mensual = $t->mensual($anio);

        return [
            'periodo' => $mes ? sprintf('%02d/%d', $mes, $anio) : (string) $anio,
            'nota' => 'Venta = subtotal sin IVA de facturas y notas no canceladas por fecha de factura; compra = costo; bruta = venta − compra; neta = bruta − gastos operativos MASUME.',
            'resumen' => $t->resumen($anio, $mes),
            'comparativo_anio_anterior' => $t->comparativo($anio, $mes),
            'top_clientes' => $t->topClientes($anio, $mes),
            'meses' => array_map(fn ($f) => ['mes' => Tablero::MESES[$f['mes'] - 1]] + $f, $mensual['filas']),
            'total_anio' => $mensual['total'],
        ];
    }

    // ---------------------------------------------------------------- mis finanzas

    private static function misFinanzas(): array
    {
        $ultimos = Patrimonio::ultimosSaldos();
        $cuentas = Cuenta::where('activa', true)->orderBy('orden')->orderBy('nombre')->get()
            ->map(fn ($c) => ['cuenta' => $c->nombre, 'tipo' => $c->tipo, 'de' => $c->de, 'saldo' => isset($ultimos[$c->id]) ? (float) $ultimos[$c->id]->saldo : null,
                'saldo_al' => $ultimos[$c->id]->corte->fecha ?? null]);

        return [
            'resumen' => Patrimonio::resumen(),
            'formula' => 'neto = disponible + me deben + cartera MASUME − tarjetas − créditos − proveedores (compras a crédito pendientes)',
            'cuentas' => $cuentas,
            'tarjetas' => Patrimonio::tarjetas()->map(fn ($t) => [
                'tarjeta' => $t['cuenta']->nombre, 'debo' => $t['saldo'], 'limite' => $t['limite'], 'corte' => $t['corte'], 'pago' => $t['pago'],
                'dias_para_pagar' => $t['dias_pago'], 'pago_sin_intereses' => $t['pago_sin_intereses'], 'fecha_limite' => $t['fecha_limite'],
                'uso_pct' => $t['utilizacion'] !== null ? round($t['utilizacion'] * 100, 1) : null, 'semaforo' => $t['semaforo']['texto'] ?? ($t['semaforo']['etiqueta'] ?? null),
                'disponible_al_70' => $t['disponible70'],
            ]),
        ];
    }

    // ---------------------------------------------------------------- vacaciones

    private static function vacaciones(array $a): array
    {
        $equipo = User::where('activo', true)->whereNull('eliminado_at')->where('rol', '!=', 'nedtracking')->orderBy('name')->get();
        $filtro = mb_strtoupper(trim((string) ($a['empleado'] ?? '')));
        if ($filtro !== '') {
            $equipo = $equipo->filter(fn (User $u) => str_contains(mb_strtoupper($u->nombreReal()), $filtro))->values();
        }
        $mes = self::mes($a);
        $q = Vacacion::with('usuario')->where('tipo', 'tomadas')->where('estatus', 'registrada')->whereIn('user_id', $equipo->pluck('id'));
        if ($mes) {
            $ini = Carbon::create(self::anio($a), $mes, 1);
            $q->where('desde', '<=', $ini->copy()->endOfMonth()->toDateString())->where('hasta', '>=', $ini->toDateString());
        } else {
            $q->where('hasta', '>=', today()->toDateString());
        }

        return [
            'empleados' => $equipo->map(function (User $u) {
                $r = Vacaciones::resumen($u);

                return ['empleado' => $u->nombreReal(), 'antiguedad' => Vacaciones::antiguedadTexto($u), 'fecha_ingreso' => $u->fecha_ingreso,
                    'calculable' => $r['calculable'], 'aviso' => $r['mensaje'], 'periodo_desde' => $r['desde'], 'periodo_hasta' => $r['hasta'],
                    'dias_del_periodo' => $r['dias_periodo'], 'usados' => $r['usados'], 'ajustes' => $r['ajustes'], 'saldo' => $r['saldo'],
                    'proximo_aniversario' => $r['proximo_aniversario'], 'dias_al_proximo_aniversario' => $r['proximos_dias'], 'trabaja_sabados' => (bool) $u->trabaja_sabados];
            })->values(),
            ($mes ? 'vacaciones_del_mes' : 'proximas_vacaciones') => $q->orderBy('desde')->get()->map(fn (Vacacion $v) => [
                'empleado' => $v->usuario?->nombreReal(), 'desde' => $v->desde, 'hasta' => $v->hasta, 'dias' => (float) $v->dias, 'notas' => $v->notas]),
        ];
    }
}

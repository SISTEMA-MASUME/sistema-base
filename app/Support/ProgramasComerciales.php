<?php

namespace App\Support;

use App\Enums\EstadoOrdenProveedor;
use App\Models\ListaPrecio;
use App\Models\OrdenProveedor;
use App\Models\PedidoPartida;
use App\Models\ProgramaAjuste;
use App\Models\ProgramaComercial;
use Illuminate\Support\Carbon;

/**
 * Programa comercial de un proveedor (Urrea M1 2026). Lo que se le compra en un trimestre (sin IVA) define el nivel del
 * trimestre SIGUIENTE: descuento en factura (0, 5 o 10%) y bono de volumen (1–5% + acelerador por SKUs, tope 8%).
 * La cuenta sale de las OC al proveedor que no se cancelaron, más ajustes a mano (compras fuera del sistema o notas
 * de crédito en negativo). Es una estimación: lo que manda es lo que Urrea facture.
 */
final class ProgramasComerciales
{
    /** Programa comercial M1 2026 de Urrea (PDF que compartió Daniel el 18-oct-2026). */
    public const URREA_M1 = [
        'nombre' => 'Urrea M1 2026 (mostrador con venta al detalle)',
        'descuento_base' => 20,
        'anticipado' => 3,
        'niveles' => [
            ['desde' => 0, 'factura' => 0, 'bono' => 1], ['desde' => 10000, 'factura' => 0, 'bono' => 2],
            ['desde' => 20000, 'factura' => 0, 'bono' => 3], ['desde' => 30000, 'factura' => 0, 'bono' => 4],
            ['desde' => 40000, 'factura' => 0, 'bono' => 5], ['desde' => 50000, 'factura' => 5, 'bono' => 1],
            ['desde' => 65000, 'factura' => 5, 'bono' => 2], ['desde' => 80000, 'factura' => 5, 'bono' => 3],
            ['desde' => 95000, 'factura' => 5, 'bono' => 4], ['desde' => 110000, 'factura' => 5, 'bono' => 5],
            ['desde' => 130000, 'factura' => 10, 'bono' => 1], ['desde' => 170000, 'factura' => 10, 'bono' => 2],
            ['desde' => 210000, 'factura' => 10, 'bono' => 3], ['desde' => 250000, 'factura' => 10, 'bono' => 4],
            ['desde' => 300000, 'factura' => 10, 'bono' => 5],
        ],
        'acelerador' => [['skus' => 200, 'bono' => 1], ['skus' => 450, 'bono' => 2], ['skus' => 700, 'bono' => 3]],
        'acelerador_desde' => 20000,
        'bono_maximo' => 8,
    ];

    /** "2026-4" = cuarto trimestre de 2026. */
    public static function claveTrimestre(Carbon $fecha): string
    {
        return $fecha->year.'-'.$fecha->quarter;
    }

    /** Trimestre natural de una fecha: [inicio, fin, número]. */
    public static function trimestre(Carbon $fecha): array
    {
        $inicio = $fecha->copy()->firstOfQuarter()->startOfDay();

        return [$inicio, $inicio->copy()->lastOfQuarter()->endOfDay(), (int) $fecha->quarter];
    }

    /**
     * Lo comprado al proveedor en un trimestre (sin IVA): OC no canceladas + compras anotadas a mano. Si se anotó el total
     * que dice el portal del proveedor (Urreanet) a una fecha, ese manda y solo se le suma lo comprado después de esa fecha.
     */
    public static function consumo(ProgramaComercial $p, Carbon $desde, Carbon $hasta): float
    {
        $oficial = self::totalOficial($p, $desde, $hasta);
        $desdeCompras = $oficial ? $oficial->fecha->copy()->addDay()->startOfDay() : $desde;
        $oc = (float) OrdenProveedor::where('proveedor_id', $p->proveedor_id)->where('estado', '!=', EstadoOrdenProveedor::Cancelada->value)
            ->whereBetween('created_at', [$desdeCompras, $hasta])->sum('total_costo');
        $ajustes = (float) ProgramaAjuste::where('programa_comercial_id', $p->id)->where('tipo', 'compra')
            ->where('fecha', '>=', $desdeCompras->toDateString())->where('fecha', '<', $hasta->copy()->addDay()->toDateString())->sum('monto');

        return round(($oficial ? (float) $oficial->monto : 0) + $oc + $ajustes, 2);
    }

    /** El último total del portal del proveedor anotado dentro del trimestre (o null). */
    public static function totalOficial(ProgramaComercial $p, Carbon $desde, Carbon $hasta): ?ProgramaAjuste
    {
        return ProgramaAjuste::where('programa_comercial_id', $p->id)->where('tipo', 'total')
            // Comparar contra el día siguiente: SQLite guarda la fecha con hora ("2026-09-30 00:00:00").
            ->where('fecha', '>=', $desde->toDateString())->where('fecha', '<', $hasta->copy()->addDay()->toDateString())->orderByDesc('fecha')->orderByDesc('id')->first();
    }

    /** El nivel que da un consumo trimestral: ['desde', 'factura', 'bono']. */
    public static function nivel(ProgramaComercial $p, float $consumo): array
    {
        $nivel = ['desde' => 0, 'factura' => 0, 'bono' => 0];
        foreach ($p->niveles as $n) {
            if ($consumo >= (float) $n['desde']) {
                $nivel = $n;
            }
        }

        return $nivel;
    }

    /** Siguiente escalón que sube el descuento en factura (o null si ya está en el más alto). */
    public static function siguienteFactura(ProgramaComercial $p, float $consumo): ?array
    {
        $actual = self::nivel($p, $consumo)['factura'];
        foreach ($p->niveles as $n) {
            if ((float) $n['factura'] > (float) $actual) {
                return $n + ['falta' => round((float) $n['desde'] - $consumo, 2)];
            }
        }

        return null;
    }

    /** Lo que falta para que el próximo trimestre siga el descuento en factura que se tiene hoy (o null si ya se alcanzó). */
    public static function mantener(ProgramaComercial $p, float $facturaHoy, float $consumo): ?array
    {
        if ($facturaHoy <= 0) {
            return null;
        }
        foreach ($p->niveles as $n) {
            if ((float) $n['factura'] >= $facturaHoy) {
                return $consumo < (float) $n['desde'] ? $n + ['falta' => round((float) $n['desde'] - $consumo, 2)] : null;
            }
        }

        return null;
    }

    /** Siguiente escalón de bono de volumen (o null). */
    public static function siguienteBono(ProgramaComercial $p, float $consumo): ?array
    {
        foreach ($p->niveles as $n) {
            if ((float) $n['desde'] > $consumo) {
                return $n + ['falta' => round((float) $n['desde'] - $consumo, 2)];
            }
        }

        return null;
    }

    /** Productos distintos comprados al proveedor en los últimos 12 meses (para el acelerador). Aproximado. */
    public static function skus(ProgramaComercial $p, ?Carbon $hoy = null): int
    {
        $hoy ??= now();

        return PedidoPartida::where('proveedor_id', $p->proveedor_id)->whereNotNull('articulo_id')
            ->where('created_at', '>=', $hoy->copy()->subYear())->distinct()->count('articulo_id');
    }

    public static function acelerador(ProgramaComercial $p, int $skus, float $consumo): float
    {
        if ($consumo < (float) $p->acelerador_desde) {
            return 0;
        }
        $bono = 0;
        foreach ($p->acelerador ?? [] as $a) {
            if ($skus >= (int) $a['skus']) {
                $bono = (float) $a['bono'];
            }
        }

        return $bono;
    }

    /**
     * Descuentos que tocan en factura, como se escriben en la lista: "20 + 10" (y "+ 2" del bono de volumen si el proveedor
     * se lo da en factura en lugar de nota de crédito).
     */
    public static function descuentosLista(ProgramaComercial $p, float $factura, float $bono = 0): string
    {
        $n = fn (float $v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        $partes = [$n((float) $p->descuento_base)];
        if ($factura > 0) {
            $partes[] = $n($factura);
        }
        if ($p->bono_en_factura && $bono > 0) {
            $partes[] = $n($bono);
        }

        return implode(' + ', $partes);
    }

    /**
     * Todo lo que se muestra: el trimestre pasado define lo que te toca hoy; lo que llevas en este define el siguiente.
     *
     * @return array<string, mixed>
     */
    public static function resumen(ProgramaComercial $p, ?Carbon $hoy = null): array
    {
        $hoy ??= now();
        [$inicio, $fin, $numero] = self::trimestre($hoy);
        [$inicioAnt, $finAnt, $numeroAnt] = self::trimestre($inicio->copy()->subDay());
        $actual = self::consumo($p, $inicio, $fin);
        $anterior = self::consumo($p, $inicioAnt, $finAnt);
        $skus = self::skus($p, $hoy);
        $nivelHoy = self::nivel($p, $anterior);
        $nivelSiguiente = self::nivel($p, $actual);
        $diasTotales = (int) $inicio->diffInDays($fin) + 1;
        $diasPasados = max(1, (int) $inicio->diffInDays($hoy->copy()->endOfDay()) + 1);
        $proyeccion = round($actual / $diasPasados * $diasTotales, 2);
        $bono = fn (array $n, float $consumo) => min((float) ($p->bono_maximo ?? 100), (float) $n['bono'] + self::acelerador($p, $skus, $consumo));
        $lista = ListaPrecio::where('proveedor_id', $p->proveedor_id)->first();
        // El bono que trae la factura real de este trimestre manda sobre el cálculo (que es aproximado).
        $bonoReal = $p->bono_en_factura && $p->bono_factura !== null && $p->bono_factura_trimestre === self::claveTrimestre($hoy);
        $bonoHoy = $bonoReal ? (float) $p->bono_factura : $bono($nivelHoy, $anterior);
        $descuentosHoy = self::descuentosLista($p, (float) $nivelHoy['factura'], $bonoHoy);

        return [
            'trimestre' => $numero, 'anio' => $inicio->year, 'inicio' => $inicio, 'fin' => $fin,
            'dias_restantes' => max(0, (int) $hoy->copy()->startOfDay()->diffInDays($fin->copy()->startOfDay())),
            'actual' => $actual, 'proyeccion' => $proyeccion,
            'anterior' => $anterior, 'trimestre_anterior' => $numeroAnt,
            'oficial_actual' => self::totalOficial($p, $inicio, $fin), 'oficial_anterior' => self::totalOficial($p, $inicioAnt, $finAnt),
            'nivel_hoy' => $nivelHoy, 'bono_hoy' => $bonoHoy, 'bono_real' => $bonoReal,
            'nivel_siguiente' => $nivelSiguiente, 'bono_siguiente' => $bonoSiguiente = $bono($nivelSiguiente, $actual),
            'descuentos_siguiente' => self::descuentosLista($p, (float) $nivelSiguiente['factura'], $bonoSiguiente),
            'siguiente_factura' => self::siguienteFactura($p, $actual),
            'siguiente_bono' => self::siguienteBono($p, $actual),
            'mantener' => self::mantener($p, (float) $nivelHoy['factura'], $actual),
            'skus' => $skus, 'acelerador' => self::acelerador($p, $skus, max($actual, (float) $p->acelerador_desde)),
            'descuentos_hoy' => $descuentosHoy,
            'lista' => $lista,
            // La lista se calculó con otros descuentos: hay que actualizarla (cambió el trimestre o el nivel).
            'lista_desactualizada' => $lista && ListasPrecios::descuentos($lista->descuentos)['texto'] !== ListasPrecios::descuentos($descuentosHoy)['texto']
                && ListasPrecios::descuentos($lista->descuentos)['texto'] !== ListasPrecios::descuentos($descuentosHoy.' + '.(float) $p->anticipado)['texto'],
        ];
    }

    /** Pone el programa M1 de Urrea a ese proveedor (o lo actualiza). */
    public static function usarUrreaM1(int $proveedorId): ProgramaComercial
    {
        return ProgramaComercial::updateOrCreate(['proveedor_id' => $proveedorId], self::URREA_M1);
    }
}

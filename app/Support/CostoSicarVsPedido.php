<?php

namespace App\Support;

use App\Models\Pedido;
use App\Models\Venta;
use Illuminate\Support\Collection;

/**
 * Costo de las facturas/notas de SICAR contra el costo del pedido (lo que se cotizó y se pidió al proveedor). Daniel
 * a veces baja el costo en SICAR al timbrar y quiere ver la diferencia; solo la ve el admin (24-oct-2026).
 *
 * Se compara por grupo: los pedidos ligados y TODAS sus facturas vigentes (una factura puede llevar varios pedidos y un
 * pedido varias facturas parciales). Del pedido cuentan las partidas marcadas "Facturada"; si no tiene ninguna, todas.
 */
final class CostoSicarVsPedido
{
    /** Diferencias menores no se enseñan (redondeos). */
    public const TOLERANCIA = 1.0;

    public static function dePedido(Pedido $pedido): ?array
    {
        return self::grupo(collect([$pedido]));
    }

    public static function deVenta(Venta $venta): ?array
    {
        return self::grupo($venta->pedidos()->get());
    }

    /** @param Collection<int, Pedido> $pedidos */
    private static function grupo(Collection $pedidos): ?array
    {
        if ($pedidos->isEmpty()) {
            return null;
        }
        $ventas = Venta::whereHas('pedidos', fn ($q) => $q->whereIn('pedidos.id', $pedidos->pluck('id')))
            ->where('estatus', '!=', 'cancelada')->orderBy('fecha')->orderBy('id')->get();
        if ($ventas->isEmpty()) {
            return null;
        }
        // Los otros pedidos que van en esas mismas facturas.
        $pedidos = Pedido::whereHas('ventas', fn ($q) => $q->whereIn('ventas.id', $ventas->pluck('id')))
            ->with('partidas:id,pedido_id,cantidad,costo,facturada')->orderBy('folio')->get();

        $costoPedido = 0.0;
        $facturadas = 0;
        $total = 0;
        foreach ($pedidos as $p) {
            $suyas = $p->partidas->contains('facturada', true) ? $p->partidas->where('facturada', true) : $p->partidas;
            $costoPedido += $suyas->sum(fn ($x) => (float) $x->costo * (float) $x->cantidad);
            $facturadas += $suyas->count();
            $total += $p->partidas->count();
        }
        $costoSicar = (float) $ventas->sum('costo');
        $diferencia = round($costoSicar - $costoPedido, 2);

        return [
            'sicar' => round($costoSicar, 2),
            'pedido' => round($costoPedido, 2),
            'diferencia' => $diferencia,
            'igual' => abs($diferencia) < self::TOLERANCIA,
            'facturas' => $ventas->pluck('folio')->all(),
            'pedidos' => $pedidos->map(fn ($p) => 'P-'.$p->folio)->all(),
            'partidas' => $facturadas,
            'partidas_total' => $total,
        ];
    }
}

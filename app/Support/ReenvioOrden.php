<?php

namespace App\Support;

use App\Enums\EstadoOrdenProveedor;
use App\Models\OrdenProveedor;
use App\Models\PedidoPartida;

/** Cuando cambia la cantidad de una partida que ya está en una OC a proveedor, la OC se corrige y, si ya salió, se avisa. */
class ReenvioOrden
{
    /**
     * Se quitó una partida de la OC (se borró del pedido): recalcula el total; si ya no le queda ninguna partida
     * la OC se cancela. Regresa el aviso para el usuario.
     */
    public static function alQuitarPartida(int $ordenId): ?string
    {
        $orden = OrdenProveedor::with('proveedor:id,nombre')->find($ordenId);
        if (! $orden || $orden->estado === EstadoOrdenProveedor::Cancelada) {
            return null;
        }
        $proveedor = $orden->proveedor->nombre ?? 'el proveedor';
        if (! $orden->partidas()->exists()) {
            $orden->update(['estado' => EstadoOrdenProveedor::Cancelada, 'total_costo' => 0]);
            CuentasPorPagar::sincronizarOrden($orden);

            return "La {$orden->folioTexto()} a {$proveedor} se quedó sin partidas y se canceló.";
        }
        $orden->total_costo = round((float) $orden->partidas()->get(['cantidad', 'costo'])->sum(fn ($p) => (float) $p->costo * (float) $p->cantidad), 2);
        $yaSalio = in_array($orden->estado, [EstadoOrdenProveedor::Enviada, EstadoOrdenProveedor::Recibida], true);
        if ($yaSalio) {
            $orden->requiere_reenvio = true;
        }
        $orden->save();
        CuentasPorPagar::sincronizarOrden($orden);

        return $yaSalio
            ? "Ojo: la {$orden->folioTexto()} a {$proveedor} ya se había enviado; avísale que esa partida ya no va."
            : "Se quitó también de la {$orden->folioTexto()}.";
    }

    /** @return string|null Aviso para mostrar al usuario, o null si la partida no está en una OC ya enviada. */
    public static function alCambiarPartida(PedidoPartida $partida): ?string
    {
        if (! $partida->orden_proveedor_id) {
            return null;
        }
        $orden = OrdenProveedor::with('proveedor:id,nombre')->find($partida->orden_proveedor_id);
        if (! $orden) {
            return null;
        }

        $total = 0.0;
        foreach ($orden->partidas()->get(['cantidad', 'costo']) as $p) {
            $total += (float) $p->costo * (float) $p->cantidad;
        }
        $orden->total_costo = round($total, 2);

        $yaSalio = in_array($orden->estado, [EstadoOrdenProveedor::Enviada, EstadoOrdenProveedor::Recibida], true);
        if ($yaSalio) {
            $orden->requiere_reenvio = true;
        }
        $orden->save();
        CuentasPorPagar::sincronizarOrden($orden);

        return $yaSalio
            ? 'Ojo: la '.$orden->folioTexto().' a '.($orden->proveedor->nombre ?? 'el proveedor').' ya se había enviado; vuelve a enviársela con la cantidad nueva.'
            : null;
    }
}

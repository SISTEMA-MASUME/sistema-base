<?php

namespace App\Support;

use App\Enums\EstadoOrdenProveedor;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * El proveedor cobró distinto a lo que decía la OC (p. ej. hizo un descuento al final): se captura el total real con IVA
 * y el costo de cada partida de la OC se ajusta en la misma proporción. Así la imagen/PDF que se manda a quien recoge y
 * paga trae el dato correcto, la utilidad del pedido se recalcula y, si el material aún no se da de alta en SICAR, el alta
 * sale con el costo nuevo (el alta lee pedido_partidas.costo al armarse).
 */
class CostoOrden
{
    /**
     * @return string Aviso para el usuario (incluye qué pasa con SICAR).
     *
     * @throws DomainException con mensaje entendible
     */
    public static function corregirTotal(OrdenProveedor $orden, float $totalConIva, string $motivo = ''): string
    {
        $iva = Precios::iva();
        $totalConIva = round($totalConIva, 2);
        if ($totalConIva <= 0) {
            throw new DomainException('Escribe el total que se pagó, con IVA.');
        }

        [$antes, $despues, $pedido] = DB::transaction(function () use ($orden, $totalConIva, $iva, $motivo) {
            $orden = OrdenProveedor::lockForUpdate()->findOrFail($orden->id);
            if ($orden->estado === EstadoOrdenProveedor::Cancelada) {
                throw new DomainException('La orden está cancelada: ya no se le puede cambiar el costo.');
            }
            $partidas = $orden->partidas()->get();
            $sinIva = $partidas->sum(fn ($p) => (float) $p->costo * (float) $p->cantidad);
            if ($partidas->isEmpty() || $sinIva <= 0) {
                throw new DomainException('Las partidas de esta orden no tienen costo; corrígelo en cada partida del pedido.');
            }
            $antes = round($sinIva * (1 + $iva), 2);
            if (abs($antes - $totalConIva) < 0.005) {
                throw new DomainException('Ese ya es el total de la orden.');
            }
            if ($totalConIva > $antes * 10) {
                throw new DomainException('El total nuevo es más de 10 veces el de la orden. Revisa la cantidad.');
            }

            $factor = ($totalConIva / (1 + $iva)) / $sinIva;
            foreach ($partidas as $p) {
                $p->update(['costo' => round((float) $p->costo * $factor, 4)]);
            }
            $nuevoSinIva = $partidas->sum(fn ($p) => (float) $p->costo * (float) $p->cantidad);
            $orden->update(['total_costo' => round($nuevoSinIva, 2)]);
            CuentasPorPagar::sincronizarOrden($orden);   // a crédito: lo que se debe también baja
            $pedido = $orden->pedido;
            $pedido->recalcularTotales();

            $motivo = trim($motivo);
            PedidoActividad::registrar($pedido, 'costo_oc', 'corrigió el costo de la '.$orden->folioTexto().' de $'.number_format($antes, 2)
                .' a $'.number_format($totalConIva, 2).' con IVA'.($motivo !== '' ? ' ('.$motivo.')' : ''));

            return [$antes, $totalConIva, $pedido];
        });

        return 'Listo: la orden pasó de $'.number_format($antes, 2).' a $'.number_format($despues, 2)
            .' con IVA y el costo de sus partidas quedó corregido. Vuelve a mandar la imagen a quien va a recoger. '.self::avisoSicar($pedido);
    }

    /** Qué pasa en SICAR con un cambio de costo del pedido. */
    public static function avisoSicar(Pedido $pedido): string
    {
        if ($pedido->alta_sicar) {
            return 'Ojo: este pedido ya se dio de alta en SICAR con el costo anterior; ahí hay que corregirlo a mano.';
        }
        if ((new PuenteSicar)->pedidosEnLoteAbierto([$pedido->id]) !== []) {
            return 'Ojo: hay un alta a SICAR en proceso con el costo anterior. Cancélala en "Lotes SICAR" y vuelve a hacerla para que entre con el costo nuevo.';
        }

        return 'Cuando se dé de alta en SICAR entrará con el costo nuevo.';
    }
}

<?php

namespace App\Support;

use App\Enums\EstadoCotizacion;
use App\Models\Cotizacion;
use App\Models\PedidoPartida;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Eliminar cotizaciones (solo el admin): borrado suave, se pueden recuperar desde la base. No se elimina la que ya
 * se volvió pedido (sus partidas están en una tarjeta). Si venía de "Por cotizar" y seguía en proceso, la solicitud
 * regresa a pendientes.
 */
class EliminarCotizacion
{
    /** Por qué no se puede eliminar, o null si sí se puede. */
    public static function impedimento(Cotizacion $cot): ?string
    {
        if ($cot->estado === EstadoCotizacion::Aceptada) {
            return "La cotización {$cot->folio} ya se aceptó (llegó OC).";
        }
        if (PedidoPartida::whereIn('cotizacion_partida_id', $cot->partidas()->select('id'))->exists()) {
            return "La cotización {$cot->folio} ya está en un pedido.";
        }

        return null;
    }

    /** @throws DomainException */
    public static function eliminar(Cotizacion $cot, User $quien): void
    {
        if (! $quien->esAdmin()) {
            throw new DomainException('Solo el administrador puede eliminar cotizaciones.');
        }
        if ($motivo = self::impedimento($cot)) {
            throw new DomainException($motivo.' No se puede eliminar.');
        }
        DB::transaction(function () use ($cot, $quien) {
            foreach (SolicitudCotizacion::where('cotizacion_id', $cot->id)->where('estatus', 'en_proceso')->get() as $s) {
                $s->update(['estatus' => 'pendiente', 'tomada_por' => null, 'tomada_at' => null, 'cotizacion_id' => null]);
                $s->anotar('soltada', $quien->nombreReal()." eliminó la cotización {$cot->folio}; la solicitud vuelve a pendientes.", $quien->id);
            }
            $cot->delete();
        });
    }
}

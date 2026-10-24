<?php

namespace App\Support;

use App\Models\Cotizacion;
use App\Models\Ned\NedCliente;
use App\Models\Ned\NedCotizacion;
use App\Models\Ned\NedPartida;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Mandar también el PDF de Ned Tracking": arma (o actualiza) la cotización de Ned de una cotización de MASUME con
 * la misma lista y precio = costo × (1 + 60%) en todas las partidas. Cada cotización de MASUME tiene a lo más una de
 * Ned ligada (ned_cotizaciones.cotizacion_id): al volver a mandarla se actualiza con el mismo folio de Ned.
 */
class NedDesdeCotizacion
{
    public const UTILIDAD = 60.0;

    public static function sincronizar(Cotizacion $cot, float $utilidad = self::UTILIDAD): NedCotizacion
    {
        $partidas = $cot->partidasIncluidas()->orderBy('orden')->get(['descripcion', 'cantidad', 'unidad', 'costo']);
        if ($partidas->isEmpty()) {
            throw new DomainException('La cotización no tiene partidas.');
        }
        $sinCosto = $partidas->filter(fn ($p) => (float) $p->costo <= 0);
        if ($sinCosto->isNotEmpty()) {
            throw new DomainException('Para el PDF de Ned falta el costo de: '
                .$sinCosto->map(fn ($p) => Str::limit((string) $p->descripcion, 40))->implode('; ').'.');
        }

        return DB::transaction(function () use ($cot, $partidas, $utilidad) {
            $ned = NedCotizacion::where('cotizacion_id', $cot->id)->first();
            if ($ned) {
                $ned->update(['fecha' => now()->toDateString()]);
                $ned->partidas()->delete();
            } else {
                $ned = NedCotizacion::create([
                    'folio' => NedCotizacion::siguienteFolio(),
                    'user_id' => auth()->id(),
                    'cotizacion_id' => $cot->id,
                    'fecha' => now()->toDateString(),
                ] + NedCliente::datosNuevaCotizacion());
            }
            $orden = 0;
            foreach ($partidas as $p) {
                $precio = Precios::precioDesdeUtilidad((float) $p->costo, $utilidad);
                NedPartida::create([
                    'ned_cotizacion_id' => $ned->id, 'orden' => ++$orden, 'descripcion' => $p->descripcion,
                    'cantidad' => $p->cantidad, 'unidad' => $p->unidad ?: 'PZA', 'costo' => $p->costo,
                    'precio' => $precio, 'importe' => Precios::importe($precio, (float) $p->cantidad),
                ]);
            }
            $ned->recalcularTotales();

            return $ned;
        });
    }
}

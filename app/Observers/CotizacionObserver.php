<?php

namespace App\Observers;

use App\Models\Cotizacion;
use App\Support\Bitacora;

class CotizacionObserver
{
    public function updated(Cotizacion $cotizacion): void
    {
        Bitacora::alCambiarEncabezado($cotizacion);
        $campos = array_diff(Bitacora::CAMPOS_ENCABEZADO, ['estado']);
        if ($cotizacion->revisada_at && $cotizacion->wasChanged($campos)) {
            $cotizacion->forceFill(['revisada_at' => null, 'revisada_por' => null])->saveQuietly();
            Bitacora::evento($cotizacion, 'revision_anulada', 'Se cambió el encabezado después de revisarla: hay que revisarla otra vez.');
        }
    }
}

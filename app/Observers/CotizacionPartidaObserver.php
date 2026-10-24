<?php

namespace App\Observers;

use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Support\Bitacora;
use App\Support\Limites;
use App\Support\Precios;
use Illuminate\Validation\ValidationException;

class CotizacionPartidaObserver
{
    /** Lo que, si cambia después de revisarla, obliga a revisarla otra vez (lo que ve el cliente o el costo). */
    private const CAMPOS_REVISADOS = ['cantidad', 'descripcion', 'unidad', 'marca', 'precio', 'costo', 'utilidad_pct', 'incluir', 'imagen_path', 'tiempo_entrega', 'entrega_lenta'];

    /** También protege importaciones/MCP: SQLite no detecta estos desbordamientos de MariaDB. */
    public function saving(CotizacionPartida $partida): void
    {
        $error = Limites::partida((float) $partida->cantidad, (float) $partida->precio,
            $partida->costo !== null ? (float) $partida->costo : null,
            $partida->utilidad_pct !== null ? (float) $partida->utilidad_pct : null);
        if (! $error) {
            $importes = CotizacionPartida::where('cotizacion_id', $partida->cotizacion_id)->where('incluir', true)
                ->when($partida->exists, fn ($q) => $q->whereKeyNot($partida->id))->pluck('importe')->all();
            if ($partida->incluir ?? true) {
                $importes[] = Precios::importe((float) $partida->precio, (float) $partida->cantidad);
            }
            $total = Precios::totales($importes)['total'];
            if (! is_finite($total) || $total > 999_999_999_999.99) {
                $error = 'El total de la cotización con IVA es demasiado grande.';
            }
        }
        if ($error) {
            throw ValidationException::withMessages(['partidas' => $error]);
        }
    }

    public function created(CotizacionPartida $partida): void
    {
        Bitacora::alCrearPartida($partida);
        $this->quitarRevision($partida);
    }

    public function updated(CotizacionPartida $partida): void
    {
        Bitacora::alEditarPartida($partida);
        if ($partida->wasChanged(self::CAMPOS_REVISADOS)) {
            $this->quitarRevision($partida);
        }
    }

    public function deleted(CotizacionPartida $partida): void
    {
        Bitacora::alEliminarPartida($partida);
        $this->quitarRevision($partida);
    }

    /** La marca "revisada" era de la versión anterior: se quita para que se vuelva a revisar (y se anota). */
    private function quitarRevision(CotizacionPartida $partida): void
    {
        $cot = Cotizacion::find($partida->cotizacion_id);
        if ($cot?->revisada_at) {
            $cot->forceFill(['revisada_at' => null, 'revisada_por' => null])->saveQuietly();
            Bitacora::evento($cot, 'revision_anulada', 'Se cambió después de revisarla: hay que revisarla otra vez.');
        }
    }
}

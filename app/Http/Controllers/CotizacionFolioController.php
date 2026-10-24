<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use Illuminate\Http\RedirectResponse;

class CotizacionFolioController extends Controller
{
    /** Atajo "Ir a folio": lleva a la cotización por su número de folio. */
    public function __invoke(string $folio): RedirectResponse
    {
        $folio = trim($folio);
        $cot = ctype_digit($folio) ? Cotizacion::where('folio', (int) $folio)->first() : null;

        if (! $cot) {
            return back(fallback: route('cotizaciones.index'))->with('error', "No existe la cotización {$folio}.");
        }

        return redirect()->route('cotizaciones.editar', $cot);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Ned\NedCotizacion;
use App\Models\Ned\NedDatos;
use App\Services\Ned\NedCotizacionPdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NedController extends Controller
{
    public function pdf(Request $request, NedCotizacion $cotizacion): Response
    {
        $disposicion = $request->boolean('descargar') ? 'attachment' : 'inline';

        return response(NedCotizacionPdf::generar($cotizacion), 200, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, private, max-age=0',
            'Content-Disposition' => $disposicion.'; filename="'.$cotizacion->nombreArchivoPdf().'"',
        ]);
    }

    public function logo(): Response
    {
        return response()->file(NedDatos::actuales()->rutaLogo(), ['Cache-Control' => 'no-cache']);
    }
}

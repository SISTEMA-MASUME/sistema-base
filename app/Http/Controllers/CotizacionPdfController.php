<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Services\CotizacionPdf;
use App\Support\CompartirDocumento;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CotizacionPdfController extends Controller
{
    public function __invoke(Request $request, Cotizacion $cotizacion): Response
    {
        $bytes = CotizacionPdf::generar($cotizacion);
        $disposicion = $request->boolean('descargar') ? 'attachment' : 'inline';
        // Descargar el PDF = se va a mandar: queda en el historial y pasa de borrador/emitida a enviada. Se
        // registra aquí (no en el navegador) para que no se pierda. registrar=0: el botón de WhatsApp baja el
        // archivo para adjuntarlo y registra él mismo el envío.
        if ($request->boolean('descargar') && $request->input('registrar') !== '0' && $request->user()) {
            CompartirDocumento::registrar($cotizacion, 'descarga', 'Descargado por '.$request->user()->name);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, private, max-age=0',
            'Content-Disposition' => $disposicion.'; filename="'.$cotizacion->nombreArchivoPdf().'"',
        ]);
    }
}

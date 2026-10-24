<?php

namespace App\Http\Controllers;

use App\Models\OrdenProveedor;
use App\Services\OrdenProveedorPdf;
use App\Support\CompartirDocumento;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrdenProveedorPdfController extends Controller
{
    public function __invoke(Request $request, OrdenProveedor $orden): Response
    {
        $bytes = OrdenProveedorPdf::generar($orden);
        $disposicion = $request->boolean('descargar') ? 'attachment' : 'inline';
        // Descargar el PDF = se va a mandar: queda en el historial y pasa de borrador/emitida a enviada. Se
        // registra aquí (no en el navegador) para que no se pierda. registrar=0: el botón de WhatsApp baja el
        // archivo para adjuntarlo y registra él mismo el envío.
        if ($request->boolean('descargar') && $request->input('registrar') !== '0' && $request->user()) {
            CompartirDocumento::registrar($orden, 'descarga', 'Descargado por '.$request->user()->name);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, private, max-age=0',
            'Content-Disposition' => $disposicion.'; filename="'.$orden->nombreArchivoPdf().'"',
        ]);
    }
}

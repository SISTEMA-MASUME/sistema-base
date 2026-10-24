<?php

namespace App\Http\Controllers;

use App\Models\OrdenProveedor;
use App\Services\OrdenProveedorImagen;
use App\Support\CompartirDocumento;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** La OC al proveedor como imagen PNG (para WhatsApp). Descargarla cuenta como envío, igual que el PDF. */
class OrdenProveedorImagenController extends Controller
{
    public function __invoke(Request $request, OrdenProveedor $orden): Response
    {
        $png = OrdenProveedorImagen::png($orden);
        $nombre = preg_replace('/\.pdf$/i', '.png', $orden->nombreArchivoPdf());
        if ($request->boolean('descargar') && $request->input('registrar') !== '0' && $request->user()) {
            CompartirDocumento::registrar($orden, 'descarga', 'Imagen descargada por '.$request->user()->name);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, private, max-age=0',
            'Content-Disposition' => ($request->boolean('descargar') ? 'attachment' : 'inline').'; filename="'.$nombre.'"',
        ]);
    }
}

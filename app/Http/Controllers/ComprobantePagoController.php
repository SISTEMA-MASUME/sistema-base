<?php

namespace App\Http\Controllers;

use App\Models\PagoProveedor;
use App\Support\ArchivoSeguro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Comprobante de un pago a proveedor (disco local privado; solo el admin, por la ruta). */
class ComprobantePagoController extends Controller
{
    public function __invoke(Request $request, PagoProveedor $pago): Response
    {
        abort_unless($request->user()?->esAdmin(), 403);
        abort_unless($pago->comprobante_path && Storage::disk('local')->exists($pago->comprobante_path), 404, 'El comprobante ya no está en el servidor.');

        return ArchivoSeguro::responder('local', $pago->comprobante_path, (string) ($pago->comprobante_nombre ?: 'comprobante'), $pago->comprobante_mime, $request->boolean('descargar'));
    }
}

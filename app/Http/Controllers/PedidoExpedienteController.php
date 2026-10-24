<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Models\PedidoArchivo;
use App\Support\ArchivoSeguro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Ver o descargar un archivo del expediente (factura, evidencia). Solo con sesión; nunca en disco público. */
class PedidoExpedienteController extends Controller
{
    public function __invoke(Request $request, Pedido $pedido, PedidoArchivo $archivo): Response
    {
        abort_unless($archivo->pedido_id === $pedido->id, 404);
        $disco = Storage::disk($archivo->disco);
        abort_unless($disco->exists($archivo->ruta), 404, 'El archivo ya no existe.');

        return ArchivoSeguro::responder($archivo->disco, $archivo->ruta, (string) $archivo->nombre_original, $archivo->mime, $request->boolean('descargar'));
    }
}

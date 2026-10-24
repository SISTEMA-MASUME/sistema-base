<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Support\ArchivoSeguro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Ver o descargar la OC que mandó el cliente (PDF o foto, disco local privado). */
class PedidoArchivoController extends Controller
{
    public function __invoke(Request $request, Pedido $pedido): Response
    {
        $ruta = $pedido->oc_cliente_path;
        abort_if(! $ruta || ! Storage::disk('local')->exists($ruta), 404, 'Este pedido no tiene archivo de OC.');

        $nombre = 'OC_'.preg_replace('/[^A-Za-z0-9]+/', '_', (string) ($pedido->numero_oc_cliente ?: 'P'.$pedido->folio)).'.'.pathinfo($ruta, PATHINFO_EXTENSION);

        // Tipo tomado del contenido; con "no-store" el navegador no muestra la OC vieja después de reemplazarla.
        return ArchivoSeguro::responder('local', $ruta, $nombre, null, $request->boolean('descargar'));
    }
}

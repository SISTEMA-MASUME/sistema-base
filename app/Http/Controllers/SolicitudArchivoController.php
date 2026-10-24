<?php

namespace App\Http\Controllers;

use App\Models\SolicitudArchivo;
use App\Support\ArchivoSeguro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Ver o descargar un archivo de una solicitud "Por cotizar" (disco local privado; solo con sesión). */
class SolicitudArchivoController extends Controller
{
    public function __invoke(Request $request, SolicitudArchivo $archivo): Response
    {
        abort_unless(Storage::disk('local')->exists($archivo->ruta), 404, 'El archivo ya no está en el servidor.');

        return ArchivoSeguro::responder('local', $archivo->ruta, (string) $archivo->nombre, $archivo->mime, $request->boolean('descargar'));
    }
}

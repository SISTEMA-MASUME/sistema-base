<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Visor de PDF dentro del sistema (celular / app instalada): al abrir un PDF en otra pestaña y cerrarla con la X,
 * Android sacaba al usuario del programa. Aquí el PDF se dibuja en la misma ventana con una flecha "← Regresar"
 * a la pantalla de donde se abrió. Solo muestra direcciones de este mismo sistema (el navegador las pide con la
 * sesión del usuario, así que cada quien ve solo lo que ya podía ver).
 */
class VisorPdfController extends Controller
{
    /** Solo documentos del sistema (y un ?query sencillo): nada de otras direcciones ni caracteres raros. */
    private const DOCUMENTOS = '#^/(cotizaciones/\d+/pdf|pedidos/\d+/(oc-cliente|archivos/\d+)|por-cotizar/archivos/\d+|ordenes-proveedor/\d+/(pdf|imagen)|ned/cotizaciones/\d+/pdf)(\?[A-Za-z0-9=&_.-]*)?$#';

    public function __invoke(Request $request): View
    {
        $u = (string) $request->query('u', '');
        abort_unless(preg_match(self::DOCUMENTOS, $u) === 1, 404);

        $volver = (string) $request->query('volver', '');
        $volver = preg_match('#^/[A-Za-z0-9/_.?=&%-]*$#', $volver) && ! str_starts_with($volver, '//') ? $volver : '/';

        return view('visor-pdf', [
            'url' => $u,
            'descargar' => $u.(str_contains($u, '?') ? '&' : '?').'descargar=1',
            'volver' => $volver,
            'titulo' => mb_substr((string) $request->query('t', 'Documento'), 0, 80),
        ]);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Módulo de cotizaciones de Ned Tracking: lo usan su propio usuario (Pedro Ramírez) y el admin (Daniel),
 * para no tener que cambiar de sesión. Los vendedores y campo de MASUME no entran.
 */
class SoloNed
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->puedeUsarNed(), 403, 'Esta sección es solo para Ned Tracking.');

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bloquea a los usuarios de rol campo (reparto): no ven costos ni proveedores. */
class SinCampo
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->esCampo()) {
            abort(403, 'Tu usuario no tiene acceso a esta sección.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SoloAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario || ! $usuario->activo || ! $usuario->esAdmin()) {
            abort(403, 'Esta sección es solo para administradores.');
        }

        return $next($request);
    }
}

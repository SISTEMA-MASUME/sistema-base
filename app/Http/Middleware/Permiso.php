<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pantalla de finanzas: solo el admin o quien tenga ese permiso (User::puede). Uso: ->middleware('permiso:ventas') */
class Permiso
{
    public function handle(Request $request, Closure $next, string $permiso): Response
    {
        abort_unless($request->user()?->puede($permiso), 403, 'No tienes acceso a esta pantalla. Pídeselo a Daniel.');

        return $next($request);
    }
}

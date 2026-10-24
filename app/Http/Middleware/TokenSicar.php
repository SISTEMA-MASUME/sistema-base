<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Token Bearer del programa de la PC de Daniel (SICAR_SYNC_TOKEN); sin token configurado nunca autoriza. */
class TokenSicar
{
    public function handle(Request $request, Closure $next): Response
    {
        $esperado = (string) config('services.sicar.token');
        $recibido = (string) $request->bearerToken();
        if ($esperado === '' || $recibido === '' || ! hash_equals($esperado, $recibido)) {
            return response()->json(['ok' => false, 'error' => 'No autorizado.'], 401);
        }

        return $next($request);
    }
}

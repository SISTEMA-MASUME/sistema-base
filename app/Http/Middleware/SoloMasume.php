<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Las pantallas de MASUME no son para usuarios externos (Ned Tracking): ellos solo ven su módulo. */
class SoloMasume
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->esNed()) {
            return redirect()->route('ned.index');
        }

        return $next($request);
    }
}

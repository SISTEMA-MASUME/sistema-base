<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sistema interno: ninguna página (ni los PDF compartidos por WhatsApp) debe quedar en Google ni en Archive.org.
 * Los archivos fijos (imágenes de partidas) llevan la misma cabecera desde public/.htaccess.
 */
class NoIndexar
{
    /** Robots de archivos de internet (Wayback Machine): no respetan siempre robots.txt, así que se les cierra la puerta. */
    private const ARCHIVADORES = '/archive\.org_bot|ia_archiver|archive\.org|wayback|archive\.today|archive\.ph/i';

    public function handle(Request $request, Closure $next): Response
    {
        if (preg_match(self::ARCHIVADORES, (string) $request->userAgent())) {
            return response('No disponible.', 403, ['X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $respuesta = $next($request);
        $respuesta->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $respuesta;
    }
}

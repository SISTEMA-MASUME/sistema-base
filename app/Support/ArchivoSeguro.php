<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrega archivos que subió la gente (OC, facturas, solicitudes, comprobantes) sin que el navegador los trate como página
 * web: dentro del navegador solo se abren PDF, fotos y texto, siempre con su tipo fijo y "nosniff"; un XML se muestra como
 * texto; cualquier otra cosa se descarga. Auditoría de seguridad oct-2026: un HTML con nombre .pdf o un XML con XHTML
 * ejecutaba código en la sesión de quien lo abría.
 */
final class ArchivoSeguro
{
    /** Tipos que el navegador puede mostrar sin ejecutar nada. */
    private const EN_NAVEGADOR = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Se ven como texto plano (el navegador no interpreta su contenido). */
    private const COMO_TEXTO = ['text/plain', 'application/xml', 'text/xml'];

    /**
     * @param  string|null  $mime  tipo guardado al subirlo; si no hay, se toma del contenido del archivo
     */
    public static function responder(string $disco, string $ruta, string $nombre, ?string $mime, bool $descargar): Response
    {
        $d = Storage::disk($disco);
        $mime = strtolower(trim(explode(';', (string) ($mime ?: $d->mimeType($ruta)))[0]));

        if (! $descargar && in_array($mime, self::EN_NAVEGADOR, true)) {
            $tipo = $mime;
        } elseif (! $descargar && in_array($mime, self::COMO_TEXTO, true)) {
            $tipo = 'text/plain; charset=utf-8';
        } else {
            return self::sinCache($d->download($ruta, str_replace(['/', '\\', '"', "\r", "\n"], '_', $nombre) ?: 'archivo', [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]));
        }

        return self::sinCache(response()->file($d->path($ruta), [
            'Content-Type' => $tipo,
            'Content-Disposition' => 'inline; filename="'.self::nombre($nombre).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    /** Sin caché: la dirección de la OC no cambia al reemplazarla (y son archivos privados). */
    private static function sinCache(Response $r): Response
    {
        $r->headers->set('Cache-Control', 'no-store, private, max-age=0');

        return $r;
    }

    /** Nombre seguro para el encabezado (sin comillas ni saltos de línea). */
    public static function nombre(string $nombre): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $nombre) ?: 'archivo';
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normaliza nombres de clientes/proveedores para evitar duplicados como
 * "PROVEX" / "PROVEX." / "provex-" que partían los reportes en Sheets.
 */
final class Nombres
{
    /** Forma de mostrar/guardar: mayúsculas, espacios simples, sin puntuación al final. */
    public static function limpiar(string $nombre): string
    {
        $nombre = Str::upper(Str::squish($nombre));

        return rtrim($nombre, ' .,-_;:');
    }

    /** Llave de comparación: además ignora acentos y puntuación interna. */
    public static function llave(string $nombre): string
    {
        return preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($nombre)));
    }
}

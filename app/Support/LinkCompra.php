<?php

namespace App\Support;

/**
 * Link de compra de una partida (Mercado Libre, Amazon, página del proveedor…). Es interno: nunca sale al
 * cliente. A veces lo pegaban en "Modelo" y en la OC salía la dirección larga; aquí se separa.
 */
final class LinkCompra
{
    public static function esUrl(?string $texto): bool
    {
        return (bool) preg_match('#^\s*(https?://|www\.)\S+\s*$#i', (string) $texto);
    }

    /** Si el modelo es un link, pasa al link de compra (si no había uno) y el modelo queda vacío. */
    public static function separar(?string $modelo, ?string $link): array
    {
        if (self::esUrl($modelo)) {
            return [null, trim((string) $link) !== '' ? $link : self::normalizar($modelo)];
        }

        return [$modelo, $link];
    }

    public static function normalizar(?string $link): ?string
    {
        $link = trim((string) $link);
        if ($link === '') {
            return null;
        }
        if (preg_match('#^www\.#i', $link)) {
            $link = 'https://'.$link;
        }

        return mb_substr($link, 0, 1000);
    }
}

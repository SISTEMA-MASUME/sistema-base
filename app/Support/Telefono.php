<?php

namespace App\Support;

/** Teléfonos de México para WhatsApp (wa.me): 52 + 10 dígitos. */
class Telefono
{
    /** Normaliza "33 1234 5678", "+52 1 33…", "(33) 1234-5678" a "523312345678"; null si no se puede. */
    public static function whatsapp(?string $telefono): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $telefono);
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        // Formato viejo de celular: 52 1 + 10 dígitos.
        if (strlen($d) === 13 && str_starts_with($d, '521')) {
            return '52'.substr($d, 3);
        }
        if (strlen($d) === 12 && str_starts_with($d, '52')) {
            return $d;
        }
        // Con el 044/045 de antes o el 01.
        if (strlen($d) === 13 && (str_starts_with($d, '044') || str_starts_with($d, '045'))) {
            $d = substr($d, 3);
        } elseif (strlen($d) === 12 && str_starts_with($d, '01')) {
            $d = substr($d, 2);
        }

        return strlen($d) === 10 ? '52'.$d : null;
    }
}

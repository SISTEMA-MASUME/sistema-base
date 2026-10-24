<?php

namespace App\Support;

use App\Models\User;

/** Cuenta de correo de salida de un usuario (users.cuenta_correo → mailer smtp_<clave>). */
class CuentaCorreo
{
    /** Dirección de la cuenta de salida, o null si el usuario no tiene una válida. */
    public static function de(?User $user): ?string
    {
        $clave = $user?->cuenta_correo;
        if (! filled($clave)) {
            return null;
        }
        $usuario = config("masume.cuentas_correo.$clave.usuario");
        $existeMailer = config('mail.mailers.smtp_'.$clave) !== null;

        return filled($usuario) && $existeMailer ? (string) $usuario : null;
    }

    /** @return list<string> */
    public static function separar(string $texto): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $texto) ?: []), fn ($c) => $c !== ''));
    }
}

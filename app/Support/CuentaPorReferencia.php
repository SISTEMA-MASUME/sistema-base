<?php

namespace App\Support;

use App\Models\Cuenta;
use Illuminate\Support\Str;

/**
 * A qué cuenta de débito llegó un cobro según la "Referencia" escrita en SICAR ("HEY", "BBVA", "pago bbva 123"…).
 * Una palabra de la referencia debe aparecer en el nombre de UNA sola cuenta de débito activa (las de MASUME primero).
 * Si no coincide ninguna o coinciden varias, no se adivina: el cobro no se suma a ninguna cuenta.
 */
final class CuentaPorReferencia
{
    /** Palabras que no sirven para distinguir una cuenta. */
    private const GENERICAS = ['BANCO', 'BANK', 'DEBITO', 'CREDITO', 'CUENTA', 'CTA', 'PAGO', 'PERSONAL', 'MASUME', 'TARJETA', 'TRANSFERENCIA', 'SPEI', 'DEPOSITO', 'REF'];

    public static function buscar(?string $referencia): ?int
    {
        $palabras = self::palabras($referencia);
        if ($palabras === []) {
            return null;
        }
        $candidatas = Cuenta::where('activa', true)->where('tipo', 'debito')->get()
            ->filter(fn (Cuenta $c) => array_intersect($palabras, self::palabras($c->nombre)) !== []);
        if ($candidatas->count() > 1) {
            $candidatas = $candidatas->where('de', 'masume');
        }

        return $candidatas->count() === 1 ? $candidatas->first()->id : null;
    }

    /** @return list<string> */
    private static function palabras(?string $texto): array
    {
        $limpio = preg_replace('/[^A-Z0-9]+/', ' ', strtoupper(Str::ascii((string) $texto)));

        return array_values(array_filter(explode(' ', $limpio),
            fn ($p) => strlen($p) >= 2 && ! ctype_digit($p) && ! in_array($p, self::GENERICAS, true)));
    }
}

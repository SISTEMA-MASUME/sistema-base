<?php

namespace App\Support;

/**
 * Topes de números que caben en las columnas de MariaDB (cantidad DECIMAL(12,3), costo DECIMAL(14,4), precio/importe
 * y totales DECIMAL(14,2)). SQLite no avisa si se pasan; MariaDB estricto truena. Mejor avisar antes de guardar.
 */
class Limites
{
    public const CANTIDAD = 99_999_999.0;

    public const PRECIO = 9_999_999_999.0;

    public const IMPORTE = 99_999_999_999.0;

    /** Mensaje si algún número se sale de lo razonable, o null si todo cabe. */
    public static function partida(float $cantidad, float $precio, ?float $costo = null, ?float $utilidad = null): ?string
    {
        foreach ([$cantidad, $precio, (float) $costo, (float) $utilidad] as $n) {
            if (! is_finite($n)) {
                return 'Ese número no es válido.';
            }
        }
        if ($utilidad !== null && abs($utilidad) > 99_999.99) {
            return 'El porcentaje de utilidad es demasiado grande.';
        }
        if ($cantidad > self::CANTIDAD) {
            return 'La cantidad es demasiado grande (máximo '.number_format(self::CANTIDAD).').';
        }
        if ($precio > self::PRECIO || ($costo ?? 0) > self::PRECIO) {
            return 'El precio o costo es demasiado grande (máximo $'.number_format(self::PRECIO).').';
        }
        if ($cantidad * $precio > self::IMPORTE) {
            return 'El importe (cantidad × precio) es demasiado grande.';
        }

        return null;
    }
}

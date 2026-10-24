<?php

namespace App\Support;

/**
 * Reglas de precio heredadas del formato de Sheets (3_CuadernoLogic):
 *   precio_sin_iva = costo_sin_iva × (1 + utilidad/100)
 *   utilidad      = (precio / costo − 1) × 100
 * El costo puede capturarse con IVA; se guarda siempre SIN IVA.
 */
final class Precios
{
    public static function iva(): float
    {
        return (float) config('masume.iva', 0.16);
    }

    public static function costoSinIva(float $costoConIva): float
    {
        return $costoConIva / (1 + self::iva());
    }

    public static function precioDesdeUtilidad(float $costo, float $utilidadPct): float
    {
        return round($costo * (1 + $utilidadPct / 100), 2);
    }

    public static function utilidadDesdePrecio(float $costo, float $precio): ?float
    {
        if ($costo <= 0) {
            return null;
        }

        return round(($precio / $costo - 1) * 100, 2);
    }

    public static function importe(float $precio, float $cantidad): float
    {
        return round($precio * $cantidad, 2);
    }

    /** @return array{subtotal: float, iva: float, total: float} */
    public static function totales(iterable $importes): array
    {
        $subtotal = 0.0;
        foreach ($importes as $importe) {
            $subtotal += (float) $importe;
        }
        $subtotal = round($subtotal, 2);
        $iva = round($subtotal * self::iva(), 2);

        return ['subtotal' => $subtotal, 'iva' => $iva, 'total' => round($subtotal + $iva, 2)];
    }
}

<?php

namespace App\Support;

/**
 * Unidades del SAT (catálogo c_ClaveUnidad del CFDI 4.0) que usa MASUME, y cómo se acomoda la unidad que escriben
 * ("PZA", "CJA", "MTS"…). Solo se sugieren las seguras; la que no se reconoce queda vacía para elegirla a mano.
 */
final class UnidadesSat
{
    /** @var array<string, string> clave SAT => nombre */
    public const LISTA = [
        'H87' => 'Pieza',
        'XBX' => 'Caja',
        'XPK' => 'Paquete',
        'SET' => 'Conjunto (juego)',
        'KT' => 'Kit',
        'PR' => 'Par',
        'XRO' => 'Rollo',
        'MTR' => 'Metro',
        'MTK' => 'Metro cuadrado',
        'MTQ' => 'Metro cúbico',
        'KGM' => 'Kilogramo',
        'LTR' => 'Litro',
        'GLL' => 'Galón',
        'XBG' => 'Bolsa',
        'E48' => 'Unidad de servicio',
        'ACT' => 'Actividad',
    ];

    /** Cómo la escriben (sin acentos, puntos ni espacios) => clave SAT. */
    private const EQUIVALENCIAS = [
        'PZA' => 'H87', 'PZAS' => 'H87', 'PZ' => 'H87', 'PIEZA' => 'H87', 'PIEZAS' => 'H87', 'PZS' => 'H87',
        'CAJA' => 'XBX', 'CAJAS' => 'XBX', 'CJA' => 'XBX', 'CJ' => 'XBX', 'CJAS' => 'XBX',
        'PAQ' => 'XPK', 'PAQUETE' => 'XPK', 'PQT' => 'XPK', 'PAQS' => 'XPK',
        'JGO' => 'SET', 'JUEGO' => 'SET', 'JGOS' => 'SET', 'SET' => 'SET',
        'KIT' => 'KT', 'KITS' => 'KT',
        'PAR' => 'PR', 'PARES' => 'PR',
        'ROLLO' => 'XRO', 'ROLLOS' => 'XRO', 'RLL' => 'XRO', 'ROL' => 'XRO',
        'M' => 'MTR', 'MT' => 'MTR', 'MTS' => 'MTR', 'METRO' => 'MTR', 'METROS' => 'MTR', 'ML' => 'MTR',
        'M2' => 'MTK', 'MT2' => 'MTK', 'MTS2' => 'MTK',
        'M3' => 'MTQ', 'MT3' => 'MTQ',
        'KG' => 'KGM', 'KGS' => 'KGM', 'KILO' => 'KGM', 'KILOS' => 'KGM', 'KILOGRAMO' => 'KGM',
        'LT' => 'LTR', 'LTS' => 'LTR', 'L' => 'LTR', 'LITRO' => 'LTR', 'LITROS' => 'LTR',
        'GAL' => 'GLL', 'GALON' => 'GLL', 'GALONES' => 'GLL',
        'BOLSA' => 'XBG', 'BOLSAS' => 'XBG',
        'SERV' => 'E48', 'SERVICIO' => 'E48', 'SERVICIOS' => 'E48',
    ];

    /** La clave SAT que le toca a la unidad como la escriben, o null si no es segura. */
    public static function sugerir(?string $unidad): ?string
    {
        return self::EQUIVALENCIAS[Nombres::llave((string) $unidad)] ?? null;
    }

    public static function nombre(?string $clave): ?string
    {
        return self::LISTA[(string) $clave] ?? null;
    }
}

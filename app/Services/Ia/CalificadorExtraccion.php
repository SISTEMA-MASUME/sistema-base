<?php

namespace App\Services\Ia;

use App\Services\ConciliadorOc;
use Illuminate\Support\Str;

/**
 * Califica lo que extrajo la IA contra un resultado esperado (evaluación manual de modelos).
 * Un renglón extraído corresponde a uno esperado si contiene todas las palabras de `clave`
 * (sin acentos, en mayúsculas) dentro de descripción + modelo.
 */
class CalificadorExtraccion
{
    /**
     * @param  array  $datos  JSON extraído (esquema oc_cliente_v1)
     * @param  array  $esperado  {numero_oc?, subtotal?, renglones: [{clave: [palabras], cantidad, unidad?, precio?, obs?}]}
     * @return array{esperados: int, encontrados: int, cantidades_ok: int, precios_ok: int, precios_esperados: int, numero_ok: ?bool, subtotal_ok: ?bool, extraidos: int}
     */
    public function calificar(array $datos, array $esperado): array
    {
        $extraidos = array_values(array_filter((array) ($datos['renglones'] ?? []), 'is_array'));
        $usados = [];
        $enc = $cant = $prec = $precEsp = 0;
        $esperados = (array) ($esperado['renglones'] ?? []);

        foreach ($esperados as $e) {
            $palabras = array_map(fn ($w) => self::limpiar((string) $w), (array) ($e['clave'] ?? []));
            $palabras = array_filter($palabras, fn ($w) => $w !== '');
            $hallado = null;
            foreach ($extraidos as $i => $r) {
                if (isset($usados[$i])) {
                    continue;
                }
                $texto = self::limpiar(($r['descripcion'] ?? '').' '.($r['modelo'] ?? ''));
                $todas = true;
                foreach ($palabras as $w) {
                    if (! str_contains($texto, $w)) {
                        $todas = false;
                        break;
                    }
                }
                if ($todas && $palabras !== []) {
                    $hallado = $i;
                    break;
                }
            }

            $tienePrecio = isset($e['precio']) && $e['precio'] !== null;
            $precEsp += $tienePrecio ? 1 : 0;
            if ($hallado === null) {
                continue;
            }
            $usados[$hallado] = true;
            $enc++;
            $r = $extraidos[$hallado];
            if (isset($e['cantidad']) && abs((float) ($r['cantidad'] ?? -1) - (float) $e['cantidad']) < 0.0005) {
                $cant++;
            }
            if ($tienePrecio && ($r['precio_unitario'] ?? null) !== null
                && abs((float) $r['precio_unitario'] - (float) $e['precio']) <= max(0.01, 0.001 * (float) $e['precio'])) {
                $prec++;
            }
        }

        $numeroOk = isset($esperado['numero_oc'])
            ? ConciliadorOc::normalizarNumeroOc($datos['numero_oc'] ?? null) === ConciliadorOc::normalizarNumeroOc((string) $esperado['numero_oc'])
            : null;
        $subtotalOk = isset($esperado['subtotal'])
            ? ($datos['subtotal'] ?? null) !== null && abs((float) $datos['subtotal'] - (float) $esperado['subtotal']) <= 0.01
            : null;

        return [
            'esperados' => count($esperados),
            'encontrados' => $enc,
            'cantidades_ok' => $cant,
            'precios_ok' => $prec,
            'precios_esperados' => $precEsp,
            'numero_ok' => $numeroOk,
            'subtotal_ok' => $subtotalOk,
            'extraidos' => count($extraidos),
        ];
    }

    private static function limpiar(string $t): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtoupper(Str::ascii($t))));
    }
}

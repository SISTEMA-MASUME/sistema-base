<?php

namespace App\Support;

/**
 * ¿Qué facturas suman un depósito? Busca combinaciones de importes (en centavos) que den exacto el depósito y, si
 * no hay, las más cercanas por debajo y por arriba. Es aritmética exacta en PHP (sin IA): instantáneo y sin costo.
 * Solo sugiere; la persona confirma y marca las pagadas (regla: no vincular por igual importe sin confirmación).
 */
final class CuadreDeposito
{
    /** Máximo de documentos que se combinan (con más, hay que filtrar por cliente o fechas). */
    public const MAX_DOCUMENTOS = 60;

    /** Tope de trabajo para que la búsqueda nunca se tarde (se reporta si se alcanzó). */
    public const MAX_PASOS = 3_000_000;

    /**
     * @param  array<int, int>  $importes  id => centavos (positivos), en el orden de preferencia (más viejos primero)
     * @return array{exactas: list<list<int>>, abajo: ?array{ids: list<int>, suma: int}, arriba: ?array{ids: list<int>, suma: int}, completa: bool}
     */
    public static function buscar(array $importes, int $objetivo, int $maxExactas = 5): array
    {
        $importes = array_filter($importes, fn ($c) => $c > 0);
        $ids = array_keys($importes);
        // De mayor a menor importe poda mucho más rápido; a igualdad, el orden original (más viejo primero).
        $orden = array_keys($ids);
        usort($orden, fn ($a, $b) => [$importes[$ids[$b]], $a] <=> [$importes[$ids[$a]], $b]);
        $valores = array_map(fn ($i) => $importes[$ids[$i]], $orden);
        $claves = array_map(fn ($i) => $ids[$i], $orden);
        $n = count($valores);
        $resto = array_fill(0, $n + 1, 0);
        for ($i = $n - 1; $i >= 0; $i--) {
            $resto[$i] = $resto[$i + 1] + $valores[$i];
        }

        $exactas = [];
        $abajo = null;   // [suma, ids] la mayor suma <= objetivo
        $arriba = null;  // [suma, ids] la menor suma > objetivo
        $pasos = 0;
        $elegidos = [];

        $buscar = function (int $i, int $suma) use (&$buscar, &$exactas, &$abajo, &$arriba, &$pasos, &$elegidos, $valores, $claves, $resto, $n, $objetivo, $maxExactas): void {
            if (++$pasos > self::MAX_PASOS) {
                return;
            }
            if ($suma === $objetivo && $elegidos) {
                // Se sigue buscando aunque ya haya exactas: también se muestran las más cercanas (diferencia de centavos).
                if (count($exactas) < $maxExactas) {
                    $exactas[] = $elegidos;
                }

                return;
            }
            if ($suma > $objetivo) {
                if ($arriba === null || $suma < $arriba[0]) {
                    $arriba = [$suma, $elegidos];
                }

                return;   // agregar más solo aleja
            }
            if ($elegidos && ($abajo === null || $suma > $abajo[0])) {
                $abajo = [$suma, $elegidos];
            }
            if ($i >= $n) {
                return;
            }
            // Ni con todo lo que queda se alcanza algo mejor que lo que ya se tiene por abajo: solo sirve para "arriba".
            if ($suma + $resto[$i] < $objetivo && $abajo !== null && $suma + $resto[$i] <= $abajo[0]) {
                return;
            }
            $elegidos[] = $claves[$i];
            $buscar($i + 1, $suma + $valores[$i]);
            array_pop($elegidos);
            $buscar($i + 1, $suma);
        };
        $buscar(0, 0);

        $ordenar = function (array $lista) use ($ids): array {
            $pos = array_flip($ids);
            usort($lista, fn ($a, $b) => $pos[$a] <=> $pos[$b]);

            return $lista;
        };

        return [
            'exactas' => array_map($ordenar, $exactas),
            'abajo' => $abajo ? ['ids' => $ordenar($abajo[1]), 'suma' => $abajo[0]] : null,
            'arriba' => $arriba ? ['ids' => $ordenar($arriba[1]), 'suma' => $arriba[0]] : null,
            'completa' => $pasos <= self::MAX_PASOS,
        ];
    }
}

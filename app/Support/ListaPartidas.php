<?php

namespace App\Support;

/**
 * Lista de partidas SIN precios para pedir cotización a otro proveedor (p. ej. Ned Tracking) por WhatsApp,
 * y para leerla de vuelta cuando la pegan: "1) 20 PZA | CUCHILLAS SK4 18 MM | Marca: TRUPER".
 */
class ListaPartidas
{
    /**
     * @param  iterable<array{cantidad: mixed, unidad: ?string, descripcion: ?string, marca?: ?string, modelo?: ?string}>  $partidas
     */
    public static function texto(iterable $partidas): string
    {
        $lineas = [];
        $n = 0;
        foreach ($partidas as $p) {
            $desc = trim(preg_replace('/\s+/u', ' ', (string) ($p['descripcion'] ?? '')));
            if ($desc === '') {
                continue;
            }
            $cant = rtrim(rtrim(number_format((float) ($p['cantidad'] ?? 1), 3, '.', ''), '0'), '.');
            $linea = (++$n).') '.$cant.' '.(trim((string) ($p['unidad'] ?? '')) ?: 'PZA').' | '.$desc;
            if (filled($p['marca'] ?? null)) {
                $linea .= ' | Marca: '.trim($p['marca']);
            }
            if (filled($p['modelo'] ?? null)) {
                $linea .= ' | Modelo: '.trim($p['modelo']);
            }
            $lineas[] = $linea;
        }

        return implode("\n", $lineas);
    }

    /**
     * Lee la lista pegada (del mensaje de WhatsApp, de Excel o renglón por renglón).
     *
     * @return list<array{cantidad: float, unidad: string, descripcion: string}>
     */
    public static function interpretar(string $texto): array
    {
        $salida = [];
        foreach (preg_split('/\R/u', $texto) as $linea) {
            $linea = trim($linea);
            if ($linea === '' || preg_match('/^(solicitud|hola|buen|gracias|saludos|favor|por favor)/iu', $linea)) {
                continue;
            }
            $linea = preg_replace('/^\d+\s*[\).\-]\s+/u', '', $linea);   // "1) " / "1. " / "1 - "
            $cantidad = 1.0;
            $unidad = 'PZA';

            if (str_contains($linea, '|')) {
                $partes = array_map('trim', explode('|', $linea));
                $cabeza = array_shift($partes);
                if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(\S+)?$/u', $cabeza, $m)) {
                    $cantidad = (float) str_replace(',', '.', $m[1]);
                    $unidad = mb_strtoupper($m[2] ?? 'PZA');
                    $descripcion = implode(' | ', $partes);
                } else {
                    $descripcion = $cabeza.($partes ? ' | '.implode(' | ', $partes) : '');
                }
            } elseif (str_contains($linea, "\t")) {
                $celdas = array_values(array_filter(array_map('trim', explode("\t", $linea)), fn ($c) => $c !== ''));
                $numeros = array_values(array_filter($celdas, fn ($c) => is_numeric(str_replace(',', '.', $c))));
                if ($numeros) {
                    $cantidad = (float) str_replace(',', '.', $numeros[0]);
                }
                $textos = array_values(array_filter($celdas, fn ($c) => ! is_numeric(str_replace(',', '.', $c))));
                usort($textos, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
                $descripcion = $textos[0] ?? '';
                if (isset($textos[1]) && mb_strlen($textos[1]) <= 10) {
                    $unidad = mb_strtoupper($textos[1]);
                }
            } elseif (preg_match('/^(\d+(?:[.,]\d+)?)\s+([A-Za-zÁÉÍÓÚÑáéíóúñ]{1,8}\.?)\s+[-–:]?\s*(.+)$/u', $linea, $m)) {
                $cantidad = (float) str_replace(',', '.', $m[1]);
                $unidad = mb_strtoupper(rtrim($m[2], '.'));
                $descripcion = $m[3];
            } else {
                $descripcion = $linea;
            }

            $descripcion = trim($descripcion);
            if ($descripcion !== '') {
                $salida[] = ['cantidad' => $cantidad > 0 ? $cantidad : 1.0, 'unidad' => mb_substr($unidad, 0, 20), 'descripcion' => mb_substr($descripcion, 0, 2000)];
            }
        }

        return $salida;
    }
}

<?php

namespace App\Support;

use Throwable;

/**
 * Convierte una hoja de cálculo (Excel .xlsx o .csv) en texto plano separado por tabuladores para que la IA acomode
 * qué columna es cantidad, descripción, modelo… Leer el texto es más exacto y barato que "ver" una imagen.
 */
final class TablaArchivo
{
    public const MAX_FILAS = 400;

    public const MAX_COLUMNAS = 20;

    public const MIMES = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
    ];

    public static function esTabla(?string $mime): bool
    {
        return in_array($mime, self::MIMES, true);
    }

    /** Texto con todas las hojas (nombre de la hoja y sus renglones); null si no se pudo leer o está vacío. */
    public static function comoTexto(string $ruta, string $mime): ?string
    {
        try {
            $hojas = $mime === self::MIMES['csv'] ? ['CSV' => self::filasCsv($ruta)] : self::filasXlsx($ruta);
        } catch (Throwable) {
            return null;
        }
        $texto = '';
        $quedan = self::MAX_FILAS;
        foreach ($hojas as $nombre => $filas) {
            $lineas = [];
            foreach ($filas as $fila) {
                $celdas = array_map(fn ($v) => self::celda($v), array_slice($fila, 0, self::MAX_COLUMNAS));
                if (implode('', $celdas) === '') {
                    continue;
                }
                $lineas[] = rtrim(implode("\t", $celdas), "\t");
                if (--$quedan <= 0) {
                    break;
                }
            }
            if ($lineas) {
                $texto .= "### Hoja: {$nombre}\n".implode("\n", $lineas)."\n\n";
            }
            if ($quedan <= 0) {
                $texto .= '(El archivo sigue; solo se mandaron las primeras '.self::MAX_FILAS." filas.)\n";
                break;
            }
        }

        return trim($texto) === '' ? null : trim($texto);
    }

    private static function celda(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_float($v) && floor($v) == $v && abs($v) < 1e15) {
            $v = (int) $v;
        }

        return str_replace(["\t", "\r", "\n"], ' ', trim((string) $v));
    }

    /** @return array<string, list<list<mixed>>> */
    private static function filasXlsx(string $ruta): array
    {
        $lector = new LectorXlsx($ruta);
        $hojas = [];
        foreach ($lector->hojas() as $h) {
            $hojas[$h] = $lector->filas($h);
        }

        return $hojas;
    }

    /** CSV con coma, punto y coma o tabulador (Excel en español suele usar ";"). @return list<list<string>> */
    private static function filasCsv(string $ruta): array
    {
        $contenido = (string) file_get_contents($ruta, false, null, 0, 5_000_000);
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        $primera = strtok($contenido, "\n") ?: '';
        $sep = collect([',', ';', "\t"])->sortByDesc(fn ($s) => substr_count($primera, $s))->first();
        $f = fopen('php://memory', 'r+');
        fwrite($f, $contenido);
        rewind($f);
        $filas = [];
        while (($fila = fgetcsv($f, 0, $sep, '"', '\\')) !== false && count($filas) < self::MAX_FILAS * 2) {
            $filas[] = $fila;
        }
        fclose($f);

        return $filas;
    }
}

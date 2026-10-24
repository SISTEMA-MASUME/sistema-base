<?php

namespace App\Support;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Un solo Excel con todo lo necesario para capturar a mano en SICAR las partidas de las tarjetas elegidas, pensado para
 * que lo lea una persona: qué hacer (color por estado), clave SICAR y modelo juntos, la descripción tal como queda en
 * SICAR (y la nuestra si la de SICAR es distinta), costo y precio con y sin IVA, utilidad y clave SAT con su nombre.
 */
class HojaAltasSicar
{
    /** Columnas: llave => [encabezado, ancho, formato ('' texto, 'num', 'dinero', 'pct')]. */
    public const COLUMNAS = [
        'accion' => ['QUÉ HACER', 22, ''],
        'pedido' => ['PEDIDO', 9, ''],
        'clave' => ['CLAVE SICAR', 18, ''],
        'modelo' => ['MODELO', 18, ''],
        'descripcion' => ['DESCRIPCIÓN (como queda en SICAR)', 48, ''],
        'nuestra' => ['NUESTRA DESCRIPCIÓN (si es distinta)', 42, ''],
        'unidad' => ['UNIDAD', 9, ''],
        'cantidad' => ['CANT.', 8, 'num'],
        'costo' => ['COSTO SIN IVA', 13, 'dinero'],
        'costo_iva' => ['COSTO CON IVA', 13, 'dinero'],
        'precio' => ['PRECIO SIN IVA', 13, 'dinero'],
        'precio_iva' => ['PRECIO CON IVA', 13, 'dinero'],
        'utilidad' => ['UTILIDAD %', 10, 'pct'],
        'sat' => ['CLAVE SAT', 11, ''],
        'sat_nombre' => ['NOMBRE CLAVE SAT', 34, ''],
        'categoria' => ['CATEGORÍA', 16, ''],
        'proveedor' => ['PROVEEDOR', 24, ''],
        'cliente' => ['CLIENTE', 26, ''],
        'oc' => ['OC CLIENTE', 14, ''],
        'notas' => ['NOTAS', 60, ''],
    ];

    /** Fila de encabezados (arriba van el título y la leyenda de colores). */
    public const FILA_ENCABEZADOS = 4;

    /** Estado → [texto de "qué hacer", color de fondo]. */
    public const ESTADOS = [
        'nuevo' => ['DAR DE ALTA + COMPRA', 'DBEAFE'],
        'posible' => ['REVISAR', 'FEF3C7'],
        'existe' => ['SOLO COMPRA', 'DCFCE7'],
    ];

    public static function nombre(): string
    {
        return 'altas_sicar_'.now()->format('Ymd_His').'.xlsx';
    }

    /** @return list<string> */
    public static function encabezados(): array
    {
        return array_column(self::COLUMNAS, 0);
    }

    /**
     * Genera el Excel y devuelve su ruta temporal (el que llama lo borra).
     *
     * @param  Collection<int, array<string, mixed>>  $filas  salida de AltaSicar::filas()
     */
    public function generar(Collection $filas): string
    {
        $libro = new Spreadsheet;
        $libro->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Altas SICAR');
        $n = count(self::COLUMNAS);
        $enc = self::FILA_ENCABEZADOS;

        // Título y leyenda de colores.
        $hoja->setCellValue([1, 1], 'Altas en SICAR · generado el '.now()->format('d/m/Y H:i'));
        $hoja->getStyle([1, 1, 1, 1])->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('12235A');
        $col = 1;
        foreach (self::ESTADOS as [$texto, $color]) {
            $hoja->setCellValue([$col, 2], $texto);
            $hoja->getStyle([$col, 2, $col, 2])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
            $hoja->getStyle([$col, 2, $col, 2])->getFont()->setBold(true);
            $col += 2;
        }

        $this->fila($hoja, $enc, self::encabezados());
        $estiloEnc = $hoja->getStyle([1, $enc, $n, $enc]);
        $estiloEnc->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $estiloEnc->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('12235A');
        $estiloEnc->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $hoja->getRowDimension($enc)->setRowHeight(32);

        $orden = array_flip(array_keys(self::ESTADOS));
        $r = $enc + 1;
        foreach ($filas->sortBy(fn ($f) => $orden[$f['estado']] ?? 9)->values() as $f) {
            $this->fila($hoja, $r, array_values(self::valores($f)));
            $hoja->getStyle([1, $r, $n, $r])->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::ESTADOS[$f['estado']][1] ?? 'FFFFFF');
            $r++;
        }
        $ultima = max($enc, $r - 1);

        $i = 1;
        foreach (self::COLUMNAS as $llave => [, $ancho, $formato]) {
            $hoja->getColumnDimensionByColumn($i)->setWidth($ancho);
            if ($ultima > $enc) {
                $rango = $hoja->getStyle([$i, $enc + 1, $i, $ultima]);
                match ($formato) {
                    'dinero' => $rango->getNumberFormat()->setFormatCode('"$"#,##0.00'),
                    'num' => $rango->getNumberFormat()->setFormatCode('#,##0.###'),
                    'pct' => $rango->getNumberFormat()->setFormatCode('0.0"%"'),
                    default => null,
                };
                if (in_array($llave, ['clave', 'modelo'], true)) {
                    $rango->getFont()->setBold(true);
                }
            }
            $i++;
        }
        if ($ultima > $enc) {
            $datos = $hoja->getStyle([1, $enc + 1, $n, $ultima]);
            $datos->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $datos->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        }
        $hoja->freezePane([4, $enc + 1]);   // quedan a la vista qué hacer, pedido y clave
        $hoja->setAutoFilter([1, $enc, $n, $ultima]);

        $ruta = tempnam(sys_get_temp_dir(), 'altas').'.xlsx';
        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();

        return $ruta;
    }

    /**
     * Valores de una partida por columna (llaves de COLUMNAS; también los usa la tabla de la pantalla).
     *
     * @param  array<string, mixed>  $f
     * @return array<string, string|float>
     */
    public static function valores(array $f): array
    {
        $pedido = $f['pedido'];
        $p = $f['partida'];
        $notas = $f['avisos'];
        if ($f['estado'] === 'posible' && $f['candidato']) {
            array_unshift($notas, 'Parece la clave '.$f['candidato']->clave.' de SICAR ('.$f['candidato']->descripcion.'). Confírmalo antes de dar de alta.');
        }
        $existe = $f['estado'] === 'existe';
        if (($f['sat_ia'] ?? null) && ($f['sat'] !== '' || ($f['sat_sugerida'] ?? '') !== '')) {
            $notas[] = 'Clave SAT revisada por IA (confianza '.$f['sat_ia'].').';
        }
        $nuestra = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $p->descripcion)));
        $deSicar = $existe && $f['articulo'] ? (string) $f['articulo']->descripcion : $nuestra;
        // P. ej. en SICAR solo dice "ESCOBA": la compra sale con ese nombre. Se muestra la nuestra para comparar.
        $distinta = $existe && $deSicar !== $nuestra;
        if ($distinta && mb_strlen($deSicar) + 15 < mb_strlen($nuestra)) {
            $notas[] = 'En SICAR este artículo se llama "'.$deSicar.'" (más corto que la nuestra); si quieres, actualiza su descripción en SICAR.';
        }
        $costo = round((float) $p->costo, 2);
        $precio = round((float) $p->precio, 2);
        $iva = 1 + Precios::iva();

        return [
            'accion' => self::ESTADOS[$f['estado']][0] ?? '',
            'pedido' => 'P-'.$pedido->folio,
            'clave' => (string) $f['clave'],
            'modelo' => (string) ($p->modelo ?? ''),
            'descripcion' => $deSicar,
            'nuestra' => $distinta ? $nuestra : '',
            'unidad' => (string) $f['unidad'],
            'cantidad' => (float) $p->cantidad,
            'costo' => $costo,
            'costo_iva' => round($costo * $iva, 2),
            'precio' => $precio,
            'precio_iva' => round($precio * $iva, 2),
            'utilidad' => Precios::utilidadDesdePrecio((float) $p->costo, (float) $p->precio) ?? '',
            'sat' => (string) $f['sat'],
            'sat_nombre' => (string) ($f['sat_nombre'] ?? ''),
            'categoria' => $existe ? (string) ($f['articulo']->categoria ?? '') : (string) $f['categoria'],
            'proveedor' => (string) ($p->proveedor?->nombre ?? 'Sin proveedor'),
            'cliente' => (string) $pedido->cliente?->nombre,
            'oc' => (string) ($pedido->numero_oc_cliente ?: 'sin OC'),
            'notas' => implode(' · ', $notas),
        ];
    }

    /** @param  list<string|float>  $valores */
    private function fila($hoja, int $fila, array $valores): void
    {
        foreach ($valores as $i => $v) {
            $hoja->getCell([$i + 1, $fila])->setValueExplicit($v, is_string($v) ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
        }
    }
}

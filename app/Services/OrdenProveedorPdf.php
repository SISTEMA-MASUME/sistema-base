<?php

namespace App\Services;

use App\Models\OrdenProveedor;
use App\Support\LinkCompra;
use App\Support\Precios;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;

/**
 * PDF de la OC de MASUME a un proveedor. Nunca incluye precio de venta ni utilidad.
 */
class OrdenProveedorPdf
{
    private const CAJA_MM = 13.2;

    public static function generar(OrdenProveedor $o): string
    {
        $html = self::html($o);

        $tmp = storage_path('app/mpdf-tmp');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'Letter',
            'margin_top' => 14,
            'margin_right' => 12,
            'margin_bottom' => 16,
            'margin_left' => 12,
            'margin_footer' => 7,
            'tempDir' => $tmp,
            'default_font' => 'freesans',
            'shrink_tables_to_fit' => 0,
        ]);
        $mpdf->SetTitle('Orden de compra '.$o->folioTexto());
        $mpdf->SetAuthor(config('masume.empresa.nombre'));
        $mpdf->SetHTMLFooter(self::pie($o));

        $anterior = ini_set('pcre.backtrack_limit', '5000000');
        try {
            $mpdf->WriteHTML($html);
            $bytes = $mpdf->Output('', 'S');
        } finally {
            if ($anterior !== false) {
                ini_set('pcre.backtrack_limit', $anterior);
            }
        }

        return $bytes;
    }

    /** HTML intermedio (público para pruebas). */
    public static function html(OrdenProveedor $o): string
    {
        $o->loadMissing(['proveedor', 'pedido.cliente', 'user']);
        $partidas = $o->partidas()->get();
        $iva = Precios::iva();

        $sinIva = $partidas->sum(fn ($p) => (float) $p->costo * (float) $p->cantidad);
        $totales = Precios::totales([$sinIva]);

        $atendio = $partidas->pluck('proveedor_vendedor')->map(fn ($v) => trim((string) $v))
            ->filter()->unique()->implode(', ');

        $logo = public_path('logo-masume.png');

        return view('pdf.orden-proveedor', [
            'o' => $o,
            'empresa' => config('masume.empresa'),
            'logo' => is_file($logo) ? $logo : null,
            'atendio' => $atendio,
            'ivaPct' => rtrim(rtrim(number_format($iva * 100, 2, '.', ''), '0'), '.'),
            'totales' => array_map(fn ($v) => self::dinero($v), $totales),
            'partidas' => $partidas->map(fn ($p) => [
                'descripcion' => $p->descripcion,
                'marca' => $p->marca,
                'modelo' => LinkCompra::esUrl($p->modelo) ? '' : $p->modelo,
                'cantidad' => rtrim(rtrim(number_format((float) $p->cantidad, 3, '.', ''), '0'), '.'),
                'unidad' => $p->unidad,
                'unitario' => self::dinero((float) $p->costo * (1 + $iva)),
                'importe' => self::dinero((float) $p->costo * (float) $p->cantidad * (1 + $iva)),
                'img' => self::imagen($p->imagen_path),
            ])->all(),
        ])->render();
    }

    public static function pie(OrdenProveedor $o): string
    {
        $e = config('masume.empresa');
        $txt = e($e['nombre'].' · '.$e['correo'].' · '.$e['telefono']);

        return '<table width="100%" style="font-family:freesans; font-size:8pt; color:#6b7280;"><tr>'
            .'<td width="60%">'.$txt.'</td>'
            .'<td width="40%" style="text-align:right;">Orden de compra '.e($o->folioTexto()).' · Página {PAGENO} de {nbpg}</td>'
            .'</tr></table>';
    }

    private static function dinero(float|string|null $v): string
    {
        return '$'.number_format((float) $v, 2);
    }

    private static function imagen(?string $path): ?array
    {
        if (! $path) {
            return null;
        }
        $ruta = Storage::disk('public')->path($path);
        $info = is_file($ruta) ? @getimagesize($ruta) : false;
        if (! $info || $info[0] < 1 || $info[1] < 1) {
            return null;
        }
        $lado = self::CAJA_MM - 0.6;
        $esc = min($lado / $info[0], $lado / $info[1]);
        $w = round($info[0] * $esc, 2);
        $h = round($info[1] * $esc, 2);

        return ['ruta' => $ruta, 'w' => $w, 'h' => $h, 'top' => round(max(0, (self::CAJA_MM - 0.5 - $h) / 2), 2)];
    }
}

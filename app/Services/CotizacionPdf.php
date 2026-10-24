<?php

namespace App\Services;

use App\Models\Cotizacion;
use App\Support\CuentaCorreo;
use App\Support\NumeroALetras;
use App\Support\Precios;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;

/**
 * Genera el PDF de una cotización con mPDF. Nunca incluye costo ni proveedor.
 */
class CotizacionPdf
{
    private const CAJA_MM = 13.2; // 50 px

    /** Bytes del PDF. */
    public static function generar(Cotizacion $c): string
    {
        $html = self::html($c);

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
        $mpdf->SetTitle('Cotización '.$c->folio);
        $mpdf->SetAuthor(config('masume.empresa.nombre'));
        $mpdf->SetHTMLFooter(self::pie($c));

        // Cotizaciones largas (con muchas partidas) exceden el límite de PCRE por defecto.
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

    /** Guarda en el disco local (cotizaciones/) y devuelve la ruta relativa al disco. */
    public static function guardar(Cotizacion $c): string
    {
        $ruta = 'cotizaciones/'.$c->nombreArchivoPdf();
        Storage::disk('local')->put($ruta, self::generar($c));

        return $ruta;
    }

    /** HTML intermedio (público para pruebas). */
    public static function html(Cotizacion $c): string
    {
        $c->loadMissing(['cliente', 'contacto', 'vendedor']);
        $partidas = $c->partidasIncluidas()->get();
        $totales = Precios::totales($partidas->pluck('importe'));

        $contacto = $c->contacto;
        $contactoLinea = collect([
            $contacto?->correo ?: $c->cliente->correo,
            $contacto?->telefono ?: $c->cliente->telefono,
        ])->filter()->implode(' · ');

        $logo = public_path('logo-masume.png');

        return view('pdf.cotizacion', [
            'c' => $c,
            'empresa' => self::empresa($c),
            'notasPie' => config('masume.cotizacion.notas_pie'),
            'logo' => is_file($logo) ? $logo : null,
            'contactoLinea' => $contactoLinea,
            'ivaPct' => rtrim(rtrim(number_format(Precios::iva() * 100, 2, '.', ''), '0'), '.'),
            'totales' => array_map(fn ($v) => self::dinero($v), $totales),
            'letra' => NumeroALetras::pesos($totales['total']),
            'partidas' => $partidas->map(fn ($p) => [
                'descripcion' => $p->descripcion,
                // El modelo es interno: a veces es el SKU del proveedor y con él el cliente daría con él.
                'meta' => (string) $p->marca,
                'entrega' => $p->tiempo_entrega,
                'lenta' => (bool) $p->entrega_lenta,
                'cantidad' => rtrim(rtrim(number_format((float) $p->cantidad, 3, '.', ''), '0'), '.'),
                'unidad' => $p->unidad,
                'precio' => self::dinero($p->precio),
                'importe' => self::dinero($p->importe),
                'img' => self::imagen($p->imagen_path),
            ])->all(),
        ])->render();
    }

    /** Datos de MASUME para el PDF; el correo es el de la cuenta del vendedor (Rosa → ventas2@…). */
    private static function empresa(Cotizacion $c): array
    {
        $e = config('masume.empresa');
        $e['correo'] = CuentaCorreo::de($c->vendedor) ?? $e['correo'];

        return $e;
    }

    public static function pie(Cotizacion $c): string
    {
        $c->loadMissing('vendedor');
        $e = self::empresa($c);
        $txt = e($e['nombre'].' · '.$e['correo'].' · '.$e['telefono']);

        return '<table width="100%" style="font-family:freesans; font-size:8pt; color:#6b7280;"><tr>'
            .'<td width="60%">'.$txt.'</td>'
            .'<td width="40%" style="text-align:right;">Cotización '.e($c->folioConRevision()).' · Página {PAGENO} de {nbpg}</td>'
            .'</tr></table>';
    }

    private static function dinero(float|string|null $v): string
    {
        return '$'.number_format((float) $v, 2);
    }

    /** Ruta absoluta y medidas (mm) para caber en 50×50 px, o null si no hay imagen. */
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

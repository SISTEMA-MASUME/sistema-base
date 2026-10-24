<?php

namespace App\Services\Ned;

use App\Models\Ned\NedCotizacion;
use App\Models\Ned\NedDatos;
use App\Support\NumeroALetras;
use Mpdf\Mpdf;

/** PDF de la cotización de Ned Tracking, con sus datos y su logo (no lleva nada de MASUME). */
class NedCotizacionPdf
{
    public static function generar(NedCotizacion $c): string
    {
        $tmp = storage_path('app/mpdf-tmp');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }
        $datos = NedDatos::actuales();

        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'Letter',
            'margin_top' => 14, 'margin_right' => 12, 'margin_bottom' => 16, 'margin_left' => 12, 'margin_footer' => 7,
            'tempDir' => $tmp, 'default_font' => 'freesans',
        ]);
        $mpdf->SetTitle('Cotización '.$c->folioTexto());
        $mpdf->SetAuthor($datos->empresa);
        $mpdf->SetHTMLFooter('<table width="100%" style="font-family:freesans;font-size:8pt;color:#6b7280;"><tr><td>'
            .e(trim($datos->regimen.' - R.F.C.: '.$datos->rfc, ' -')).'</td><td style="text-align:right;">{PAGENO} / {nbpg}</td></tr></table>');
        $mpdf->WriteHTML(self::html($c, $datos));

        return $mpdf->Output('', 'S');
    }

    public static function html(NedCotizacion $c, ?NedDatos $datos = null): string
    {
        $datos ??= NedDatos::actuales();
        $logo = $datos->rutaLogo();
        $dinero = fn ($v) => number_format((float) $v, 2);

        return view('pdf.ned-cotizacion', [
            'c' => $c,
            'datos' => $datos,
            'logo' => $logo,
            'partidas' => $c->partidas()->get(),
            'dinero' => $dinero,
            'letra' => NumeroALetras::pesos((float) $c->total),
            'vence' => $c->fecha->copy()->addDays((int) $c->vigencia_dias)->format('d/m/y'),
        ])->render();
    }
}

<?php

namespace App\Support;

/**
 * Evita subir como "OC del cliente" un documento nuestro: la cotización o la OC a proveedor que genera el sistema
 * (mPDF les pone autor MASUME INDUSTRIAL) o una factura CFDI (trae folio fiscal / UUID). Pasó dos veces: la tarjeta
 * decía "OC 6265…" y al abrirla era la cotización o la factura.
 */
class DocumentoPropio
{
    /** Por qué ese archivo no parece la OC del cliente, o null si no se detecta nada raro. */
    public static function motivoNoEsOc(string $contenido, string $nombre = ''): ?string
    {
        if (! str_starts_with(ltrim($contenido), '%PDF')) {
            return null; // fotos o capturas: no se revisan
        }
        $autor = self::campoInfo($contenido, 'Author');
        $titulo = self::campoInfo($contenido, 'Title');
        if ($autor !== null && str_contains(mb_strtoupper($autor), 'MASUME')) {
            return 'Ese PDF lo hizo nuestro sistema'.($titulo ? " ({$titulo})" : '').': no es la OC del cliente. Sube el archivo que mandó el cliente.';
        }
        $leido = FacturaArchivo::leer($contenido, 'factura_pdf', $nombre);
        if ($leido['uuid']) {
            return 'Ese PDF es una factura (CFDI, folio fiscal '.$leido['uuid'].'): no es la OC del cliente. Súbela en la sección verde «Factura».';
        }

        return null;
    }

    /** /Author o /Title del PDF (mPDF los escribe en UTF-16 con BOM). */
    private static function campoInfo(string $pdf, string $campo): ?string
    {
        if (! preg_match('#/'.$campo.'\s*\((.*?)(?<!\\\\)\)#s', $pdf, $m)) {
            return null;
        }
        $v = $m[1];
        if (str_starts_with($v, "\xFE\xFF")) {
            $v = @mb_convert_encoding(substr($v, 2), 'UTF-8', 'UTF-16BE') ?: '';
        }
        $v = trim(preg_replace('/[^\P{C}]+/u', '', (string) $v) ?? '');

        return $v === '' ? null : $v;
    }
}

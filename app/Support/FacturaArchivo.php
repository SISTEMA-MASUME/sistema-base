<?php

namespace App\Support;

use App\Models\Pedido;
use App\Models\PedidoArchivo;
use App\Models\Venta;
use Illuminate\Support\Facades\Storage;

/**
 * Lee el UUID y la serie-folio de una factura CFDI subida al expediente del pedido y la liga con la venta de SICAR.
 * Sirve para los pedidos sin OC o sin comentario en SICAR. XML: exacto. PDF: el UUID si viene como texto legible (casi
 * siempre en el PDF de SICAR) o en el nombre del archivo. Ligar nunca duplica (Trazabilidad::ligarPedido).
 */
final class FacturaArchivo
{
    private const UUID = '/\b([0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12})\b/i';

    /** @return array{uuid: ?string, folio: ?string} */
    public static function leer(string $contenido, string $tipo, string $nombre = ''): array
    {
        $uuid = $folio = null;
        if ($tipo === 'factura_xml') {
            // Con expresiones regulares, sin cargar el XML (evita entidades externas).
            if (preg_match('/<(?:\w+:)?TimbreFiscalDigital\b[^>]*\bUUID="([^"]+)"/i', $contenido, $m)) {
                $uuid = $m[1];
            }
            if (preg_match('/<(?:\w+:)?Comprobante\b[^>]*>/i', $contenido, $c)) {
                $serie = preg_match('/\bSerie="([^"]*)"/', $c[0], $s) ? trim($s[1]) : '';
                $numero = preg_match('/\bFolio="([^"]*)"/', $c[0], $f) ? trim($f[1]) : '';
                $folio = $numero !== '' ? ($serie !== '' ? $serie.'-'.$numero : $numero) : null;
            }
        } else {
            $texto = $nombre.' '.self::sinIdentificadoresDelArchivo(self::textoPdf($contenido));
            if (preg_match(self::UUID, $texto, $m)) {
                $uuid = $m[1];
            }
        }

        return ['uuid' => $uuid ? mb_strtoupper($uuid) : null, 'folio' => $folio ? mb_strtoupper($folio) : null];
    }

    /**
     * Quita los identificadores que el programa que hizo el PDF le pone a CUALQUIER archivo (metadatos XMP:
     * DocumentID/InstanceID "uuid:…", "xmp.did:…"). Tienen la misma forma que un folio fiscal y hacían pasar una OC
     * de DELTA (hecha con WPTools) por factura (23-oct-2026). El folio fiscal real viene en el texto de la factura.
     */
    private static function sinIdentificadoresDelArchivo(string $texto): string
    {
        $texto = preg_replace('#<x:xmpmeta\b.*?</x:xmpmeta>#si', ' ', $texto) ?? $texto;
        $texto = preg_replace('#<(xmpMM:\w*ID)>.*?</\1>#si', ' ', $texto) ?? $texto;

        return preg_replace('#\b(?:uuid|xmp\.[a-z]+|adobe:docid:[a-z]+):[0-9a-f-]+#i', ' ', $texto) ?? $texto;
    }

    /** Texto crudo del PDF más sus flujos comprimidos (FlateDecode) descomprimidos; sin librerías. */
    private static function textoPdf(string $pdf): string
    {
        $texto = $pdf;
        $total = 0;
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $flujos)) {
            foreach (array_slice($flujos[1], 0, 200) as $f) {
                // Tope por flujo y total al descomprimir: un PDF chico podría inflarse a gigas.
                if (strlen($f) < 2_000_000 && ($plano = @gzuncompress($f, 5_000_000)) !== false) {
                    $texto .= ' '.$plano;
                    if (($total += strlen($plano)) > 20_000_000) {
                        break;
                    }
                }
            }
        }

        return $texto;
    }

    /** La venta de SICAR que corresponde a lo leído (por UUID; si no, por serie-folio). */
    public static function venta(?string $uuid, ?string $folio): ?Venta
    {
        $q = Venta::where('estatus', '!=', 'cancelada');
        if ($uuid && ($v = (clone $q)->whereRaw('UPPER(uuid) = ?', [$uuid])->first())) {
            return $v;
        }

        return $folio ? (clone $q)->where('folio_llave', Venta::llave('factura', $folio))->first() : null;
    }

    /** Al subir el archivo: guarda lo leído y liga si la venta ya llegó de SICAR. Regresa el folio ligado o null. */
    public static function procesar(PedidoArchivo $a): ?string
    {
        if (! in_array($a->tipo, ['factura_xml', 'factura_pdf'], true)) {
            return null;
        }
        $contenido = Storage::disk($a->disco ?: 'local')->get($a->ruta) ?? '';
        $datos = self::leer($contenido, $a->tipo, (string) $a->nombre_original);
        if (! $datos['uuid'] && ! $datos['folio']) {
            return null;
        }
        $a->update(['cfdi_uuid' => $datos['uuid'], 'cfdi_folio' => $datos['folio']]);
        $venta = self::venta($datos['uuid'], $datos['folio']);

        return $venta && self::ligar($a->pedido, $venta) ? $venta->folio : null;
    }

    /** Cuando SICAR manda una factura nueva: la liga con los pedidos que ya tienen su XML/PDF subido. */
    public static function alLlegarVenta(Venta $venta): int
    {
        if ($venta->tipo !== 'factura' || $venta->estatus === 'cancelada') {
            return 0;
        }
        $n = 0;
        PedidoArchivo::query()
            ->where(function ($q) use ($venta) {
                $q->whereRaw('1 = 0');
                if ($venta->uuid) {
                    $q->orWhere('cfdi_uuid', mb_strtoupper($venta->uuid));
                }
                $q->orWhere('cfdi_folio', mb_strtoupper(preg_replace('/\s+/u', '', $venta->folio)));
            })
            ->with('pedido')->get()
            ->each(function (PedidoArchivo $a) use ($venta, &$n) {
                if ($a->pedido && self::ligar($a->pedido, $venta)) {
                    $n++;
                }
            });

        return $n;
    }

    private static function ligar(Pedido $pedido, Venta $venta): bool
    {
        // false = ya estaba ligada: no se duplica.
        return Trazabilidad::ligarPedido($pedido, $venta, 'sicar', 'ligada sola al subir su archivo (UUID/folio del CFDI).');
    }
}

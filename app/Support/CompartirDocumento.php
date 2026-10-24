<?php

namespace App\Support;

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoOrdenProveedor;
use App\Models\Cotizacion;
use App\Models\DocumentoEnvio;
use App\Models\OrdenProveedor;
use App\Services\CotizacionPdf;
use App\Services\OrdenProveedorPdf;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Compartir cotizaciones y OC a proveedores (WhatsApp, correo, descarga y enlace seguro).
 * Cada uso queda en documento_envios; compartir marca el documento como enviado.
 */
class CompartirDocumento
{
    public const TIPOS = ['cotizacion' => Cotizacion::class, 'orden_proveedor' => OrdenProveedor::class];

    public const DIAS_ENLACE = 30;

    public const CANALES = [
        'correo' => 'Enviado por correo',
        'whatsapp' => 'Compartido por WhatsApp',
        'descarga' => 'Descarga',
        'enlace' => 'Abrió el enlace',
    ];

    public static function buscar(string $tipo, int $id): Cotizacion|OrdenProveedor
    {
        abort_unless(isset(self::TIPOS[$tipo]), 404);

        return self::TIPOS[$tipo]::findOrFail($id);
    }

    public static function tipo(Cotizacion|OrdenProveedor $doc): string
    {
        return $doc instanceof Cotizacion ? 'cotizacion' : 'orden_proveedor';
    }

    /** Enlace de descarga sin sesión, firmado y con vencimiento. Lleva quién lo compartió para el registro. */
    public static function enlace(Cotizacion|OrdenProveedor $doc, ?int $userId = null): string
    {
        // Firma relativa (solo ruta y parámetros): no se rompe si el servidor ve http detrás del proxy https.
        return url(URL::temporarySignedRoute('documentos.publico', now()->addDays(self::DIAS_ENLACE), [
            'tipo' => self::tipo($doc),
            'id' => $doc->id,
            'u' => $userId ?? auth()->id(),
            // Revisión con la que se compartió: el enlace sigue mostrando ese PDF aunque después se edite (Rev. N+1).
            'r' => $doc instanceof Cotizacion ? (int) $doc->revision : null,
        ], absolute: false));
    }

    /** Copia fija del PDF de una cotización tal como se mandó en esa revisión (disco privado). */
    public static function rutaCopia(Cotizacion $cot, int $revision): string
    {
        return 'documentos-enviados/cotizacion-'.$cot->id.'-rev'.$revision.'.pdf';
    }

    /** Guarda la copia del PDF de la revisión actual la primera vez que se manda (no se vuelve a escribir). */
    public static function guardarCopia(Cotizacion $cot, ?string $bytes = null): void
    {
        $ruta = self::rutaCopia($cot, (int) $cot->revision);
        if (! Storage::disk('local')->exists($ruta)) {
            Storage::disk('local')->put($ruta, $bytes ?? CotizacionPdf::generar($cot));
        }
    }

    public static function envios(Cotizacion|OrdenProveedor $doc): MorphMany
    {
        return $doc instanceof Cotizacion ? $doc->documentoEnvios() : $doc->envios();
    }

    public static function pdf(Cotizacion|OrdenProveedor $doc): string
    {
        return $doc instanceof Cotizacion ? CotizacionPdf::generar($doc) : OrdenProveedorPdf::generar($doc);
    }

    public static function nombreArchivo(Cotizacion|OrdenProveedor $doc): string
    {
        return $doc->nombreArchivoPdf();
    }

    /**
     * Registra el envío y aplica el cambio de estado: OC emitida → enviada (y ya no requiere reenvío);
     * cotización en borrador → enviada, sin abrir revisión (igual que el correo).
     */
    public static function registrar(Cotizacion|OrdenProveedor $doc, string $canal, ?string $destinatario = null, ?int $userId = null, bool $exito = true, ?string $error = null): DocumentoEnvio
    {
        $envio = self::envios($doc)->create([
            'user_id' => $userId ?? auth()->id(),
            'canal' => $canal,
            'destinatario' => $destinatario !== null ? mb_substr($destinatario, 0, 255) : null,
            'revision' => $doc instanceof Cotizacion ? (int) $doc->revision : 0,
            'exito' => $exito,
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
        ]);

        // Que un tercero abra el enlace no cambia estados: solo lo hace quien comparte.
        if (! $exito || $canal === 'enlace') {
            return $envio;
        }

        if ($doc instanceof OrdenProveedor) {
            if ($doc->estado === EstadoOrdenProveedor::Emitida) {
                $doc->estado = EstadoOrdenProveedor::Enviada;
            }
            if ($doc->estado !== EstadoOrdenProveedor::Cancelada) {
                $doc->requiere_reenvio = false;
            }
            $doc->save();
        } else {
            // El paso automático a "enviada" no es un cambio del documento: no va a la bitácora.
            Bitacora::sinRegistro(function () use ($doc) {
                $doc->enviada_at = now();
                if ($doc->estado === EstadoCotizacion::Borrador) {
                    $doc->estado = EstadoCotizacion::Enviada;
                }
                $doc->save();
            });
            // Copia fija de lo que se mandó (para que el enlace no cambie si después se edita). Nunca frena el envío.
            try {
                self::guardarCopia($doc);
            } catch (\Throwable $e) {
                RegistroErrores::registrar($e);
            }
            // La solicitud "Por cotizar" de la que salió esta cotización queda cotizada.
            // Nunca debe impedir que el envío quede registrado.
            try {
                Solicitudes::alEnviarCotizacion($doc);
            } catch (\Throwable $e) {
                RegistroErrores::registrar($e);
            }
        }

        return $envio;
    }
}

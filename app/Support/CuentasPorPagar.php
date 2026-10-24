<?php

namespace App\Support;

use App\Enums\EstadoOrdenProveedor;
use App\Models\CuentaPorPagar;
use App\Models\OrdenProveedor;
use App\Models\PagoProveedor;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * "A quién le debo": compras a crédito a proveedores. Una OC con método CRÉDITO crea (y mantiene al día) su deuda; también
 * se capturan a mano. Pagar liquida una o varias deudas del mismo proveedor con fecha, cuenta y comprobante; un pago se
 * puede anular ("fue un error") y sus deudas regresan a pendientes. Nada se borra: queda la trazabilidad.
 */
final class CuentasPorPagar
{
    /** Total pendiente (con IVA) de todas las deudas a proveedores. */
    public static function totalPendiente(): float
    {
        return round((float) CuentaPorPagar::where('estatus', 'pendiente')->sum('importe'), 2);
    }

    /**
     * Pone la deuda de una OC según cómo está la OC: a crédito y vigente → pendiente con el total con IVA; cancelada o ya
     * no a crédito → la deuda pendiente se cancela. Una deuda ya pagada no se toca. Seguro de llamar varias veces.
     */
    public static function sincronizarOrden(OrdenProveedor $orden): void
    {
        $deuda = CuentaPorPagar::where('orden_proveedor_id', $orden->id)->first();
        $aCredito = $orden->metodo_pago === EstadoOrdenProveedor::CREDITO && $orden->estado !== EstadoOrdenProveedor::Cancelada;

        if ($deuda && $deuda->estatus === 'pagada') {
            return;
        }
        if (! $aCredito) {
            $deuda?->update(['estatus' => 'cancelada']);

            return;
        }
        $importe = round((float) $orden->total_costo * (1 + Precios::iva()), 2);
        $datos = ['proveedor_id' => $orden->proveedor_id, 'importe' => $importe, 'estatus' => 'pendiente'];
        if ($deuda) {
            $deuda->update($datos);

            return;
        }
        CuentaPorPagar::create($datos + [
            'orden_proveedor_id' => $orden->id,
            'folio' => $orden->folioTexto(),
            'descripcion' => 'Pedido P-'.($orden->pedido?->folio ?? '?').($orden->pedido?->cliente?->nombre ? ' · '.$orden->pedido->cliente->nombre : ''),
            'fecha' => ($orden->created_at ?? now())->toDateString(),
            'user_id' => $orden->user_id,
        ]);
    }

    /**
     * Captura a mano una deuda (p. ej. las facturas que ya se debían).
     *
     * @param  array{proveedor_id: int, folio: ?string, descripcion: ?string, fecha: string, vence: ?string, importe: float}  $d
     */
    public static function agregar(array $d, User $u): CuentaPorPagar
    {
        if ($d['importe'] <= 0) {
            throw new DomainException('El importe debe ser mayor a cero.');
        }
        $folio = trim((string) ($d['folio'] ?? ''));
        if ($folio !== '' && CuentaPorPagar::where('proveedor_id', $d['proveedor_id'])->where('folio', $folio)->where('estatus', '!=', 'cancelada')->exists()) {
            throw new DomainException('Ya está anotada la factura '.$folio.' de ese proveedor.');
        }

        return CuentaPorPagar::create([
            'proveedor_id' => $d['proveedor_id'], 'folio' => $folio !== '' ? mb_substr($folio, 0, 60) : null,
            'descripcion' => filled($d['descripcion'] ?? null) ? mb_substr(trim($d['descripcion']), 0, 255) : null,
            'fecha' => $d['fecha'], 'vence' => filled($d['vence'] ?? null) ? $d['vence'] : null,
            'importe' => round($d['importe'], 2), 'estatus' => 'pendiente', 'user_id' => $u->id,
        ]);
    }

    /**
     * Paga deudas pendientes de UN proveedor: quedan "pagadas" ligadas al pago con su comprobante.
     *
     * @param  list<int>  $ids
     */
    public static function pagar(array $ids, string $fecha, ?int $cuentaId, ?string $referencia, ?UploadedFile $comprobante, User $u): PagoProveedor
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw new DomainException('Marca primero lo que pagaste.');
        }
        $ruta = null;
        if ($comprobante) {
            $ext = strtolower($comprobante->getClientOriginalExtension() ?: $comprobante->extension() ?: 'pdf');
            $ruta = $comprobante->storeAs('pagos-proveedor', bin2hex(random_bytes(12)).'.'.$ext, 'local');
        }

        try {
            return DB::transaction(function () use ($ids, $fecha, $cuentaId, $referencia, $comprobante, $ruta, $u) {
                $deudas = CuentaPorPagar::whereIn('id', $ids)->lockForUpdate()->get();
                if ($deudas->count() !== count($ids) || $deudas->contains(fn ($d) => $d->estatus !== 'pendiente')) {
                    throw new DomainException('Alguna de las marcadas ya no está pendiente. Recarga la página.');
                }
                if ($deudas->pluck('proveedor_id')->unique()->count() > 1) {
                    throw new DomainException('Marca deudas de un solo proveedor por pago.');
                }
                $pago = PagoProveedor::create([
                    'proveedor_id' => $deudas->first()->proveedor_id, 'fecha' => $fecha, 'cuenta_id' => $cuentaId,
                    'referencia' => filled($referencia) ? mb_substr(trim($referencia), 0, 120) : null,
                    'monto' => round((float) $deudas->sum('importe'), 2),
                    'comprobante_path' => $ruta,
                    'comprobante_nombre' => $comprobante ? mb_substr($comprobante->getClientOriginalName(), 0, 255) : null,
                    'comprobante_mime' => $comprobante?->getMimeType(),
                    'detalle' => $deudas->map(fn ($d) => ['id' => $d->id, 'folio' => $d->folio, 'importe' => (float) $d->importe])->values()->all(),
                    'user_id' => $u->id,
                ]);
                CuentaPorPagar::whereIn('id', $ids)->update(['estatus' => 'pagada', 'pago_id' => $pago->id]);

                return $pago;
            });
        } catch (\Throwable $e) {
            if ($ruta) {
                Storage::disk('local')->delete($ruta);
            }
            throw $e;
        }
    }

    /** Tipos de comprobante que se aceptan (PDF o foto/captura; las fotos de iPhone en HEIC también). */
    public const MIMES_COMPROBANTE = 'pdf,jpg,jpeg,png,webp,gif,heic,heif';

    /** Subir o cambiar el comprobante de un pago ya registrado (si al pagar no se pudo subir). */
    public static function adjuntarComprobante(PagoProveedor $pago, UploadedFile $archivo): void
    {
        $ext = strtolower($archivo->getClientOriginalExtension() ?: $archivo->extension() ?: 'pdf');
        $ruta = $archivo->storeAs('pagos-proveedor', bin2hex(random_bytes(12)).'.'.$ext, 'local');
        $anterior = $pago->comprobante_path;
        $pago->update([
            'comprobante_path' => $ruta,
            'comprobante_nombre' => mb_substr($archivo->getClientOriginalName(), 0, 255),
            'comprobante_mime' => $archivo->getMimeType(),
        ]);
        if ($anterior && $anterior !== $ruta) {
            Storage::disk('local')->delete($anterior);
        }
    }

    /** "Fue un error": el pago queda anulado (no se borra) y sus deudas regresan a pendientes. */
    public static function anularPago(PagoProveedor $pago, User $u): void
    {
        DB::transaction(function () use ($pago, $u) {
            $pago = PagoProveedor::lockForUpdate()->findOrFail($pago->id);
            if ($pago->anulado_at) {
                return;
            }
            CuentaPorPagar::where('pago_id', $pago->id)->where('estatus', 'pagada')->update(['estatus' => 'pendiente', 'pago_id' => null]);
            $pago->update(['anulado_at' => now(), 'anulado_por' => $u->id]);
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pago a un proveedor que liquida una o varias cuentas por pagar, con su comprobante (trazabilidad). */
class PagoProveedor extends Model
{
    protected $table = 'pagos_proveedor';

    protected $fillable = ['proveedor_id', 'fecha', 'cuenta_id', 'referencia', 'monto', 'comprobante_path', 'comprobante_nombre',
        'comprobante_mime', 'detalle', 'user_id', 'anulado_at', 'anulado_por'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'monto' => 'decimal:2',
            'anulado_at' => 'datetime',
            'detalle' => 'array',
        ];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Las deudas que liquidó (las de un pago anulado ya regresaron a pendientes y se ven en la bitácora del pago). */
    public function deudas(): HasMany
    {
        return $this->hasMany(CuentaPorPagar::class, 'pago_id');
    }
}

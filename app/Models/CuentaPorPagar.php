<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lo que MASUME le debe a un proveedor por una compra a crédito (desde una OC con método CRÉDITO o capturada a mano). */
class CuentaPorPagar extends Model
{
    protected $table = 'cuentas_por_pagar';

    protected $fillable = ['proveedor_id', 'orden_proveedor_id', 'folio', 'descripcion', 'fecha', 'vence', 'importe', 'estatus', 'pago_id', 'user_id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'vence' => 'date',
            'importe' => 'decimal:2',
        ];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenProveedor::class, 'orden_proveedor_id');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(PagoProveedor::class, 'pago_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

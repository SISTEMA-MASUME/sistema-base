<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bitácora de una venta: quién cambió costo, pago o estatus y cuándo (solo inserción). */
class VentaCambio extends Model
{
    protected $table = 'venta_cambios';

    public const UPDATED_AT = null;

    protected $fillable = ['venta_id', 'user_id', 'campo', 'antes', 'despues', 'origen', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

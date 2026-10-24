<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Liga entre documentos: la nota (u otra factura) `origen` quedó reemplazada por la factura `destino`. */
class VentaVinculo extends Model
{
    protected $table = 'venta_vinculos';

    public const UPDATED_AT = null;

    public const TIPOS = ['facturada_en' => 'Se facturó en', 'sustituida_por' => 'Sustituida por'];

    protected $fillable = ['origen_id', 'destino_id', 'tipo', 'fuente', 'estatus_anterior', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'origen_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'destino_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

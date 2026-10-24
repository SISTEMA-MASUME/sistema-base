<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplementoPago extends Model
{
    protected $table = 'complementos_pago';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha_pago' => 'date', 'importe' => 'decimal:2', 'documentos' => 'array',
            'cancelado' => 'boolean', 'requiere_revision' => 'boolean'];
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(DepositoCobro::class, 'deposito_id');
    }
}

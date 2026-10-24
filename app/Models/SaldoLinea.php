<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Saldo de una cuenta en un corte (en tarjetas y créditos es lo que se debe). */
class SaldoLinea extends Model
{
    protected $table = 'saldo_lineas';

    protected $fillable = ['saldo_corte_id', 'cuenta_id', 'saldo', 'pago_sin_intereses', 'fecha_limite'];

    protected function casts(): array
    {
        return ['saldo' => 'decimal:2', 'pago_sin_intereses' => 'decimal:2', 'fecha_limite' => 'date'];
    }

    public function corte(): BelongsTo
    {
        return $this->belongsTo(SaldoCorte::class, 'saldo_corte_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class, 'cuenta_id');
    }
}

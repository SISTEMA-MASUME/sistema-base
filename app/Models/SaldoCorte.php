<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un "corte" de saldos: el día que se llenaron los saldos de las cuentas (Mis finanzas). */
class SaldoCorte extends Model
{
    protected $table = 'saldo_cortes';

    protected $fillable = ['fecha', 'user_id', 'notas'];

    protected function casts(): array
    {
        return ['fecha' => 'date'];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(SaldoLinea::class, 'saldo_corte_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

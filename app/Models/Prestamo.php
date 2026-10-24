<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Préstamo a un empleado; se descuenta en la nómina (descuento_semanal) o con abonos sueltos. */
class Prestamo extends Model
{
    protected $table = 'prestamos';

    protected $fillable = ['user_id', 'nombre', 'monto', 'fecha', 'descuento_semanal', 'notas', 'saldado_at'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'decimal:2', 'descuento_semanal' => 'decimal:2', 'saldado_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function abonos(): HasMany
    {
        return $this->hasMany(PrestamoAbono::class)->orderBy('fecha')->orderBy('id');
    }

    public function saldo(): float
    {
        return max(0, round((float) $this->monto - (float) $this->abonos()->sum('monto'), 2));
    }

    public function scopeActivos(Builder $q): Builder
    {
        return $q->whereNull('saldado_at');
    }

    /** Marca saldado si ya no debe (o lo reabre si se quitó un abono). */
    public function revisarSaldo(): void
    {
        $saldado = $this->saldo() <= 0.004;
        if ($saldado && ! $this->saldado_at) {
            $this->update(['saldado_at' => now()]);
        } elseif (! $saldado && $this->saldado_at) {
            $this->update(['saldado_at' => null]);
        }
    }
}

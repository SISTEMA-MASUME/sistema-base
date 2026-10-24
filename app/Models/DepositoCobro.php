<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepositoCobro extends Model
{
    protected $table = 'depositos_cobro';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'importe' => 'decimal:2'];
    }

    public function folio(): string
    {
        return 'DEP-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    public function complemento(): BelongsTo
    {
        return $this->belongsTo(ComplementoPago::class);
    }

    public function facturas(): BelongsToMany
    {
        return $this->belongsToMany(Venta::class, 'deposito_facturas', 'deposito_id', 'venta_id')
            ->withPivot(['importe', 'original', 'activa'])->withTimestamps();
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(DepositoEvento::class, 'deposito_id')->latest('id');
    }
}

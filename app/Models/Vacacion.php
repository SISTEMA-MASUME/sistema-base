<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vacaciones tomadas (con fechas) o ajustes de días del admin (la fecha del ajuste va en `desde`). */
class Vacacion extends Model
{
    protected $table = 'vacaciones';

    protected $fillable = ['user_id', 'tipo', 'desde', 'hasta', 'dias', 'notas', 'estatus', 'evento_id', 'registrada_por'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date', 'dias' => 'decimal:1'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function esAjuste(): bool
    {
        return $this->tipo === 'ajuste';
    }

    public function estaCancelada(): bool
    {
        return $this->estatus === 'cancelada';
    }
}

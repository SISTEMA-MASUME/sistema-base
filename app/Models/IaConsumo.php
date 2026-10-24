<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Gasto de IA fuera de la conciliación de OC (p. ej. sugerir claves SAT); cuenta para el tope mensual. */
class IaConsumo extends Model
{
    protected $table = 'ia_consumos';

    protected $fillable = ['uso', 'modelo', 'tokens_entrada', 'tokens_salida', 'costo_usd', 'user_id'];

    protected function casts(): array
    {
        return ['costo_usd' => 'decimal:5'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Para la cuenta del programa: 'compra' = compra que no pasó por una OC del sistema (o nota de crédito, en negativo);
 * 'total' = lo que dice el portal del proveedor (Urreanet) que va facturado en el trimestre a esa fecha: manda sobre lo
 * anterior y solo se le suma lo que se compre después.
 */
class ProgramaAjuste extends Model
{
    protected $table = 'programa_ajustes';

    protected $fillable = ['programa_comercial_id', 'tipo', 'fecha', 'monto', 'nota', 'user_id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrestamoAbono extends Model
{
    protected $table = 'prestamo_abonos';

    public const UPDATED_AT = null;

    protected $fillable = ['prestamo_id', 'nomina_id', 'fecha', 'monto', 'nota', 'created_at'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function prestamo(): BelongsTo
    {
        return $this->belongsTo(Prestamo::class);
    }

    public function nomina(): BelongsTo
    {
        return $this->belongsTo(Nomina::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lo de una persona en una nómina: base + comisión + bono/descuento − préstamo = total. */
class NominaLinea extends Model
{
    protected $table = 'nomina_lineas';

    protected $fillable = ['nomina_id', 'user_id', 'nombre', 'sueldo_base', 'comision_pct', 'comision', 'comision_manual', 'ajuste',
        'descuento_prestamo', 'total', 'notas', 'cuenta_id'];

    protected function casts(): array
    {
        return ['sueldo_base' => 'decimal:2', 'comision_pct' => 'decimal:2', 'comision' => 'decimal:2', 'comision_manual' => 'boolean',
            'ajuste' => 'decimal:2', 'descuento_prestamo' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function nomina(): BelongsTo
    {
        return $this->belongsTo(Nomina::class);
    }

    /** Desde dónde se le paga esta semana (vacío = la del empleado o la general de la nómina). */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function calcularTotal(): void
    {
        $this->total = round((float) $this->sueldo_base + (float) $this->comision + (float) $this->ajuste - (float) $this->descuento_prestamo, 2);
    }
}

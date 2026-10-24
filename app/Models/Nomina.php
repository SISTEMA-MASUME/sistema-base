<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Nómina semanal (lunes a sábado) o reparto mensual al socio. Borrador → pagada (cerrada). */
class Nomina extends Model
{
    protected $table = 'nominas';

    protected $fillable = ['tipo', 'anio', 'semana', 'mes', 'desde', 'hasta', 'utilidad', 'bolsa', 'gastos', 'estatus', 'pagada_at',
        'user_id', 'origen', 'notas'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date', 'pagada_at' => 'datetime', 'utilidad' => 'decimal:2', 'bolsa' => 'decimal:2',
            'gastos' => 'decimal:2'];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(NominaLinea::class)->orderBy('id');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pagada(): bool
    {
        return $this->estatus === 'pagada';
    }

    public function total(): float
    {
        return round((float) $this->lineas()->sum('total'), 2);
    }
}

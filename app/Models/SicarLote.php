<?php

namespace App\Models;

use App\Enums\EstadoLoteSicar;
use App\Support\Precios;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Lote de alta en SICAR: snapshot de lo que la web manda a la PC de Daniel y lo que ésta devuelve. */
class SicarLote extends Model
{
    protected $table = 'sicar_lotes';

    protected $fillable = ['estado', 'datos', 'firmas', 'resultado', 'mensaje', 'creado_por', 'confirmado_por', 'simulado_at', 'confirmado_at', 'aplicado_at'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoLoteSicar::class,
            'datos' => 'array',
            'firmas' => 'array',
            'resultado' => 'array',
            'simulado_at' => 'datetime',
            'confirmado_at' => 'datetime',
            'aplicado_at' => 'datetime',
        ];
    }

    public function pedidos(): BelongsToMany
    {
        return $this->belongsToMany(Pedido::class, 'sicar_lote_pedido', 'lote_id', 'pedido_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function confirmador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    /** @param  Builder<self>  $q */
    public function scopeAbiertos(Builder $q): Builder
    {
        return $q->whereIn('estado', array_map(fn ($e) => $e->value, EstadoLoteSicar::abiertos()));
    }

    /**
     * Bloqueos que reportó la PC en el simulacro.
     *
     * @return list<string>
     */
    public function bloqueos(): array
    {
        return array_values((array) ($this->resultado['bloqueos'] ?? []));
    }

    /**
     * Compras de `datos` con sus totales (subtotal, IVA, total) calculados.
     *
     * @return list<array{proveedor: string, renglones: list<array<string, mixed>>, subtotal: float, iva: float, total: float}>
     */
    public function comprasConTotales(): array
    {
        $out = [];
        foreach ((array) ($this->datos['compras'] ?? []) as $c) {
            $renglones = array_values((array) ($c['renglones'] ?? []));
            $t = Precios::totales(array_map(fn ($r) => Precios::importe((float) ($r['costo'] ?? 0), (float) ($r['cantidad'] ?? 0)), $renglones));
            $out[] = ['proveedor' => (string) ($c['proveedor'] ?? ''), 'renglones' => $renglones, ...$t];
        }

        return $out;
    }
}

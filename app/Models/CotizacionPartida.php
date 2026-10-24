<?php

namespace App\Models;

use App\Observers\CotizacionPartidaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(CotizacionPartidaObserver::class)]
class CotizacionPartida extends Model
{
    use HasFactory;

    protected $table = 'cotizacion_partidas';

    protected $fillable = [
        'cotizacion_id', 'orden', 'incluir', 'descripcion', 'marca', 'modelo', 'unidad', 'cantidad',
        'proveedor_id', 'proveedor_vendedor', 'proveedor_telefono', 'link_compra', 'costo', 'utilidad_pct', 'precio', 'importe',
        'tiempo_entrega', 'entrega_lenta', 'imagen_path', 'articulo_id',
    ];

    protected function casts(): array
    {
        return [
            'incluir' => 'boolean',
            'entrega_lenta' => 'boolean',
            'cantidad' => 'decimal:3',
            'costo' => 'decimal:4',
            'utilidad_pct' => 'decimal:2',
            'precio' => 'decimal:2',
            'importe' => 'decimal:2',
        ];
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    /** Partidas de pedido que salieron de este renglón (cuándo se colocó). */
    public function pedidoPartidas(): HasMany
    {
        return $this->hasMany(PedidoPartida::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /** Artículo del catálogo elegido en "¿Es este?" (se pasa al pedido). */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }
}

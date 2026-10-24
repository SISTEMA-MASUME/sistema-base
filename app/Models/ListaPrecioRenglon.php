<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un producto de una lista de precios: clave del proveedor, descripción, datos del SAT, código de barras y costo. */
class ListaPrecioRenglon extends Model
{
    protected $table = 'lista_precio_renglones';

    protected $fillable = [
        'lista_precio_id', 'clave', 'clave_llave', 'codigo', 'descripcion', 'busqueda', 'marca', 'unidad', 'unidad_sat', 'clave_sat',
        'codigo_barras', 'precio_lista', 'costo', 'familia', 'articulo_id',
    ];

    protected function casts(): array
    {
        return ['precio_lista' => 'decimal:4', 'costo' => 'decimal:4'];
    }

    public function lista(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class, 'lista_precio_id');
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lista de precios de una marca (una por proveedor; se reemplaza al subir la del mes). Ver Support\ListasPrecios. */
class ListaPrecio extends Model
{
    protected $table = 'listas_precios';

    protected $fillable = ['proveedor_id', 'nombre', 'columna_precio', 'precio_con_iva', 'descuentos', 'descuento_pct', 'archivo', 'renglones', 'ligados', 'user_id', 'importada_at'];

    protected function casts(): array
    {
        return ['precio_con_iva' => 'boolean', 'descuento_pct' => 'decimal:3', 'importada_at' => 'datetime'];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class)->withTrashed();
    }

    public function renglonesLista(): HasMany
    {
        return $this->hasMany(ListaPrecioRenglon::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use App\Support\CatalogoImagenes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un proveedor que vende el artículo: su clave (SKU), el último costo SIN IVA y de qué pedido salió. */
class ArticuloProveedor extends Model
{
    protected $table = 'articulo_proveedores';

    protected $fillable = ['articulo_id', 'proveedor_id', 'clave', 'clave_llave', 'ultimo_costo', 'costo_fecha', 'pedido_id', 'link_compra', 'lista_precio_id'];

    protected function casts(): array
    {
        return ['ultimo_costo' => 'decimal:4', 'costo_fecha' => 'date'];
    }

    protected static function booted(): void
    {
        static::saving(function (ArticuloProveedor $p) {
            $p->clave_llave = CatalogoImagenes::llaveModelo($p->clave);
        });
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class)->withTrashed();
    }

    public function lista(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class, 'lista_precio_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }
}

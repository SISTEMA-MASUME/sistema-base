<?php

namespace App\Models;

use App\Support\Articulos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Artículo del catálogo: una sola vez, con sus proveedores y datos para facturar. Ver Support\Articulos. */
class Articulo extends Model
{
    use SoftDeletes;

    protected $table = 'articulos';

    protected $fillable = [
        'descripcion', 'descripcion_llave', 'marca', 'unidad', 'unidad_sat', 'clave_sat', 'codigo_barras',
        'sicar_art_id', 'sicar_clave', 'origen', 'ultimo_pedido_at', 'unido_a_id', 'user_id',
    ];

    public const ORIGENES = ['sicar' => 'De SICAR', 'pedido' => 'Nació en un pedido', 'lista' => 'Lista de precios', 'manual' => 'Alta a mano'];

    protected function casts(): array
    {
        return ['ultimo_pedido_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Articulo $a) {
            $a->descripcion_llave = Articulos::llave($a->descripcion);
            $a->busqueda = Articulos::textoBusqueda($a->descripcion, $a->marca);
        });
    }

    public function proveedores(): HasMany
    {
        return $this->hasMany(ArticuloProveedor::class)->orderByRaw('ultimo_costo is null')->orderBy('ultimo_costo');
    }

    public function nombres(): HasMany
    {
        return $this->hasMany(ArticuloNombre::class)->orderBy('id');
    }

    public function partidasPedido(): HasMany
    {
        return $this->hasMany(PedidoPartida::class);
    }

    public function unidoA(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'unido_a_id')->withTrashed();
    }

    /** ¿Le falta algo para poder facturarlo? (clave del SAT o unidad del SAT) */
    public function faltaDatoFiscal(): bool
    {
        return blank($this->clave_sat) || blank($this->unidad_sat);
    }
}

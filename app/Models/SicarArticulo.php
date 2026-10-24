<?php

namespace App\Models;

use App\Support\Nombres;
use Illuminate\Database\Eloquent\Model;

/** Artículo del catálogo de SICAR (copia sincronizada; SICAR es la fuente de verdad). */
class SicarArticulo extends Model
{
    protected $table = 'sicar_articulos';

    protected $primaryKey = 'art_id';

    public $incrementing = false;

    protected $fillable = [
        'art_id', 'clave', 'clave_alterna', 'descripcion', 'unidad', 'precio_compra', 'precio1',
        'clave_prod_serv', 'categoria', 'status', 'clave_llave', 'sincronizado_at',
    ];

    protected function casts(): array
    {
        return ['sincronizado_at' => 'datetime', 'status' => 'integer', 'precio_compra' => 'decimal:4', 'precio1' => 'decimal:4'];
    }

    /** Clave normalizada: mayúsculas, sin espacios, guiones, puntos ni acentos. */
    public static function llave(?string $clave): string
    {
        return Nombres::llave((string) $clave);
    }
}

<?php

namespace App\Models;

use App\Support\Articulos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Otro nombre con el que se conoce un artículo (p. ej. el de un repetido que se unió). */
class ArticuloNombre extends Model
{
    protected $table = 'articulo_nombres';

    protected $fillable = ['articulo_id', 'nombre', 'llave'];

    protected static function booted(): void
    {
        static::saving(function (ArticuloNombre $n) {
            $n->llave = Articulos::llave($n->nombre) ?? sha1('');
        });
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }
}

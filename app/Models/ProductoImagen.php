<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Imagen guardada de un producto (catálogo): se pone sola al cotizarlo otra vez. Ver Support\CatalogoImagenes. */
class ProductoImagen extends Model
{
    protected $table = 'producto_imagenes';

    protected $fillable = ['llave_modelo', 'llave_descripcion', 'descripcion', 'modelo', 'imagen_path', 'user_id', 'veces_usada'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

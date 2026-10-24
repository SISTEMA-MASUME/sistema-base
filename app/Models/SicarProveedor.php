<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Proveedor del catálogo de SICAR (lo sube la PC de Daniel). */
class SicarProveedor extends Model
{
    protected $table = 'sicar_proveedores';

    protected $fillable = ['pro_id', 'nombre', 'status', 'sincronizado_at'];

    protected function casts(): array
    {
        return ['sincronizado_at' => 'datetime', 'status' => 'integer'];
    }
}

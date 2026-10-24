<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vendedor o persona de contacto de un proveedor. El principal es el que se usa al mandar la OC. */
class ProveedorContacto extends Model
{
    protected $table = 'proveedor_contactos';

    protected $fillable = ['proveedor_id', 'nombre', 'puesto', 'correo', 'telefono', 'principal'];

    protected $casts = ['principal' => 'boolean'];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }
}

<?php

namespace App\Models\Ned;

use Illuminate\Database\Eloquent\Model;

/** Cliente guardado de Ned Tracking con sus contactos (p. ej. HOTEL CENTRAL: Sofía Luna y Laura Martínez). */
class NedCliente extends Model
{
    protected $table = 'ned_clientes';

    protected $fillable = ['nombre', 'direccion', 'correo', 'telefono', 'contactos', 'predeterminado'];

    protected $casts = ['contactos' => 'array', 'predeterminado' => 'boolean'];

    public static function predeterminado(): ?self
    {
        return self::where('predeterminado', true)->first();
    }

    /** Datos para una cotización nueva de Ned (el primer contacto queda en "Atención a"). */
    public static function datosNuevaCotizacion(): array
    {
        $c = self::predeterminado();

        return $c ? [
            'cliente_nombre' => $c->nombre,
            'cliente_direccion' => $c->direccion,
            'cliente_correo' => $c->correo,
            'cliente_telefono' => $c->telefono,
            'cliente_contacto' => $c->contactos[0] ?? null,
        ] : ['cliente_nombre' => 'CLIENTE NUEVO'];
    }
}

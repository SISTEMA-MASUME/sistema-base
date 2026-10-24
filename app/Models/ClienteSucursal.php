<?php

namespace App\Models;

use App\Support\CuentaCorreo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Plaza de un cliente con la misma razón social en varias ciudades (PARQUES / LUMEX). */
class ClienteSucursal extends Model
{
    use SoftDeletes;

    protected $table = 'cliente_sucursales';

    protected $fillable = ['cliente_id', 'nombre', 'ciudad', 'estado', 'direccion', 'correos_facturacion', 'contacto', 'telefono', 'notas'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'sucursal_id');
    }

    /** @return list<string> */
    public function correos(): array
    {
        return CuentaCorreo::separar((string) $this->correos_facturacion);
    }

    /** "LUMEX MÉRIDA · Mérida, Yucatán" */
    public function ubicacion(): string
    {
        $lugar = implode(', ', array_filter([$this->ciudad, $this->estado]));

        return $lugar !== '' ? $this->nombre.' · '.$lugar : $this->nombre;
    }
}

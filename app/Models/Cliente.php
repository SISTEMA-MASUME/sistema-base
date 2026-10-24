<?php

namespace App\Models;

use App\Models\Concerns\BuscaPorNombre;
use App\Support\Nombres;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use BuscaPorNombre, HasFactory, SoftDeletes;

    protected $table = 'clientes';

    protected static function booted(): void
    {
        static::saving(function (self $cliente) {
            $cliente->nombre = Nombres::limpiar($cliente->nombre);
            $cliente->nombre_llave = Nombres::llave($cliente->nombre);
        });
    }

    protected $fillable = ['nombre', 'razon_social', 'rfc', 'direccion', 'telefono', 'correo', 'dias_credito', 'notas', 'oc_despues_de_facturar', 'portal_url',
        'sube_factura_portal', 'envia_evidencia_por_correo'];

    protected $casts = ['oc_despues_de_facturar' => 'boolean', 'sube_factura_portal' => 'boolean', 'envia_evidencia_por_correo' => 'boolean'];

    public function contactos(): HasMany
    {
        return $this->hasMany(Contacto::class);
    }

    public function sucursales(): HasMany
    {
        return $this->hasMany(ClienteSucursal::class)->orderBy('nombre');
    }

    /** Razones sociales (nombres de SICAR) con las que se le factura a este cliente. */
    public function razonesSociales(): HasMany
    {
        return $this->hasMany(ClienteRazonSocial::class)->orderBy('nombre');
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class);
    }

    /** Hay que subir la factura a su portal de proveedores (los que generan la OC después, también). */
    public function subeFacturaPortal(): bool
    {
        return (bool) ($this->sube_factura_portal || $this->oc_despues_de_facturar);
    }

    public function cotizaciones(): HasMany
    {
        return $this->hasMany(Cotizacion::class);
    }
}

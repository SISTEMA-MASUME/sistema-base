<?php

namespace App\Models;

use App\Models\Concerns\BuscaPorNombre;
use App\Support\Nombres;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proveedor extends Model
{
    use BuscaPorNombre, HasFactory, SoftDeletes;

    protected $table = 'proveedores';

    protected static function booted(): void
    {
        static::saving(function (self $proveedor) {
            $proveedor->nombre = Nombres::limpiar($proveedor->nombre);
            $proveedor->nombre_llave = Nombres::llave($proveedor->nombre);
        });
    }

    protected $fillable = ['nombre', 'contacto', 'telefono', 'correo', 'notas', 'sicar_pro_id', 'vende_a_credito'];

    protected function casts(): array
    {
        return ['vende_a_credito' => 'boolean'];
    }

    /** Contactos (vendedores); el principal primero. */
    public function contactos(): HasMany
    {
        return $this->hasMany(ProveedorContacto::class)->orderByDesc('principal')->orderBy('nombre');
    }

    /**
     * A quién se le manda la OC: el contacto principal (o el primero); si no tiene teléfono o correo,
     * se usan los generales del proveedor.
     *
     * @return array{nombre: ?string, telefono: ?string, correo: ?string}
     */
    public function datosContacto(): array
    {
        $c = $this->relationLoaded('contactos') ? $this->contactos->first() : $this->contactos()->first();

        return [
            'nombre' => $c?->nombre ?: ($this->contacto ?: null),
            'telefono' => $c?->telefono ?: ($this->telefono ?: null),
            'correo' => $c?->correo ?: ($this->correo ?: null),
        ];
    }
}

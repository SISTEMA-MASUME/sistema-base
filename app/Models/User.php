<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'rol',
        'iniciales',
        'telefono',
        'cuenta_correo',
        'activo',
        'acceso_ned',
        'permisos',
        'en_nomina',
        'sueldo_base',
        'comision_pct',
        'fecha_ingreso',
        'trabaja_sabados',
        'vacaciones_dias_anuales',
        'cuenta_nomina_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'acceso_ned' => 'boolean',
            'permisos' => 'array',
            'en_nomina' => 'boolean',
            'sueldo_base' => 'decimal:2',
            'comision_pct' => 'decimal:2',
            'fecha_ingreso' => 'date',
            'trabaja_sabados' => 'boolean',
            'vacaciones_dias_anuales' => 'integer',
            'eliminado_at' => 'datetime',
        ];
    }

    public const SUFIJO_ELIMINADO = ' (usuario inactivo)';

    /**
     * Nombre sin "(usuario inactivo)". Al eliminar un usuario el sufijo se guarda en su nombre para que salga
     * en todas las pantallas internas; los PDFs y correos usan el nombre tal cual.
     */
    public function nombreReal(): string
    {
        $nombre = (string) ($this->attributes['name'] ?? '');

        return str_ends_with($nombre, self::SUFIJO_ELIMINADO) ? substr($nombre, 0, -strlen(self::SUFIJO_ELIMINADO)) : $nombre;
    }

    public function vacaciones(): HasMany
    {
        return $this->hasMany(Vacacion::class);
    }

    /** Cuenta de la que normalmente se le paga la nómina (vacío = la general de la nómina). */
    public function cuentaNomina(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class, 'cuenta_nomina_id');
    }

    public function estaEliminado(): bool
    {
        return $this->eliminado_at !== null;
    }

    public function esAdmin(): bool
    {
        return $this->rol === 'admin';
    }

    /**
     * Personal de campo (Hugo, Ernesto): recogen y pagan con tarjeta, así que SÍ ven costos,
     * proveedores y OC a proveedores. No ven precio de venta ni utilidad, ni cotizaciones.
     */
    public function esCampo(): bool
    {
        return $this->rol === 'campo';
    }

    /** Usuario externo de Ned Tracking (Pedro Ramírez): solo usa su módulo de cotizaciones. */
    public function esNed(): bool
    {
        return $this->rol === 'nedtracking';
    }

    /**
     * Entra al módulo de Ned Tracking: Pedro, el admin (Daniel) y los vendedores con acceso (Rosa y Alberto),
     * sin cambiar de sesión.
     */
    public function puedeUsarNed(): bool
    {
        return $this->esNed() || $this->esAdmin() || ($this->rol === 'vendedor' && $this->acceso_ned);
    }

    /** Personal de MASUME dentro de Ned: ve el costo interno y usa "Pasar a Ned Tracking" (Pedro no). */
    public function nedDesdeMasume(): bool
    {
        return $this->puedeUsarNed() && ! $this->esNed();
    }

    /**
     * Pantallas de finanzas (ventas, cobranza, nómina…): el admin entra a todo; los demás solo a las que el
     * admin les palomeó en Usuarios (Support\Permisos).
     */
    public function puede(string $permiso): bool
    {
        if ($this->esAdmin()) {
            return true;
        }

        return $this->activo && ! $this->esNed() && in_array($permiso, (array) ($this->permisos ?? []), true);
    }

    public function puedeVerCostos(): bool
    {
        return true;
    }

    /** Precio de venta, importe al cliente y utilidad. */
    public function puedeVerVenta(): bool
    {
        return ! $this->esCampo();
    }
}

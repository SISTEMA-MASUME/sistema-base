<?php

namespace App\Models;

use App\Support\Nombres;
use Illuminate\Database\Eloquent\Model;

/** Banco, tarjeta, efectivo o crédito (cuenta de depósito de los cobros; después gastos y patrimonio). */
class Cuenta extends Model
{
    protected $table = 'cuentas';

    public const TIPOS = [
        'debito' => 'Débito', 'credito' => 'Tarjeta de crédito', 'efectivo' => 'Efectivo',
        'inversion' => 'Inversión', 'apartado' => 'Apartado / cajita', 'prestamo' => 'Crédito o deuda',
        'por_cobrar' => 'Me deben (préstamo personal)',
    ];

    /** Tipos que suman a lo disponible en Mis finanzas. */
    public const DISPONIBLES = ['debito', 'efectivo', 'inversion', 'apartado'];

    protected $fillable = ['nombre', 'nombre_llave', 'tipo', 'de', 'dia_corte', 'dia_pago', 'limite', 'activa', 'orden'];

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'limite' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (Cuenta $c) {
            $c->nombre = Nombres::limpiar($c->nombre);
            $c->nombre_llave = Nombres::llave($c->nombre);
        });
    }

    /** La cuenta con ese nombre (sin importar acentos/mayúsculas); la crea si no existe. */
    public static function obtener(?string $nombre): ?self
    {
        $nombre = trim((string) $nombre);
        if ($nombre === '') {
            return null;
        }

        return self::firstOrCreate(['nombre_llave' => Nombres::llave($nombre)], [
            'nombre' => $nombre,
            'tipo' => preg_match('/CR[EÉ]DITO|PLATINUM|AMEX|INVEX|COSTCO/iu', $nombre) ? 'credito'
                : (preg_match('/EFECTIVO/iu', $nombre) ? 'efectivo' : 'debito'),
        ]);
    }
}

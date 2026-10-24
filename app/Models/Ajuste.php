<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Ajustes persistentes clave → valor (texto). */
class Ajuste extends Model
{
    public const PUENTE_ACTIVO = 'sicar_puente_activo';

    public const PUENTE_CONEXION = 'sicar_puente_ultima_conexion';

    protected $table = 'ajustes';

    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['clave', 'valor'];

    public static function obtener(string $clave, ?string $defecto = null): ?string
    {
        $v = self::query()->whereKey($clave)->value('valor');

        return $v ?? $defecto;
    }

    public static function guardar(string $clave, ?string $valor): void
    {
        self::updateOrCreate(['clave' => $clave], ['valor' => $valor]);
    }

    /** Puente encendido (por omisión sí). */
    public static function puenteActivo(): bool
    {
        return self::obtener(self::PUENTE_ACTIVO, '1') === '1';
    }

    public static function ponerPuenteActivo(bool $activo): void
    {
        self::guardar(self::PUENTE_ACTIVO, $activo ? '1' : '0');
    }

    public static function registrarConexionPuente(): void
    {
        self::guardar(self::PUENTE_CONEXION, now()->toIso8601String());
    }

    public static function ultimaConexionPuente(): ?Carbon
    {
        $v = self::obtener(self::PUENTE_CONEXION);

        return $v ? Carbon::parse($v) : null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SicarSincronizacion extends Model
{
    protected $table = 'sicar_sincronizaciones';

    protected $fillable = ['inicio', 'fin', 'recibidos', 'activos', 'origen', 'error'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fin' => 'datetime'];
    }

    /** Última sincronización terminada (o null si nunca hubo). */
    public static function ultimaCompleta(): ?self
    {
        return self::whereNotNull('fin')->orderByDesc('fin')->first();
    }
}

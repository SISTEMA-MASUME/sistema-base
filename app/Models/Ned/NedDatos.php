<?php

namespace App\Models\Ned;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Datos de Ned Tracking que salen en sus cotizaciones (los edita Pedro en "Mis datos y logo"). */
class NedDatos extends Model
{
    protected $table = 'ned_datos';

    protected $fillable = ['empresa', 'nombre', 'rfc', 'regimen', 'direccion', 'telefono', 'correo', 'web', 'logo_path', 'condiciones'];

    public static function actuales(): self
    {
        return self::query()->first() ?? self::create(['empresa' => 'NED TRACKING']);
    }

    /** Ruta en disco del logo: el que subió Pedro o, si no ha subido uno, el de Ned Tracking de fábrica. */
    public function rutaLogo(): string
    {
        if ($this->logo_path && Storage::disk('local')->exists($this->logo_path)) {
            return Storage::disk('local')->path($this->logo_path);
        }

        return resource_path('ned/logo-ned-tracking.png');
    }
}

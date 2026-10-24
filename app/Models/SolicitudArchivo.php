<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Archivo (PDF o imagen) que acompaña una solicitud; vive en el disco privado `local`. */
class SolicitudArchivo extends Model
{
    protected $table = 'solicitud_cotizacion_archivos';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['leido_at' => 'datetime'];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCotizacion::class, 'solicitud_id');
    }

    public function esImagen(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}

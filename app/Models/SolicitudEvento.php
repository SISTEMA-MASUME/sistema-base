<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bitácora de la solicitud (solo inserción): creó, tomó, soltó, reasignó, descartó… */
class SolicitudEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'solicitud_cotizacion_eventos';

    protected $fillable = ['solicitud_id', 'user_id', 'tipo', 'descripcion'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

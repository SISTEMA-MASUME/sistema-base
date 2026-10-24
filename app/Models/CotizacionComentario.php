<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Comentario de revisión interna de una cotización (general o de una partida). Nunca sale al cliente. */
class CotizacionComentario extends Model
{
    protected $table = 'cotizacion_comentarios';

    protected $fillable = ['cotizacion_id', 'cotizacion_partida_id', 'partida_orden', 'user_id', 'texto', 'resuelto_at', 'resuelto_por'];

    protected $casts = ['resuelto_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resueltoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }

    public function partida(): BelongsTo
    {
        return $this->belongsTo(CotizacionPartida::class, 'cotizacion_partida_id');
    }
}

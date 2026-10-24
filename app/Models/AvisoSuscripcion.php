<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un celular (navegador) que aceptó recibir avisos del sistema. */
class AvisoSuscripcion extends Model
{
    protected $table = 'avisos_suscripciones';

    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'agente', 'ultimo_envio_at'];

    protected function casts(): array
    {
        return ['ultimo_envio_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

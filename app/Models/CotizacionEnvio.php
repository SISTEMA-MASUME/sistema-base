<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotizacionEnvio extends Model
{
    protected $table = 'cotizacion_envios';

    protected $fillable = ['cotizacion_id', 'user_id', 'para', 'cc', 'asunto', 'mensaje', 'exito', 'error', 'revision'];

    protected function casts(): array
    {
        return ['exito' => 'boolean'];
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

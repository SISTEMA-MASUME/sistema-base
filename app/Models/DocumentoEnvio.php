<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Cada vez que alguien comparte un documento: por correo, WhatsApp o descarga. */
class DocumentoEnvio extends Model
{
    protected $table = 'documento_envios';

    protected $fillable = ['documento_type', 'documento_id', 'user_id', 'canal', 'destinatario', 'revision', 'exito', 'error'];

    protected function casts(): array
    {
        return ['exito' => 'boolean'];
    }

    public function documento(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

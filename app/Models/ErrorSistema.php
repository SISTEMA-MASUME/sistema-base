<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErrorSistema extends Model
{
    protected $table = 'errores_sistema';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'primera_vez' => 'datetime',
            'ultima_vez' => 'datetime',
            'resuelto_en' => 'datetime',
            'resuelto' => 'boolean',
            'contexto' => 'array',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolvio(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Comentario en la tarjeta del pedido (autor, fecha y hora). */
class PedidoComentario extends Model
{
    protected $table = 'pedido_comentarios';

    protected $fillable = ['pedido_id', 'user_id', 'texto'];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro de actividad de la tarjeta (solo inserción), como el historial de Trello. */
class PedidoActividad extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'pedido_actividades';

    protected $fillable = ['pedido_id', 'user_id', 'tipo', 'descripcion'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Anota una actividad del usuario actual en el pedido. */
    public static function registrar(Pedido|int $pedido, string $tipo, string $descripcion): self
    {
        return self::create([
            'pedido_id' => $pedido instanceof Pedido ? $pedido->id : $pedido,
            'user_id' => auth()->id(),
            'tipo' => $tipo,
            'descripcion' => mb_substr($descripcion, 0, 500),
        ]);
    }
}

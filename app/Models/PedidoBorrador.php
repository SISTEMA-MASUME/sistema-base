<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pedido nuevo a medio capturar (uno por usuario) para retomarlo donde se quedó. */
class PedidoBorrador extends Model
{
    protected $table = 'pedido_borradores';

    protected $fillable = ['user_id', 'cliente_id', 'paso', 'partidas', 'datos'];

    /** Después de estos días ya no se ofrece retomarlo. */
    public const DIAS_VIGENCIA = 15;

    protected function casts(): array
    {
        return ['datos' => 'array', 'paso' => 'integer', 'partidas' => 'integer'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    public static function vigenteDe(int $userId): ?self
    {
        return self::where('user_id', $userId)->where('updated_at', '>=', now()->subDays(self::DIAS_VIGENCIA))->first();
    }
}

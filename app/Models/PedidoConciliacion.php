<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una revisión de la OC del cliente contra el pedido (historial: nunca se sobreescribe). */
class PedidoConciliacion extends Model
{
    protected $table = 'pedido_conciliaciones';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'extraccion' => 'array',
            'resultado' => 'array',
            'resoluciones' => 'array',
            'hay_diferencias' => 'boolean',
            'costo_usd' => 'decimal:5',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Costo en USD según los precios por millón de tokens de config/services.php. */
    public static function calcularCosto(string $modelo, int $entrada, int $salida): float
    {
        $p = config('services.openai.precios.'.$modelo);
        if (! is_array($p)) {
            // Modelo sin tarifa: se cuenta con la tarifa más cara configurada (mejor de más que gratis).
            $todas = array_filter((array) config('services.openai.precios'), 'is_array');
            $p = $todas ? collect($todas)->sortByDesc(fn ($t) => (float) $t['entrada'] + (float) $t['salida'])->first() : ['entrada' => 5, 'salida' => 20];
        }

        return round(($entrada * (float) $p['entrada'] + $salida * (float) $p['salida']) / 1_000_000, 5);
    }

    /** Gasto en IA del mes en curso (USD): conciliaciones de OC + otros usos (claves SAT). */
    public static function gastoDelMes(): float
    {
        return (float) self::where('created_at', '>=', now()->startOfMonth())->sum('costo_usd')
            + (float) IaConsumo::where('created_at', '>=', now()->startOfMonth())->sum('costo_usd');
    }
}

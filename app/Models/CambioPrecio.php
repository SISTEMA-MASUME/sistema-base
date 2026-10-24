<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cambio de precio de venta de una partida al dar de alta el pedido (con autorización y evidencia). */
class CambioPrecio extends Model
{
    public const ESTATUS = ['pendiente' => 'Por autorizar', 'autorizado' => 'Autorizado', 'rechazado' => 'Rechazado', 'directo' => 'Directo (admin)'];

    protected $table = 'cambios_precio';

    protected $fillable = [
        'pedido_id', 'pedido_partida_id', 'cotizacion_partida_id', 'descripcion', 'cantidad', 'precio_anterior', 'precio_solicitado',
        'precio_final', 'costo_anterior', 'costo_nuevo', 'proveedor_id', 'motivo', 'estatus', 'solicitado_por', 'resuelto_por',
        'resuelto_at', 'nota_resolucion', 'utilidad_antes', 'utilidad_despues', 'avisado_at',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'avisado_at' => 'datetime',
            'precio_anterior' => 'decimal:4',
            'precio_solicitado' => 'decimal:4',
            'precio_final' => 'decimal:4',
            'costo_anterior' => 'decimal:4',
            'costo_nuevo' => 'decimal:4',
            'utilidad_antes' => 'decimal:2',
            'utilidad_despues' => 'decimal:2',
            'resuelto_at' => 'datetime',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class)->withTrashed();
    }

    public function partida(): BelongsTo
    {
        return $this->belongsTo(PedidoPartida::class, 'pedido_partida_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function resolutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }

    public function esPendiente(): bool
    {
        return $this->estatus === 'pendiente';
    }

    public function etiquetaEstatus(): string
    {
        return self::ESTATUS[$this->estatus] ?? $this->estatus;
    }
}

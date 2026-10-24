<?php

namespace App\Models;

use App\Enums\EstadoPartidaPedido;
use App\Support\Articulos;
use App\Support\LinkCompra;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoPartida extends Model
{
    use HasFactory;

    protected $table = 'pedido_partidas';

    protected $fillable = [
        'pedido_id', 'cotizacion_partida_id', 'orden_proveedor_id', 'orden', 'descripcion', 'marca',
        'modelo', 'unidad', 'cantidad', 'proveedor_id', 'proveedor_vendedor', 'costo', 'precio',
        'estado', 'cantidad_recogida', 'facturada', 'fecha_entrega', 'imagen_path', 'link_compra', 'proveedor_telefono',
        'sicar_art_id', 'sicar_clave', 'sicar_clave_sat', 'sicar_unidad', 'sicar_categoria', 'sicar_sat_ia', 'articulo_id',
    ];

    /** Lo que se decidió en Alta en SICAR para esta partida. */
    public const DECISION_SICAR = ['sicar_art_id', 'sicar_clave', 'sicar_clave_sat', 'sicar_unidad', 'sicar_categoria', 'sicar_sat_ia'];

    /** Cada partida de pedido queda ligada a su artículo del catálogo, con el proveedor y el costo (Support\Articulos). */
    protected static function booted(): void
    {
        // Si la partida cambia de producto, la decisión de SICAR ya no aplica (P-176: la reducción se cambió por el pasador y
        // siguió ligada al artículo de la reducción, así que nunca se mandó). Se vuelve a emparejar en Alta en SICAR.
        static::saving(function (PedidoPartida $p) {
            if ($p->exists && $p->isDirty(['descripcion', 'modelo']) && ! $p->isDirty(self::DECISION_SICAR)) {
                $p->forceFill(array_fill_keys(self::DECISION_SICAR, null));
            }
        });

        static::saved(function (PedidoPartida $p) {
            if ($p->wasRecentlyCreated || $p->wasChanged(['proveedor_id', 'costo', 'modelo', 'marca', 'sicar_art_id', 'link_compra'])) {
                Articulos::ligarPartidaSeguro($p);
            }
        });
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    protected function casts(): array
    {
        return [
            'estado' => EstadoPartidaPedido::class,
            'facturada' => 'boolean',
            'fecha_entrega' => 'date',
            'cantidad' => 'decimal:3',
            'sicar_alta_at' => 'datetime',
            'cantidad_recogida' => 'decimal:3',
            'costo' => 'decimal:4',
            'precio' => 'decimal:2',
        ];
    }

    /** Cuánto ya se recogió: completa si está "Recogida" o "Entregada"; si no, lo registrado en partes (0 si nada). */
    public function recogido(): float
    {
        if ($this->estado->nivel() >= EstadoPartidaPedido::Recibida->nivel()) {
            return (float) $this->cantidad;
        }

        return min((float) $this->cantidad, (float) ($this->cantidad_recogida ?? 0));
    }

    /** Lo que falta recoger (0 si ya se recogió todo). */
    public function faltaRecoger(): float
    {
        return max(0.0, round((float) $this->cantidad - $this->recogido(), 3));
    }

    /** El proveedor entregó solo una parte: hay algo recogido pero no todo. */
    public function recogidaParcial(): bool
    {
        return $this->recogido() > 0 && $this->faltaRecoger() > 0;
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /** Renglón de cotización de donde salió (trazabilidad cotizado → colocado). */
    public function cotizacionPartida(): BelongsTo
    {
        return $this->belongsTo(CotizacionPartida::class);
    }

    public function ordenProveedor(): BelongsTo
    {
        return $this->belongsTo(OrdenProveedor::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /** Crea la partida del pedido copiando el renglón de la cotización. */
    public static function desdeCotizacion(CotizacionPartida $p, Pedido $pedido, int $orden, ?float $cantidad = null): self
    {
        [$modelo, $link] = LinkCompra::separar($p->modelo, $p->link_compra);

        return self::create([
            'pedido_id' => $pedido->id,
            'cotizacion_partida_id' => $p->id,
            'orden' => $orden,
            'descripcion' => $p->descripcion,
            'marca' => $p->marca,
            'modelo' => $modelo,
            'unidad' => $p->unidad,
            'cantidad' => $cantidad ?? $p->cantidad,
            'proveedor_id' => $p->proveedor_id,
            'proveedor_vendedor' => $p->proveedor_vendedor,
            'link_compra' => $link,
            'proveedor_telefono' => $p->proveedor_telefono,
            'costo' => $p->costo,
            'precio' => $p->precio,
            'imagen_path' => $p->imagen_path,
            'articulo_id' => $p->articulo_id,
        ]);
    }
}

<?php

namespace App\Models;

use App\Enums\EstadoOrdenProveedor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** OC de MASUME a un proveedor. Reemplaza las "OC INTERNA" (folios 10412.01C) del REGISTRO. */
class OrdenProveedor extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ordenes_proveedor';

    protected $fillable = ['folio', 'pedido_id', 'proveedor_id', 'user_id', 'metodo_pago', 'comentarios', 'estado', 'requiere_reenvio', 'total_costo',
        'motivo_sin_conciliar', 'autorizo_sin_conciliar_id'];

    protected function casts(): array
    {
        return ['estado' => EstadoOrdenProveedor::class, 'total_costo' => 'decimal:2', 'requiere_reenvio' => 'boolean'];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function partidas(): HasMany
    {
        return $this->hasMany(PedidoPartida::class)->orderBy('orden');
    }

    public function envios(): MorphMany
    {
        return $this->morphMany(DocumentoEnvio::class, 'documento')->latest('id');
    }

    public function folioTexto(): string
    {
        return 'OC-'.str_pad((string) $this->folio, 4, '0', STR_PAD_LEFT);
    }

    public function nombreArchivoPdf(): string
    {
        $prov = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $this->proveedor->nombre ?? 'PROVEEDOR'), '_');

        return $this->folioTexto().'_'.$prov.'.pdf';
    }

    /**
     * Referencia para el mensaje de WhatsApp: "Pedido P-60 · OC 4509086595 · PLAZA NORTE - PROVESA". El cliente NO sale en el
     * PDF de la OC; solo aquí, en el texto que se puede editar antes de mandarlo.
     */
    public function referenciaCliente(): string
    {
        $this->loadMissing(['pedido.cliente', 'proveedor']);
        $oc = $this->pedido?->numero_oc_cliente ? 'OC '.$this->pedido->numero_oc_cliente : 'Sin OC del cliente';

        $tarjeta = $this->pedido ? 'Pedido P-'.$this->pedido->folio.' · ' : '';

        return trim($tarjeta.$oc.' · '.($this->pedido?->cliente?->nombre ?? '').' - '.($this->proveedor?->nombre ?? ''), ' ·-');
    }
}

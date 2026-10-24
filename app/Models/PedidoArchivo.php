<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Archivo del expediente de un pedido: factura (PDF/XML), evidencia de entrega, etc. La OC del cliente vive en pedidos.oc_cliente_path. */
class PedidoArchivo extends Model
{
    public const TIPOS = ['oc_cliente', 'factura_pdf', 'factura_xml', 'evidencia', 'otro'];

    protected $table = 'pedido_archivos';

    protected $fillable = ['pedido_id', 'tipo', 'disco', 'ruta', 'nombre_original', 'mime', 'tamano', 'user_id', 'notas', 'partidas_ids', 'cfdi_uuid', 'cfdi_folio'];

    protected function casts(): array
    {
        return ['partidas_ids' => 'array'];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $a) {
            Storage::disk($a->disco)->delete($a->ruta);
        });
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function esImagen(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function tamanoTexto(): string
    {
        return $this->tamano >= 1048576
            ? number_format($this->tamano / 1048576, 1).' MB'
            : number_format(max(1, $this->tamano / 1024), 0).' KB';
    }
}

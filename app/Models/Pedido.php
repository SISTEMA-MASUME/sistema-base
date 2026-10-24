<?php

namespace App\Models;

use App\Enums\ColumnaPedido;
use App\Enums\EstadoPartidaPedido;
use App\Enums\TipoPedido;
use App\Support\AltaSicarPartidas;
use App\Support\Nombres;
use App\Support\RegistroErrores;
use App\Support\Trazabilidad;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** OC de un cliente (o nota de venta): una tarjeta del tablero. */
class Pedido extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pedidos';

    /** Columnas del cliente que necesitan las etiquetas (reglas de facturación y entrega, T16). */
    /** Lo que se dice cuando escriben el folio de la cotización donde va la OC del cliente (pasó en P-47 y P-48). */
    public const AVISO_OC_ES_COTIZACION = 'Eso es el folio de una cotización, no la OC del cliente. Si se surte con nota de venta, deja el número vacío (marca "No tengo OC del cliente"); la OC se captura después en la tarjeta.';

    /** ¿Escribieron el folio de una cotización ("COT 90123", "Cotización 90123") en lugar del número de OC del cliente? */
    public static function esFolioDeCotizacion(?string $numero): bool
    {
        return (bool) preg_match('/^\s*(COT|COTIZACI[OÓ]N)(\b|\s|[.#:-]|\d)/iu', (string) $numero);
    }

    public const CLIENTE_REGLAS = 'cliente:id,nombre,oc_despues_de_facturar,sube_factura_portal,envia_evidencia_por_correo,portal_url';

    protected $fillable = [
        'folio', 'tipo', 'cliente_id', 'numero_oc_cliente', 'responsable_id', 'fecha_oc',
        'fecha_compromiso', 'columna', 'posicion', 'alta_sicar', 'revisar', 'modificar_oc',
        'trello_card_id', 'trello_url', 'cancelado_at', 'archivado_at', 'archivado_por', 'factura_portal_at', 'factura_portal_por', 'oc_cliente_path', 'notas', 'total_venta', 'total_costo', 'titulo_personalizado',
        'sucursal_id', 'oc_correo_remitente', 'oc_correo_asunto', 'evidencia_enviada_at', 'evidencia_enviada_por',
        'factura_sucursal_enviada_at', 'factura_sucursal_enviada_por',
    ];

    protected static function booted(): void
    {
        // La llave evita duplicar la misma OC de un cliente y el match parcial de Trello ("OC 45" ≈ "OC 4567").
        static::saving(function (self $pedido) {
            $numero = trim((string) $pedido->numero_oc_cliente);
            $pedido->numero_oc_cliente = $numero !== '' ? $numero : null;
            $pedido->numero_oc_llave = $numero !== ''
                ? Nombres::llave(preg_replace('/^\s*(OC|O\.C\.|PO)\s*[:#-]?\s*/i', '', $numero))
                : null;
        });
        // Si la OC ya viene en el comentario de una factura de SICAR, se ligan solas. Nunca frena el guardado.
        static::saved(function (self $pedido) {
            if ($pedido->numero_oc_llave && ($pedido->wasRecentlyCreated || $pedido->wasChanged(['numero_oc_llave', 'cliente_id']))) {
                try {
                    Trazabilidad::ligarFacturasDelPedido($pedido);
                } catch (\Throwable $e) {
                    RegistroErrores::registrar($e);
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoPedido::class,
            'columna' => ColumnaPedido::class,
            'fecha_oc' => 'date',
            'fecha_compromiso' => 'date',
            'cancelado_at' => 'datetime',
            'archivado_at' => 'datetime',
            'factura_portal_at' => 'datetime',
            'evidencia_enviada_at' => 'datetime',
            'factura_sucursal_enviada_at' => 'datetime',
            'alta_sicar' => 'boolean',
            'revisar' => 'boolean',
            'modificar_oc' => 'boolean',
            'total_venta' => 'decimal:2',
            'total_costo' => 'decimal:2',
        ];
    }

    /** Incluye clientes borrados o unidos en Duplicados: el pedido histórico no pierde su cliente. */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    public function facturaPortalPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'factura_portal_por');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(ClienteSucursal::class, 'sucursal_id')->withTrashed();
    }

    public function evidenciaEnviadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evidencia_enviada_por');
    }

    public function facturaSucursalEnviadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'factura_sucursal_enviada_por');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /** Facturas y notas de este pedido (trazabilidad; muchos a muchos). Las propuestas por confirmar NO cuentan. */
    public function ventas(): BelongsToMany
    {
        return $this->belongsToMany(Venta::class, 'pedido_venta')->withPivot(['user_id', 'created_at', 'por_confirmar'])->wherePivot('por_confirmar', false);
    }

    /** Facturas que parecen de este pedido (traen su OC) pero salen a otra razón social: falta que Daniel confirme. */
    public function ventasPorConfirmar(): BelongsToMany
    {
        return $this->belongsToMany(Venta::class, 'pedido_venta')->withPivot(['user_id', 'created_at', 'por_confirmar'])->wherePivot('por_confirmar', true);
    }

    public function partidas(): HasMany
    {
        return $this->hasMany(PedidoPartida::class)->orderBy('orden');
    }

    /** Lotes de alta en SICAR en los que ha ido este pedido. */
    public function lotesSicar(): BelongsToMany
    {
        return $this->belongsToMany(SicarLote::class, 'sicar_lote_pedido', 'pedido_id', 'lote_id');
    }

    public function ordenesProveedor(): HasMany
    {
        return $this->hasMany(OrdenProveedor::class)->orderBy('folio');
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(PedidoArchivo::class)->orderBy('id');
    }

    public function comentarios(): HasMany
    {
        return $this->hasMany(PedidoComentario::class)->latest('id');
    }

    public function actividades(): HasMany
    {
        return $this->hasMany(PedidoActividad::class)->latest('id');
    }

    /** Correos que salieron del pedido (evidencia de entrega, factura a la plaza). */
    public function envios(): MorphMany
    {
        return $this->morphMany(DocumentoEnvio::class, 'documento')->latest('id');
    }

    public function conciliaciones(): HasMany
    {
        return $this->hasMany(PedidoConciliacion::class)->latest('id');
    }

    /** Última revisión de la OC del cliente que terminó bien (la fallidas no cuentan). */
    public function ultimaConciliacion(): HasOne
    {
        return $this->hasOne(PedidoConciliacion::class)->ofMany(['id' => 'max'], fn ($q) => $q->where('estado', 'completada'));
    }

    /** Liga a la OC del cliente con huella del archivo: al reemplazarla cambia la liga y nunca se ve la copia vieja guardada. */
    public function urlOc(bool $descargar = false): string
    {
        return route('pedidos.oc-cliente', array_filter([
            'pedido' => $this, 'descargar' => $descargar ? 1 : null, 'v' => substr(md5((string) $this->oc_cliente_path), 0, 10),
        ]));
    }

    /** Nombre de la tarjeta, igual que en Trello: "FANOSA - OC 617489 - ROSY". */
    public function titulo(): string
    {
        if (filled($this->titulo_personalizado)) {
            return $this->titulo_personalizado;
        }
        $oc = $this->numero_oc_cliente ? 'OC '.$this->numero_oc_cliente : ($this->tipo ?? TipoPedido::Oc)->etiqueta();

        return trim(($this->cliente->nombre ?? 'Sin cliente').' - '.$oc.' - '.($this->responsable->iniciales ?? ''), ' -');
    }

    public function recalcularTotales(): void
    {
        $venta = 0.0;
        $costo = 0.0;
        foreach ($this->partidas()->get(['cantidad', 'precio', 'costo']) as $p) {
            $venta += (float) $p->precio * (float) $p->cantidad;
            $costo += (float) $p->costo * (float) $p->cantidad;
        }
        $this->forceFill(['total_venta' => round($venta, 2), 'total_costo' => round($costo, 2)])->save();
    }

    /** Cliente que genera su OC después de recibir la factura en su portal de proveedores. */
    public function clienteGeneraOcDespues(): bool
    {
        if (! $this->cliente_id) {
            return false;
        }
        if (! $this->relationLoaded('cliente')) {
            $this->load(self::CLIENTE_REGLAS);
        }

        return (bool) $this->cliente?->oc_despues_de_facturar;
    }

    private function clienteCargado(): ?Cliente
    {
        if (! $this->cliente_id) {
            return null;
        }
        if (! $this->relationLoaded('cliente')) {
            $this->load(self::CLIENTE_REGLAS);
        }

        return $this->cliente;
    }

    /** Hay que subir la factura al portal de proveedores del cliente. */
    public function clienteSubeFacturaPortal(): bool
    {
        return (bool) $this->clienteCargado()?->subeFacturaPortal();
    }

    public function clienteEnviaEvidenciaPorCorreo(): bool
    {
        return (bool) $this->clienteCargado()?->envia_evidencia_por_correo;
    }

    /** Ya hay factura: PDF en el expediente o alguna partida marcada como facturada. */
    public function tieneFactura(): bool
    {
        $partidas = $this->relationLoaded('partidas') ? $this->partidas : null;
        if ($partidas?->contains('facturada', true)) {
            return true;
        }
        if (array_key_exists('facturas_count', $this->attributes)) {
            return (int) $this->attributes['facturas_count'] > 0;
        }
        if ($partidas === null && $this->partidas()->where('facturada', true)->exists()) {
            return true;
        }

        return $this->archivos()->where('tipo', 'factura_pdf')->exists();
    }

    /** Hay algo que mandar como evidencia: fotos en el expediente o partidas entregadas. */
    public function tieneEvidencia(): bool
    {
        if (array_key_exists('evidencias_count', $this->attributes)) {
            return (int) $this->attributes['evidencias_count'] > 0;
        }

        return $this->archivos()->where('tipo', 'evidencia')->exists();
    }

    /**
     * Etiquetas de la tarjeta. Las de avance salen del estado de las partidas (ya no se ponen a mano);
     * las de criterio (revisar, modificar OC, cancelada, alta SICAR) son banderas del pedido.
     *
     * @return list<array{texto: string, color: string}>
     */
    public function etiquetas(): array
    {
        $partidas = $this->relationLoaded('partidas') ? $this->partidas : $this->partidas()->get();
        $n = $partidas->count();
        $niveles = $partidas->map(fn ($p) => $p->estado->nivel());
        $todas = fn (EstadoPartidaPedido $e) => $n > 0 && $niveles->every(fn ($x) => $x >= $e->nivel());
        $facturadas = $partidas->where('facturada', true)->count();

        $e = [];
        if ($this->archivado_at) {
            $e[] = ['texto' => 'Archivado', 'color' => 'gris'];
        }
        if ($this->cancelado_at) {
            $e[] = ['texto' => 'OC cancelada por cliente', 'color' => 'rojo'];
        }
        if ($this->tipo === TipoPedido::NotaVenta) {
            $e[] = ['texto' => 'Nota de venta', 'color' => 'morado'];
        }
        // Se surtió (normalmente con nota de venta) y el cliente aún no manda su OC.
        if (! $this->cancelado_at) {
            if ($this->clienteGeneraOcDespues()) {
                // Cliente con portal de proveedores: primero se sube la factura y luego ellos generan la OC.
                if (! $this->factura_portal_at) {
                    $e[] = ['texto' => 'Subir factura al portal', 'color' => 'naranja'];
                } elseif (! $this->numero_oc_cliente) {
                    $e[] = ['texto' => 'Esperando OC del portal', 'color' => 'amarillo'];
                }
            } else {
                // Portal de facturas aunque la OC llegue antes (PLAZA CENTRO, GRUPO KAPPA…): cuando ya hay factura.
                if ($this->clienteSubeFacturaPortal() && ! $this->factura_portal_at && $this->tieneFactura()) {
                    $e[] = ['texto' => 'Subir factura al portal', 'color' => 'naranja'];
                }
                if (! $this->numero_oc_cliente) {
                    $e[] = ['texto' => 'Esperando OC del cliente', 'color' => 'amarillo'];
                }
            }
            // ACME: la evidencia de entrega se responde al correo de la OC.
            if ($this->clienteEnviaEvidenciaPorCorreo() && ! $this->evidencia_enviada_at && $this->tieneEvidencia()) {
                $e[] = ['texto' => 'Enviar evidencia', 'color' => 'morado'];
            }
            // PARQUES / LUMEX: la factura va a los correos de la plaza.
            if ($this->sucursal_id && ! $this->factura_sucursal_enviada_at && $this->tieneFactura()) {
                $e[] = ['texto' => 'Enviar factura a la plaza', 'color' => 'naranja'];
            }
        }
        if ($todas(EstadoPartidaPedido::Entregada)) {
            $e[] = ['texto' => 'Material entregado', 'color' => 'verde'];
        } elseif ($todas(EstadoPartidaPedido::Recibida)) {
            $e[] = ['texto' => 'Recibido', 'color' => 'azul'];
        } elseif ($todas(EstadoPartidaPedido::Solicitada)) {
            $e[] = ['texto' => 'Material solicitado', 'color' => 'rosa'];
        } elseif ($niveles->contains(fn ($x) => $x >= EstadoPartidaPedido::Solicitada->nivel())) {
            $e[] = ['texto' => 'Solicitado parcial', 'color' => 'rosa'];
        }
        // El proveedor entregó incompleto: queda a la vista hasta que se recoja lo que falta.
        if (! $this->cancelado_at && $partidas->contains(fn ($p) => $p->recogidaParcial())) {
            $e[] = ['texto' => 'Falta recoger', 'color' => 'naranja'];
        }
        if ($this->alta_sicar) {
            $e[] = ['texto' => 'Mat. dado de alta', 'color' => 'verde'];
            // Partidas que se agregaron o cambiaron después del alta (P-176).
            if ($partidas->contains(fn ($p) => AltaSicarPartidas::falta($p, $this))) {
                $e[] = ['texto' => 'Falta alta en SICAR', 'color' => 'naranja'];
            }
        }
        if ($n > 0 && $facturadas === $n) {
            $e[] = ['texto' => 'Facturada completa', 'color' => 'azul'];
        } elseif ($facturadas > 0) {
            $e[] = ['texto' => 'Factura parcial', 'color' => 'naranja'];
        }
        if ($this->modificar_oc) {
            $e[] = ['texto' => 'Se envía a modificar OC', 'color' => 'amarillo'];
        }
        if ($this->revisar) {
            $e[] = ['texto' => 'Revisar / dar seguimiento', 'color' => 'rojo'];
        }
        // Solo si la relación ya viene cargada (tablero): evita una consulta por tarjeta.
        // Sin archivo de OC (se quitó por equivocado) la comparación vieja ya no aplica.
        if ($this->oc_cliente_path && $this->relationLoaded('ultimaConciliacion') && $this->ultimaConciliacion) {
            $e[] = $this->ultimaConciliacion->hay_diferencias
                ? ['texto' => 'Diferencias con OC', 'color' => 'rojo']
                : ['texto' => 'Conciliada con OC', 'color' => 'verde'];
        }

        // Solo si el conteo ya viene cargado (tablero): evita una consulta por tarjeta.
        if ((int) ($this->attributes['evidencias_count'] ?? 0) > 0) {
            $e[] = ['texto' => 'Evidencia de entrega', 'color' => 'verde'];
        }

        return $e;
    }
}

<?php

namespace App\Models;

use App\Support\Semanas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Factura o nota de venta (reemplaza la hoja VENTAS del Sheets). Montos sin IVA salvo `total`.
 * Utilidad = subtotal − costo (la comisión es el 10% de la utilidad de lo cobrado en la semana).
 */
class Venta extends Model
{
    use SoftDeletes;

    protected $table = 'ventas';

    public const ESTATUS = ['pendiente' => 'Pendiente', 'pagada' => 'Pagada', 'cancelada' => 'Cancelada'];

    public const PORTAL = [
        'por_cargar' => 'Por cargar', 'cargada' => 'Cargada', 'evidencia' => 'Evidencia enviada',
        'programada' => 'Programada para pago', 'no_aplica' => 'No aplica',
    ];

    public const TIPOS = ['factura' => 'Factura', 'nota' => 'Nota de venta'];

    protected $fillable = [
        'tipo', 'folio', 'folio_llave', 'cliente_id', 'cliente_nombre', 'concepto', 'fecha', 'costo', 'costo_sicar',
        'subtotal', 'iva', 'retencion', 'total', 'estatus', 'fecha_pago', 'cuenta_id', 'nomina_id', 'pago_referencia', 'saldo_sicar', 'estatus_portal',
        'metodo_pago', 'factura_sustituta', 'uuid', 'sicar_id', 'origen', 'notas', 'comentario_sicar',
        'comision_excluida_at', 'comision_excluida_por', 'comision_excluida_motivo', 'pago_registrado_at', 'deposito_cobro_id', 'complemento_pago_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'fecha_pago' => 'date',
            'comision_excluida_at' => 'datetime',
            'pago_registrado_at' => 'datetime',
            'costo' => 'decimal:2',
            'costo_sicar' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'iva' => 'decimal:2',
            'retencion' => 'decimal:2',
            'total' => 'decimal:2',
            'saldo_sicar' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Venta $v) {
            $v->folio_llave = self::llave($v->tipo, $v->folio);
        });
    }

    /**
     * Llave única del documento: tipo + folio sin espacios ni mayúsculas/minúsculas ("5-3317"). En notas solo
     * cuenta el número: son una sola secuencia escrita distinto según el año ("NOTA 90", "NOTA-298").
     */
    public static function llave(?string $tipo, ?string $folio): string
    {
        $folio = mb_strtoupper(preg_replace('/\s+/u', '', (string) $folio));
        if ($tipo === 'nota') {
            $numero = preg_match('/^NOTA-?(\d+)$/', $folio, $m) ? ltrim($m[1], '0') : null;

            return 'nota|'.($numero !== null && $numero !== '' ? $numero : $folio);
        }

        return 'factura|'.$folio;
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    public function depositoCobro(): BelongsTo
    {
        return $this->belongsTo(DepositoCobro::class);
    }

    public function complementoPago(): BelongsTo
    {
        return $this->belongsTo(ComplementoPago::class);
    }

    /** Nómina en la que ya se pagó la comisión de esta venta cobrada. */
    public function nomina(): BelongsTo
    {
        return $this->belongsTo(Nomina::class);
    }

    public function cambios(): HasMany
    {
        return $this->hasMany(VentaCambio::class)->latest('id');
    }

    /** Ligas donde esta venta fue reemplazada (nota facturada, factura sustituida). */
    public function reemplazadaPor(): HasMany
    {
        return $this->hasMany(VentaVinculo::class, 'origen_id');
    }

    /** Ligas de los documentos que esta factura reemplaza (sus notas, la factura vieja). */
    public function reemplaza(): HasMany
    {
        return $this->hasMany(VentaVinculo::class, 'destino_id');
    }

    /** Pedidos ligados. Las propuestas por confirmar NO cuentan. */
    public function pedidos(): BelongsToMany
    {
        return $this->belongsToMany(Pedido::class, 'pedido_venta')->withPivot(['user_id', 'created_at', 'por_confirmar'])->wherePivot('por_confirmar', false);
    }

    public function pedidosPorConfirmar(): BelongsToMany
    {
        return $this->belongsToMany(Pedido::class, 'pedido_venta')->withPivot(['user_id', 'created_at', 'por_confirmar'])->wherePivot('por_confirmar', true);
    }

    public function utilidad(): float
    {
        return round((float) $this->subtotal - (float) $this->costo, 2);
    }

    /** Margen sobre el costo (como la columna PORCENTAJE del Sheets); null sin costo. */
    public function margen(): ?float
    {
        return (float) $this->costo > 0 ? $this->utilidad() / (float) $this->costo : null;
    }

    /** Días de crédito del cliente (30 si no está ligado). */
    public function diasCredito(): int
    {
        return (int) ($this->cliente?->dias_credito ?? 30);
    }

    public function vence(): Carbon
    {
        return $this->fecha->copy()->addDays($this->diasCredito());
    }

    /** Las que cuentan para venta/utilidad (no canceladas). */
    public function scopeVigentes(Builder $q): Builder
    {
        return $q->where('estatus', '!=', 'cancelada');
    }

    /** Cobradas en la semana de comisión (lunes a sábado; el domingo cuenta para la siguiente). */
    public function scopePagadasEnSemana(Builder $q, int $anio, int $semana): Builder
    {
        [$desde, $hasta] = Semanas::rangoCobro($anio, $semana);

        // Hasta las 23:59:59: en SQLite las fechas se guardan con hora y '2026-09-26 00:00:00' > '2026-09-26'.
        return $q->where('estatus', 'pagada')->whereBetween('fecha_pago', [$desde->toDateString(), $hasta->toDateString().' 23:59:59']);
    }

    /** Anota un cambio en la bitácora (costo, pago, estatus…). */
    public function comisionExcluidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'comision_excluida_por');
    }

    public function anotar(string $campo, mixed $antes, mixed $despues, string $origen = 'usuario'): void
    {
        if ((string) $antes === (string) $despues) {
            return;
        }
        $this->cambios()->create([
            'user_id' => $origen === 'usuario' ? auth()->id() : null,
            'campo' => $campo,
            'antes' => $antes === null ? null : (string) $antes,
            'despues' => $despues === null ? null : (string) $despues,
            'origen' => $origen,
            'created_at' => now(),
        ]);
    }
}

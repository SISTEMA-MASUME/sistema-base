<?php

namespace App\Models;

use App\Enums\EstadoCotizacion;
use App\Observers\CotizacionObserver;
use App\Support\Precios;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(CotizacionObserver::class)]
class Cotizacion extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cotizaciones';

    protected $fillable = [
        'folio', 'cliente_id', 'contacto_id', 'vendedor_id', 'fecha', 'vigencia_dias', 'moneda',
        'forma_pago', 'condiciones_entrega', 'notas', 'estado', 'subtotal', 'iva', 'total', 'enviada_at', 'revision',
        'revision_pedida_at', 'revision_pedida_por', 'revisada_at', 'revisada_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'enviada_at' => 'datetime',
            'revision_pedida_at' => 'datetime',
            'revisada_at' => 'datetime',
            'estado' => EstadoCotizacion::class,
            'subtotal' => 'decimal:2',
            'iva' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function contacto(): BelongsTo
    {
        return $this->belongsTo(Contacto::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function partidas(): HasMany
    {
        return $this->hasMany(CotizacionPartida::class)->orderBy('orden');
    }

    /** Solo las partidas marcadas para ir al PDF. */
    public function partidasIncluidas(): HasMany
    {
        return $this->partidas()->where('incluir', true);
    }

    public function cambios(): HasMany
    {
        return $this->hasMany(CotizacionCambio::class)->orderByDesc('id');
    }

    /** "90001" o "90001 · Rev. 1" cuando ya hubo revisiones. */
    public function folioConRevision(string $sep = ' · '): string
    {
        return $this->revision > 0 ? "{$this->folio}{$sep}Rev. {$this->revision}" : (string) $this->folio;
    }

    public function comentarios(): HasMany
    {
        return $this->hasMany(CotizacionComentario::class)->latest('id');
    }

    public function revisionPedidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revision_pedida_por');
    }

    public function revisadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisada_por');
    }

    /** Ya se revisó internamente (pedir revisión otra vez quita la marca). */
    public function estaRevisada(): bool
    {
        return $this->revisada_at !== null;
    }

    /** Aviso antes de mandarla al cliente sin revisión (null si ya está revisada). */
    public function avisoSinRevisar(): ?string
    {
        if ($this->estaRevisada()) {
            return null;
        }

        return $this->revision_pedida_at
            ? 'Esta cotización está en revisión y todavía nadie la marca como revisada. ¿La envías al cliente así?'
            : '¿No la vas a mandar a revisión? Esta cotización no se ha revisado. ¿La envías al cliente sin revisar?';
    }

    /** Solicitud "Por cotizar" de la que salió esta cotización (si la hubo). */
    public function solicitud(): HasOne
    {
        return $this->hasOne(SolicitudCotizacion::class, 'cotizacion_id');
    }

    public function envios(): HasMany
    {
        return $this->hasMany(CotizacionEnvio::class)->latest('id');
    }

    /** Registro unificado: correo, WhatsApp, descarga y enlace (T11). */
    public function documentoEnvios(): MorphMany
    {
        return $this->morphMany(DocumentoEnvio::class, 'documento')->latest('id');
    }

    /** Recalcula subtotal/iva/total con las partidas incluidas y guarda. */
    public function recalcularTotales(): void
    {
        $t = Precios::totales($this->partidasIncluidas()->pluck('importe'));
        $this->forceFill($t)->save();
    }

    public function nombreArchivoPdf(): string
    {
        $cliente = preg_replace('/[^A-Za-z0-9]+/', '_', $this->cliente->nombre ?? 'CLIENTE');

        $rev = $this->revision > 0 ? "_Rev{$this->revision}" : '';

        return sprintf('%s_Cotizacion_%d%s_%s.pdf', trim($cliente, '_'), $this->folio, $rev, $this->fecha->format('dmY'));
    }
}

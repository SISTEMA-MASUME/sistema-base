<?php

namespace App\Models;

use App\Support\Solicitudes;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Algo que un cliente pidió cotizar y todavía hay que atender (bandeja "Por cotizar").
 * La fecha de envío NO se guarda: se calcula de la cotización (Support\Solicitudes::primerEnvio).
 */
class SolicitudCotizacion extends Model
{
    public const CANALES = ['whatsapp' => 'WhatsApp', 'correo' => 'Correo', 'llamada' => 'Llamada', 'visita' => 'Visita', 'otro' => 'Otro'];

    public const ESTATUS = ['pendiente' => 'Pendiente', 'en_proceso' => 'En proceso', 'cotizada' => 'Cotizada', 'descartada' => 'Descartada'];

    protected $table = 'solicitudes_cotizacion';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recibida_at' => 'datetime',
            'tomada_at' => 'datetime',
            'ia_inicio_at' => 'datetime',
            'partidas' => 'array',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    public function tomadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tomada_por');
    }

    public function creadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(SolicitudArchivo::class, 'solicitud_id')->orderBy('id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(SolicitudEvento::class, 'solicitud_id')->orderByDesc('id');
    }

    /** "S-12" */
    public function folioTexto(): string
    {
        return 'S-'.$this->folio;
    }

    public function nombreCliente(): string
    {
        return (string) ($this->cliente?->nombre ?: $this->cliente_texto ?: 'Sin cliente');
    }

    public function canalTexto(): string
    {
        return self::CANALES[$this->canal] ?? (string) $this->canal;
    }

    public function estaAbierta(): bool
    {
        return in_array($this->estatus, ['pendiente', 'en_proceso'], true);
    }

    /** Primer envío de la cotización al cliente (calculado; null si aún no sale o no hay cotización). */
    public function enviadaAt(): ?Carbon
    {
        return $this->cotizacion_id ? (Solicitudes::primerosEnvios([$this->cotizacion_id])[$this->cotizacion_id] ?? null) : null;
    }

    public function minutosHastaTomar(): ?int
    {
        return Solicitudes::minutosHastaTomar($this);
    }

    public function minutosHastaEnviar(): ?int
    {
        return Solicitudes::minutosHastaEnviar($this);
    }

    /** Anota en la bitácora de la solicitud. */
    public function anotar(string $tipo, string $descripcion, ?int $userId = null): SolicitudEvento
    {
        return SolicitudEvento::create([
            'solicitud_id' => $this->id,
            'user_id' => $userId ?? auth()->id(),
            'tipo' => $tipo,
            'descripcion' => mb_substr($descripcion, 0, 500),
        ]);
    }
}

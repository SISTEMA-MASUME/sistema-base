<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Gasto de MASUME o personal. Los movimientos entre cuentas se guardan pero no cuentan como gasto. */
class Gasto extends Model
{
    use SoftDeletes;

    protected $table = 'gastos';

    public const DE = ['masume' => 'MASUME', 'personal' => 'Personal'];

    public const TIPOS = ['gasto' => 'Gasto', 'movimiento' => 'Movimiento entre cuentas'];

    /**
     * Lista general de categorías (solo estas se pueden elegir). Las de nómina, reparto y pago de tarjetas las crea el
     * sistema (`CalculoNomina` depende de 'NOMINA' y 'REPARTO SOCIO'): no cambiarles el nombre.
     */
    public const CATEGORIAS = ['GASOLINA', 'MANTENIMIENTO VEHICULOS', 'NOMINA', 'ALIMENTOS', 'IMPUESTOS', 'CONTADOR Y TIMBRES',
        'TRANSPORTE Y ENVIOS', 'REPARACIONES', 'SEGUROS', 'PUBLICIDAD', 'COMPUTO Y PAPELERIA', 'SERVICIOS', 'SUBSCRIPCIONES',
        'PERSONAL Y EVENTOS', 'COMPRA DE VEHICULOS Y EQUIPO', 'GASTOS OPERATIVOS', 'PAGO TARJETAS', 'PAGO PRESTAMO', 'REPARTO SOCIO'];

    /** El reparto al socio es reparto de utilidad: no entra en la base del reparto siguiente. */
    public const NO_RESTAN_AL_REPARTO = ['REPARTO SOCIO'];

    /** No son gasto operativo del mes en el Dashboard: el reparto es utilidad y la compra de una camioneta/equipo es inversión. */
    public const NO_OPERATIVOS = ['REPARTO SOCIO', 'COMPRA DE VEHICULOS Y EQUIPO'];

    protected $fillable = ['fecha', 'monto', 'de', 'tipo', 'categoria', 'descripcion', 'cuenta_id', 'cuenta_destino_id', 'nomina_id',
        'origen', 'llave_importacion', 'user_id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (Gasto $g) {
            $g->categoria = filled($g->categoria) ? mb_substr(Str::upper(Str::squish((string) $g->categoria)), 0, 60) : null;
        });
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    public function cuentaDestino(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class, 'cuenta_destino_id');
    }

    public function nomina(): BelongsTo
    {
        return $this->belongsTo(Nomina::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Los que sí son gasto (no movimientos entre cuentas). */
    public function scopeReales(Builder $q): Builder
    {
        return $q->where('tipo', 'gasto');
    }

    /** Gastos MASUME del mes que se restan para el reparto al socio. */
    public static function delMesParaReparto(int $anio, int $mes): float
    {
        $desde = Carbon::create($anio, $mes, 1);

        return round((float) self::reales()->where('de', 'masume')
            ->where('fecha', '>=', $desde->toDateString())->where('fecha', '<=', $desde->copy()->endOfMonth()->toDateString().' 23:59:59')
            ->where(fn ($q) => $q->whereNull('categoria')->orWhereNotIn('categoria', self::NO_RESTAN_AL_REPARTO))
            ->sum('monto'), 2);
    }
}

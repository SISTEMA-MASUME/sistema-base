<?php

namespace App\Models\Ned;

use App\Models\User;
use App\Support\Folios;
use App\Support\Precios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/** Cotización de Ned Tracking. Independiente de las cotizaciones de MASUME. */
class NedCotizacion extends Model
{
    use SoftDeletes;

    protected $table = 'ned_cotizaciones';

    protected $fillable = ['folio', 'user_id', 'cotizacion_id', 'cliente_nombre', 'cliente_direccion', 'cliente_contacto', 'cliente_correo',
        'cliente_telefono', 'fecha', 'vigencia_dias', 'notas', 'subtotal', 'iva', 'total'];

    protected $casts = ['fecha' => 'date', 'subtotal' => 'decimal:2', 'iva' => 'decimal:2', 'total' => 'decimal:2'];

    public function partidas(): HasMany
    {
        return $this->hasMany(NedPartida::class)->orderBy('orden');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public const CONTADOR = 'ned_cotizacion';

    /** Siguiente folio libre (se salta los que Pedro ya haya usado a mano). */
    public static function siguienteFolio(): int
    {
        do {
            $folio = Folios::siguiente(self::CONTADOR);
        } while (self::withTrashed()->where('folio', $folio)->exists());

        return $folio;
    }

    /** Número que tomará la próxima cotización nueva. */
    public static function proximoFolio(): int
    {
        $ultimo = DB::table('contadores')->where('clave', self::CONTADOR)->value('ultimo');

        return (int) ($ultimo ?? self::withTrashed()->max('folio')) + 1;
    }

    /** Pedro cambia el consecutivo: la próxima cotización nueva saldrá con este número. */
    public static function fijarProximoFolio(int $numero): void
    {
        DB::table('contadores')->updateOrInsert(
            ['clave' => self::CONTADOR],
            ['ultimo' => $numero - 1, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function folioTexto(): string
    {
        return 'NT-'.str_pad((string) $this->folio, 4, '0', STR_PAD_LEFT);
    }

    public function nombreArchivoPdf(): string
    {
        $cliente = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $this->cliente_nombre), '_');

        return 'NedTracking_Cotizacion_'.$this->folioTexto().'_'.$cliente.'.pdf';
    }

    public function recalcularTotales(): void
    {
        $this->forceFill(Precios::totales($this->partidas()->pluck('importe')))->save();
    }
}

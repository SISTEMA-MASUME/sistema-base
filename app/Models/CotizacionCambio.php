<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bitácora de cambios: solo inserción, nunca se edita ni se borra. */
class CotizacionCambio extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cotizacion_cambios';

    protected $fillable = [
        'cotizacion_id', 'partida_id', 'partida_orden', 'user_id', 'accion', 'campo',
        'valor_anterior', 'valor_nuevo', 'descripcion_partida',
    ];

    public const ETIQUETAS = [
        'cliente_id' => 'cliente', 'contacto_id' => 'contacto', 'vendedor_id' => 'elaboró', 'fecha' => 'fecha', 'vigencia_dias' => 'vigencia (días)',
        'forma_pago' => 'forma de pago', 'condiciones_entrega' => 'condiciones de entrega', 'notas' => 'notas', 'estado' => 'estado',
        'cantidad' => 'cantidad', 'descripcion' => 'descripción', 'unidad' => 'unidad', 'marca' => 'marca', 'modelo' => 'modelo',
        'precio' => 'precio', 'costo' => 'costo', 'utilidad_pct' => 'utilidad %', 'proveedor' => 'proveedor',
        'tiempo_entrega' => 'tiempo de entrega', 'incluir' => 'incluir en PDF', 'entrega_lenta' => 'entrega lenta', 'imagen_path' => 'imagen',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Texto legible, sin fecha ni usuario. */
    public function texto(): string
    {
        $v = fn (?string $s) => $s === null || $s === '' ? '(vacío)' : $s;
        $partida = $this->partida_orden ? "partida {$this->partida_orden}" : 'partida';
        $desc = $this->descripcion_partida ? ' ('.$this->descripcion_partida.')' : '';
        $etq = self::ETIQUETAS[$this->campo] ?? $this->campo;

        return match ($this->accion) {
            'revision' => 'Rev. '.$this->valor_nuevo.' iniciada',
            'revision_pedida' => 'pidió revisión de la cotización'.($this->valor_nuevo ? ': '.$this->valor_nuevo : ''),
            'revisada' => 'marcó la cotización como revisada ✓',
            'revision_anulada' => 'cambió la cotización después de revisarla: se quitó la marca de revisada',
            'comentario' => 'comentó'.($this->partida_orden ? ' en la '.$partida : '').': "'.$this->valor_nuevo.'"',
            'comentario_resuelto' => 'marcó como resuelto el comentario'.($this->partida_orden ? ' de la '.$partida : '').': "'.$this->valor_nuevo.'"',
            'partida_agregada' => 'agregó la '.$partida.($this->descripcion_partida ? ': '.$this->descripcion_partida : ''),
            'partida_eliminada' => 'eliminó la '.$partida.($this->descripcion_partida ? ': '.$this->descripcion_partida : ''),
            'partida_editada' => ucfirst($partida).$desc.': '.$etq.($this->campo === 'imagen_path'
                ? ' cambiada'
                : ' '.$v($this->valor_anterior).' → '.$v($this->valor_nuevo)),
            default => 'Encabezado: '.$etq.' '.$v($this->valor_anterior).' → '.$v($this->valor_nuevo),
        };
    }
}

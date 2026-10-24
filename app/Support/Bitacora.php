<?php

namespace App\Support;

use App\Enums\EstadoCotizacion;
use App\Models\Cliente;
use App\Models\Contacto;
use App\Models\Cotizacion;
use App\Models\CotizacionCambio;
use App\Models\CotizacionPartida;
use App\Models\Proveedor;
use App\Models\User;
use Carbon\CarbonInterface;

/** Registro de cambios de cotizaciones ya enviadas y control del número de revisión. */
class Bitacora
{
    public const CAMPOS_ENCABEZADO = [
        'cliente_id', 'contacto_id', 'fecha', 'vigencia_dias', 'forma_pago', 'condiciones_entrega', 'notas', 'estado', 'vendedor_id',
    ];

    public const CAMPOS_PARTIDA = [
        'cantidad', 'descripcion', 'unidad', 'marca', 'modelo', 'precio', 'costo', 'utilidad_pct',
        'proveedor_id', 'tiempo_entrega', 'incluir', 'entrega_lenta', 'imagen_path',
    ];

    private const NUMERICOS = ['cantidad', 'precio', 'costo', 'utilidad_pct', 'vigencia_dias'];

    private const BOOLEANOS = ['incluir', 'entrega_lenta'];

    private static bool $pausada = false;

    /** Ejecuta el callback sin registrar cambios (importador, envío de correo). */
    public static function sinRegistro(callable $fn): mixed
    {
        $antes = self::$pausada;
        self::$pausada = true;
        try {
            return $fn();
        } finally {
            self::$pausada = $antes;
        }
    }

    /** Se registra después del primer envío o desde que se pidió la revisión interna. */
    public static function activa(Cotizacion $cot): bool
    {
        if (self::$pausada) {
            return false;
        }

        return self::enviada($cot) || $cot->revision_pedida_at !== null;
    }

    private static function enviada(Cotizacion $cot): bool
    {
        return $cot->enviada_at !== null || $cot->envios()->where('exito', true)->exists();
    }

    /** Anota un evento de la revisión interna (pedida, revisada, comentario). No abre Rev. N. */
    public static function evento(Cotizacion $cot, string $accion, ?string $texto = null, ?int $partidaOrden = null): void
    {
        CotizacionCambio::create([
            'cotizacion_id' => $cot->id, 'user_id' => auth()->id(), 'accion' => $accion,
            'valor_nuevo' => $texto, 'partida_orden' => $partidaOrden,
        ]);
    }

    /** Revisión con la que salió el último envío (0 si nunca hubo registro de envío). */
    private static function revisionUltimoEnvio(Cotizacion $cot): int
    {
        $envio = $cot->envios()->reorder('id', 'desc')->where('exito', true)->first();

        return (int) ($envio?->revision ?? 0);
    }

    public static function alCambiarEncabezado(Cotizacion $cot): void
    {
        if (! self::activa($cot)) {
            return;
        }

        $filas = [];
        foreach (array_keys($cot->getChanges()) as $campo) {
            if (! in_array($campo, self::CAMPOS_ENCABEZADO, true)) {
                continue;
            }
            $ant = self::valorEncabezado($campo, $cot->getOriginal($campo));
            $nue = self::valorEncabezado($campo, $cot->getAttribute($campo));
            if ($ant !== $nue) {
                $filas[] = ['accion' => $campo === 'estado' ? 'estado' : 'encabezado', 'campo' => $campo, 'valor_anterior' => $ant, 'valor_nuevo' => $nue];
            }
        }

        // Un cambio de estado (p. ej. aceptada) no modifica el documento enviado: se anota pero no abre revisión.
        $abreRevision = collect($filas)->contains(fn ($f) => $f['accion'] === 'encabezado');
        self::guardar($cot, $filas, [], $abreRevision);
    }

    public static function alCrearPartida(CotizacionPartida $p): void
    {
        $cot = Cotizacion::find($p->cotizacion_id);
        if (! $cot || ! self::activa($cot)) {
            return;
        }
        self::guardar($cot, [['accion' => 'partida_agregada']], self::datosPartida($p));
    }

    public static function alEditarPartida(CotizacionPartida $p): void
    {
        $cot = Cotizacion::find($p->cotizacion_id);
        if (! $cot || ! self::activa($cot)) {
            return;
        }

        $filas = [];
        foreach (array_keys($p->getChanges()) as $campo) {
            if (! in_array($campo, self::CAMPOS_PARTIDA, true)) {
                continue;
            }
            $ant = self::valorPartida($campo, $p->getOriginal($campo));
            $nue = self::valorPartida($campo, $p->getAttribute($campo));
            if ($ant === $nue) {
                continue;
            }
            if ($campo === 'imagen_path') {
                $ant = $nue = null;
            }
            $filas[] = [
                'accion' => 'partida_editada', 'campo' => $campo === 'proveedor_id' ? 'proveedor' : $campo,
                'valor_anterior' => $ant, 'valor_nuevo' => $nue,
            ];
        }
        self::guardar($cot, $filas, self::datosPartida($p));
    }

    public static function alEliminarPartida(CotizacionPartida $p): void
    {
        $cot = Cotizacion::find($p->cotizacion_id);
        if (! $cot || ! self::activa($cot)) {
            return;
        }
        self::guardar($cot, [['accion' => 'partida_eliminada']], self::datosPartida($p));
    }

    /** Inserta las filas; antes, si es el primer cambio tras el último envío, abre la revisión siguiente. */
    private static function guardar(Cotizacion $cot, array $filas, array $extra, bool $abreRevision = true): void
    {
        if ($filas === []) {
            return;
        }

        $cot = Cotizacion::withTrashed()->find($cot->id);
        $userId = auth()->id();

        // La Rev. N solo existe después de mandarla al cliente; en la revisión interna solo se anota.
        if ($abreRevision && self::enviada($cot) && $cot->revision <= self::revisionUltimoEnvio($cot)) {
            $nueva = $cot->revision + 1;
            Cotizacion::withTrashed()->whereKey($cot->id)->update(['revision' => $nueva]);
            CotizacionCambio::create([
                'cotizacion_id' => $cot->id, 'user_id' => $userId, 'accion' => 'revision',
                'valor_anterior' => (string) $cot->revision, 'valor_nuevo' => (string) $nueva,
            ]);
        }

        foreach ($filas as $fila) {
            CotizacionCambio::create($fila + $extra + ['cotizacion_id' => $cot->id, 'user_id' => $userId]);
        }
    }

    private static function datosPartida(CotizacionPartida $p): array
    {
        $desc = trim((string) $p->descripcion);

        return [
            'partida_id' => $p->id,
            'partida_orden' => $p->orden,
            'descripcion_partida' => $desc === '' ? null : mb_strimwidth($desc, 0, 60, '…'),
        ];
    }

    private static function valorPartida(string $campo, mixed $v): ?string
    {
        if ($campo === 'proveedor_id') {
            return $v ? Proveedor::find($v)?->nombre : null;
        }

        return self::normalizar($campo, $v);
    }

    private static function valorEncabezado(string $campo, mixed $v): ?string
    {
        return match ($campo) {
            'cliente_id' => $v ? Cliente::find($v)?->nombre : null,
            'contacto_id' => $v ? Contacto::find($v)?->nombre : null,
            'vendedor_id' => $v ? User::find($v)?->name : null,
            'estado' => $v instanceof EstadoCotizacion ? $v->value : self::normalizar($campo, $v),
            default => self::normalizar($campo, $v),
        };
    }

    private static function normalizar(string $campo, mixed $v): ?string
    {
        if ($v instanceof CarbonInterface) {
            return $v->format('Y-m-d');
        }
        if (in_array($campo, self::BOOLEANOS, true)) {
            return $v ? 'sí' : 'no';
        }
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }
        if (in_array($campo, self::NUMERICOS, true) && is_numeric($v)) {
            $s = rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');

            return $s === '' || $s === '-0' ? '0' : $s;
        }

        return trim((string) $v);
    }
}

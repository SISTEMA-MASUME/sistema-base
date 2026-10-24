<?php

namespace App\Support;

use App\Models\PedidoConciliacion;
use App\Models\SolicitudArchivo;
use App\Models\SolicitudCotizacion;
use App\Services\Ia\ExtractorOc;
use App\Services\Ia\ExtractorOcException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lectura de la OC del cliente con IA (asíncrona): iniciar y consultar. La usan la tarjeta del pedido
 * (Pedidos\Conciliacion) y el alta de pedido (Pedidos\Nuevo, antes de que exista el pedido).
 */
final class LecturaOc
{
    public const MINUTOS_MAXIMOS = 9;

    // El POST puede tener tres intentos de 30 segundos antes de devolver el identificador.
    public const MINUTOS_PARA_INICIAR = 3;

    public static function mime(string $ruta): ?string
    {
        return match (strtolower(pathinfo($ruta, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };
    }

    /** Modelo para el archivo: PDF → económico; fotos → el más fuerte (lee mejor códigos en fotos). */
    public static function modelo(string $mime): string
    {
        return str_starts_with($mime, 'image/')
            ? (string) config('services.openai.modelo_imagenes')
            : (string) config('services.openai.modelo');
    }

    /** Texto de error si se pasaría del tope mensual de IA; null si hay presupuesto. */
    public static function sinPresupuesto(string $modelo): ?string
    {
        $tope = (float) config('services.openai.tope_mensual_usd');
        $gastado = PedidoConciliacion::gastoDelMes();
        // Lecturas que siguen en curso (todavía sin costo registrado) cuentan como apartadas.
        $apartado = PedidoConciliacion::where('estado', 'procesando')->where('created_at', '>=', now()->subMinutes(15))->get(['modelo'])
            ->sum(fn ($c) => PedidoConciliacion::calcularCosto($c->modelo, 12000, 8000));
        $solicitudes = SolicitudCotizacion::where('ia_estado', 'leyendo')->where('ia_inicio_at', '>=', now()->subMinutes(15))->get(['ia_archivo_id']);
        $mimes = SolicitudArchivo::whereIn('id', $solicitudes->pluck('ia_archivo_id'))->pluck('mime', 'id');
        $apartado += $solicitudes->sum(fn ($s) => PedidoConciliacion::calcularCosto(self::modelo($mimes[$s->ia_archivo_id] ?? 'application/pdf'), 12000, 8000));
        $estimado = PedidoConciliacion::calcularCosto($modelo, 12000, 8000);
        if ($gastado + $apartado + $estimado > $tope) {
            return sprintf('Se alcanzó el tope mensual de IA (US$%s de US$%s). Pídele a un administrador que lo suba (IA_TOPE_MENSUAL_USD) o espera al próximo mes.',
                number_format($gastado, 2), number_format($tope, 2));
        }

        return null;
    }

    /** Revisa presupuesto y crea la reserva bajo el mismo candado de base de datos. */
    public static function reservar(array $datos): PedidoConciliacion
    {
        return PresupuestoIa::reservar(fn () => PedidoConciliacion::create($datos + ['estado' => 'procesando']), (string) $datos['modelo']);
    }

    /** Manda el archivo a leer; deja la conciliación en "procesando" con su response_id (o en error). */
    public static function iniciar(PedidoConciliacion $c, string $ruta, string $mime, string $contexto): void
    {
        try {
            $id = app()->make(ExtractorOc::class, ['modelo' => $c->modelo])->iniciar($ruta, $mime, $contexto);
            $c->update(['response_id' => $id]);
        } catch (Throwable $e) {
            self::fallarConExcepcion($c, $e);
        }
    }

    /**
     * Pregunta si ya terminó. Devuelve true si la lectura quedó completada (con `extraccion`), false si sigue
     * procesando o falló (en ese caso `estado` = error y `error` explica).
     */
    public static function consultar(PedidoConciliacion $c, bool $recuperarVencida = false): bool
    {
        $c->refresh();
        if ($c->estado !== 'procesando') {
            return $c->estado === 'completada';
        }
        if (! $c->response_id) {
            if ($c->created_at->lt(now()->subMinutes(self::MINUTOS_PARA_INICIAR))) {
                self::fallarSiPendiente($c, 'no se pudo iniciar la lectura');
            }

            return false;
        }
        // Con store:false OpenAI solo guarda la respuesta ~10 minutos.
        if ($c->created_at->lt(now()->subMinutes($recuperarVencida ? 10 : self::MINUTOS_MAXIMOS))) {
            self::fallarSiPendiente($c, 'tiempo agotado; la lectura tardó demasiado. Vuelve a intentar');

            return false;
        }
        try {
            $r = app()->make(ExtractorOc::class, ['modelo' => $c->modelo])->consultar($c->response_id);
        } catch (Throwable $e) {
            self::siPendiente($c, fn ($actual) => self::fallarConExcepcion($actual, $e));

            return false;
        }
        if ($r['estado'] === 'procesando') {
            return false;
        }

        $uso = $r['uso'] ?? [];
        $tokens = [
            'tokens_entrada' => (int) ($uso['tokens_entrada'] ?? 0),
            'tokens_salida' => (int) ($uso['tokens_salida'] ?? 0),
            'tokens_cache' => (int) ($uso['tokens_cache'] ?? 0),
            'costo_usd' => PedidoConciliacion::calcularCosto($c->modelo, (int) ($uso['tokens_entrada'] ?? 0), (int) ($uso['tokens_salida'] ?? 0)),
            'duracion_seg' => max(0, now()->timestamp - $c->created_at->timestamp),
        ];
        self::siPendiente($c, function ($actual) use ($tokens, $r) {
            $actual->update($tokens + ($r['estado'] === 'error'
                ? ['estado' => 'error', 'error' => 'No se pudo leer la OC: '.($r['error'] ?? 'error desconocido').'.']
                : ['estado' => 'completada', 'extraccion' => $r['datos']]));
        });

        $c->refresh();

        return $c->estado === 'completada';
    }

    private static function siPendiente(PedidoConciliacion $c, callable $guardar): void
    {
        DB::transaction(function () use ($c, $guardar) {
            $actual = PedidoConciliacion::lockForUpdate()->find($c->id);
            if ($actual?->estado === 'procesando' && $actual->response_id === $c->response_id) {
                $guardar($actual);
            }
        });
    }

    private static function fallarSiPendiente(PedidoConciliacion $c, string $motivo): void
    {
        self::siPendiente($c, fn ($actual) => self::fallar($actual, $motivo));
    }

    public static function fallar(PedidoConciliacion $c, string $motivo): void
    {
        $c->update(['estado' => 'error', 'error' => 'No se pudo leer la OC: '.$motivo.'.']);
    }

    public static function fallarConExcepcion(PedidoConciliacion $c, Throwable $e): void
    {
        $registro = RegistroErrores::registrar($e);
        $motivo = $e instanceof ExtractorOcException
            ? rtrim($e->getMessage(), '.')
            : 'ocurrió un error inesperado'.($registro ? ' (código '.$registro->codigo.')' : '');
        self::fallar($c, $motivo);
    }

    /**
     * Diferencias en palabras sencillas para quien captura el pedido.
     *
     * @return list<array{tipo: string, texto: string, clave: string, partida_id: ?int, cantidad_oc: ?float, renglon: ?array}>
     */
    public static function explicar(array $resultado): array
    {
        $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.');
        $salida = [];
        foreach ($resultado['filas'] ?? [] as $f) {
            if ($f['estado'] === 'coincide' || ($f['resuelta'] ?? false)) {
                continue;
            }
            $r = $f['renglon'];
            $p = $f['partida'];
            $nombre = '«'.mb_strimwidth((string) ($p['descripcion'] ?? $r['descripcion'] ?? ''), 0, 70, '…').'»';
            foreach ($f['diferencias'] as $d) {
                $texto = match ($d) {
                    'falta_en_pedido' => 'La OC pide «'.mb_strimwidth((string) $r['descripcion'], 0, 70, '…').'» ('.$n($r['cantidad']).' '.($r['unidad'] ?? '').') y no lo marcaste. Búscalo en el paso 2.',
                    'sobra_en_pedido' => "Marcaste {$nombre} pero no viene en la OC del cliente.",
                    'cantidad_distinta' => "{$nombre}: la OC pide ".$n($r['cantidad']).' y tú pusiste '.$n($p['cantidad']).'.',
                    'modelo_distinto' => "{$nombre}: el modelo no coincide (OC «{$r['modelo']}», tuyo «{$p['modelo']}»).",
                    'unidad_distinta' => "{$nombre}: la unidad no coincide (OC {$r['unidad']}, tuya {$p['unidad']}).",
                    'precio_distinto' => "{$nombre}: el precio no coincide (".($f['detalles']['precio_distinto'] ?? '').').',
                    'dudoso' => "{$nombre}: no estoy seguro de que sea lo mismo que «".mb_strimwidth((string) $r['descripcion'], 0, 60, '…').'» de la OC. Revísalo.',
                    'confianza_baja' => 'Un renglón de la OC se leyó con dificultad («'.mb_strimwidth((string) ($r['descripcion'] ?? ''), 0, 60, '…').'»): revísalo contra el archivo.',
                    default => null,
                };
                if ($texto) {
                    $salida[] = ['tipo' => $d, 'texto' => $texto, 'clave' => $f['clave'], 'partida_id' => $p['id'] ?? null,
                        'cantidad_oc' => $r ? (float) $r['cantidad'] : null, 'renglon' => $r];
                }
            }
        }

        return $salida;
    }
}

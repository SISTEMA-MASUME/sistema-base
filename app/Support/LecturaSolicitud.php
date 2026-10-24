<?php

namespace App\Support;

use App\Models\Cliente;
use App\Models\IaConsumo;
use App\Models\PedidoConciliacion;
use App\Models\SolicitudArchivo;
use App\Models\SolicitudCotizacion;
use App\Services\Ia\ExtractorOcException;
use App\Services\Ia\OpenAiExtractorSolicitud;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lectura con IA de los archivos de una solicitud, asíncrona (iniciar y consultar con wire:poll), un archivo a la
 * vez. Lo que lee se AGREGA a lo ya escrito. Si falla, la solicitud sigue sirviendo (ia_estado = error).
 * El gasto cuenta para el tope mensual (ia_consumos, igual que las claves SAT).
 */
final class LecturaSolicitud
{
    public const MINUTOS_MAXIMOS = 9;

    public const MAX_PARTIDAS = 200;

    /** Texto pegado en "Qué pidió" que se manda a acomodar en partidas (se guarda como archivo .txt). */
    public const MIME_TEXTO = 'text/plain';

    /** Manda a leer el siguiente archivo sin leer. Deja ia_estado en leyendo o en error. */
    public static function iniciar(SolicitudCotizacion $s): void
    {
        $s->refresh();
        if ($s->ia_estado === 'leyendo') {
            return;
        }
        $archivo = $s->archivos()->whereNull('leido_at')->first();
        if (! $archivo) {
            self::fallar($s, $s->archivos()->exists() ? 'Ya se leyeron todos los archivos.' : 'Sube primero un PDF, una foto o un Excel.');

            return;
        }
        $ruta = Storage::disk('local')->path($archivo->ruta);
        $temporal = null;
        if (TablaArchivo::esTabla($archivo->mime) && Storage::disk('local')->exists($archivo->ruta)) {
            // Excel/CSV: se manda como texto (más exacto y barato que una imagen).
            $texto = TablaArchivo::comoTexto($ruta, $archivo->mime);
            if ($texto === null) {
                $archivo->update(['leido_at' => now()]);
                self::fallar($s, "No pude leer «{$archivo->nombre}». Si es Excel viejo (.xls), guárdalo como .xlsx y súbelo otra vez.");

                return;
            }
            $temporal = 'solicitudes/tmp/'.Str::uuid().'.txt';
            Storage::disk('local')->put($temporal, $texto);
            $ruta = Storage::disk('local')->path($temporal);
            $mime = 'text/plain';
        } elseif ($archivo->mime === self::MIME_TEXTO) {
            // Texto pegado en "Qué pidió": se manda tal cual.
            $mime = 'text/plain';
        } else {
            $mime = LecturaOc::mime($ruta);
        }
        if (! $mime || ! is_file($ruta)) {
            self::fallar($s, "«{$archivo->nombre}» no es un PDF, foto o Excel que se pueda leer.");

            return;
        }
        $modelo = LecturaOc::modelo($mime);
        $reservada = DB::transaction(function () use ($s, $archivo, $modelo) {
            $actual = SolicitudCotizacion::lockForUpdate()->findOrFail($s->id);
            if ($actual->ia_estado === 'leyendo') {
                return false;
            }
            try {
                PresupuestoIa::reservar(fn () => $actual->update(['ia_estado' => 'leyendo', 'ia_archivo_id' => $archivo->id, 'ia_inicio_at' => now(), 'ia_mensaje' => null, 'ia_respuesta_id' => null]), $modelo);
            } catch (\DomainException $e) {
                self::fallar($actual, $e->getMessage());

                return false;
            }

            return true;
        });
        $s->refresh();
        if (! $reservada) {
            if ($temporal) {
                Storage::disk('local')->delete($temporal);
            }

            return;
        }
        try {
            $id = app()->make(OpenAiExtractorSolicitud::class, ['modelo' => $modelo])->iniciar($ruta, $mime,
                'Cliente capturado por el vendedor: '.($s->nombreCliente() !== 'Sin cliente' ? $s->nombreCliente() : 'no capturado').'. '
                .($mime === 'text/plain' ? 'Extrae los productos que pide el cliente del texto que sigue.' : 'Extrae los productos que pide el cliente del archivo adjunto.'));
            $s->update(['ia_respuesta_id' => $id]);
        } catch (Throwable $e) {
            self::fallarConExcepcion($s, $e);
        } finally {
            if ($temporal) {
                Storage::disk('local')->delete($temporal);
            }
        }
    }

    /**
     * Pregunta si ya terminó el archivo en curso. Devuelve null si sigue leyendo o falló; si terminó, lo que se
     * agregó a la solicitud: ['partidas' => [...], 'cliente_id', 'cliente_texto', 'contacto_texto', 'notas'].
     * Si quedan archivos sin leer, arranca el siguiente.
     */
    public static function consultar(SolicitudCotizacion $s, bool $recuperarVencida = false): ?array
    {
        $s->refresh();
        if ($s->ia_estado !== 'leyendo') {
            return null;
        }
        if (! $s->ia_respuesta_id) {
            if ($s->ia_inicio_at && $s->ia_inicio_at->lt(now()->subMinutes(LecturaOc::MINUTOS_PARA_INICIAR))) {
                self::siPendiente($s, fn ($actual) => self::fallar($actual, 'No se pudo iniciar la lectura.'));
            }

            return null;
        }
        // Con store:false OpenAI solo guarda la respuesta ~10 minutos.
        if ($s->ia_inicio_at && $s->ia_inicio_at->lt(now()->subMinutes($recuperarVencida ? 10 : self::MINUTOS_MAXIMOS))) {
            self::siPendiente($s, fn ($actual) => self::fallar($actual, 'La lectura tardó demasiado. Vuelve a intentar.'));

            return null;
        }
        $modelo = '';
        try {
            $archivo = SolicitudArchivo::find($s->ia_archivo_id);
            $modelo = LecturaOc::modelo((string) $archivo?->mime ?: 'application/pdf');
            $r = app()->make(OpenAiExtractorSolicitud::class, ['modelo' => $modelo])->consultar($s->ia_respuesta_id);
        } catch (Throwable $e) {
            self::siPendiente($s, fn ($actual) => self::fallarConExcepcion($actual, $e));

            return null;
        }
        if ($r['estado'] === 'procesando') {
            return null;
        }

        $agregado = self::siPendiente($s, function ($actual) use ($r, $modelo, $archivo) {
            $uso = $r['uso'] ?? [];
            IaConsumo::create([
                'uso' => 'solicitud', 'modelo' => $modelo, 'user_id' => $actual->creada_por,
                'tokens_entrada' => (int) ($uso['tokens_entrada'] ?? 0), 'tokens_salida' => (int) ($uso['tokens_salida'] ?? 0),
                'costo_usd' => PedidoConciliacion::calcularCosto($modelo, (int) ($uso['tokens_entrada'] ?? 0), (int) ($uso['tokens_salida'] ?? 0)),
            ]);
            if ($r['estado'] === 'error') {
                self::fallar($actual, 'No se pudo leer el archivo: '.($r['error'] ?? 'error desconocido').'.');

                return null;
            }

            $agregado = self::aplicar($actual, (array) $r['datos']);
            $archivo?->update(['leido_at' => now()]);
            $actual->update(['ia_estado' => 'lista', 'ia_respuesta_id' => null, 'ia_mensaje' => null]);
            $actual->anotar('ia', 'La IA leyó un archivo y agregó '.count($agregado['partidas']).' partida(s).');

            return $agregado;
        });
        $s->refresh();
        if ($agregado !== null && $s->archivos()->whereNull('leido_at')->exists()) {
            self::iniciar($s);
        }

        return $agregado;
    }

    /** El navegador y el cron pueden consultar a la vez; solo uno aplica y contabiliza cada respuesta. */
    private static function siPendiente(SolicitudCotizacion $s, callable $guardar): mixed
    {
        return DB::transaction(function () use ($s, $guardar) {
            $actual = SolicitudCotizacion::lockForUpdate()->find($s->id);
            if ($actual?->ia_estado !== 'leyendo' || $actual->ia_respuesta_id !== $s->ia_respuesta_id || $actual->ia_archivo_id !== $s->ia_archivo_id) {
                return null;
            }

            return $guardar($actual);
        });
    }

    /** Agrega lo leído a la solicitud sin borrar nada de lo escrito a mano. */
    public static function aplicar(SolicitudCotizacion $s, array $datos): array
    {
        $nuevas = [];
        foreach (array_slice((array) ($datos['partidas'] ?? []), 0, self::MAX_PARTIDAS) as $p) {
            $desc = trim((string) ($p['descripcion'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $nuevas[] = [
                'cantidad' => isset($p['cantidad']) && is_numeric($p['cantidad']) ? (float) $p['cantidad'] : null,
                'unidad' => filled($p['unidad'] ?? null) ? mb_substr(trim((string) $p['unidad']), 0, 10) : '',
                'descripcion' => mb_substr($desc, 0, 500),
                'modelo' => filled($p['modelo'] ?? null) ? mb_substr(trim((string) $p['modelo']), 0, 250) : '',
            ];
        }
        $cambios = ['partidas' => $nuevas, 'cliente_id' => null, 'cliente_texto' => null, 'contacto_texto' => null, 'notas' => null];
        $campos = ['partidas' => array_merge((array) $s->partidas, $nuevas)];

        $nombre = Nombres::limpiar((string) ($datos['cliente_nombre'] ?? ''));
        if (! $s->cliente_id && $nombre !== '' && Nombres::llave($nombre) !== '') {
            $cliente = Cliente::where('nombre_llave', Nombres::llave($nombre))->first();
            if ($cliente) {
                $campos['cliente_id'] = $cambios['cliente_id'] = $cliente->id;
            } elseif (blank($s->cliente_texto)) {
                $campos['cliente_texto'] = $cambios['cliente_texto'] = mb_substr($nombre, 0, 250);
            }
        }
        if (blank($s->contacto_texto) && filled($datos['contacto'] ?? null)) {
            $campos['contacto_texto'] = $cambios['contacto_texto'] = mb_substr(trim((string) $datos['contacto']), 0, 250);
        }
        if (filled($datos['notas'] ?? null)) {
            $nota = trim((string) $datos['notas']);
            $campos['detalle'] = trim(($s->detalle ? $s->detalle."\n" : '').$nota);
            $cambios['notas'] = $nota;
        }
        $s->update($campos);

        return $cambios;
    }

    public static function fallar(SolicitudCotizacion $s, string $mensaje): void
    {
        $s->update(['ia_estado' => 'error', 'ia_mensaje' => mb_substr($mensaje, 0, 500), 'ia_respuesta_id' => null]);
    }

    public static function fallarConExcepcion(SolicitudCotizacion $s, Throwable $e): void
    {
        $registro = RegistroErrores::registrar($e);
        $motivo = $e instanceof ExtractorOcException
            ? rtrim($e->getMessage(), '.')
            : 'ocurrió un error inesperado'.($registro ? ' (código '.$registro->codigo.')' : '');
        self::fallar($s, 'No se pudo leer el archivo: '.$motivo.'.');
    }
}

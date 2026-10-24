<?php

namespace App\Support;

use App\Models\Vacacion;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Crea y borra el evento de vacaciones en el Google Calendar de calendario@ejemplo.com, por medio de un Apps Script
 * publicado como aplicación web (docs/CALENDARIO-VACACIONES.md). Si no está configurado o falla, la vacación se registra
 * igual y el llamador avisa que se anote a mano. Nunca lanza excepciones.
 */
class CalendarioVacaciones
{
    public const AVISO = 'No se pudo crear el evento en el calendario; anótalo a mano.';

    public const AVISO_BORRAR = 'No se pudo borrar el evento del calendario; bórralo a mano.';

    /** Por qué falló la última llamada, en palabras para Daniel (se muestra junto al aviso). */
    public static ?string $motivo = null;

    /** El aviso con el motivo, para la pantalla. */
    public static function aviso(string $aviso = self::AVISO): string
    {
        return trim($aviso.' '.(self::$motivo ?? ''));
    }

    public static function configurado(): bool
    {
        return filled(config('services.calendario.url')) && filled(config('services.calendario.token'));
    }

    /** Crea el evento y regresa su id, o null si no se pudo. */
    public static function crear(Vacacion $v): ?string
    {
        $nombre = $v->usuario?->nombreReal() ?? 'Empleado';
        $dias = rtrim(rtrim(number_format((float) $v->dias, 1, '.', ''), '0'), '.');
        $respuesta = self::enviar([
            'accion' => 'crear',
            'titulo' => 'Vacaciones: '.$nombre.' ('.$dias.($dias === '1' ? ' día' : ' días').')',
            'desde' => $v->desde->toDateString(),
            'hasta' => $v->hasta->toDateString(),
            'descripcion' => trim('Vacaciones de '.$nombre.' del '.$v->desde->format('d/m/Y').' al '.$v->hasta->format('d/m/Y').'.'
                .($v->notas ? "\n".$v->notas : '')),
        ]);

        return $respuesta['evento_id'] ?? null;
    }

    public static function borrar(Vacacion $v): bool
    {
        if (! $v->evento_id) {
            return true;
        }

        return self::enviar(['accion' => 'borrar', 'evento_id' => $v->evento_id]) !== null;
    }

    /** @return array<string, mixed>|null respuesta con ok = true, o null si algo falló */
    private static function enviar(array $datos): ?array
    {
        self::$motivo = null;
        if (! self::configurado()) {
            // Ojo: en el servidor la configuración queda guardada al publicar; un cambio al .env entra en la siguiente publicación.
            self::$motivo = 'El sistema todavía no tiene la dirección y la clave del calendario (CALENDARIO_URL y CALENDARIO_TOKEN en el .env).';

            return null;
        }
        try {
            $r = Http::timeout(10)->asJson()->post(config('services.calendario.url'),
                ['token' => config('services.calendario.token')] + $datos);
            $json = $r->json();
            if (! $r->successful() || ! is_array($json) || empty($json['ok'])) {
                // Si Google regresa una página (HTML) en vez de JSON, lo útil es su título y a dónde redirigió.
                preg_match('/<title>(.*?)<\/title>/is', (string) $r->body(), $t);
                $uri = $r->effectiveUri();
                throw new RuntimeException('El calendario respondió con error: HTTP '.$r->status()
                    .(isset($t[1]) ? ' · página: '.trim(html_entity_decode(strip_tags($t[1]))) : '')
                    .($uri ? ' · terminó en: '.$uri->getHost().$uri->getPath() : '')
                    .(is_array($json) ? ' · '.mb_substr(json_encode($json, JSON_UNESCAPED_UNICODE), 0, 200) : ''));
            }

            return $json;
        } catch (Throwable $e) {
            $error = RegistroErrores::registrar($e);
            $detalle = str_contains($e->getMessage(), 'Token inv') ? 'Google dice que la clave no coincide: el TOKEN del script y CALENDARIO_TOKEN del .env deben ser idénticos.'
                : 'Google no respondió bien.';
            self::$motivo = $detalle.($error ? ' (código '.$error->codigo.')' : '');

            return null;
        }
    }
}

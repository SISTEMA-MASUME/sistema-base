<?php

namespace App\Support;

use App\Mail\ErrorRegistradoMail;
use App\Models\ErrorSistema;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Guarda los errores del sistema en la tabla errores_sistema, agrupados por
 * "huella", y avisa por correo a los administradores. Nunca lanza excepciones.
 */
class RegistroErrores
{
    public const ATRIBUTO = 'error_codigo';

    public static function registrar(Throwable $e): ?ErrorSistema
    {
        try {
            $huella = self::huella($e);
            $consola = app()->runningInConsole() && ! app()->runningUnitTests();
            $usuarioId = auth()->id();
            $url = $consola ? 'consola' : Str::limit(request()->fullUrl(), 990, '');
            $metodo = $consola ? 'CLI' : request()->method();

            $error = ErrorSistema::where('huella', $huella)->where('resuelto', false)->first();

            if ($error) {
                $error->update([
                    'ocurrencias' => $error->ocurrencias + 1,
                    'ultima_vez' => now(),
                    'url' => $url,
                    'metodo' => $metodo,
                    'user_id' => $usuarioId,
                ]);
                $nuevo = false;
            } else {
                $error = ErrorSistema::create([
                    'codigo' => self::codigoUnico(),
                    'huella' => $huella,
                    'clase' => get_class($e),
                    'mensaje' => Str::limit($e->getMessage(), 2000, '…'),
                    'archivo' => Str::limit(str_replace(base_path().DIRECTORY_SEPARATOR, '', $e->getFile()), 490, ''),
                    'linea' => $e->getLine(),
                    'url' => $url,
                    'metodo' => $metodo,
                    'user_id' => $usuarioId,
                    'ocurrencias' => 1,
                    'primera_vez' => now(),
                    'ultima_vez' => now(),
                    'traza' => Str::limit($e->getTraceAsString(), 20000, "\n…(recortada)"),
                    'contexto' => $consola ? null : self::contexto(),
                ]);
                $nuevo = true;
            }

            request()->attributes->set(self::ATRIBUTO, $error->codigo);

            if ($nuevo) {
                self::avisarAdmins($error);
            }

            return $error;
        } catch (Throwable $fallo) {
            try {
                Log::error('No se pudo registrar el error en la base de datos: '.$fallo->getMessage());
            } catch (Throwable) {
                // nada más que hacer
            }

            return null;
        }
    }

    public static function huella(Throwable $e): string
    {
        $mensaje = preg_replace('/[0-9a-f]{8}-[0-9a-f-]{27}/i', '#', $e->getMessage());
        $mensaje = preg_replace('/\d+/', '#', (string) $mensaje);

        return sha1(get_class($e).'|'.$e->getFile().'|'.$e->getLine().'|'.$mensaje);
    }

    public static function codigoUnico(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $codigo = 'E-';
            for ($i = 0; $i < 6; $i++) {
                $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
        } while (ErrorSistema::where('codigo', $codigo)->exists());

        return $codigo;
    }

    /** Entrada de la petición sin contraseñas, tokens ni archivos. */
    public static function contexto(): ?array
    {
        $datos = self::limpiar(request()->except(['_token']));
        if ($datos === []) {
            return null;
        }
        if (strlen((string) json_encode($datos)) > 8000) {
            return ['_recortado' => 'Entrada demasiado grande', 'campos' => array_slice(array_keys($datos), 0, 50)];
        }

        return $datos;
    }

    private static function limpiar(mixed $valor, string $llave = ''): mixed
    {
        if (preg_match('/pass|clave|token|secret|contrase|snapshot|authorization|card|tarjeta/i', $llave)) {
            return '[oculto]';
        }
        if ($valor instanceof UploadedFile) {
            return '[archivo]';
        }
        if (is_array($valor)) {
            $salida = [];
            foreach ($valor as $k => $v) {
                $salida[$k] = self::limpiar($v, (string) $k);
            }

            return $salida;
        }
        if (is_string($valor)) {
            return Str::limit($valor, 200, '…');
        }

        return is_scalar($valor) || $valor === null ? $valor : '['.get_debug_type($valor).']';
    }

    private static function avisarAdmins(ErrorSistema $error): void
    {
        $cfg = config('mail.mailers.smtp_ventas');
        if (blank($cfg['username'] ?? null) || blank($cfg['password'] ?? null)) {
            return;
        }
        // Máximo un correo por huella por hora.
        if (! Cache::add('error-correo:'.$error->huella, 1, 3600)) {
            return;
        }
        $destinos = User::where('rol', 'admin')->where('activo', true)->pluck('email')->all();
        if ($destinos === []) {
            return;
        }
        try {
            Mail::mailer('smtp_ventas')->to($destinos)->send(new ErrorRegistradoMail($error));
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar el aviso de error '.$error->codigo.': '.$e->getMessage());
        }
    }
}

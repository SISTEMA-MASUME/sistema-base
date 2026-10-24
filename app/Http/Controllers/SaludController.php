<?php

namespace App\Http\Controllers;

use App\Models\ErrorSistema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class SaludController extends Controller
{
    public function index()
    {
        return view('salud.index', [
            'bd' => $this->baseDeDatos(),
            'archivos' => $this->archivos(),
            'cuentas' => $this->cuentas(),
            'respaldo' => $this->respaldo(),
            'version' => $this->version(),
            'errores' => $this->erroresPendientes(),
            'php' => self::php(PHP_VERSION),
        ]);
    }

    /** Primera versión de cada rama con el parche de CVE-2026-109618 y CVE-2026-109619 (php.net, 21-oct-2026). */
    public const PHP_PARCHADO = ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];

    /** Versión de PHP con la que corre la web y si ya trae el parche; también el freno del exploit público (upload progress). */
    public static function php(string $version): array
    {
        $rama = implode('.', array_slice(explode('.', $version), 0, 2));
        $minimo = self::PHP_PARCHADO[$rama] ?? null;

        return [
            'version' => $version,
            'minimo' => $minimo ?? self::PHP_PARCHADO['8.3'],
            'parchado' => $minimo !== null && version_compare($version, $minimo, '>='),
            'upload_progress' => filter_var(ini_get('session.upload_progress.enabled'), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function baseDeDatos(): array
    {
        try {
            DB::connection()->getPdo();
            $driver = DB::connection()->getDriverName();
            $bytes = null;
            if ($driver === 'mysql') {
                $bytes = (int) DB::table('information_schema.tables')
                    ->where('table_schema', DB::connection()->getDatabaseName())
                    ->selectRaw('COALESCE(SUM(data_length + index_length), 0) as total')
                    ->value('total');
            } elseif ($driver === 'sqlite') {
                $ruta = DB::connection()->getDatabaseName();
                $bytes = is_file($ruta) ? filesize($ruta) : null;
            }

            return ['ok' => true, 'mb' => $bytes === null ? null : round($bytes / 1048576, 2)];
        } catch (Throwable) {
            return ['ok' => false, 'mb' => null];
        }
    }

    private function archivos(): array
    {
        $dir = storage_path('app/public');
        $bytes = 0;
        $total = 0;
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $archivo) {
                if ($archivo->isFile()) {
                    $total++;
                    $bytes += $archivo->getSize();
                }
            }
        }

        return ['mb' => round($bytes / 1048576, 2), 'total' => $total];
    }

    private function cuentas(): array
    {
        $salida = [];
        foreach (config('masume.cuentas_correo', []) as $nombre => $cuenta) {
            $salida[$nombre] = filled($cuenta['usuario'] ?? null) && filled($cuenta['clave'] ?? null);
        }

        return $salida;
    }

    private function respaldo(): ?int
    {
        $archivos = File::glob(storage_path('app/respaldos/*.sql.gz')) ?: [];

        return $archivos === [] ? null : max(array_map('filemtime', $archivos));
    }

    private function version(): string
    {
        $ruta = base_path('REVISION');
        if (! is_file($ruta)) {
            return '—';
        }
        $hash = trim((string) file_get_contents($ruta));

        return $hash === '' ? '—' : substr($hash, 0, 7);
    }

    private function erroresPendientes(): ?int
    {
        try {
            return ErrorSistema::where('resuelto', false)->count();
        } catch (Throwable) {
            return null;
        }
    }
}

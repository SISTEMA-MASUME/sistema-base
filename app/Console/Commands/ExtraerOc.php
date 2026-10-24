<?php

namespace App\Console\Commands;

use App\Models\PedidoConciliacion;
use App\Services\Ia\CalificadorExtraccion;
use App\Services\Ia\ExtractorOcException;
use App\Services\Ia\OpenAiExtractorOc;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * Evaluación manual: manda OC a la IA (llamada REAL, cuesta dinero), imprime lo que extrajo y,
 * con --esperado, califica la extracción contra el resultado correcto.
 *
 * Formato de --esperado (JSON, una entrada por nombre de archivo; o un solo objeto si es un archivo):
 *   {"oc1.pdf": {"numero_oc": "123", "subtotal": 1500.5,
 *                "renglones": [{"clave": ["foco", "led"], "cantidad": 10, "unidad": "PZA", "precio": 25.5, "obs": false}]}}
 */
class ExtraerOc extends Command
{
    protected $signature = 'masume:extraer-oc
        {archivo? : PDF o imagen de la OC}
        {--carpeta= : Corre todos los .pdf/.jpg/.png/.webp de una carpeta}
        {--modelo= : Modelo a probar (por omisión IA_MODELO)}
        {--esperado= : JSON con el resultado correcto para calificar}';

    protected $description = 'Extrae con IA los renglones de OC de clientes y muestra JSON, tokens, costo y segundos (evaluación manual)';

    private const MIMES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function handle(): int
    {
        $archivos = $this->archivos();
        if ($archivos === null) {
            return self::FAILURE;
        }
        $esperados = $this->esperados();
        if ($esperados === null) {
            return self::FAILURE;
        }

        $extractor = new OpenAiExtractorOc($this->option('modelo') ?: null);
        $modelo = $extractor->modelo();
        $this->line("Modelo: {$modelo} · Archivos: ".count($archivos));

        // Se inician todos y se sondea cada 3 s (más rápido que uno por uno).
        $pend = [];
        $res = [];
        foreach ($archivos as $ruta) {
            $nombre = basename($ruta);
            $mime = self::MIMES[strtolower(pathinfo($ruta, PATHINFO_EXTENSION))];
            $t0 = microtime(true);
            try {
                $id = $extractor->iniciar($ruta, $mime, 'Extrae los renglones del documento adjunto.');
                $pend[$nombre] = ['id' => $id, 't0' => $t0];
                $this->line("  {$nombre}: en proceso ({$id})");
            } catch (ExtractorOcException $e) {
                $res[$nombre] = ['error' => $e->getMessage(), 'seg' => 0.0];
                $this->error("  {$nombre}: {$e->getMessage()}");
            }
        }

        while ($pend !== []) {
            Sleep::sleep(3);
            foreach ($pend as $nombre => $p) {
                try {
                    $r = $extractor->consultar($p['id']);
                } catch (ExtractorOcException $e) {
                    $r = ['estado' => 'error', 'error' => $e->getMessage()];
                }
                if ($r['estado'] === 'procesando' && microtime(true) - $p['t0'] > 540) {
                    $r = ['estado' => 'error', 'error' => 'Tiempo agotado (más de 9 minutos)'];
                }
                if ($r['estado'] === 'procesando') {
                    continue;
                }
                $r['seg'] = round(microtime(true) - $p['t0'], 1);
                $res[$nombre] = $r;
                unset($pend[$nombre]);
                $this->line("  {$nombre}: ".($r['estado'] === 'completada' ? 'listo' : 'ERROR '.($r['error'] ?? '')).' ('.$r['seg'].' s)');
            }
        }

        $unico = count($archivos) === 1 && ! $this->option('carpeta');
        $calificador = new CalificadorExtraccion;
        $filas = [];
        $tot = ['esp' => 0, 'enc' => 0, 'cant' => 0, 'prec' => 0, 'precEsp' => 0, 'ocOk' => 0, 'ocN' => 0, 'in' => 0, 'out' => 0, 'usd' => 0.0, 'seg' => 0.0, 'errores' => 0];

        foreach ($archivos as $ruta) {
            $nombre = basename($ruta);
            $r = $res[$nombre];
            $uso = $r['uso'] ?? [];
            $in = (int) ($uso['tokens_entrada'] ?? 0);
            $out = (int) ($uso['tokens_salida'] ?? 0);
            $usd = PedidoConciliacion::calcularCosto($modelo, $in, $out);
            $seg = (float) ($r['seg'] ?? 0);
            $tot['in'] += $in;
            $tot['out'] += $out;
            $tot['usd'] += $usd;
            $tot['seg'] += $seg;

            if (($r['estado'] ?? 'error') !== 'completada') {
                $tot['errores']++;
                $filas[] = [$nombre, 'ERROR', '-', '-', '-', "{$in}/{$out}", number_format($usd, 5), $seg];
                if ($unico) {
                    $this->error('La IA no pudo leer la OC: '.($r['error'] ?? 'error desconocido'));
                }

                continue;
            }

            $datos = $r['datos'];
            if ($unico || $this->output->isVerbose()) {
                $this->line(json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $esp = $esperados[$nombre] ?? null;
            if ($esp) {
                $c = $calificador->calificar($datos, $esp);
                $tot['esp'] += $c['esperados'];
                $tot['enc'] += $c['encontrados'];
                $tot['cant'] += $c['cantidades_ok'];
                $tot['prec'] += $c['precios_ok'];
                $tot['precEsp'] += $c['precios_esperados'];
                if ($c['numero_ok'] !== null) {
                    $tot['ocN']++;
                    $tot['ocOk'] += $c['numero_ok'] ? 1 : 0;
                }
                $filas[] = [
                    $nombre, "{$c['encontrados']}/{$c['esperados']}", "{$c['cantidades_ok']}/{$c['esperados']}",
                    "{$c['precios_ok']}/{$c['precios_esperados']}", $c['numero_ok'] === null ? '-' : ($c['numero_ok'] ? 'sí' : 'NO'),
                    "{$in}/{$out}", number_format($usd, 5), $seg,
                ];
            } else {
                $filas[] = [$nombre, count($datos['renglones'] ?? []).' leídos', '-', '-', '-', "{$in}/{$out}", number_format($usd, 5), $seg];
            }
        }

        $this->newLine();
        $encabezado = ['Archivo', 'Renglones (enc/esp)', 'Cantidades ok', 'Precios ok', 'Nº OC', 'Tokens ent/sal', 'USD', 'Seg'];
        if (count($filas) > 1 || $esperados !== []) {
            $filas[] = [
                'TOTAL', "{$tot['enc']}/{$tot['esp']}", "{$tot['cant']}/{$tot['esp']}", "{$tot['prec']}/{$tot['precEsp']}",
                "{$tot['ocOk']}/{$tot['ocN']}", "{$tot['in']}/{$tot['out']}", number_format($tot['usd'], 5), round($tot['seg'], 1),
            ];
        }
        $this->table($encabezado, $filas);
        $this->line(sprintf('Costo total: US$%.5f · Tiempo acumulado: %s s · Errores: %d', $tot['usd'], round($tot['seg'], 1), $tot['errores']));

        return $tot['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string>|null */
    private function archivos(): ?array
    {
        $carpeta = $this->option('carpeta');
        $archivo = $this->argument('archivo');

        if ($carpeta) {
            if (! is_dir($carpeta)) {
                $this->error("No existe la carpeta: {$carpeta}");

                return null;
            }
            $lista = [];
            foreach (scandir($carpeta) ?: [] as $f) {
                $ruta = rtrim($carpeta, '/\\').DIRECTORY_SEPARATOR.$f;
                if (is_file($ruta) && isset(self::MIMES[strtolower(pathinfo($f, PATHINFO_EXTENSION))])) {
                    $lista[] = $ruta;
                }
            }
            if ($lista === []) {
                $this->error('La carpeta no tiene PDF ni imágenes.');

                return null;
            }

            return $lista;
        }

        if (! $archivo) {
            $this->error('Indica un archivo o --carpeta=.');

            return null;
        }
        if (! is_file($archivo)) {
            $this->error("No existe el archivo: {$archivo}");

            return null;
        }
        if (! isset(self::MIMES[strtolower(pathinfo($archivo, PATHINFO_EXTENSION))])) {
            $this->error('Solo PDF, JPG, PNG o WEBP.');

            return null;
        }

        return [$archivo];
    }

    /** @return array<string, array>|null nombre de archivo => esperado */
    private function esperados(): ?array
    {
        $ruta = $this->option('esperado');
        if (! $ruta) {
            return [];
        }
        $json = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;
        if (! is_array($json)) {
            $this->error("No se pudo leer el JSON esperado: {$ruta}");

            return null;
        }
        // Un solo objeto (sin nombres de archivo) aplica al archivo único.
        if (isset($json['renglones']) && is_array($json['renglones'])) {
            $unico = $this->argument('archivo');

            return $unico ? [basename((string) $unico) => $json] : [];
        }

        return $json;
    }
}

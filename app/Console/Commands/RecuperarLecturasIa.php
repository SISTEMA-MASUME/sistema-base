<?php

namespace App\Console\Commands;

use App\Models\PedidoConciliacion;
use App\Models\SolicitudCotizacion;
use App\Services\ConciliadorOc;
use App\Support\LecturaOc;
use App\Support\LecturaSolicitud;
use App\Support\RegistroErrores;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Consulta trabajos existentes sin navegador: no vuelve a subir las OC ni necesita una cola residente. */
class RecuperarLecturasIa extends Command
{
    protected $signature = 'masume:recuperar-lecturas-ia {--limite=20 : Máximo de trabajos a consultar por ejecución}';

    protected $description = 'Recupera las lecturas de OC y solicitudes pendientes y contabiliza sus resultados';

    public function handle(): int
    {
        $limite = filter_var($this->option('limite'), FILTER_VALIDATE_INT);
        if ($limite === false || $limite < 1 || $limite > 200) {
            $this->error('El límite debe ser un entero de 1 a 200.');

            return self::FAILURE;
        }
        if (app()->isDownForMaintenance()) {
            $this->info('El sistema está en mantenimiento; se consultará en la próxima ejecución.');

            return self::SUCCESS;
        }

        $candado = Cache::lock('masume:recuperar-lecturas-ia', 900);
        if (! $candado->get()) {
            $this->info('Otra recuperación sigue en curso.');

            return self::SUCCESS;
        }
        try {
            return $this->recuperar($limite);
        } finally {
            $candado->release();
        }
    }

    private function recuperar(int $limite): int
    {
        // Mezcla ambos tipos por antigüedad: una bandeja no deja esperando indefinidamente a la otra.
        $trabajos = PedidoConciliacion::where('estado', 'procesando')->oldest('created_at')->orderBy('id')->limit($limite)->get()
            ->map(fn ($c) => ['tipo' => 'oc', 'trabajo' => $c, 'inicio' => $c->created_at]);
        $solicitudes = SolicitudCotizacion::where('ia_estado', 'leyendo')->orderBy('ia_inicio_at')->orderBy('id')->limit($limite)->get()
            ->map(fn ($s) => ['tipo' => 'solicitud', 'trabajo' => $s, 'inicio' => $s->ia_inicio_at ?? $s->created_at]);
        $trabajos = $trabajos->concat($solicitudes)->sortBy(fn ($t) => $t['inicio']->timestamp)->take($limite);
        $inicio = microtime(true);
        $consultados = $terminados = $errores = 0;
        foreach ($trabajos as $t) {
            // El siguiente cron llega cada cinco minutos; no iniciamos más consultas después de cuatro minutos.
            if (microtime(true) - $inicio >= 240) {
                $this->warn('Se alcanzó el tiempo de esta ejecución; los pendientes seguirán en la próxima.');
                break;
            }
            $trabajo = $t['trabajo'];
            $consultados++;
            try {
                if ($t['tipo'] === 'oc') {
                    if (LecturaOc::consultar($trabajo, recuperarVencida: true)) {
                        $trabajo->refresh();
                        if ($pedido = $trabajo->pedido) {
                            $r = app(ConciliadorOc::class)->conciliar((array) $trabajo->extraccion,
                                $pedido->partidas()->get(['id', 'descripcion', 'modelo', 'cantidad', 'unidad', 'precio']),
                                $pedido->numero_oc_cliente, (array) $trabajo->resoluciones);
                            $trabajo->update(['resultado' => $r, 'resumen' => $r['resumen'], 'hay_diferencias' => $r['hay_diferencias']]);
                        }
                        $terminados++;
                    }
                    $fallo = $trabajo->fresh()->estado === 'error';
                } else {
                    if (LecturaSolicitud::consultar($trabajo, recuperarVencida: true) !== null) {
                        $terminados++;
                    }
                    $fallo = $trabajo->fresh()->ia_estado === 'error';
                }
                if ($fallo) {
                    $errores++;
                    $this->warn('Lectura '.$t['tipo'].' #'.$trabajo->id.': requiere revisión en el sistema.');
                }
            } catch (Throwable $e) {
                RegistroErrores::registrar($e);
                $errores++;
                $this->warn('No se pudo recuperar '.$t['tipo'].' #'.$trabajo->id.'; se continuará con las demás.');
            }
        }
        $this->info("Consultadas: {$consultados}; terminadas: {$terminados}; errores: {$errores}.");

        return $errores > 0 ? self::FAILURE : self::SUCCESS;
    }
}

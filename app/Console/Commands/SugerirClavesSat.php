<?php

namespace App\Console\Commands;

use App\Models\Pedido;
use App\Support\AltaSicar;
use App\Support\ClavesSatConIa;
use Illuminate\Console\Command;

/** Sugiere con IA la clave SAT de las partidas de pedidos sin alta en SICAR (lo mismo que el botón de la pantalla). */
class SugerirClavesSat extends Command
{
    protected $signature = 'masume:sugerir-claves-sat {--pedido=* : id del pedido (si no, todos los no archivados sin alta)}';

    protected $description = 'Sugiere con IA la clave SAT de las partidas por dar de alta en SICAR';

    public function handle(): int
    {
        $q = Pedido::query()->whereNull('archivado_at')->with(['cliente:id,nombre', 'partidas.proveedor:id,nombre']);
        $this->option('pedido') ? $q->whereIn('id', $this->option('pedido')) : $q->where('alta_sicar', false);
        $alta = new AltaSicar;
        $r = (new ClavesSatConIa)->aplicar($alta->filas($q->get()));
        $this->info("Revisadas: {$r['revisadas']} · sin respuesta válida: {$r['sin_respuesta']} · faltan: {$r['faltan']}");

        $pedidos = $q->get();
        foreach ((new AltaSicar)->filas($pedidos) as $f) {
            if ($f['sat_ia']) {
                $this->line(sprintf('P-%s | %s | %s | %s %s%s', $f['pedido']->folio, $f['estado'], mb_substr($f['partida']->descripcion, 0, 60),
                    $f['sat_sugerida'] ?: $f['sat'], $f['sat_sugerida'] ? $f['sat_sugerida_nombre'] : $f['sat_nombre'], ' ['.$f['sat_ia'].']'));
            }
        }

        return self::SUCCESS;
    }
}

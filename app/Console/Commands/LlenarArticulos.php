<?php

namespace App\Console\Commands;

use App\Support\Articulos;
use Illuminate\Console\Command;

/** Llena (o completa) el catálogo de artículos con SICAR y las partidas de los pedidos. Se puede repetir: no duplica. */
class LlenarArticulos extends Command
{
    protected $signature = 'masume:llenar-articulos';

    protected $description = 'Llena el catálogo de artículos con los de SICAR y las partidas de los pedidos (sin duplicar)';

    public function handle(): int
    {
        $r = Articulos::llenar();
        $this->info("Listo: {$r['sicar']} artículos nuevos de SICAR y {$r['partidas']} partidas de pedidos ligadas.");

        return self::SUCCESS;
    }
}

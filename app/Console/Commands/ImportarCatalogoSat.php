<?php

namespace App\Console\Commands;

use App\Models\SatClave;
use App\Support\CatalogoSat;
use Illuminate\Console\Command;

/**
 * Carga el catálogo oficial del SAT (c_ClaveProdServ) desde database/datos/sat_clave_prod_serv.csv.gz.
 * Ese archivo sale de catCFDI_V_4_<fecha>.xls de sat.gob.mx (Anexo 20), solo claves vigentes.
 * Idempotente: actualiza las que ya existen y borra las que ya no vienen.
 */
class ImportarCatalogoSat extends Command
{
    protected $signature = 'masume:importar-catalogo-sat {--archivo= : CSV .gz con columnas clave,descripcion,similares}';

    protected $description = 'Carga el catálogo de claves de productos y servicios del SAT';

    public function handle(): int
    {
        $ruta = $this->option('archivo') ?: database_path('datos/sat_clave_prod_serv.csv.gz');
        $f = @gzopen($ruta, 'rb');
        if (! $f) {
            $this->error("No se pudo abrir {$ruta}");

            return self::FAILURE;
        }

        fgetcsv($f); // encabezados
        $lote = [];
        $claves = [];
        while (($r = fgetcsv($f)) !== false) {
            if (count($r) < 2 || ! preg_match('/^\d{8}$/', $r[0])) {
                continue;
            }
            $claves[] = $r[0];
            $lote[] = ['clave' => $r[0], 'descripcion' => mb_substr($r[1], 0, 255), 'similares' => ($r[2] ?? '') ?: null,
                'texto' => CatalogoSat::normalizar($r[1].' '.($r[2] ?? ''))];
            if (count($lote) === 1000) {
                SatClave::upsert($lote, ['clave'], ['descripcion', 'similares', 'texto']);
                $lote = [];
            }
        }
        gzclose($f);
        if ($lote) {
            SatClave::upsert($lote, ['clave'], ['descripcion', 'similares', 'texto']);
        }
        if (count($claves) > 50000) {
            // Solo se depura si el archivo venía completo.
            $sobran = array_diff(SatClave::pluck('clave')->all(), $claves);
            foreach (array_chunk($sobran, 500) as $trozo) {
                SatClave::whereIn('clave', $trozo)->delete();
            }
        }

        $this->info(count($claves).' claves SAT cargadas.');

        return self::SUCCESS;
    }
}

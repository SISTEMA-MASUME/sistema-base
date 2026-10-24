<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\Proveedor;
use App\Support\ImportadorRegistro;
use Illuminate\Console\Command;

/**
 * Importa el REGISTRO_COTIZACIONES_MASUME exportado a JSON (TABLA_COT_HDR, TABLA_COT_DET,
 * DIRECTORIO_CLIENTES y CONFIG!B2). Idempotente: las cotizaciones cuyo folio ya existe se
 * saltan, así que se puede correr de nuevo para traer solo las nuevas. También se puede desde la
 * pantalla "Importar REGISTRO" subiendo el Excel descargado de Sheets.
 */
class ImportarRegistro extends Command
{
    protected $signature = 'masume:importar-registro {archivo : JSON exportado del REGISTRO}';

    protected $description = 'Importa cotizaciones, clientes y el consecutivo desde el REGISTRO de Google Sheets';

    public function handle(): int
    {
        $datos = json_decode(file_get_contents($this->argument('archivo')), true);
        if (! $datos) {
            $this->error('No se pudo leer el archivo.');

            return self::FAILURE;
        }

        $r = (new ImportadorRegistro)->importar($datos);

        $this->info("Cotizaciones nuevas: {$r['nuevas']} · ya existían: {$r['existentes']}");
        $this->info('Clientes: '.Cliente::count().' · Proveedores: '.Proveedor::count());
        $this->info('Siguiente folio: '.$r['siguiente']);

        return self::SUCCESS;
    }
}

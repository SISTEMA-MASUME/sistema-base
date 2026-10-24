<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Carga el catálogo del SAT al publicar (el despliegue solo corre migrate). En pruebas no se carga:
 * 52 mil filas en cada prueba harían lenta la suite; las pruebas crean las claves que necesitan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        Artisan::call('masume:importar-catalogo-sat');
    }

    public function down(): void {}
};

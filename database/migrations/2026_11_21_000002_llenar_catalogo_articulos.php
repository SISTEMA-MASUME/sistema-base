<?php

use App\Support\Articulos;
use App\Support\RegistroErrores;
use Illuminate\Database\Migrations\Migration;

/**
 * Llena el catálogo de artículos con lo que ya hay: los artículos activos de SICAR y todas las partidas de los
 * pedidos vigentes (con su proveedor, clave y último costo). Si algo falla, la publicación sigue y el error queda en
 * Errores; se vuelve a correr con `php artisan masume:llenar-articulos` (no duplica).
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Articulos::llenar();
        } catch (Throwable $e) {
            RegistroErrores::registrar($e);
        }
    }

    public function down(): void
    {
        // Las tablas las quita la migración anterior.
    }
};

<?php

use App\Support\CatalogoImagenes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de imágenes por producto: la imagen que se le pone a una partida queda ligada al producto
 * (por modelo, o por descripción si no tiene modelo) y se pone sola la próxima vez que se cotiza.
 * Se llena con las imágenes que ya tenían las cotizaciones (la más reciente de cada producto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_imagenes', function (Blueprint $table) {
            $table->id();
            $table->char('llave_modelo', 40)->nullable()->index();
            $table->char('llave_descripcion', 40)->index();
            $table->text('descripcion');
            $table->string('modelo', 191)->nullable();
            $table->string('imagen_path');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('veces_usada')->default(0);
            $table->timestamps();
        });

        DB::table('cotizacion_partidas')->whereNotNull('imagen_path')->where('descripcion', '!=', '')
            ->orderByDesc('id')->select(['id', 'descripcion', 'modelo', 'imagen_path'])
            ->chunk(500, function ($filas) {
                foreach ($filas as $f) {
                    if (CatalogoImagenes::buscarFila($f->modelo, $f->descripcion, true) === null) {
                        CatalogoImagenes::vincular($f->modelo, $f->descripcion, $f->imagen_path);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_imagenes');
    }
};

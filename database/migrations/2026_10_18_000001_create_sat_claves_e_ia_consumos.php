<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Catálogo oficial del SAT (c_ClaveProdServ), gasto de IA fuera de la conciliación y clave SAT sugerida por IA. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sat_claves', function (Blueprint $table) {
            $table->string('clave', 8)->primary();
            $table->string('descripcion', 255);
            $table->text('similares')->nullable();
            // Descripción + similares en MAYÚSCULAS sin acentos, para buscar con LIKE.
            $table->text('texto');
        });

        Schema::create('ia_consumos', function (Blueprint $table) {
            $table->id();
            $table->string('uso', 40);
            $table->string('modelo', 60);
            $table->unsignedInteger('tokens_entrada')->default(0);
            $table->unsignedInteger('tokens_salida')->default(0);
            $table->decimal('costo_usd', 10, 5)->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('pedido_partidas', function (Blueprint $table) {
            // Confianza de la IA en la clave SAT sugerida (alta, media, baja); null = no la ha revisado la IA.
            $table->string('sicar_sat_ia', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pedido_partidas', fn (Blueprint $table) => $table->dropColumn('sicar_sat_ia'));
        Schema::dropIfExists('ia_consumos');
        Schema::dropIfExists('sat_claves');
    }
};

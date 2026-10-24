<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes guardados de Ned Tracking con sus contactos. El predeterminado (HOTEL CENTRAL) se carga solo en
 * cada cotización nueva de Ned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ned_clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->text('direccion')->nullable();
            $table->string('correo')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->json('contactos')->nullable();
            $table->boolean('predeterminado')->default(false);
            $table->timestamps();
        });

        DB::table('ned_clientes')->insert([
            'nombre' => 'HOTEL CENTRAL',
            'contactos' => json_encode(['SOFÍA LUNA', 'LAURA MARTÍNEZ'], JSON_UNESCAPED_UNICODE),
            'predeterminado' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ned_clientes');
    }
};

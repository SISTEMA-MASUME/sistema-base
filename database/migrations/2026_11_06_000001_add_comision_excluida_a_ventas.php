<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobros que no deben comisionarse (otra vez): p. ej. facturas que Daniel marcó pagadas a mano para la nómina y luego el
 * complemento de SICAR las volvió a traer con otra fecha. Quien maneja la nómina las quita de "Cobros que entran".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->timestamp('comision_excluida_at')->nullable();
            $table->unsignedBigInteger('comision_excluida_por')->nullable();
            $table->string('comision_excluida_motivo', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ventas', fn (Blueprint $table) => $table->dropColumn(['comision_excluida_at', 'comision_excluida_por', 'comision_excluida_motivo']));
    }
};

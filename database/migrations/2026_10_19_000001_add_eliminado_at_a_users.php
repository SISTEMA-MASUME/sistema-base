<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuario eliminado: ya no entra ni aparece en la lista, pero sus cotizaciones, pedidos, comentarios y
 * demás movimientos se quedan y muestran su nombre con "(usuario inactivo)".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->timestamp('eliminado_at')->nullable()->after('acceso_ned'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('eliminado_at'));
    }
};

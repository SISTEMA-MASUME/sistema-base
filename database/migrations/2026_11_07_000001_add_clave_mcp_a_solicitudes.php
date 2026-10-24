<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Un reintento del MCP conserva la misma solicitud, incluso con llamadas simultáneas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_cotizacion', function (Blueprint $table) {
            $table->string('mcp_clave', 64)->nullable()->unique();
            $table->string('mcp_huella', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_cotizacion', function (Blueprint $table) {
            $table->dropUnique(['mcp_clave']);
            $table->dropColumn(['mcp_clave', 'mcp_huella']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes_proveedor', function (Blueprint $table) {
            // La cantidad cambió después de enviarla al proveedor: hay que volver a enviársela.
            $table->boolean('requiere_reenvio')->default(false)->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_proveedor', function (Blueprint $table) {
            $table->dropColumn('requiere_reenvio');
        });
    }
};

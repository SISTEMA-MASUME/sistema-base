<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cuándo el vendedor avisó a Daniel por WhatsApp de un cambio de precio hecho sin autorización (modo actual). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cambios_precio', function (Blueprint $table) {
            $table->dateTime('avisado_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cambios_precio', function (Blueprint $table) {
            $table->dropColumn('avisado_at');
        });
    }
};

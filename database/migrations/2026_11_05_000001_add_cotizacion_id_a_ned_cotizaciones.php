<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotización de MASUME de la que salió la de Ned al mandar "ambos PDF": si se vuelve a mandar, se actualiza
 * la misma (mismo folio de Ned) en vez de crear otra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ned_cotizaciones', fn (Blueprint $table) => $table->unsignedBigInteger('cotizacion_id')->nullable()->index()->after('user_id'));
    }

    public function down(): void
    {
        Schema::table('ned_cotizaciones', function (Blueprint $table) {
            $table->dropIndex(['cotizacion_id']);
            $table->dropColumn('cotizacion_id');
        });
    }
};

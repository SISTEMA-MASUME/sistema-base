<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisión interna de cotizaciones antes de mandarlas al cliente (antes: link del Sheets al grupo de
 * WhatsApp). Quien revisa corrige y comenta; todo queda en la bitácora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->timestamp('revision_pedida_at')->nullable();
            $table->foreignId('revision_pedida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisada_at')->nullable();
            $table->foreignId('revisada_por')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('cotizacion_comentarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->foreignId('cotizacion_partida_id')->nullable()->constrained('cotizacion_partidas')->nullOnDelete();
            $table->unsignedInteger('partida_orden')->nullable();   // se conserva si la partida se borra
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('texto');
            $table->timestamp('resuelto_at')->nullable();
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['cotizacion_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_comentarios');
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revisada_por');
            $table->dropConstrainedForeignId('revision_pedida_por');
            $table->dropColumn(['revision_pedida_at', 'revisada_at']);
        });
    }
};

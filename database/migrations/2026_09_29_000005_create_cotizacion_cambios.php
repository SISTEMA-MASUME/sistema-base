<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizacion_cambios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->unsignedBigInteger('partida_id')->nullable();   // sin FK: la partida puede borrarse
            $table->unsignedSmallInteger('partida_orden')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 20);
            $table->string('campo')->nullable();
            $table->text('valor_anterior')->nullable();
            $table->text('valor_nuevo')->nullable();
            $table->string('descripcion_partida')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['cotizacion_id', 'id']);
        });

        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->unsignedSmallInteger('revision')->default(0);
        });
        Schema::table('cotizacion_envios', function (Blueprint $table) {
            $table->unsignedSmallInteger('revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('cotizacion_envios', fn (Blueprint $t) => $t->dropColumn('revision'));
        Schema::table('cotizaciones', fn (Blueprint $t) => $t->dropColumn('revision'));
        Schema::dropIfExists('cotizacion_cambios');
    }
};

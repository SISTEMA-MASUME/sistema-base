<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Celulares que aceptaron avisos del sistema (notificaciones push del navegador). Uno por celular y usuario. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos_suscripciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('p256dh', 200);
            $table->string('auth', 100);
            $table->string('agente', 255)->nullable();
            $table->timestamp('ultimo_envio_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_suscripciones');
    }
};

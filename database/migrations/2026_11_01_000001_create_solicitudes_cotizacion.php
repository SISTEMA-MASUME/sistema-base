<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bandeja "Por cotizar": solicitudes de cotización que llegan de clientes, sus archivos y su bitácora. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_cotizacion', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('folio')->unique();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('cliente_texto', 250)->nullable();
            $table->string('contacto_texto', 250)->nullable();
            $table->string('canal', 20)->default('whatsapp');
            $table->dateTime('recibida_at');
            $table->text('detalle')->nullable();
            $table->json('partidas')->nullable();
            // Lectura con IA
            $table->string('ia_estado', 20)->nullable();
            $table->string('ia_respuesta_id', 120)->nullable();
            $table->unsignedBigInteger('ia_archivo_id')->nullable();
            $table->dateTime('ia_inicio_at')->nullable();
            $table->string('ia_mensaje', 500)->nullable();
            // Control
            $table->string('estatus', 20)->default('pendiente')->index();
            $table->foreignId('tomada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('tomada_at')->nullable();
            $table->foreignId('cotizacion_id')->nullable()->constrained('cotizaciones')->nullOnDelete();
            $table->string('motivo_descarte', 500)->nullable();
            $table->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('recibida_at');
        });

        Schema::create('solicitud_cotizacion_archivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes_cotizacion')->cascadeOnDelete();
            $table->string('ruta', 500);
            $table->string('nombre', 250);
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('bytes')->default(0);
            $table->dateTime('leido_at')->nullable();
            $table->timestamps();
        });

        Schema::create('solicitud_cotizacion_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes_cotizacion')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 30);
            $table->string('descripcion', 500);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_cotizacion_eventos');
        Schema::dropIfExists('solicitud_cotizacion_archivos');
        Schema::dropIfExists('solicitudes_cotizacion');
    }
};

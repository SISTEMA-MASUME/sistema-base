<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** T16: reglas de facturación y entrega por cliente (portal, evidencia por correo, plazas). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            // Hay que subir la factura a su portal (aunque la OC llegue antes).
            $table->boolean('sube_factura_portal')->default(false);
            // ACME: la evidencia de entrega se manda respondiendo el correo de la OC.
            $table->boolean('envia_evidencia_por_correo')->default(false);
        });
        // Los que generan la OC después de facturar también suben la factura a su portal.
        DB::table('clientes')->where('oc_despues_de_facturar', true)->update(['sube_factura_portal' => true]);

        // PARQUES / LUMEX: plazas en todo el país con la misma razón social.
        Schema::create('cliente_sucursales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('nombre', 150);
            $table->string('ciudad', 100)->nullable();
            $table->string('estado', 100)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('correos_facturacion', 1000)->nullable();   // varios, separados por coma
            $table->string('contacto', 150)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['cliente_id', 'nombre']);
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->constrained('cliente_sucursales')->nullOnDelete();
            $table->string('oc_correo_remitente', 255)->nullable();
            $table->string('oc_correo_asunto', 255)->nullable();
            $table->timestamp('evidencia_enviada_at')->nullable();
            $table->foreignId('evidencia_enviada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('factura_sucursal_enviada_at')->nullable();
            $table->foreignId('factura_sucursal_enviada_por')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('factura_sucursal_enviada_por');
            $table->dropConstrainedForeignId('evidencia_enviada_por');
            $table->dropConstrainedForeignId('sucursal_id');
            $table->dropColumn(['oc_correo_remitente', 'oc_correo_asunto', 'evidencia_enviada_at', 'factura_sucursal_enviada_at']);
        });
        Schema::dropIfExists('cliente_sucursales');
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['sube_factura_portal', 'envia_evidencia_por_correo']);
        });
    }
};

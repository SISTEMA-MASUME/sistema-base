<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** UUID y serie-folio leídos del XML/PDF de la factura subida al pedido: con eso se liga la venta de SICAR (llegue antes o después). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_archivos', function (Blueprint $table) {
            $table->string('cfdi_uuid', 40)->nullable()->index();
            $table->string('cfdi_folio', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pedido_archivos', function (Blueprint $table) {
            $table->dropIndex(['cfdi_uuid']);
            $table->dropColumn(['cfdi_uuid', 'cfdi_folio']);
        });
    }
};

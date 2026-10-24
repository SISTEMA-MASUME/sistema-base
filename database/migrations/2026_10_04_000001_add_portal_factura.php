<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->boolean('oc_despues_de_facturar')->default(false);
            $table->string('portal_url')->nullable();
        });
        Schema::table('pedidos', function (Blueprint $table) {
            $table->timestamp('factura_portal_at')->nullable();
            $table->foreignId('factura_portal_por')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('factura_portal_por');
            $table->dropColumn('factura_portal_at');
        });
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['oc_despues_de_facturar', 'portal_url']);
        });
    }
};

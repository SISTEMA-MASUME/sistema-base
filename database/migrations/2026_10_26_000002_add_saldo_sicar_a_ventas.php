<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lo que SICAR dice que falta por cobrar (con IVA): para encontrar las que aquí están pagadas y en SICAR no. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->decimal('saldo_sicar', 14, 2)->nullable()->after('pago_referencia');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('saldo_sicar');
        });
    }
};

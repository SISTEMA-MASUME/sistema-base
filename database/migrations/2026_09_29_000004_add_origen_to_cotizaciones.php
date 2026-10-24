<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->string('origen', 20)->default('sistema');   // sistema | registro (importada de Sheets)
            $table->string('pdf_original_url')->nullable();      // PDF en Drive de las importadas
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn(['origen', 'pdf_original_url']);
        });
    }
};

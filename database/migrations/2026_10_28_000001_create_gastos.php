<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos (reemplaza las hojas GASTOS 2023/2024/2025 del Sheets): MASUME o personal, categoría, con qué se pagó.
 * Los pagos de nómina y el reparto al socio que se registran en el sistema crean su gasto solos (nomina_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gastos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->decimal('monto', 14, 2);
            $table->string('de', 10)->default('masume');           // masume | personal
            $table->string('tipo', 12)->default('gasto');          // gasto | movimiento (entre cuentas: no es gasto)
            $table->string('categoria', 60)->nullable();
            $table->text('descripcion')->nullable();
            $table->foreignId('cuenta_id')->nullable()->constrained('cuentas')->nullOnDelete();          // pagado con
            $table->foreignId('cuenta_destino_id')->nullable()->constrained('cuentas')->nullOnDelete();
            $table->foreignId('nomina_id')->nullable()->constrained('nominas')->cascadeOnDelete();
            $table->string('origen', 10)->default('manual');       // manual | sheets | nomina
            $table->string('llave_importacion', 40)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('fecha');
            $table->index(['de', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gastos');
    }
};

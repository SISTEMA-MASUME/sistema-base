<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finanzas, fase 1 (reemplaza la hoja VENTAS del Google Sheets de Daniel):
 * - users.permisos: pantallas de finanzas que el admin le abre a cada usuario (por defecto ninguna).
 * - cuentas: bancos, tarjetas y efectivo (cuenta de depósito de los cobros; después gastos y patrimonio).
 * - ventas: una fila por factura o nota de venta (de SICAR o del Sheets), con costo editable para saber
 *   la utilidad real, estatus de pago y estatus del portal del cliente.
 * - venta_cambios: bitácora de quién cambió costo, pago o estatus y cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permisos')->nullable();
        });

        Schema::create('cuentas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80);
            $table->string('nombre_llave', 80)->unique();
            $table->string('tipo', 20)->default('debito');      // debito | credito | efectivo | inversion | apartado | prestamo
            $table->string('de', 10)->default('masume');         // masume | personal
            $table->unsignedTinyInteger('dia_corte')->nullable();
            $table->unsignedTinyInteger('dia_pago')->nullable();
            $table->decimal('limite', 14, 2)->nullable();
            $table->boolean('activa')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 10)->default('factura');      // factura | nota
            $table->string('folio', 40);                          // "5-3317", "NOTA-298"
            $table->string('folio_llave', 60)->unique();          // Venta::llave(): evita duplicar la misma factura
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('cliente_nombre');
            $table->text('concepto')->nullable();
            $table->date('fecha');
            $table->decimal('costo', 14, 2)->default(0);          // compra sin IVA (editable)
            $table->decimal('costo_sicar', 14, 2)->nullable();    // el que dio SICAR (para saber si se ajustó)
            $table->decimal('subtotal', 14, 2)->default(0);       // venta sin IVA
            $table->decimal('iva', 14, 2)->default(0);
            $table->decimal('retencion', 14, 2)->default(0);      // retención que hace el cliente (2025 la tenía)
            $table->decimal('total', 14, 2)->default(0);          // lo que paga el cliente
            $table->string('estatus', 12)->default('pendiente');  // pendiente | pagada | cancelada
            $table->date('fecha_pago')->nullable();
            $table->foreignId('cuenta_id')->nullable()->constrained('cuentas')->nullOnDelete();
            $table->string('pago_referencia', 120)->nullable();   // complemento(s) de pago, "PUE"…
            $table->string('estatus_portal', 20)->nullable();     // por_cargar | cargada | evidencia | programada | no_aplica
            $table->string('metodo_pago', 3)->nullable();         // PUE | PPD
            $table->string('factura_sustituta', 40)->nullable();
            $table->string('uuid', 40)->nullable();
            $table->string('sicar_id', 40)->nullable()->unique();
            $table->string('origen', 10)->default('manual');      // sheets | sicar | manual
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('fecha');
            $table->index(['estatus', 'fecha_pago']);
        });

        Schema::create('venta_cambios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('campo', 40);
            $table->text('antes')->nullable();
            $table->text('despues')->nullable();
            $table->string('origen', 10)->default('usuario');    // usuario | sicar
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_cambios');
        Schema::dropIfExists('ventas');
        Schema::dropIfExists('cuentas');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permisos');
        });
    }
};

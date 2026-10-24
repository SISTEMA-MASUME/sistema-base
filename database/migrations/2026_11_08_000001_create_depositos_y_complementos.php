<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depositos_cobro', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 36)->unique();
            $t->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $t->foreignId('cuenta_id')->constrained('cuentas')->restrictOnDelete();
            $t->date('fecha');
            $t->decimal('importe', 14, 2);
            $t->string('referencia', 120)->nullable();
            $t->string('estatus', 20)->default('provisional');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['cliente_id', 'estatus']);
        });
        Schema::create('complementos_pago', function (Blueprint $t) {
            $t->id();
            $t->string('sicar_id', 40)->nullable()->unique();
            $t->string('uuid', 36)->nullable()->unique();
            $t->string('folio', 40);
            $t->string('rfc', 13);
            $t->string('cliente_nombre');
            $t->date('fecha_pago');
            $t->decimal('importe', 14, 2);
            $t->string('moneda', 3)->default('MXN');
            $t->string('estatus', 20)->default('nuevo');
            $t->boolean('cancelado')->default(false);
            $t->boolean('requiere_revision')->default(false);
            $t->string('huella', 64);
            $t->json('documentos');
            $t->foreignId('deposito_id')->nullable()->constrained('depositos_cobro')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['rfc', 'folio']);
        });
        Schema::table('depositos_cobro', function (Blueprint $t) {
            $t->foreignId('complemento_id')->nullable()->unique()->constrained('complementos_pago')->restrictOnDelete();
        });
        Schema::table('ventas', function (Blueprint $t) {
            $t->foreignId('deposito_cobro_id')->nullable()->constrained('depositos_cobro')->restrictOnDelete();
            $t->foreignId('complemento_pago_id')->nullable()->constrained('complementos_pago')->restrictOnDelete();
        });
        Schema::create('deposito_facturas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deposito_id')->constrained('depositos_cobro')->restrictOnDelete();
            $t->foreignId('venta_id')->constrained('ventas')->restrictOnDelete();
            $t->decimal('importe', 14, 2);
            $t->boolean('original')->default(false);
            $t->boolean('activa')->default(true);
            $t->timestamps();
            $t->unique(['deposito_id', 'venta_id']);
        });
        Schema::create('deposito_eventos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deposito_id')->nullable()->constrained('depositos_cobro')->restrictOnDelete();
            $t->foreignId('complemento_id')->nullable()->constrained('complementos_pago')->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('accion', 40);
            $t->json('datos');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposito_eventos');
        Schema::dropIfExists('deposito_facturas');
        Schema::table('ventas', function (Blueprint $t) {
            $t->dropConstrainedForeignId('deposito_cobro_id');
            $t->dropConstrainedForeignId('complemento_pago_id');
        });
        Schema::table('depositos_cobro', fn (Blueprint $t) => $t->dropConstrainedForeignId('complemento_id'));
        Schema::dropIfExists('complementos_pago');
        Schema::dropIfExists('depositos_cobro');
    }
};

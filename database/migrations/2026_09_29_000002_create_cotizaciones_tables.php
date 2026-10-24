<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('folio')->unique();
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('contacto_id')->nullable()->constrained('contactos')->nullOnDelete();
            $table->foreignId('vendedor_id')->constrained('users');   // "Elaboró"
            $table->date('fecha');
            $table->unsignedSmallInteger('vigencia_dias')->default(15);
            $table->string('moneda', 3)->default('MXN');
            $table->string('forma_pago')->nullable();                  // "Crédito 30 días"
            $table->string('condiciones_entrega')->nullable();         // "1 a 3 días hábiles"
            $table->text('notas')->nullable();                         // notas extra en el PDF
            $table->string('estado', 20)->default('borrador');         // App\Enums\EstadoCotizacion
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('iva', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('enviada_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['estado', 'fecha']);
        });

        Schema::create('cotizacion_partidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden');
            $table->boolean('incluir')->default(true);                 // = checkbox col. M del formato
            $table->text('descripcion');
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->string('unidad', 10)->default('PZA');
            $table->decimal('cantidad', 12, 3);
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
            $table->string('proveedor_vendedor')->nullable();          // contacto en el proveedor
            $table->decimal('costo', 14, 4)->nullable();               // SIN IVA, unitario
            $table->decimal('utilidad_pct', 7, 2)->nullable();         // precio = costo × (1 + pct/100)
            $table->decimal('precio', 14, 2);                          // SIN IVA, unitario (lo que ve el cliente)
            $table->decimal('importe', 14, 2);                         // precio × cantidad
            $table->string('tiempo_entrega')->nullable();              // "Inmediata", "3-5 días hábiles"
            $table->boolean('entrega_lenta')->default(false);          // se resalta en naranja en el PDF
            $table->string('imagen_path')->nullable();                 // disco 'public'
            $table->timestamps();
            $table->index(['cotizacion_id', 'orden']);
        });

        Schema::create('cotizacion_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->text('para');                                      // correos separados por coma
            $table->text('cc')->nullable();
            $table->string('asunto');
            $table->text('mensaje')->nullable();
            $table->boolean('exito')->default(true);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_envios');
        Schema::dropIfExists('cotizacion_partidas');
        Schema::dropIfExists('cotizaciones');
    }
};

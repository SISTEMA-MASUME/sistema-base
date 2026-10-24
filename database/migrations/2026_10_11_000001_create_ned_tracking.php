<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de cotizaciones de Ned Tracking (Pedro Ramírez), separado de MASUME: sus datos, sus clientes y
 * sus precios. No toma nada de las cotizaciones de MASUME.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ned_datos', function (Blueprint $table) {
            $table->id();
            $table->string('empresa')->default('EMPRESA DE EJEMPLO');
            $table->string('nombre')->nullable();          // quien emite (persona física)
            $table->string('rfc', 20)->nullable();
            $table->string('regimen')->nullable();
            $table->text('direccion')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('correo')->nullable();
            $table->string('web')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('condiciones')->nullable();       // notas al pie de sus cotizaciones
            $table->timestamps();
        });

        DB::table('ned_datos')->insert([
            'empresa' => 'EMPRESA DE EJEMPLO',
            'nombre' => 'NOMBRE DEL TITULAR',
            'rfc' => 'BBBB010101BB2',
            'regimen' => 'Persona Física Con Actividad Empresarial',
            'direccion' => "CALLE Y NÚMERO
COLONIA
CP CIUDAD",
            'telefono' => '3300000000',
            'correo' => 'facturas@ejemplo.com',
            'web' => 'https://www.ejemplo.com',
            'condiciones' => "Precios en pesos mexicanos más IVA.
Vigencia de la cotización según se indica.",
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::create('ned_cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('folio')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cliente_nombre');
            $table->text('cliente_direccion')->nullable();
            $table->string('cliente_contacto')->nullable();
            $table->string('cliente_correo')->nullable();
            $table->string('cliente_telefono', 30)->nullable();
            $table->date('fecha');
            $table->unsignedSmallInteger('vigencia_dias')->default(15);
            $table->text('notas')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('iva', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ned_partidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ned_cotizacion_id')->constrained('ned_cotizaciones')->cascadeOnDelete();
            $table->unsignedInteger('orden');
            $table->text('descripcion');
            $table->decimal('cantidad', 12, 3)->default(1);
            $table->string('unidad', 20)->default('PZA');
            $table->decimal('precio', 14, 2)->default(0);
            $table->decimal('importe', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ned_partidas');
        Schema::dropIfExists('ned_cotizaciones');
        Schema::dropIfExists('ned_datos');
    }
};

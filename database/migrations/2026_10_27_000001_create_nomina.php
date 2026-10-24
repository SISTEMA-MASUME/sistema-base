<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Nómina y comisiones (reemplaza la parte de nómina del DASHBOARD y la hoja NOMINA del Sheets):
 * - users: datos de empleado (sueldo base semanal, % de la comisión, si entra en nómina, fecha de ingreso para
 *   vacaciones, si trabaja sábados).
 * - nominas: una por semana (lunes a sábado) o reparto mensual al socio; borrador → pagada (queda cerrada).
 * - nomina_lineas: lo de cada persona (base, comisión, bono/descuento con nota, descuento de préstamo, total).
 * - prestamos y prestamo_abonos: préstamos a empleados que se descuentan en la nómina.
 * - ventas.nomina_id: en qué nómina se pagó la comisión de esa venta cobrada (no se paga dos veces).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('en_nomina')->default(false);
            $table->decimal('sueldo_base', 10, 2)->default(0);
            $table->decimal('comision_pct', 5, 2)->default(0);
            $table->date('fecha_ingreso')->nullable();
            $table->boolean('trabaja_sabados')->default(false);
        });

        Schema::create('nominas', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 10)->default('semanal');        // semanal | reparto
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('semana')->nullable();
            $table->unsignedTinyInteger('mes')->nullable();
            $table->date('desde');
            $table->date('hasta');
            $table->decimal('utilidad', 14, 2)->default(0);        // utilidad bruta cobrada
            $table->decimal('bolsa', 14, 2)->default(0);           // semanal: 10% (comisión total); reparto: lo del socio
            $table->decimal('gastos', 14, 2)->nullable();          // reparto: gastos MASUME del mes
            $table->string('estatus', 10)->default('borrador');    // borrador | pagada
            $table->timestamp('pagada_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origen', 10)->default('sistema');      // sistema | sheets
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->index(['tipo', 'anio', 'semana']);
        });

        Schema::create('nomina_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nomina_id')->constrained('nominas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre', 120);
            $table->decimal('sueldo_base', 10, 2)->default(0);
            $table->decimal('comision_pct', 5, 2)->default(0);
            $table->decimal('comision', 12, 2)->default(0);
            $table->boolean('comision_manual')->default(false);
            $table->decimal('ajuste', 12, 2)->default(0);          // bono (+) o descuento (−)
            $table->decimal('descuento_prestamo', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        Schema::create('prestamos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre', 120);
            $table->decimal('monto', 12, 2);
            $table->date('fecha');
            $table->decimal('descuento_semanal', 12, 2)->default(0);
            $table->text('notas')->nullable();
            $table->timestamp('saldado_at')->nullable();
            $table->timestamps();
        });

        Schema::create('prestamo_abonos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prestamo_id')->constrained('prestamos')->cascadeOnDelete();
            $table->foreignId('nomina_id')->nullable()->constrained('nominas')->cascadeOnDelete();
            $table->date('fecha');
            $table->decimal('monto', 12, 2);
            $table->string('nota', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('nomina_id')->nullable()->after('cuenta_id')->constrained('nominas')->nullOnDelete();
        });

        // Datos iniciales de empleados (vacío en la copia de ejemplo).
        $datos = [];
        foreach (DB::table('users')->whereNull('eliminado_at')->where('rol', '!=', 'nedtracking')->get(['id', 'name']) as $u) {
            $primero = strtoupper(Str::ascii(explode(' ', trim((string) $u->name))[0] ?? ''));
            if (isset($datos[$primero])) {
                [$base, $pct, $ingreso, $sabados] = $datos[$primero];
                DB::table('users')->where('id', $u->id)->update(['en_nomina' => true, 'sueldo_base' => $base, 'comision_pct' => $pct,
                    'fecha_ingreso' => $ingreso, 'trabaja_sabados' => $sabados]);
                unset($datos[$primero]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nomina_id');
        });
        Schema::dropIfExists('prestamo_abonos');
        Schema::dropIfExists('prestamos');
        Schema::dropIfExists('nomina_lineas');
        Schema::dropIfExists('nominas');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['en_nomina', 'sueldo_base', 'comision_pct', 'fecha_ingreso', 'trabaja_sabados']);
        });
    }
};

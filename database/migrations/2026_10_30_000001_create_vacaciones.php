<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Vacaciones por antigüedad:
 * - users.vacaciones_dias_anuales: días propios por año (null = según la ley; Ernesto, Ivonne y Daniel no van con la ley).
 * - vacaciones: lo tomado ('tomadas', con desde/hasta) y los ajustes del admin ('ajuste', +/− días; la fecha del ajuste va en
 *   `desde`). `evento_id` es el evento del Google Calendar de calendario@ejemplo.com.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('vacaciones_dias_anuales')->nullable();
        });

        Schema::create('vacaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 10)->default('tomadas');          // tomadas | ajuste
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();
            $table->decimal('dias', 5, 1);
            $table->text('notas')->nullable();
            $table->string('estatus', 10)->default('registrada');    // registrada | cancelada
            $table->string('evento_id')->nullable();
            $table->foreignId('registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'estatus', 'desde']);
        });

        // Saldos iniciales (vacío en la copia de ejemplo).
        $ajustes = [];
        foreach (DB::table('users')->whereNull('eliminado_at')->where('rol', '!=', 'nedtracking')->get(['id', 'name']) as $u) {
            $primero = strtoupper(Str::ascii(explode(' ', trim((string) $u->name))[0] ?? ''));
            if (isset($ajustes[$primero])) {
                [$dias, $fecha, $nota] = $ajustes[$primero];
                DB::table('vacaciones')->insert(['user_id' => $u->id, 'tipo' => 'ajuste', 'desde' => $fecha, 'dias' => $dias,
                    'notas' => $nota, 'estatus' => 'registrada', 'created_at' => now(), 'updated_at' => now()]);
                unset($ajustes[$primero]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vacaciones');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('vacaciones_dias_anuales');
        });
    }
};

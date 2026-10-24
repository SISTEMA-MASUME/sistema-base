<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se registró en el sistema un pago real de una venta (complemento de SICAR, REP, depósito o marcada a mano). Sirve a la nómina: un complemento
 * hecho tarde con la fecha real del pago (anterior al arranque de comisiones) se comisiona igual, porque se registró
 * después. Se llenan las que ya se marcaron pagadas desde el arranque, según la bitácora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->timestamp('pago_registrado_at')->nullable()->index();
        });

        // Las que ya se pagaron con un complemento/pago real desde el arranque (según la bitácora). Se excluyen las que
        // marcó cobradas la migración de años anteriores, las "saldadas" de cobranza y los cobros heredados de una nota:
        // no son pagos nuevos.
        $desde = (string) (DB::table('ajustes')->where('clave', 'comisiones_desde')->value('valor') ?: '2026-09-27');
        $cambios = DB::table('venta_cambios')->where('campo', 'estatus')->where('despues', 'pagada')
            ->where('created_at', '>=', $desde)->orderBy('created_at')->get(['venta_id', 'created_at']);
        foreach ($cambios as $c) {
            DB::table('ventas')->where('id', $c->venta_id)->where('estatus', 'pagada')->whereNull('nomina_id')
                ->where('fecha_pago', '>=', '2026-01-01')
                ->where(fn ($q) => $q->whereNull('pago_referencia')->orWhere(fn ($w) => $w
                    ->where('pago_referencia', '!=', 'Sin adeudo en SICAR (años anteriores)')
                    ->where('pago_referencia', '!=', 'Saldada en SICAR')
                    ->where('pago_referencia', 'not like', 'Cobrada como%')))
                ->update(['pago_registrado_at' => $c->created_at]);
        }
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropIndex(['pago_registrado_at']);
            $table->dropColumn('pago_registrado_at');
        });
    }
};

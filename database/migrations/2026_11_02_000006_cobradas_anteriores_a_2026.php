<?php

use App\Models\Venta;
use App\Support\CalculoNomina;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2 oct 2026, Daniel: "en SICAR no nos deben nada de otros años, solo 2026". El Sheets dejó como PENDIENTE muchas de
 * 2023–2025 (sobre todo 2024: nunca se marcó su cobro) y la carga de SICAR solo trajo 120 días. Se marcan cobradas con la
 * fecha del documento (fecha aproximada, anterior al inicio de comisiones: no entran a ninguna nómina). También las de 2026
 * anteriores al inicio de comisiones que SICAR ya reporta con saldo 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        $desde = CalculoNomina::desde();
        Venta::query()->where('estatus', 'pendiente')
            ->where(fn ($q) => $q->where('fecha', '<', '2026-01-01')
                ->orWhere(fn ($w) => $w->where('fecha', '<', $desde)->whereNotNull('saldo_sicar')->where('saldo_sicar', '<=', 0.5)))
            ->orderBy('id')
            ->each(function (Venta $v) {
                DB::transaction(function () use ($v) {
                    $fecha = $v->fecha->toDateString();
                    $v->update(['estatus' => 'pagada', 'fecha_pago' => $fecha,
                        'pago_referencia' => $v->fecha->year < 2026 ? 'Sin adeudo en SICAR (años anteriores)' : 'Saldada en SICAR']);
                    $v->anotar('estatus', 'pendiente', 'pagada', 'sicar');
                    $v->anotar('fecha_pago', null, $fecha, 'sicar');
                });
            });
    }

    public function down(): void {}
};

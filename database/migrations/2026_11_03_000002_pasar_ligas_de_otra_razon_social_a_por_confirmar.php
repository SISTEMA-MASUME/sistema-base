<?php

use App\Models\Pedido;
use App\Models\Venta;
use App\Support\RegistroErrores;
use App\Support\Trazabilidad;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las ligas automáticas (sin usuario) que valían solo por "OC larga y única" con otra razón social pasan a "por confirmar":
 * Daniel las confirma una vez y de ahí en adelante esa razón social liga sola. Las hechas a mano o por archivo no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pedidos = Pedido::whereNotNull('numero_oc_llave')->whereNull('cancelado_at')
            ->get(['id', 'folio', 'cliente_id', 'numero_oc_cliente', 'numero_oc_llave']);

        foreach (DB::table('pedido_venta')->whereNull('user_id')->where('por_confirmar', false)->orderBy('id')->get() as $liga) {
            try {
                $pedido = Pedido::find($liga->pedido_id);
                $venta = Venta::find($liga->venta_id);
                if ($pedido && $venta && Trazabilidad::estadoLigaAutomatica($pedido, $venta, $pedidos) === 'por_confirmar') {
                    DB::table('pedido_venta')->where('id', $liga->id)->update(['por_confirmar' => true]);
                }
            } catch (Throwable $e) {
                RegistroErrores::registrar($e);
            }
        }
    }

    public function down(): void {}
};

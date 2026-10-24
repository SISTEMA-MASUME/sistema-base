<?php

use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\Venta;
use App\Support\RegistroErrores;
use App\Support\Trazabilidad;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2 oct 2026: la búsqueda de OC por partes ligó facturas de Kappa a pedidos ajenos (todas sus OC terminan en
 * "/00 ZONA INDUSTRIAL"). Se revisan las ligas AUTOMÁTICAS (sin usuario) con la regla corregida y se quitan las que ya no
 * valen, junto con su aviso "ligada sola" en la tarjeta. Las ligas hechas a mano no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pedidos = Pedido::whereNotNull('numero_oc_llave')->whereNull('cancelado_at')
            ->get(['id', 'folio', 'cliente_id', 'numero_oc_cliente', 'numero_oc_llave']);

        foreach (DB::table('pedido_venta')->whereNull('user_id')->orderBy('id')->get() as $liga) {
            try {
                $pedido = Pedido::find($liga->pedido_id);
                $venta = Venta::find($liga->venta_id);
                if (! $pedido || ! $venta || Trazabilidad::ligaAutomaticaValida($pedido, $venta, $pedidos)) {
                    continue;
                }
                DB::table('pedido_venta')->where('id', $liga->id)->delete();
                $venta->anotar('pedido', 'P-'.$pedido->folio, null, 'sicar');
                PedidoActividad::where('pedido_id', $pedido->id)->where('descripcion', 'like', '%'.$venta->folio.'%ligada sola%')->delete();
            } catch (Throwable $e) {
                RegistroErrores::registrar($e);
            }
        }
    }

    public function down(): void {}
};

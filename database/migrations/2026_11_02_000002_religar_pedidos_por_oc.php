<?php

use App\Models\Pedido;
use App\Models\Venta;
use App\Support\RegistroErrores;
use App\Support\Trazabilidad;
use Illuminate\Database\Migrations\Migration;

/**
 * Una sola vez: vuelve a buscar la OC en los comentarios de SICAR ya recibidos con la regla mejorada (OC con texto largo y
 * facturas a otra razón social). Ligar es idempotente: lo ya ligado no se duplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pedidos = null;
        Venta::whereNotNull('comentario_sicar')->where('estatus', '!=', 'cancelada')->orderBy('id')
            ->each(function (Venta $v) use (&$pedidos) {
                try {
                    $pedidos ??= (fn () => Pedido::whereNotNull('numero_oc_llave')->whereNull('cancelado_at')
                        ->get(['id', 'folio', 'cliente_id', 'numero_oc_cliente', 'numero_oc_llave']))();
                    Trazabilidad::ligarPedidosPorOc($v, $pedidos);
                } catch (Throwable $e) {
                    RegistroErrores::registrar($e);
                }
            });
    }

    public function down(): void {}
};

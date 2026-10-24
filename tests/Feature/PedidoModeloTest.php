<?php

namespace Tests\Feature;

use App\Enums\ColumnaPedido;
use App\Models\Cliente;
use App\Models\CotizacionPartida;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PedidoModeloTest extends TestCase
{
    use RefreshDatabase;

    public function test_misma_oc_del_mismo_cliente_no_se_duplica_aunque_se_escriba_distinto(): void
    {
        $cliente = Cliente::factory()->create();
        $p = Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '617489']);
        $this->assertSame(ColumnaPedido::OcRecibida, $p->fresh()->columna);

        // "OC 45" y "OC 4567" son distintas (el bug del match parcial de Trello)
        Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '45']);
        Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '4567']);

        $this->expectException(QueryException::class);
        Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => 'OC 617489']);
    }

    public function test_etiquetas_automaticas_segun_partidas(): void
    {
        $pedido = Pedido::factory()->create();
        $origen = CotizacionPartida::factory()->create();
        $a = PedidoPartida::desdeCotizacion($origen, $pedido, 1);
        $b = PedidoPartida::desdeCotizacion($origen, $pedido, 2, 3);
        $this->assertEquals(3, (float) $b->cantidad);
        $this->assertSame([], $pedido->fresh()->etiquetas());

        $a->update(['estado' => 'solicitada']);
        $this->assertContains('Solicitado parcial', array_column($pedido->fresh()->etiquetas(), 'texto'));

        $b->update(['estado' => 'recibida', 'facturada' => true]);
        $textos = array_column($pedido->fresh()->etiquetas(), 'texto');
        $this->assertContains('Material solicitado', $textos);
        $this->assertContains('Factura parcial', $textos);

        $pedido->recalcularTotales();
        $this->assertEquals(round($origen->precio * $origen->cantidad + $origen->precio * 3, 2), (float) $pedido->fresh()->total_venta);
    }
}

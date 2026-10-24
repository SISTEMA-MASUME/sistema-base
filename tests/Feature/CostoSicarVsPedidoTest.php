<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Ventas;
use App\Livewire\Pedidos\Expediente;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\User;
use App\Models\Venta;
use App\Support\CostoSicarVsPedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Daniel baja el costo en SICAR al timbrar: ve la diferencia contra el pedido; los vendedores no (24-oct-2026). */
class CostoSicarVsPedidoTest extends TestCase
{
    use RefreshDatabase;

    private function venta(string $folio, float $costo, array $extra = []): Venta
    {
        return Venta::create($extra + ['tipo' => 'factura', 'folio' => $folio, 'cliente_nombre' => 'ACME MEXICO', 'fecha' => now()->toDateString(),
            'costo' => $costo, 'subtotal' => 5000, 'iva' => 800, 'total' => 5800, 'estatus' => 'pendiente', 'origen' => 'sicar']);
    }

    private function partida(Pedido $p, float $costo, int $cantidad, bool $facturada): PedidoPartida
    {
        return PedidoPartida::factory()->create(['pedido_id' => $p->id, 'costo' => $costo, 'cantidad' => $cantidad, 'precio' => $costo * 1.25, 'facturada' => $facturada]);
    }

    public function test_factura_parcial_compara_solo_lo_facturado_y_solo_lo_ve_el_admin(): void
    {
        $pedido = Pedido::factory()->create(['folio' => 176]);
        $this->partida($pedido, 99.52, 4, true);      // gabinete slim
        $this->partida($pedido, 548.62, 5, true);     // luminaria
        $this->partida($pedido, 665.45, 2, false);    // aún sin facturar: no cuenta
        $f = $this->venta('5-5221', 3000.00);
        $pedido->ventas()->attach($f->id);
        $this->venta('5-9999', 10, ['estatus' => 'cancelada']);

        $c = CostoSicarVsPedido::dePedido($pedido);
        $this->assertEquals(3141.18, $c['pedido']);
        $this->assertEquals(3000.00, $c['sicar']);
        $this->assertEquals(-141.18, $c['diferencia']);
        $this->assertSame(['5-5221'], $c['facturas']);
        $this->assertSame([2, 3], [$c['partidas'], $c['partidas_total']]);

        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->assertSeeHtml('data-costo-sicar')->assertSee('Costo SICAR vs pedido')->assertSee('$141.18')->assertSee('a favor')->assertSee('(2 de 3)');
        Livewire::test(Ventas::class)->call('verDetalle', $f->id)->assertSeeHtml('data-costo-sicar')->assertSee('$3,141.18');

        // Vendedor (aun con permiso de ventas) y campo no lo ven.
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => ['ventas']]));
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->assertSee('5-5221')->assertDontSeeHtml('data-costo-sicar');
        Livewire::test(Ventas::class)->call('verDetalle', $f->id)->assertSee('P-176')->assertDontSeeHtml('data-costo-sicar');
        $this->actingAs(User::factory()->create(['rol' => 'campo']));
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->assertDontSeeHtml('data-costo-sicar');
    }

    public function test_una_factura_con_dos_pedidos_y_costo_mayor_en_sicar(): void
    {
        $a = Pedido::factory()->create(['folio' => 10]);
        $b = Pedido::factory()->create(['folio' => 11]);
        $this->partida($a, 100, 1, false);   // sin ninguna marcada: cuenta todo el pedido
        $this->partida($b, 50, 2, false);
        $f = $this->venta('5-100', 250);
        $f->pedidos()->attach([$a->id, $b->id]);

        $c = CostoSicarVsPedido::dePedido($a);
        $this->assertEquals(200, $c['pedido']);
        $this->assertEquals(50, $c['diferencia']);
        $this->assertSame(['P-10', 'P-11'], $c['pedidos']);
        $this->assertFalse($c['igual']);

        $this->assertNull(CostoSicarVsPedido::dePedido(Pedido::factory()->create()));
    }
}

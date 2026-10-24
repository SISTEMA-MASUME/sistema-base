<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Ventas;
use App\Livewire\Pedidos\Expediente;
use App\Livewire\Pedidos\Ver;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaVinculo;
use App\Support\Trazabilidad;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Pantallas de la trazabilidad: ligar en lote y detalle en Ventas, y "Facturas y notas" en la tarjeta del pedido. */
class TrazabilidadPantallasTest extends TestCase
{
    use RefreshDatabase;

    private function vendedorVentas(): User
    {
        return User::factory()->create(['rol' => 'vendedor', 'permisos' => ['ventas']]);
    }

    private function venta(array $d): Venta
    {
        return Venta::create($d + ['cliente_nombre' => 'PROVEX', 'fecha' => now()->year.'-03-01', 'costo' => 50, 'subtotal' => 100,
            'iva' => 16, 'total' => 116, 'estatus' => 'pendiente', 'origen' => 'manual']);
    }

    public function test_un_vendedor_con_permiso_liga_notas_a_una_factura_en_lote(): void
    {
        $this->actingAs($this->vendedorVentas());
        $n1 = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-1', 'estatus' => 'pagada', 'fecha_pago' => now()->year.'-03-05']);
        $n2 = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-2', 'estatus' => 'pagada', 'fecha_pago' => now()->year.'-03-08']);
        $f = $this->venta(['tipo' => 'factura', 'folio' => '5-100']);

        Livewire::test(Ventas::class)
            ->set('seleccion', [$n1->id, $n2->id])
            ->call('abrirLote', 'ligar')
            ->assertSet('panelLote', 'ligar')
            ->assertSee('5-100')   // sugerida (mismo cliente)
            ->set('folioDestino', '5-100')
            ->call('ligarSeleccion')
            ->assertSet('panelLote', '')
            ->assertSet('seleccion', [])
            ->assertSee('2 documentos quedaron ligados a 5-100')
            ->assertSee('La factura tomó el cobro del 08/03/'.now()->year);

        $this->assertSame('cancelada', $n1->fresh()->estatus);
        $this->assertSame('pagada', $f->fresh()->estatus);
        $this->assertSame(2, $f->reemplaza()->count());
        $this->assertSame(auth()->id(), VentaVinculo::first()->user_id);
    }

    public function test_ligar_a_una_nota_o_a_un_folio_inexistente_avisa_sin_cambiar_nada(): void
    {
        $this->actingAs($this->vendedorVentas());
        $n1 = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-1']);
        $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-2']);

        Livewire::test(Ventas::class)
            ->set('seleccion', [$n1->id])
            ->call('abrirLote', 'ligar')
            ->set('folioDestino', 'NOTA-2')
            ->call('ligarSeleccion')
            ->assertSet('panelLote', 'ligar')
            ->assertSet('error', 'Solo se puede ligar a una factura vigente (no cancelada).')
            ->set('folioDestino', '9-999')
            ->call('ligarSeleccion')
            ->assertHasErrors(['folioDestino']);

        $this->assertSame('pendiente', $n1->fresh()->estatus);
        $this->assertSame(0, VentaVinculo::count());
    }

    public function test_sin_permiso_de_ventas_no_se_puede_ligar(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => []]));
        Livewire::test(Ventas::class)->assertForbidden();
    }

    public function test_deshacer_desde_el_detalle_regresa_la_nota(): void
    {
        $this->actingAs($this->vendedorVentas());
        $nota = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-1']);
        $f = $this->venta(['tipo' => 'factura', 'folio' => '5-100']);
        Trazabilidad::ligar(collect([$nota]), $f);
        $vinculo = VentaVinculo::sole();

        Livewire::test(Ventas::class)
            ->call('verDetalle', $f->id)
            ->assertSee('Trazabilidad')
            ->assertSee('Reemplaza a')
            ->assertSee('NOTA-1')
            ->assertSee('Antes estaba pendiente')
            ->assertSee('Liga')   // bitácora: "Reemplaza a NOTA-1"
            ->call('deshacerLiga', $vinculo->id)
            ->assertSee('Se deshizo la liga');

        $this->assertSame('pendiente', $nota->fresh()->estatus);
        $this->assertNull($nota->fresh()->factura_sustituta);
        $this->assertSame(0, VentaVinculo::count());
    }

    public function test_no_se_deshace_una_liga_de_otra_venta(): void
    {
        $this->actingAs($this->vendedorVentas());
        $n = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-1']);
        $f = $this->venta(['tipo' => 'factura', 'folio' => '5-100']);
        $otra = $this->venta(['tipo' => 'factura', 'folio' => '5-200']);
        Trazabilidad::ligar(collect([$n]), $f);

        $this->expectException(ModelNotFoundException::class);
        try {
            Livewire::test(Ventas::class)->call('verDetalle', $otra->id)->call('deshacerLiga', VentaVinculo::sole()->id);
        } finally {
            $this->assertSame(1, VentaVinculo::count());
        }
    }

    public function test_ligar_y_quitar_pedido_desde_el_detalle_y_avisa_si_es_de_otro_cliente(): void
    {
        $this->actingAs($this->vendedorVentas());
        $pedido = Pedido::factory()->create();
        $f = $this->venta(['tipo' => 'factura', 'folio' => '5-100']);

        Livewire::test(Ventas::class)
            ->call('verDetalle', $f->id)
            ->set('folioPedido', 'P-'.$pedido->folio)
            ->call('ligarAPedido')
            ->assertSee('Quedó ligada al pedido P-'.$pedido->folio)
            ->assertSee('Ojo: el pedido es de otro cliente')
            ->call('quitarPedido', $pedido->id)
            ->assertSee('Se quitó el pedido');
        $this->assertSame(0, $pedido->ventas()->count());

        Livewire::test(Ventas::class)->call('verDetalle', $f->id)->set('folioPedido', '9999')->call('ligarAPedido')->assertHasErrors(['folioPedido']);
        $this->assertSame(2, PedidoActividad::where('pedido_id', $pedido->id)->where('tipo', 'factura_ligada')->count());
    }

    public function test_filtro_notas_sin_facturar(): void
    {
        $this->actingAs($this->vendedorVentas());
        $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-10']);
        $facturada = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-11']);
        $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-12', 'estatus' => 'cancelada']);
        $this->venta(['tipo' => 'factura', 'folio' => '5-100']);
        Trazabilidad::ligar(collect([$facturada]), Venta::where('folio', '5-100')->first());

        Livewire::test(Ventas::class)->set('tipo', Ventas::NOTAS_SIN_FACTURAR)
            ->assertSee('NOTA-10')
            ->assertDontSee('NOTA-11')
            ->assertDontSee('NOTA-12')
            ->assertDontSee('5-100');
    }

    public function test_insignias_de_trazabilidad_en_la_lista(): void
    {
        $this->actingAs($this->vendedorVentas());
        $nota = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-1']);
        $vieja = $this->venta(['tipo' => 'factura', 'folio' => '5-1']);
        $f = $this->venta(['tipo' => 'factura', 'folio' => '5-100']);
        Trazabilidad::ligar(collect([$nota, $vieja]), $f);
        $pedido = Pedido::factory()->create();
        Trazabilidad::ligarPedido($pedido, $f);

        Livewire::test(Ventas::class)
            ->assertSee('Facturada en 5-100')
            ->assertSee('Sustituida por 5-100')
            ->assertSee('Reemplaza 2 docs')
            ->assertSee('P-'.$pedido->folio);
    }

    public function test_el_pedido_muestra_y_liga_facturas_por_folio_solo_con_permiso_de_ventas(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        $this->actingAs($admin);
        $pedido = Pedido::factory()->create();
        $factura = $this->venta(['tipo' => 'factura', 'folio' => '5-100']);
        $nota = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-298']);

        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->assertSee('Facturas y notas')
            ->set('folioVenta', '5-100')->call('ligarVenta')
            ->set('folioVenta', 'nota 298')->call('ligarVenta')
            ->assertSee('NOTA-298')
            ->set('folioVenta', 'NOPE-1')->call('ligarVenta')
            ->assertSee('No encontré ese folio en Ventas')
            ->call('quitarVenta', $nota->id)
            ->assertSee('Se quitó la nota NOTA-298');

        $this->assertSame([$factura->id], $pedido->ventas()->pluck('ventas.id')->all());
        $this->assertSame(3, PedidoActividad::where('pedido_id', $pedido->id)->where('tipo', 'factura_ligada')->count());
    }

    public function test_campo_y_vendedor_sin_permiso_no_ven_ni_usan_la_seccion_del_pedido(): void
    {
        $pedido = Pedido::factory()->create();
        $factura = $this->venta(['tipo' => 'factura', 'folio' => '5-100']);
        Trazabilidad::ligarPedido($pedido, $factura);

        foreach ([User::factory()->create(['rol' => 'campo']), User::factory()->create(['rol' => 'vendedor', 'permisos' => []]),
            User::factory()->create(['rol' => 'campo', 'permisos' => ['ventas']])] as $usuario) {
            $this->actingAs($usuario);
            Livewire::test(Ver::class, ['pedido' => $pedido])->assertDontSee('5-100');
            Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
                ->assertDontSee('5-100')
                ->assertDontSee('Ligar una nota o factura')
                ->set('folioVenta', '5-100')->call('ligarVenta')->assertForbidden();
            Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->call('quitarVenta', $factura->id)->assertForbidden();
        }
        $this->assertSame(1, $pedido->ventas()->count());
    }
}

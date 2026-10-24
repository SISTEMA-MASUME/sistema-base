<?php

namespace Tests\Feature;

use App\Enums\EstadoOrdenProveedor;
use App\Enums\EstadoPartidaPedido;
use App\Livewire\Pedidos\Ver;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PartidasPedidoEditarTest extends TestCase
{
    use RefreshDatabase;

    private function pedidoConPartida(array $partida = []): array
    {
        $pedido = Pedido::factory()->create();
        $p = PedidoPartida::factory()->create(array_merge(['pedido_id' => $pedido->id, 'descripcion' => 'LLAVE PLASTICO', 'cantidad' => 5, 'costo' => 10, 'precio' => 20], $partida));
        $pedido->recalcularTotales();

        return [$pedido, $p];
    }

    private function conOc(PedidoPartida $p, EstadoOrdenProveedor $estado): OrdenProveedor
    {
        $prov = Proveedor::factory()->create();
        $oc = OrdenProveedor::create(['folio' => 7, 'pedido_id' => $p->pedido_id, 'proveedor_id' => $prov->id, 'user_id' => User::factory()->create()->id, 'metodo_pago' => 'efectivo', 'estado' => $estado, 'total_costo' => 50]);
        $p->update(['orden_proveedor_id' => $oc->id, 'proveedor_id' => $prov->id]);

        return $oc;
    }

    public function test_editar_cambia_datos_recalcula_y_anota(): void
    {
        [$pedido, $p] = $this->pedidoConPartida();
        $prov = Proveedor::factory()->create(['nombre' => 'FERRETODO']);
        $this->actingAs(User::factory()->create());

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->call('editarPartida', $p->id)
            ->assertSet('e_cantidad', '5')
            ->set('e_cantidad', '6')->set('e_costo', '12')->set('e_proveedor', 'ferretodo')
            ->call('guardarPartida')
            ->assertHasNoErrors()
            ->assertSet('editandoId', null);

        $p->refresh();
        $this->assertEquals(6, (float) $p->cantidad);
        $this->assertSame($prov->id, $p->proveedor_id);
        $pedido->refresh();
        $this->assertEquals(120.0, (float) $pedido->total_venta);
        $this->assertEquals(72.0, (float) $pedido->total_costo);
        $this->assertTrue(PedidoActividad::where('pedido_id', $pedido->id)->where('descripcion', 'like', '%cambió la cantidad de "LLAVE PLASTICO" de 5 a 6%')->exists());
    }

    public function test_proveedor_inexistente_no_se_crea(): void
    {
        [$pedido, $p] = $this->pedidoConPartida();
        $this->actingAs(User::factory()->create());

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->call('editarPartida', $p->id)->set('e_proveedor', 'NO EXISTE SA')->call('guardarPartida')
            ->assertHasErrors('e_proveedor');
        $this->assertSame(0, Proveedor::where('nombre', 'NO EXISTE SA')->count());
    }

    public function test_editar_partida_con_oc_enviada_marca_reenvio(): void
    {
        [$pedido, $p] = $this->pedidoConPartida();
        $oc = $this->conOc($p, EstadoOrdenProveedor::Enviada);
        $this->actingAs(User::factory()->create());

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->call('editarPartida', $p->id)->set('e_cantidad', '8')->call('guardarPartida')
            ->assertSee('OC-0007 hay que reenviarla al proveedor');

        $oc->refresh();
        $this->assertTrue($oc->requiere_reenvio);
        $this->assertEquals(80.0, (float) $oc->total_costo);
    }

    public function test_con_oc_no_se_cambia_el_proveedor(): void
    {
        [$pedido, $p] = $this->pedidoConPartida();
        $this->conOc($p, EstadoOrdenProveedor::Enviada);
        $otro = Proveedor::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->call('editarPartida', $p->id)->set('e_proveedor', $otro->nombre)->call('guardarPartida')
            ->assertHasErrors('e_proveedor');
    }

    public function test_quitar_partida_tambien_con_oc_y_recogida_solo_admin(): void
    {
        [$pedido, $libre] = $this->pedidoConPartida();
        $conOc = PedidoPartida::factory()->create(['pedido_id' => $pedido->id, 'orden' => 2, 'cantidad' => 2, 'costo' => 10]);
        $oc = $this->conOc($conOc, EstadoOrdenProveedor::Enviada);
        $otra = PedidoPartida::factory()->create(['pedido_id' => $pedido->id, 'orden' => 3, 'cantidad' => 1, 'costo' => 30, 'orden_proveedor_id' => $oc->id]);
        $recogida = PedidoPartida::factory()->create(['pedido_id' => $pedido->id, 'orden' => 4, 'estado' => EstadoPartidaPedido::Recibida]);
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));

        $c = Livewire::test(Ver::class, ['pedido' => $pedido]);
        // Con OC ya enviada: se quita del pedido y de la OC, que se recalcula y pide reenviar.
        $c->call('quitarPartida', $conOc->id)->assertSee('Partida quitada')->assertSee('ya se había enviado');
        $this->assertDatabaseMissing('pedido_partidas', ['id' => $conOc->id]);
        $oc->refresh();
        $this->assertEquals(30, (float) $oc->total_costo);
        $this->assertTrue((bool) $oc->requiere_reenvio);
        // La última partida de la OC: la OC se cancela.
        $c->call('quitarPartida', $otra->id)->assertSee('se quedó sin partidas y se canceló');
        $this->assertSame(EstadoOrdenProveedor::Cancelada, $oc->fresh()->estado);

        // Recogida: el vendedor no; el admin sí.
        $c->call('quitarPartida', $recogida->id)->assertSee('Solo el administrador');
        $this->assertDatabaseHas('pedido_partidas', ['id' => $recogida->id]);

        $c->call('quitarPartida', $libre->id)->assertSee('Partida quitada');
        $this->assertDatabaseMissing('pedido_partidas', ['id' => $libre->id]);
        $this->assertTrue(PedidoActividad::where('pedido_id', $pedido->id)->where('descripcion', 'like', 'quitó la partida "LLAVE PLASTICO"')->exists());

        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        Livewire::test(Ver::class, ['pedido' => $pedido])->call('quitarPartida', $recogida->id)->assertSee('Partida quitada');
        $this->assertSame(0, $pedido->partidas()->count());
    }

    public function test_agregar_de_cotizacion_con_trazabilidad_y_sin_duplicar(): void
    {
        [$pedido] = $this->pedidoConPartida();
        $prov = Proveedor::factory()->create();
        $cot = Cotizacion::factory()->create(['cliente_id' => $pedido->cliente_id]);
        $cp = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'descripcion' => 'TORNILLO HEX', 'cantidad' => 4, 'costo' => 3, 'precio' => 9, 'proveedor_id' => $prov->id]);
        $ajena = Cotizacion::factory()->create();
        CotizacionPartida::factory()->create(['cotizacion_id' => $ajena->id, 'descripcion' => 'TORNILLO AJENO']);
        $this->actingAs(User::factory()->create());

        $c = Livewire::test(Ver::class, ['pedido' => $pedido])
            ->call('abrirBuscadorCotizacion')
            ->set('cotBusca', 'tornillo')
            ->assertSee('TORNILLO HEX')->assertDontSee('TORNILLO AJENO')
            ->set('cotTodas', true)->assertSee('TORNILLO AJENO')->set('cotTodas', false)
            ->set('cotSel', [(string) $cp->id])->set('cotCant', [$cp->id => '7'])
            ->call('agregarDeCotizacion')->assertHasNoErrors();

        $nueva = $pedido->partidas()->where('cotizacion_partida_id', $cp->id)->first();
        $this->assertNotNull($nueva);
        $this->assertEquals(7, (float) $nueva->cantidad);
        $this->assertEquals(3.0, (float) $nueva->costo);
        $this->assertSame($prov->id, $nueva->proveedor_id);
        $this->assertEquals(2, $nueva->orden);
        $this->assertEquals(63.0 + 100.0, (float) $pedido->fresh()->total_venta);

        // Ya está en el pedido: se avisa y no se vuelve a crear.
        $c->call('abrirBuscadorCotizacion')->set('cotBusca', (string) $cot->folio)->assertSee('Ya está en este pedido')
            ->set('cotSel', [(string) $cp->id])->call('agregarDeCotizacion');
        $this->assertSame(1, $pedido->partidas()->where('cotizacion_partida_id', $cp->id)->count());
    }

    public function test_agregar_manual_con_proveedor(): void
    {
        [$pedido] = $this->pedidoConPartida();
        $prov = Proveedor::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->set('agregando', true)
            ->set('n_descripcion', 'cinta')->set('n_cantidad', '2')->set('n_precio', '15')->set('n_costo', '8')->set('n_proveedor', $prov->nombre)
            ->call('agregarPartida')->assertHasNoErrors();

        $this->assertSame($prov->id, $pedido->partidas()->where('descripcion', 'CINTA')->value('proveedor_id'));
    }

    public function test_campo_no_edita_quita_ni_agrega_y_no_ve_precios(): void
    {
        [$pedido, $p] = $this->pedidoConPartida();
        $this->actingAs(User::factory()->create(['rol' => 'campo']));

        $c = Livewire::test(Ver::class, ['pedido' => $pedido]);
        $c->assertDontSee('Agregar de cotización')->assertDontSee('Agregar manual')->assertDontSee('editarPartida', false)->assertDontSee('quitarPartida', false);
        $c->call('editarPartida', $p->id)->assertForbidden();
        Livewire::test(Ver::class, ['pedido' => $pedido])->call('quitarPartida', $p->id)->assertForbidden();
        Livewire::test(Ver::class, ['pedido' => $pedido])->call('abrirBuscadorCotizacion')->assertForbidden();
        Livewire::test(Ver::class, ['pedido' => $pedido])->call('agregarDeCotizacion')->assertForbidden();
        $this->assertDatabaseHas('pedido_partidas', ['id' => $p->id, 'cantidad' => 5]);
    }
}

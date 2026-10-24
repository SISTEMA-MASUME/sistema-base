<?php

namespace Tests\Feature;

use App\Enums\EstadoPartidaPedido;
use App\Livewire\OrdenesProveedor\Ver as VerOrden;
use App\Livewire\Pedidos\PorRecoger;
use App\Livewire\Pedidos\Ver;
use App\Livewire\Tablero\Index as Tablero;
use App\Models\Cliente;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\PorRecoger as Datos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** El proveedor entrega incompleto (10 guantes y solo da 5): se anota lo recogido y queda a la vista lo que falta. */
class RecogidasParcialesTest extends TestCase
{
    use RefreshDatabase;

    private Proveedor $urrea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->urrea = Proveedor::factory()->create(['nombre' => 'URREA']);
    }

    /** Pedido con 10 cajas de guante (pedidas al proveedor) y 4 jaladores. */
    private function pedido(array $datos = []): Pedido
    {
        $pedido = Pedido::factory()->create(['cliente_id' => Cliente::factory()->create(['nombre' => 'HOTEL CENTRAL'])->id] + $datos);
        PedidoPartida::create(['pedido_id' => $pedido->id, 'orden' => 1, 'descripcion' => 'GUANTE NITRILO NEGRO', 'unidad' => 'CAJA', 'cantidad' => 10,
            'costo' => 185, 'precio' => 250, 'estado' => 'solicitada', 'proveedor_id' => $this->urrea->id]);
        PedidoPartida::create(['pedido_id' => $pedido->id, 'orden' => 2, 'descripcion' => 'JALADOR DE PISO', 'unidad' => 'PZA', 'cantidad' => 4,
            'costo' => 90, 'precio' => 130, 'estado' => 'solicitada', 'proveedor_id' => $this->urrea->id]);

        return $pedido->fresh();
    }

    private function guante(Pedido $p): PedidoPartida
    {
        return $p->partidas()->where('orden', 1)->firstOrFail();
    }

    public function test_hugo_recoge_5_de_10_y_despues_lo_que_faltaba(): void
    {
        $pedido = $this->pedido();
        $g = $this->guante($pedido);
        $hugo = User::factory()->create(['rol' => 'campo', 'name' => 'Hugo']);

        // Al tocar el estado se pregunta cuántas.
        $t = Livewire::actingAs($hugo)->test(Ver::class, ['pedido' => $pedido])
            ->assertSee('abrirRecoger('.$g->id.')', false)
            ->call('abrirRecoger', $g->id)->assertSet('recogiendoIds', [$g->id])->assertSee('¿Cuántas recogiste?')
            ->call('registrarRecogida', $g->id, '5')->assertSet('recogiendoIds', [])
            ->assertSee('faltan 5 caja por recoger', false)
            ->assertSee('Recogidas 5 de 10')->assertSee('Falta recoger')->assertSee('Recogida parcial');

        $g->refresh();
        $this->assertSame(EstadoPartidaPedido::Solicitada, $g->estado);
        $this->assertEquals(5, (float) $g->cantidad_recogida);
        $this->assertTrue($g->recogidaParcial());
        $this->assertEquals(5, $g->faltaRecoger());
        $this->assertTrue(PedidoActividad::where('descripcion', 'like', 'recogió 5 caja de "GUANTE NITRILO NEGRO" (lleva 5 de 10; faltan 5 caja)')->exists());
        $this->assertContains('Falta recoger', array_column($pedido->fresh()->etiquetas(), 'texto'));
        // Campo nunca ve precio de venta aunque registre recogidas.
        $t->assertDontSee('250.00');

        // Después llegan las otras 5: "Recogí lo que faltaba".
        $t->call('registrarRecogida', $g->id, 5)->assertSee('quedó recogida completa');
        $g->refresh();
        $this->assertSame(EstadoPartidaPedido::Recibida, $g->estado);
        $this->assertEquals(10, (float) $g->cantidad_recogida);
        $this->assertFalse($g->recogidaParcial());
        $this->assertNotContains('Falta recoger', array_column($pedido->fresh()->etiquetas(), 'texto'));
    }

    public function test_varias_partes_tope_y_cantidades_invalidas(): void
    {
        $pedido = $this->pedido();
        $g = $this->guante($pedido);
        $t = Livewire::actingAs(User::factory()->create(['rol' => 'vendedor']))->test(Ver::class, ['pedido' => $pedido]);

        $t->call('registrarRecogida', $g->id, '0')->assertHasErrors('recoger');
        $t->call('registrarRecogida', $g->id, 'abc')->assertHasErrors('recoger');
        $this->assertNull($g->fresh()->cantidad_recogida);

        $t->call('registrarRecogida', $g->id, '3')->call('registrarRecogida', $g->id, '2.5');
        $this->assertEquals(5.5, (float) $g->fresh()->cantidad_recogida);
        // Más de lo que falta: se completa (no pasa de lo pedido).
        $t->call('registrarRecogida', $g->id, '99');
        $this->assertEquals(10, (float) $g->fresh()->cantidad_recogida);
        $this->assertSame(EstadoPartidaPedido::Recibida, $g->fresh()->estado);
        $t->call('registrarRecogida', $g->id, '1')->assertSee('ya estaba recogida completa');
    }

    public function test_poner_el_estado_a_mano_mantiene_la_cantidad_coherente(): void
    {
        $pedido = $this->pedido();
        $g = $this->guante($pedido);
        $t = Livewire::actingAs(User::factory()->create(['rol' => 'admin']))->test(Ver::class, ['pedido' => $pedido]);

        $t->call('registrarRecogida', $g->id, 4);
        // "Pedida al proveedor" a mano = se empieza de nuevo.
        $t->call('establecerEstado', $g->id, 'pendiente')->call('establecerEstado', $g->id, 'solicitada');
        $this->assertNull($g->fresh()->cantidad_recogida);
        // "Recogida" a mano = todo.
        $t->call('establecerEstado', $g->id, 'recibida');
        $this->assertEquals(10, (float) $g->fresh()->cantidad_recogida);
        // Cambio masivo también.
        $j = $pedido->partidas()->where('orden', 2)->first();
        $t->set('seleccion', [(string) $j->id])->call('accionMasiva', 'recibida')->assertSet('recogiendoIds', [$j->id])
            ->call('registrarRecogidas', [$j->id => '4']);
        $this->assertEquals(4, (float) $j->fresh()->cantidad_recogida);
        // Una sola pieza no pregunta cuántas.
        $uno = PedidoPartida::create(['pedido_id' => $pedido->id, 'orden' => 3, 'descripcion' => 'CUBETA', 'unidad' => 'PZA', 'cantidad' => 1,
            'costo' => 10, 'precio' => 20, 'estado' => 'solicitada']);
        Livewire::test(Ver::class, ['pedido' => $pedido->fresh()])->assertSee('siguienteEstado('.$uno->id.')', false);
    }

    public function test_oc_al_proveedor_recibida_cuenta_todo_recogido(): void
    {
        $pedido = $this->pedido();
        $orden = OrdenProveedor::create(['pedido_id' => $pedido->id, 'proveedor_id' => $this->urrea->id, 'folio' => 61, 'estado' => 'enviada',
            'total_costo' => 2210, 'metodo_pago' => 'TRANSFERENCIA', 'user_id' => User::factory()->create(['rol' => 'admin'])->id]);
        $pedido->partidas()->update(['orden_proveedor_id' => $orden->id]);
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));

        Livewire::test(VerOrden::class, ['orden' => $orden])->call('marcarRecibida');
        $this->assertEquals([10.0, 4.0], $pedido->partidas()->orderBy('orden')->pluck('cantidad_recogida')->map(fn ($c) => (float) $c)->all());
    }

    public function test_lista_por_recoger_agrupada_por_proveedor(): void
    {
        $pedido = $this->pedido();
        $this->guante($pedido)->update(['cantidad_recogida' => 5]);
        $milwaukee = Proveedor::factory()->create(['nombre' => 'MILWAUKEE']);
        $otro = Pedido::factory()->create();
        PedidoPartida::create(['pedido_id' => $otro->id, 'orden' => 1, 'descripcion' => 'BROCA SDS', 'unidad' => 'PZA', 'cantidad' => 10,
            'costo' => 50, 'precio' => 80, 'estado' => 'solicitada', 'proveedor_id' => $milwaukee->id]);
        // No salen: ya recogidas, pendientes de pedir, cancelados ni archivados.
        PedidoPartida::create(['pedido_id' => $otro->id, 'orden' => 2, 'descripcion' => 'YA RECOGIDA', 'unidad' => 'PZA', 'cantidad' => 1, 'costo' => 1, 'precio' => 2, 'estado' => 'recibida']);
        PedidoPartida::create(['pedido_id' => $otro->id, 'orden' => 3, 'descripcion' => 'SIN PEDIR', 'unidad' => 'PZA', 'cantidad' => 1, 'costo' => 1, 'precio' => 2, 'estado' => 'pendiente']);
        $cancelado = Pedido::factory()->create(['cancelado_at' => now()]);
        PedidoPartida::create(['pedido_id' => $cancelado->id, 'orden' => 1, 'descripcion' => 'DE CANCELADO', 'unidad' => 'PZA', 'cantidad' => 1, 'costo' => 1, 'precio' => 2, 'estado' => 'solicitada']);

        $this->assertSame(1, Datos::parciales());
        $grupos = Datos::porProveedor();
        $this->assertSame(['URREA', 'MILWAUKEE'], $grupos->pluck('proveedor')->all());   // primero el que tiene algo a medias
        $this->assertSame(['GUANTE NITRILO NEGRO', 'JALADOR DE PISO'], $grupos[0]['partidas']->pluck('descripcion')->all());

        $hugo = User::factory()->create(['rol' => 'campo']);
        $this->actingAs($hugo)->get('/por-recoger')->assertOk()
            ->assertSee('Faltan 5 CAJA · ya se recogieron 5 de 10')->assertSee('BROCA SDS')
            ->assertDontSee('YA RECOGIDA')->assertDontSee('SIN PEDIR')->assertDontSee('DE CANCELADO')
            ->assertSee('Por recoger');
        Livewire::actingAs($hugo)->test(PorRecoger::class)->set('soloParciales', true)
            ->assertSee('GUANTE NITRILO NEGRO')->assertDontSee('BROCA SDS')
            ->set('soloParciales', false)->set('buscar', 'milwau')->assertSee('BROCA SDS')->assertDontSee('GUANTE NITRILO');

        // Pedro (Ned) no entra.
        $this->actingAs(User::factory()->create(['rol' => 'nedtracking']))->get('/por-recoger')->assertRedirect();
    }

    public function test_el_tablero_muestra_lo_que_falta_recoger(): void
    {
        $pedido = $this->pedido();
        $this->guante($pedido)->update(['cantidad_recogida' => 5]);

        Livewire::actingAs(User::factory()->create(['rol' => 'campo']))->test(Tablero::class)
            ->assertSee('Falta recoger 5 CAJA')->assertSee('/por-recoger', false);
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']))->get('/pedidos')
            ->assertSeeInOrder(['Por recoger', '>1<'], false);
    }

    public function test_marcar_recogida_con_casillas_pregunta_cuantas_igual_que_el_estado(): void
    {
        $pedido = $this->pedido();
        $g = $this->guante($pedido);
        $j = $pedido->partidas()->where('orden', 2)->first();
        $uno = PedidoPartida::create(['pedido_id' => $pedido->id, 'orden' => 3, 'descripcion' => 'CUBETA', 'unidad' => 'PZA', 'cantidad' => 1,
            'costo' => 10, 'precio' => 20, 'estado' => 'solicitada']);
        $t = Livewire::actingAs(User::factory()->create(['rol' => 'campo']))->test(Ver::class, ['pedido' => $pedido]);

        // Varias marcadas: sale la misma ventanita con cada una (las completas no aparecen).
        $j->update(['estado' => 'recibida', 'cantidad_recogida' => 4]);
        $t->set('seleccion', [(string) $g->id, (string) $j->id, (string) $uno->id])->call('accionMasiva', 'recibida')
            ->assertSet('recogiendoIds', [$g->id, $uno->id])->assertSee('¿Cuántas recogiste?')->assertSee('CUBETA');
        $this->assertSame(EstadoPartidaPedido::Solicitada, $g->fresh()->estado);   // nada cambia hasta guardar

        // Del guante solo dieron 6; la cubeta sí; 0 = no se tocó.
        $t->call('registrarRecogidas', [$g->id => '6', $uno->id => '1'])->assertSet('recogiendoIds', [])->assertSet('seleccion', []);
        $this->assertEquals(6, (float) $g->fresh()->cantidad_recogida);
        $this->assertSame(EstadoPartidaPedido::Recibida, $uno->fresh()->estado);
        $t->call('registrarRecogidas', [$g->id => '0'])->assertHasErrors('recoger');

        // Solo piezas sueltas (1 de 1): no pregunta, se marcan de una vez.
        $dos = PedidoPartida::create(['pedido_id' => $pedido->id, 'orden' => 4, 'descripcion' => 'ESCOBA', 'unidad' => 'PZA', 'cantidad' => 1,
            'costo' => 10, 'precio' => 20, 'estado' => 'solicitada']);
        $t->set('seleccion', [(string) $dos->id])->call('accionMasiva', 'recibida')->assertSet('recogiendoIds', []);
        $this->assertSame(EstadoPartidaPedido::Recibida, $dos->fresh()->estado);
    }
}

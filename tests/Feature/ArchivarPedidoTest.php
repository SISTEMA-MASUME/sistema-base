<?php

namespace Tests\Feature;

use App\Livewire\Pedidos\Ver;
use App\Livewire\Tablero\Index;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArchivarPedidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_archiva_y_desarchiva_desde_el_detalle(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        $pedido = Pedido::factory()->create();

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->assertSee('Archivar pedido')
            ->call('archivar')
            ->assertSee('Archivado')
            ->assertSee('Desarchivar pedido');
        $this->assertNotNull($pedido->fresh()->archivado_at);
        $this->assertSame($u->id, $pedido->fresh()->archivado_por);

        Livewire::test(Ver::class, ['pedido' => $pedido])->call('desarchivar');
        $this->assertNull($pedido->fresh()->archivado_at);

        // Sigue accesible por URL.
        $pedido->update(['archivado_at' => now()]);
        $this->get('/pedidos/'.$pedido->id)->assertOk()->assertSee('Archivado');
    }

    public function test_campo_no_puede_archivar_ni_desarchivar(): void
    {
        $pedido = Pedido::factory()->create();
        $this->actingAs(User::factory()->create(['rol' => 'campo']));

        Livewire::test(Ver::class, ['pedido' => $pedido])->assertDontSee('Archivar pedido')->call('archivar')->assertForbidden();
        Livewire::test(Ver::class, ['pedido' => $pedido])->call('desarchivar')->assertForbidden();
        Livewire::test(Index::class)->call('desarchivar', $pedido->id)->assertForbidden();
    }

    public function test_confirmacion_reforzada_con_pendientes(): void
    {
        $this->actingAs(User::factory()->create());
        $pedido = Pedido::factory()->create();
        PedidoPartida::factory()->count(2)->create(['pedido_id' => $pedido->id, 'estado' => 'pendiente']);
        PedidoPartida::factory()->create(['pedido_id' => $pedido->id, 'estado' => 'entregada', 'facturada' => true, 'orden' => 3]);

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->assertSee('Este pedido tiene 2 partidas sin entregar y 2 partidas sin facturar. ¿Archivar de todas formas?', false);

        $limpio = Pedido::factory()->create();
        PedidoPartida::factory()->create(['pedido_id' => $limpio->id, 'estado' => 'entregada', 'facturada' => true]);
        Livewire::test(Ver::class, ['pedido' => $limpio])
            ->assertSee('¿Archivar este pedido? Saldrá del tablero.', false)
            ->assertDontSee('de todas formas');
    }

    public function test_el_tablero_oculta_archivados_y_el_filtro_los_muestra(): void
    {
        $this->actingAs(User::factory()->create());
        $cliente = Cliente::factory()->create(['nombre' => 'ZETA INDUSTRIAL']);
        Pedido::factory()->create(['numero_oc_cliente' => 'VIS-1']);
        $oculto = Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => 'ARC-77', 'archivado_at' => now()]);

        Livewire::test(Index::class)
            ->assertSee('OC VIS-1')->assertDontSee('ARC-77')
            ->set('verArchivados', true)
            ->assertSee('ARC-77')->assertDontSee('VIS-1')
            ->set('busqueda', 'ZETA')->assertSee('ARC-77')
            ->set('busqueda', 'NADA-QUE-VER')->assertDontSee('ARC-77')
            ->set('busqueda', '')
            ->call('desarchivar', $oculto->id);

        $this->assertNull($oculto->fresh()->archivado_at);
        Livewire::test(Index::class)->assertSee('ARC-77');
    }
}

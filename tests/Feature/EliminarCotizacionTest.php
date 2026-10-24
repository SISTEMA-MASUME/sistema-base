<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\Editor;
use App\Livewire\Cotizaciones\Index;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Solo el admin elimina cotizaciones (borrado suave); las que ya son pedido no. */
class EliminarCotizacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_elimina_varias_desde_la_lista(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $a = Cotizacion::factory()->create();
        $b = Cotizacion::factory()->create();
        $queda = Cotizacion::factory()->create();
        $enPedido = Cotizacion::factory()->create();
        $cp = CotizacionPartida::factory()->create(['cotizacion_id' => $enPedido->id]);
        PedidoPartida::factory()->create(['pedido_id' => Pedido::factory()->create()->id, 'cotizacion_partida_id' => $cp->id]);

        Livewire::test(Index::class)
            ->set('seleccion', [(string) $a->id, (string) $b->id, (string) $enPedido->id])
            ->assertSee('Eliminar seleccionadas')
            ->call('eliminarSeleccionadas')
            ->assertSee('Se eliminaron las cotizaciones')
            ->assertSee('ya está en un pedido');

        $this->assertSoftDeleted($a);
        $this->assertSoftDeleted($b);
        $this->assertNotSoftDeleted($queda);
        $this->assertNotSoftDeleted($enPedido);
    }

    public function test_admin_elimina_desde_la_cotizacion(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $cot = Cotizacion::factory()->create();

        Livewire::test(Editor::class, ['cotizacion' => $cot])->assertSee('Eliminar cotización')
            ->call('eliminarCotizacion')->assertRedirect(route('cotizaciones.index'));
        $this->assertSoftDeleted($cot);
    }

    public function test_vendedor_no_puede_eliminar(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $cot = Cotizacion::factory()->create();

        Livewire::test(Index::class)->assertDontSee('Seleccionar cotización')
            ->set('seleccion', [(string) $cot->id])->call('eliminarSeleccionadas')->assertForbidden();
        Livewire::test(Editor::class, ['cotizacion' => $cot])->assertDontSee('Eliminar cotización')
            ->call('eliminarCotizacion')->assertForbidden();
        $this->assertNotSoftDeleted($cot);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\EstadoOrdenProveedor;
use App\Livewire\OrdenesProveedor\Index;
use App\Livewire\OrdenesProveedor\Ver as VerOrden;
use App\Livewire\Pedidos\Conciliacion;
use App\Livewire\Pedidos\Ver;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\PedidoConciliacion;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\Folios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ReenvioOrdenTest extends TestCase
{
    use RefreshDatabase;

    private function escenario(EstadoOrdenProveedor $estado): array
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $pedido = Pedido::factory()->create();
        $prov = Proveedor::factory()->create(['nombre' => 'ACME']);
        $orden = OrdenProveedor::create([
            'folio' => Folios::siguiente('oc_proveedor'), 'pedido_id' => $pedido->id, 'proveedor_id' => $prov->id,
            'user_id' => auth()->id(), 'metodo_pago' => 'TARJETA', 'estado' => $estado, 'total_costo' => 150,
        ]);
        $partida = PedidoPartida::create([
            'pedido_id' => $pedido->id, 'orden_proveedor_id' => $orden->id, 'orden' => 1, 'descripcion' => 'LAMPARA XXXX',
            'modelo' => 'XXXX', 'unidad' => 'PZA', 'cantidad' => 3, 'costo' => 50, 'precio' => 100,
        ]);
        $pedido->recalcularTotales();

        $renglon = [
            'num_renglon' => 1, 'codigo_cliente' => null, 'descripcion' => 'LAMPARA XXXX', 'marca' => null, 'modelo' => 'XXXX',
            'cantidad' => 6, 'unidad' => 'PZA', 'precio_unitario' => 100, 'importe' => 600, 'fecha_entrega' => null,
            'origen' => 'tabla', 'confianza' => 'alta', 'texto_original' => '6 LAMPARA XXXX',
        ];
        PedidoConciliacion::create([
            'pedido_id' => $pedido->id, 'user_id' => auth()->id(), 'archivo_hash' => str_repeat('a', 64), 'modelo' => 'gpt-6-luna',
            'estado' => 'completada',
            'extraccion' => [
                'numero_oc' => $pedido->numero_oc_cliente, 'fecha_oc' => null, 'cliente_detectado' => 'X', 'moneda' => 'MXN',
                'precios_incluyen_iva' => false, 'renglones' => [$renglon], 'subtotal' => null, 'iva' => null, 'total' => null,
                'observaciones_generales' => null, 'advertencias' => [],
            ],
        ]);

        return [$pedido, $partida, $orden];
    }

    public function test_usar_cantidad_recalcula_la_orden_y_avisa_si_ya_se_habia_enviado(): void
    {
        [$pedido, $partida, $orden] = $this->escenario(EstadoOrdenProveedor::Enviada);

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])
            ->call('usarCantidad', 'r0')
            ->assertDispatched('partidas-actualizadas')
            ->assertSee('Cantidad actualizada a 6')
            ->assertSee('vuelve a enviársela con la cantidad nueva');

        $orden->refresh();
        $this->assertEquals(300, $orden->total_costo);
        $this->assertTrue($orden->requiere_reenvio);
        $this->assertEquals(600, $pedido->fresh()->total_venta);
    }

    public function test_orden_emitida_se_recalcula_sin_aviso(): void
    {
        [$pedido, , $orden] = $this->escenario(EstadoOrdenProveedor::Emitida);

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])
            ->call('usarCantidad', 'r0')
            ->assertDontSee('vuelve a enviársela');

        $this->assertEquals(300, $orden->fresh()->total_costo);
        $this->assertFalse($orden->fresh()->requiere_reenvio);
    }

    public function test_el_padre_muestra_la_cantidad_nueva_al_recibir_el_evento(): void
    {
        [$pedido, $partida] = $this->escenario(EstadoOrdenProveedor::Emitida);

        $padre = Livewire::test(Ver::class, ['pedido' => $pedido])->assertSee('3 PZA');

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('usarCantidad', 'r0');
        $this->assertEquals(6, $partida->fresh()->cantidad);

        $padre->dispatch('partidas-actualizadas')->assertSee('6 PZA')->assertDontSee('3 PZA')->assertSee('$300.00');
    }

    public function test_aviso_en_pantalla_y_lista_de_ordenes_y_se_limpia(): void
    {
        [$pedido, , $orden] = $this->escenario(EstadoOrdenProveedor::Enviada);
        $orden->update(['requiere_reenvio' => true]);

        Livewire::test(Index::class)->assertSee('Reenviar');
        Livewire::test(Ver::class, ['pedido' => $pedido])->assertSee('Reenviar al proveedor');

        Livewire::test(VerOrden::class, ['orden' => $orden])
            ->assertSee('Reenviar a ')
            ->call('marcarReenviada')
            ->assertDontSee('Ya se la reenvié');
        $this->assertFalse($orden->fresh()->requiere_reenvio);
    }

    public function test_marcar_enviada_limpia_el_aviso(): void
    {
        [, , $orden] = $this->escenario(EstadoOrdenProveedor::Emitida);
        $orden->update(['requiere_reenvio' => true]);

        Livewire::test(VerOrden::class, ['orden' => $orden])->call('marcarEnviada');

        $this->assertFalse($orden->fresh()->requiere_reenvio);
        $this->assertSame(EstadoOrdenProveedor::Enviada, $orden->fresh()->estado);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\EstadoPartidaPedido;
use App\Livewire\Pedidos\Nuevo;
use App\Livewire\Pedidos\Ver;
use App\Livewire\Tablero\Index as Tablero;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Pedido;
use App\Models\PedidoArchivo;
use App\Models\PedidoPartida;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Tarjeta del pedido rediseñada: pasos del pedido, fecha estimada por partida y recordatorio de la foto de entrega. */
class TarjetaPedidoRedisenoTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->vendedor = User::factory()->create(['rol' => 'vendedor']);
        $this->actingAs($this->vendedor);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedido(array $estados): array
    {
        $pedido = Pedido::factory()->create(['responsable_id' => $this->vendedor->id]);
        $partidas = [];
        foreach ($estados as $i => $estado) {
            $partidas[] = PedidoPartida::factory()->create(['pedido_id' => $pedido->id, 'orden' => $i + 1, 'estado' => $estado, 'descripcion' => 'PIEZA '.($i + 1)]);
        }

        return [$pedido, $partidas];
    }

    public function test_muestra_en_que_va_y_el_siguiente_paso(): void
    {
        [$pedido] = $this->pedido(['solicitada', 'pendiente']);
        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->assertSee('¿En qué va este pedido?')->assertSee('1/2 pedidas')
            ->assertSee('Siguiente paso: pedir 1 partida al proveedor')->assertSee('Marcar recogida')
            ->assertSee('Generar OC a proveedores');

        [$listo] = $this->pedido(['entregada']);
        PedidoPartida::where('pedido_id', $listo->id)->update(['facturada' => true]);
        PedidoArchivo::create(['pedido_id' => $listo->id, 'tipo' => 'evidencia', 'disco' => 'local', 'ruta' => 'x.jpg', 'nombre_original' => 'x.jpg', 'mime' => 'image/jpeg', 'tamano' => 1, 'user_id' => $this->vendedor->id]);
        Livewire::test(Ver::class, ['pedido' => $listo])->assertSee('Todo entregado y facturado')->assertDontSee('Foto de entrega: todavía no hay');
    }

    public function test_fecha_estimada_por_partida_la_pone_el_vendedor_y_campo_solo_la_ve(): void
    {
        [$pedido, [$a, $b]] = $this->pedido(['solicitada', 'pendiente']);
        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->assertSee('+ Fecha estimada')
            ->call('guardarFechaEntrega', $a->id, '2026-10-10')->assertHasNoErrors()->assertSee('Llega 10 oct')
            ->call('guardarFechaEntrega', $b->id, '2026-10-07')->assertSee('Atrasada · 7 oct')
            ->call('guardarFechaEntrega', $b->id, '2026-02-31')->assertHasErrors('fechaEntrega.'.$b->id)
            ->call('guardarFechaEntrega', $b->id, '')->assertSee('Fecha estimada quitada');
        $this->assertSame('2026-10-10', $a->fresh()->fecha_entrega->format('Y-m-d'));
        $this->assertNull($b->fresh()->fecha_entrega);
        $this->assertDatabaseHas('pedido_actividades', ['pedido_id' => $pedido->id, 'tipo' => 'fecha_entrega', 'descripcion' => 'puso la fecha estimada de entrega de "PIEZA 1": 10/10/2026']);

        $hugo = User::factory()->create(['rol' => 'campo']);
        Livewire::actingAs($hugo)->test(Ver::class, ['pedido' => $pedido])
            ->assertSee('Llega 10 oct')->assertDontSee('+ Fecha estimada')->assertDontSeeHtml('data-fecha-entrega')
            ->call('guardarFechaEntrega', $a->id, '2026-10-12')->assertForbidden();
        $this->assertSame('2026-10-10', $a->fresh()->fecha_entrega->format('Y-m-d'));

        // En el tablero se ve la fecha más próxima de lo que falta entregar.
        Livewire::test(Tablero::class)->assertSee('🚚 10 oct');
    }

    public function test_quien_da_de_alta_el_pedido_pone_la_fecha_estimada(): void
    {
        $cot = Cotizacion::factory()->create(['vendedor_id' => $this->vendedor->id, 'cliente_id' => Cliente::factory()->create()->id, 'estado' => 'enviada']);
        $a = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'cantidad' => 2, 'costo' => 10, 'precio' => 20]);
        $b = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 2, 'cantidad' => 1, 'costo' => 10, 'precio' => 20]);

        Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)
            ->set('seleccion', [(string) $a->id, (string) $b->id])
            ->set('numeroOc', '777')->set('justificacion', 'La OC llega por correo después')
            ->call('irAPaso', 4)->assertSee('Fecha estimada de entrega')
            ->set('fechaTodas', '2026-10-12')->call('aplicarFechaTodas')
            ->set('fechasEntrega.c'.$b->id, '2026-10-15')
            ->call('crear')->assertHasNoErrors();

        $pedido = Pedido::firstOrFail();
        $this->assertSame('2026-10-12', $pedido->partidas->firstWhere('cotizacion_partida_id', $a->id)->fecha_entrega->format('Y-m-d'));
        $this->assertSame('2026-10-15', $pedido->partidas->firstWhere('cotizacion_partida_id', $b->id)->fecha_entrega->format('Y-m-d'));
    }

    public function test_al_entregar_sin_foto_invita_a_tomarla_sin_obligar(): void
    {
        [$pedido, [$a, $b]] = $this->pedido(['recibida', 'recibida']);
        $hugo = User::factory()->create(['rol' => 'campo']);

        $c = Livewire::actingAs($hugo)->test(Ver::class, ['pedido' => $pedido])
            ->assertSee('No te vayas sin la foto de entrega')->assertSee('Marcar entregada')
            ->call('siguienteEstado', $a->id)->assertSet('pedirFoto', true)->assertSee('Ahora la foto de entrega')
            ->call('cerrarAvisoFoto')->assertSet('pedirFoto', false)->assertDontSee('Ahora la foto de entrega');
        $this->assertSame(EstadoPartidaPedido::Entregada, $a->fresh()->estado);   // se entregó aunque no haya foto

        // En el tablero, la tarjeta queda con "Falta foto" hasta que se suba la primera.
        Livewire::actingAs($this->vendedor)->test(Tablero::class)->assertSee('Falta foto');
        PedidoArchivo::create(['pedido_id' => $pedido->id, 'tipo' => 'evidencia', 'disco' => 'local', 'ruta' => 'x.jpg', 'nombre_original' => 'x.jpg', 'mime' => 'image/jpeg', 'tamano' => 1, 'user_id' => $hugo->id]);
        Livewire::test(Tablero::class)->assertDontSee('Falta foto');

        // Con foto ya no vuelve a preguntar.
        $c->set('seleccion', [(string) $b->id])->call('accionMasiva', 'entregada')->assertSet('pedirFoto', false)
            ->assertDontSee('No te vayas sin la foto de entrega');
    }
}

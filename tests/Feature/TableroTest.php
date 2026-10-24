<?php

namespace Tests\Feature;

use App\Enums\ColumnaPedido;
use App\Livewire\Tablero\Index;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class TableroTest extends TestCase
{
    use RefreshDatabase;

    private function entrar(string $rol = 'vendedor'): User
    {
        $u = User::factory()->create(['rol' => $rol]);
        $this->actingAs($u);

        return $u;
    }

    public function test_carga_con_columnas_y_tarjetas(): void
    {
        $this->entrar();
        $cliente = Cliente::factory()->create(['nombre' => 'FANOSA']);
        Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '617489']);

        $r = $this->get(route('pedidos.index'))->assertOk();
        foreach (ColumnaPedido::cases() as $c) {
            $r->assertSee($c->etiqueta());
        }
        $r->assertSee('FANOSA - OC 617489')->assertSee('Nuevo pedido');
    }

    public function test_mover_cambia_columna_y_orden(): void
    {
        $this->entrar();
        [$a, $b, $c] = Pedido::factory()->count(3)->create()->all();
        $x = Pedido::factory()->create(['columna' => 'en_tramite', 'posicion' => 5]);

        Livewire::test(Index::class)->call('mover', $x->id, 'oc_recibida', [$c->id, $x->id, $a->id, $b->id]);

        $this->assertSame(ColumnaPedido::OcRecibida, $x->fresh()->columna);
        $orden = Pedido::where('columna', 'oc_recibida')->orderBy('posicion')->pluck('id')->all();
        $this->assertSame([$c->id, $x->id, $a->id, $b->id], $orden);

        // Sin orden (celular): queda al tope de la columna destino
        Livewire::test(Index::class)->call('mover', $b->id, 'por_facturar', []);
        $this->assertSame(ColumnaPedido::PorFacturar, $b->fresh()->columna);
        $this->assertSame(0, $b->fresh()->posicion);
    }

    public function test_mover_ignora_columna_invalida(): void
    {
        $this->entrar();
        $p = Pedido::factory()->create();
        Livewire::test(Index::class)->call('mover', $p->id, 'inventada', []);
        $this->assertSame(ColumnaPedido::OcRecibida, $p->fresh()->columna);
    }

    public function test_filtros_buscar_y_responsable(): void
    {
        $yo = $this->entrar();
        $otro = User::factory()->create();
        $ruby = Cliente::factory()->create(['nombre' => 'CLIENTE ROSY']);
        $p1 = Pedido::factory()->create(['cliente_id' => $ruby->id, 'responsable_id' => $yo->id, 'numero_oc_cliente' => '111']);
        $p2 = Pedido::factory()->create(['responsable_id' => $otro->id, 'numero_oc_cliente' => '222']);
        PedidoPartida::create(['pedido_id' => $p2->id, 'orden' => 1, 'descripcion' => 'TORNILLO ESPECIAL', 'unidad' => 'PZA', 'cantidad' => 1, 'costo' => 1, 'precio' => 2]);

        $ids = fn ($t) => collect($t->viewData('columnas'))->flatMap(fn ($c) => $c['pedidos']->pluck('id'))->all();

        $this->assertEqualsCanonicalizing([$p1->id, $p2->id], $ids(Livewire::test(Index::class)));
        $this->assertSame([$p1->id], $ids(Livewire::test(Index::class)->set('busqueda', 'rosy')));
        $this->assertSame([$p2->id], $ids(Livewire::test(Index::class)->set('busqueda', 'tornillo')));
        $this->assertSame([$p2->id], $ids(Livewire::test(Index::class)->set('busqueda', 'P-'.$p2->folio)));
        $this->assertSame([$p2->id], $ids(Livewire::test(Index::class)->set('responsableId', (string) $otro->id)));
        $this->assertSame([$p1->id], $ids(Livewire::test(Index::class)->set('soloMios', true)));
    }

    public function test_ocultar_cancelados(): void
    {
        $this->entrar();
        $p = Pedido::factory()->create(['cancelado_at' => now()]);
        $this->assertSame(1, Livewire::test(Index::class)->viewData('columnas')->sum(fn ($c) => $c['pedidos']->count()));
        $this->assertSame(0, Livewire::test(Index::class)->set('mostrarCancelados', false)->viewData('columnas')->sum(fn ($c) => $c['pedidos']->count()));
        $this->assertNotNull($p->fresh()->cancelado_at);
    }

    public function test_campo_ve_costo_pero_no_venta_ni_boton_nuevo(): void
    {
        $this->entrar('campo');
        Pedido::factory()->create(['total_venta' => 98765.43, 'total_costo' => 4321.09]);

        $this->get(route('pedidos.index'))->assertOk()
            ->assertSee('OC recibida')
            ->assertDontSee('98,765.43')->assertSee('Costo $4,321.09', false)
            ->assertDontSee('Nuevo pedido');

        $this->entrar('vendedor');
        $this->get(route('pedidos.index'))->assertSee('98,765.43')->assertDontSee('4,321.09')->assertSee('Nuevo pedido');
    }

    public function test_sin_n_mas_uno_con_50_pedidos(): void
    {
        $this->entrar();
        $pedidos = Pedido::factory()->count(50)->create();
        foreach ($pedidos as $p) {
            PedidoPartida::create(['pedido_id' => $p->id, 'orden' => 1, 'descripcion' => 'X', 'unidad' => 'PZA', 'cantidad' => 1, 'costo' => 1, 'precio' => 2]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('pedidos.index'))->assertOk();
        $this->assertLessThan(15, count(DB::getQueryLog()));
    }

    public function test_entregadas_limitadas_a_30_dias(): void
    {
        $this->entrar();
        $reciente = Pedido::factory()->create(['columna' => 'entregadas_completas']);
        $viejo = Pedido::factory()->create(['columna' => 'entregadas_completas']);
        Pedido::where('id', $viejo->id)->update(['updated_at' => now()->subDays(60)]);

        $ids = fn ($t) => $t->viewData('columnas')->firstWhere('valor', 'entregadas_completas')['pedidos']->pluck('id')->all();

        $this->assertSame([$reciente->id], $ids(Livewire::test(Index::class)));
        $this->assertEqualsCanonicalizing([$reciente->id, $viejo->id], $ids(Livewire::test(Index::class)->set('verTodasEntregadas', true)));
    }
}

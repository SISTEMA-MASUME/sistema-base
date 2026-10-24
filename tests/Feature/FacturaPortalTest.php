<?php

namespace Tests\Feature;

use App\Livewire\Clientes\Index;
use App\Livewire\Pedidos\Ver;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class FacturaPortalTest extends TestCase
{
    use RefreshDatabase;

    private function textos(Pedido $p): array
    {
        return array_column(Pedido::find($p->id)->etiquetas(), 'texto');
    }

    private function pedidoPortal(array $extra = []): Pedido
    {
        $cliente = Cliente::factory()->create(['oc_despues_de_facturar' => true, 'portal_url' => 'https://portal.plazanorte.mx']);

        return Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => null] + $extra);
    }

    public function test_etiquetas_en_cada_estado(): void
    {
        $normal = Pedido::factory()->create(['numero_oc_cliente' => null]);
        $this->assertContains('Esperando OC del cliente', $this->textos($normal));

        $portal = $this->pedidoPortal();
        $this->assertContains('Subir factura al portal', $this->textos($portal));
        $this->assertNotContains('Esperando OC del cliente', $this->textos($portal));

        $portal->update(['factura_portal_at' => now()]);
        $this->assertContains('Esperando OC del portal', $this->textos($portal));
        $this->assertNotContains('Subir factura al portal', $this->textos($portal));

        $portal->update(['numero_oc_cliente' => '4500001']);
        $t = $this->textos($portal);
        $this->assertNotContains('Esperando OC del portal', $t);
        $this->assertNotContains('Subir factura al portal', $t);

        $cancelado = $this->pedidoPortal(['cancelado_at' => now()]);
        $this->assertNotContains('Subir factura al portal', $this->textos($cancelado));
    }

    public function test_marcar_y_deshacer_factura_en_portal(): void
    {
        $pedido = $this->pedidoPortal();
        $vendedor = User::factory()->create();
        $this->actingAs($vendedor);

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->assertSee('Abrir portal de')
            ->assertSee('Ya subí la factura al portal')
            ->call('marcarFacturaPortal')
            ->assertSee('Factura subida al portal por '.$vendedor->name)
            ->call('deshacerFacturaPortal')
            ->assertForbidden();

        $pedido->refresh();
        $this->assertNotNull($pedido->factura_portal_at);
        $this->assertSame($vendedor->id, $pedido->factura_portal_por);

        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        Livewire::test(Ver::class, ['pedido' => $pedido])->call('deshacerFacturaPortal');
        $pedido->refresh();
        $this->assertNull($pedido->factura_portal_at);
        $this->assertNull($pedido->factura_portal_por);
    }

    public function test_campo_no_puede_marcar_ni_ve_controles(): void
    {
        $pedido = $this->pedidoPortal();
        $this->actingAs(User::factory()->create(['rol' => 'campo']));

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->assertDontSee('Ya subí la factura al portal')
            ->call('marcarFacturaPortal')
            ->assertForbidden();
        $this->assertNull($pedido->fresh()->factura_portal_at);
    }

    public function test_cliente_normal_no_puede_marcar_factura_portal(): void
    {
        $pedido = Pedido::factory()->create(['numero_oc_cliente' => null]);
        $this->actingAs(User::factory()->create());

        Livewire::test(Ver::class, ['pedido' => $pedido])->assertSee('Número de OC del cliente')->call('marcarFacturaPortal')->assertStatus(422);
    }

    public function test_captura_de_oc_desde_el_recuadro_del_portal(): void
    {
        $pedido = $this->pedidoPortal(['factura_portal_at' => now()]);
        $this->actingAs(User::factory()->create());

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->set('numeroOc', '4509999')
            ->call('guardarNumeroOc')
            ->assertHasNoErrors();
        $this->assertSame('4509999', $pedido->fresh()->numero_oc_cliente);
    }

    public function test_edicion_del_cliente_con_casilla_y_url(): void
    {
        $this->actingAs(User::factory()->create());
        $c = Cliente::factory()->create();

        Livewire::test(Index::class)
            ->call('editar', $c->id)
            ->set('oc_despues_de_facturar', true)
            ->set('portal_url', 'no es url')
            ->call('guardar')
            ->assertHasErrors('portal_url');

        Livewire::test(Index::class)
            ->call('editar', $c->id)
            ->set('oc_despues_de_facturar', true)
            ->set('portal_url', 'https://portal.tierrayarmonia.mx')
            ->call('guardar')
            ->assertHasNoErrors();

        $c->refresh();
        $this->assertTrue($c->oc_despues_de_facturar);
        $this->assertSame('https://portal.tierrayarmonia.mx', $c->portal_url);
        $this->get(route('clientes.ver', $c))->assertOk()->assertSee('Abrir portal de proveedores');
    }

    public function test_tablero_sin_n_mas_uno_con_clientes_de_portal(): void
    {
        $this->actingAs(User::factory()->create());
        $clientes = Cliente::factory()->count(10)->create(['oc_despues_de_facturar' => true]);
        foreach ($clientes as $cl) {
            Pedido::factory()->count(3)->create(['cliente_id' => $cl->id, 'numero_oc_cliente' => null]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('pedidos.index'))->assertOk()->assertSee('Subir factura al portal');
        $this->assertLessThan(15, count(DB::getQueryLog()));
    }
}

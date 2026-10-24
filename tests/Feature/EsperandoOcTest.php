<?php

namespace Tests\Feature;

use App\Livewire\Pedidos\Ver;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EsperandoOcTest extends TestCase
{
    use RefreshDatabase;

    public function test_pedido_sin_oc_muestra_etiqueta_y_se_captura_despues(): void
    {
        $pedido = Pedido::factory()->create(['numero_oc_cliente' => null, 'tipo' => 'nota_venta']);
        $this->assertContains('Esperando OC del cliente', array_column($pedido->etiquetas(), 'texto'));

        $this->actingAs(User::factory()->create());
        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->set('numeroOc', 'OC 4509078358')
            ->call('guardarNumeroOc')
            ->assertHasNoErrors()
            ->assertSee('Se registró la OC');

        $pedido->refresh();
        $this->assertSame('OC 4509078358', $pedido->numero_oc_cliente);
        $this->assertNotContains('Esperando OC del cliente', array_column($pedido->etiquetas(), 'texto'));
    }

    public function test_no_permite_oc_duplicada_del_mismo_cliente_ni_a_campo(): void
    {
        $existente = Pedido::factory()->create(['numero_oc_cliente' => '617489']);
        $pedido = Pedido::factory()->create(['cliente_id' => $existente->cliente_id, 'numero_oc_cliente' => null]);

        $this->actingAs(User::factory()->create());
        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->set('numeroOc', '617489')
            ->call('guardarNumeroOc')
            ->assertHasErrors('numeroOc');

        $this->actingAs(User::factory()->create(['rol' => 'campo']));
        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->set('numeroOc', '999')
            ->call('guardarNumeroOc')
            ->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolCampoTest extends TestCase
{
    use RefreshDatabase;

    public function test_campo_entra_a_pedidos_y_no_a_cotizaciones_ni_clientes(): void
    {
        $campo = User::factory()->create(['rol' => 'campo']);

        $this->actingAs($campo)->get('/')->assertRedirect(route('pedidos.index'));
        $this->actingAs($campo)->get(route('pedidos.index'))->assertOk();
        $this->actingAs($campo)->get(route('ordenes-proveedor.index'))->assertOk();
        $this->actingAs($campo)->get(route('cotizaciones.index'))->assertForbidden();
        $this->actingAs($campo)->get(route('clientes.index'))->assertForbidden();
        $this->actingAs($campo)->get(route('proveedores.index'))->assertForbidden();
    }

    public function test_vendedor_sigue_entrando_a_todo(): void
    {
        $vendedor = User::factory()->create();

        $this->actingAs($vendedor)->get(route('cotizaciones.index'))->assertOk();
        $this->actingAs($vendedor)->get(route('clientes.index'))->assertOk();
    }
}

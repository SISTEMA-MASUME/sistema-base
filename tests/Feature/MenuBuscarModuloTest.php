<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Menú lateral: buscador de módulos y sin "Importar REGISTRO" (Daniel ya no lo usa). */
class MenuBuscarModuloTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_admin_tiene_buscador_y_ya_no_ve_importar_registro(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']))->get('/cotizaciones')->assertOk()
            ->assertSee('Buscar módulo…')
            ->assertSee('data-buscar="Nómina sueldos comisiones prestamos reparto empleados pagar"', false)
            ->assertDontSee('Importar REGISTRO');
        // La pantalla sigue existiendo por si hace falta.
        $this->get('/importar-registro')->assertOk();
    }

    public function test_con_pocos_modulos_no_sale_el_buscador(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'campo']))->get('/pedidos')->assertOk()->assertDontSee('Buscar módulo…');
    }
}

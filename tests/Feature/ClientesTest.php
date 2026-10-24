<?php

namespace Tests\Feature;

use App\Livewire\Clientes\Index;
use App\Livewire\Clientes\Ver;
use App\Models\Cliente;
use App\Models\Contacto;
use App\Models\Cotizacion;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_las_paginas_cargan(): void
    {
        $c = Cliente::factory()->create();

        $this->get(route('clientes.index'))->assertOk();
        $this->get(route('clientes.ver', $c))->assertOk()->assertSee($c->nombre);
    }

    public function test_requiere_sesion(): void
    {
        auth()->logout();

        $this->get(route('clientes.index'))->assertRedirect();
    }

    public function test_crea_cliente_y_limpia_nombre(): void
    {
        Livewire::test(Index::class)
            ->call('nuevo')
            ->set('nombre', '  provex. ')
            ->set('rfc', 'abc010101xyz')
            ->set('dias_credito', 15)
            ->call('guardar')
            ->assertHasNoErrors();

        $c = Cliente::firstOrFail();
        $this->assertSame('PROVEX', $c->nombre);
        $this->assertSame('ABC010101XYZ', $c->rfc);
        $this->assertSame(15, $c->dias_credito);
    }

    public function test_nombre_es_obligatorio(): void
    {
        Livewire::test(Index::class)->call('nuevo')->set('nombre', '')->call('guardar')->assertHasErrors('nombre');
    }

    public function test_duplicado_muestra_error_amigable(): void
    {
        Cliente::factory()->create(['nombre' => 'PROVEX']);

        Livewire::test(Index::class)
            ->call('nuevo')
            ->set('nombre', 'provex.')
            ->call('guardar')
            ->assertHasErrors('nombre')
            ->assertSee('Ya existe un cliente con ese nombre: PROVEX');

        $this->assertSame(1, Cliente::count());
    }

    public function test_editar_no_choca_consigo_mismo_pero_si_con_otro(): void
    {
        $a = Cliente::factory()->create(['nombre' => 'PROVEX']);
        Cliente::factory()->create(['nombre' => 'FANOSA']);

        Livewire::test(Index::class)
            ->call('editar', $a->id)
            ->set('nombre', 'Provex.')
            ->call('guardar')
            ->assertHasNoErrors();

        Livewire::test(Index::class)
            ->call('editar', $a->id)
            ->set('nombre', 'fanosa')
            ->call('guardar')
            ->assertHasErrors('nombre');
    }

    public function test_busqueda_filtra_y_pagina_de_25(): void
    {
        Cliente::factory()->count(30)->create();
        Cliente::factory()->create(['nombre' => 'ZZZ ESPECIAL']);

        $this->assertCount(25, Livewire::test(Index::class)->viewData('clientes')->items());

        $r = Livewire::test(Index::class)->set('busqueda', 'zzz especial');
        $this->assertSame(1, $r->viewData('clientes')->total());
    }

    public function test_borrar_es_soft_delete(): void
    {
        $c = Cliente::factory()->create();

        Livewire::test(Index::class)->call('borrar', $c->id)->assertSee('Cliente borrado');

        $this->assertSoftDeleted($c);
    }

    public function test_no_borra_cliente_con_cotizaciones(): void
    {
        $c = Cliente::factory()->create(['nombre' => 'FANOSA']);
        Cotizacion::factory()->create(['cliente_id' => $c->id]);

        Livewire::test(Index::class)->call('borrar', $c->id)->assertSee('No se puede borrar a FANOSA');

        $this->assertNotSoftDeleted($c);
    }

    public function test_contactos_alta_edicion_y_borrado(): void
    {
        $c = Cliente::factory()->create();

        $t = Livewire::test(Ver::class, ['cliente' => $c])
            ->call('nuevoContacto')
            ->set('c_nombre', 'Juan Pérez')
            ->set('c_puesto', 'Compras')
            ->set('c_correo', 'juan@ejemplo.com')
            ->set('c_telefono', '3312345678')
            ->call('guardarContacto')
            ->assertHasNoErrors();

        $k = $c->contactos()->firstOrFail();
        $this->assertSame('Compras', $k->puesto);

        $t->call('editarContacto', $k->id)->set('c_puesto', 'Gerente')->call('guardarContacto');
        $this->assertSame('Gerente', $k->fresh()->puesto);

        $t->call('borrarContacto', $k->id);
        $this->assertSame(0, $c->contactos()->count());
    }

    public function test_contacto_valida_nombre_y_correo(): void
    {
        $c = Cliente::factory()->create();

        Livewire::test(Ver::class, ['cliente' => $c])
            ->call('nuevoContacto')
            ->set('c_correo', 'no-es-correo')
            ->call('guardarContacto')
            ->assertHasErrors(['c_nombre', 'c_correo']);
    }

    public function test_no_edita_contactos_de_otro_cliente(): void
    {
        $c = Cliente::factory()->create();
        $ajeno = Contacto::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(Ver::class, ['cliente' => $c])->call('editarContacto', $ajeno->id);
    }

    public function test_ficha_muestra_historial_de_cotizaciones(): void
    {
        $c = Cliente::factory()->create();
        $cot = Cotizacion::factory()->create(['cliente_id' => $c->id, 'total' => 1234.5]);

        $this->get(route('clientes.ver', $c))
            ->assertOk()
            ->assertSee('Folio '.$cot->folio)
            ->assertSee('$1,234.50')
            ->assertSee('/cotizaciones/'.$cot->id);
    }
}

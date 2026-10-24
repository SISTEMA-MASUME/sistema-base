<?php

namespace Tests\Feature;

use App\Livewire\Proveedores\Index;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProveedoresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_la_pagina_carga(): void
    {
        Proveedor::factory()->create(['nombre' => 'TRUPER']);

        $this->get(route('proveedores.index'))->assertOk()->assertSee('TRUPER');
    }

    public function test_crea_proveedor(): void
    {
        Livewire::test(Index::class)
            ->call('nuevo')
            ->set('nombre', 'truper.')
            ->set('correo', 'ventas@truper.com')
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertSame('TRUPER', Proveedor::firstOrFail()->nombre);
    }

    public function test_duplicado_muestra_error_amigable(): void
    {
        Proveedor::factory()->create(['nombre' => 'TRUPER']);

        Livewire::test(Index::class)
            ->call('nuevo')
            ->set('nombre', ' Truper ')
            ->call('guardar')
            ->assertHasErrors('nombre')
            ->assertSee('Ya existe un proveedor con ese nombre: TRUPER');

        $this->assertSame(1, Proveedor::count());
    }

    public function test_editar_y_borrar(): void
    {
        $p = Proveedor::factory()->create(['nombre' => 'TRUPER']);

        $t = Livewire::test(Index::class)
            ->call('editar', $p->id)
            ->set('nombre', 'Truper.')
            ->set('telefono', '3300000000')
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertSame('3300000000', $p->fresh()->telefono);

        $t->call('borrar', $p->id);
        $this->assertSoftDeleted($p);
    }

    public function test_correo_invalido_y_nombre_obligatorio(): void
    {
        Livewire::test(Index::class)
            ->call('nuevo')
            ->set('correo', 'x')
            ->call('guardar')
            ->assertHasErrors(['nombre', 'correo']);
    }

    public function test_busqueda_y_paginacion(): void
    {
        Proveedor::factory()->count(30)->create();
        Proveedor::factory()->create(['nombre' => 'YYY UNICO']);

        $this->assertCount(25, Livewire::test(Index::class)->viewData('proveedores')->items());
        $this->assertSame(1, Livewire::test(Index::class)->set('busqueda', 'yyy')->viewData('proveedores')->total());
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Al abrir una cotización y regresar a la lista, los filtros siguen puestos. */
class CotizacionesFiltrosRecordadosTest extends TestCase
{
    use RefreshDatabase;

    public function test_regresar_a_la_lista_conserva_los_filtros(): void
    {
        $u = User::factory()->create(['rol' => 'admin']);
        $this->actingAs($u);
        Livewire::withQueryParams([])->test(Index::class)->set('estado', 'borrador')->set('buscar', 'KAPPA');

        // Regresa a /cotizaciones sin nada en la dirección: se ponen los últimos filtros.
        Livewire::withQueryParams([])->test(Index::class)->assertSet('estado', 'borrador')->assertSet('buscar', 'KAPPA')
            ->call('limpiar');
        Livewire::withQueryParams([])->test(Index::class)->assertSet('estado', '')->assertSet('buscar', '');
    }

    public function test_si_la_direccion_trae_filtros_mandan_esos(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        Livewire::withQueryParams([])->test(Index::class)->set('estado', 'borrador');
        Livewire::withQueryParams(['q' => '10689'])->test(Index::class)->assertSet('buscar', '10689')->assertSet('estado', '');
    }
}

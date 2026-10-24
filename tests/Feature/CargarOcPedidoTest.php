<?php

namespace Tests\Feature;

use App\Livewire\Pedidos\Ver;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CargarOcPedidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_se_carga_y_reemplaza_la_oc_desde_el_detalle(): void
    {
        Storage::fake('local');
        $pedido = Pedido::factory()->create(['oc_cliente_path' => null]);
        $this->actingAs(User::factory()->create());

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->set('archivoOc', UploadedFile::fake()->create('oc.pdf', 200, 'application/pdf'))
            ->assertHasNoErrors()
            ->assertSee('Se cargó la OC del cliente');

        $primera = $pedido->fresh()->oc_cliente_path;
        Storage::disk('local')->assertExists($primera);

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->set('archivoOc', UploadedFile::fake()->image('foto.jpg'))
            ->assertSee('Se reemplazó la OC del cliente');

        Storage::disk('local')->assertMissing($primera);
        Storage::disk('local')->assertExists($pedido->fresh()->oc_cliente_path);
    }

    public function test_rechaza_otros_tipos_y_campo_no_puede_cargar(): void
    {
        Storage::fake('local');
        $pedido = Pedido::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->set('archivoOc', UploadedFile::fake()->create('oc.docx', 50))
            ->assertHasErrors('archivoOc');

        $this->actingAs(User::factory()->create(['rol' => 'campo']));
        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->set('archivoOc', UploadedFile::fake()->create('oc.pdf', 50, 'application/pdf'))
            ->assertForbidden();
    }
}

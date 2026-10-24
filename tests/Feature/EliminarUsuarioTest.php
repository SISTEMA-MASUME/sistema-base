<?php

namespace Tests\Feature;

use App\Livewire\Usuarios\Index;
use App\Models\Cotizacion;
use App\Models\User;
use App\Services\CotizacionPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Eliminar un usuario: ya no entra ni sale en la lista, pero sus movimientos se quedan con "(usuario inactivo)". */
class EliminarUsuarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_eliminar_usuario_conserva_sus_movimientos(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        $this->actingAs($admin);
        $irene = User::factory()->create(['rol' => 'vendedor', 'name' => 'Irene Rocha', 'email' => 'irene@ejemplo.com']);
        $cot = Cotizacion::factory()->create(['vendedor_id' => $irene->id]);

        Livewire::test(Index::class)->assertSee('Irene Rocha')
            ->call('eliminar', $irene->id)->assertSee('Se eliminó a Irene Rocha')
            ->assertDontSee('irene@ejemplo.com')
            ->set('verEliminados', true)->assertSee('Irene Rocha (usuario inactivo)');

        $irene->refresh();
        $this->assertFalse($irene->activo);
        $this->assertSame('Irene Rocha (usuario inactivo)', $cot->fresh()->vendedor->name);   // sus movimientos se quedan
        $this->assertSame('Irene Rocha', $irene->nombreReal());
        $this->assertStringContainsString('Irene Rocha', CotizacionPdf::html($cot->fresh()));
        $this->assertStringNotContainsString('usuario inactivo', CotizacionPdf::html($cot->fresh()));

        // Ya no puede entrar.
        auth()->logout();
        $this->post(route('login.entrar'), ['email' => 'irene@ejemplo.com', 'password' => 'password'])->assertSessionHasErrors('email');

        // Se puede reactivar.
        $this->actingAs($admin);
        Livewire::test(Index::class)->call('restaurar', $irene->id);
        $this->assertSame('Irene Rocha', $irene->fresh()->name);
        $this->assertTrue($irene->fresh()->activo);

        // No se puede eliminar a sí mismo.
        Livewire::test(Index::class)->call('eliminar', $admin->id)->assertSee('No puedes eliminarte');
        $this->assertNull($admin->fresh()->eliminado_at);
    }
}

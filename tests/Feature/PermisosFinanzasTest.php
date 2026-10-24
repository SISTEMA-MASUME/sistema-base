<?php

namespace Tests\Feature;

use App\Livewire\Usuarios\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PermisosFinanzasTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_admin_palomea_permisos_y_solo_valen_los_conocidos(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        $andrea2 = User::factory()->create(['rol' => 'vendedor']);
        $this->actingAs($admin);

        $this->assertFalse($andrea2->puede('ventas'));
        $this->assertTrue($admin->puede('ventas'));

        Livewire::test(Index::class)->call('editar', $andrea2->id)
            ->set('permisos', ['ventas'])->call('guardar')->assertHasNoErrors();
        $this->assertTrue($andrea2->fresh()->puede('ventas'));

        Livewire::test(Index::class)->call('editar', $andrea2->id)
            ->set('permisos', ['inventado'])->call('guardar')->assertHasErrors('permisos.0');

        Livewire::test(Index::class)->call('editar', $andrea2->id)
            ->set('permisos', [])->call('guardar');
        $this->assertFalse($andrea2->fresh()->puede('ventas'));
    }

    public function test_inactivos_y_ned_nunca_tienen_permiso(): void
    {
        $inactivo = User::factory()->create(['rol' => 'vendedor', 'activo' => false, 'permisos' => ['ventas']]);
        $ned = User::factory()->create(['rol' => 'nedtracking', 'permisos' => ['ventas']]);
        $this->assertFalse($inactivo->puede('ventas'));
        $this->assertFalse($ned->puede('ventas'));
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Usuarios\Index;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AccesoTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(array $extra = []): User
    {
        return User::factory()->create($extra + ['password' => 'secreto123'])->fresh();
    }

    public function test_login_correcto_entra_al_sistema(): void
    {
        $u = $this->usuario();

        $this->post('/login', ['email' => $u->email, 'password' => 'secreto123'])
            ->assertRedirect(route('inicio'));

        $this->assertAuthenticatedAs($u);
    }

    public function test_login_con_contrasena_incorrecta_falla(): void
    {
        $u = $this->usuario();

        $this->from('/login')->post('/login', ['email' => $u->email, 'password' => 'mala'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_inactivo_no_puede_entrar(): void
    {
        $u = $this->usuario(['activo' => false]);

        $this->post('/login', ['email' => $u->email, 'password' => 'secreto123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_rutas_protegidas_redirigen_a_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/usuarios')->assertRedirect('/login');
    }

    public function test_inicio_muestra_nombre_y_logout_funciona(): void
    {
        $u = $this->usuario(['name' => 'Rosa Prueba']);

        $this->actingAs($u)->get('/')->assertRedirect(route('cotizaciones.index'));
        $this->actingAs($u)->get(route('cotizaciones.index'))->assertOk()->assertSee('Rosa Prueba');

        $this->actingAs($u)->post('/salir')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_vendedor_no_entra_a_usuarios(): void
    {
        $this->actingAs($this->usuario(['rol' => 'vendedor']))->get('/usuarios')->assertForbidden();
    }

    public function test_admin_ve_usuarios_y_el_menu(): void
    {
        $this->actingAs($this->usuario(['rol' => 'admin']))->get('/usuarios')->assertOk()->assertSee('Usuarios');
    }

    public function test_admin_crea_y_edita_usuarios(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('nuevo')
            ->set('name', 'Nuevo Vendedor')
            ->set('email', 'nuevo@masume.test')
            ->set('iniciales', 'nvo')
            ->set('password', 'clave12345')
            ->call('guardar')
            ->assertHasNoErrors();

        $nuevo = User::where('email', 'nuevo@masume.test')->firstOrFail();
        $this->assertSame('NVO', $nuevo->iniciales);
        $this->assertSame('vendedor', $nuevo->rol);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('editar', $nuevo->id)
            ->set('activo', false)
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertFalse($nuevo->fresh()->activo);
        // Sin contraseña nueva, la anterior se conserva.
        $this->assertTrue(Hash::check('clave12345', $nuevo->fresh()->password));
    }

    public function test_admin_no_puede_desactivarse_a_si_mismo(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('editar', $admin->id)
            ->set('activo', false)
            ->call('guardar')
            ->assertHasErrors('rol');

        $this->assertTrue($admin->fresh()->activo);
    }

    public function test_correo_duplicado_se_rechaza(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('nuevo')
            ->set('name', 'X')
            ->set('email', $admin->email)
            ->set('password', 'clave12345')
            ->call('guardar')
            ->assertHasErrors('email');
    }

    public function test_usuario_desactivado_con_sesion_abierta_es_expulsado(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario)->get(route('cotizaciones.index'))->assertOk();

        $usuario->update(['activo' => false]);

        $this->get(route('cotizaciones.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_seeder_crea_admin_y_vendedores(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class); // idempotente

        $this->assertSame(5, User::count());
        $this->assertTrue(User::where('email', 'admin@ejemplo.com')->first()->esAdmin());
        $this->assertSame('ALBY', User::where('name', 'Alberto')->first()->iniciales);
    }
}

<?php

namespace Tests\Feature;

use App\Http\Controllers\SaludController;
use App\Livewire\Errores\Index;
use App\Mail\ErrorRegistradoMail;
use App\Models\ErrorSistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ErroresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_prueba-error', function () {
            throw new \RuntimeException('Falla de prueba con id 123');
        });
        Route::middleware('web')->post('/_prueba-error-post', function () {
            throw new \RuntimeException('Falla en POST');
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'admin', 'activo' => true]);
    }

    private function vendedor(): User
    {
        return User::factory()->create(['rol' => 'vendedor', 'activo' => true]);
    }

    private function configurarCorreo(): void
    {
        config([
            'mail.mailers.smtp_ventas.username' => 'ventas@example.com',
            'mail.mailers.smtp_ventas.password' => 'x',
        ]);
    }

    public function test_excepcion_se_registra_y_pagina_500_muestra_el_codigo(): void
    {
        $respuesta = $this->get('/_prueba-error');

        $respuesta->assertStatus(500);
        $error = ErrorSistema::firstOrFail();
        $this->assertMatchesRegularExpression('/^E-[A-Z0-9]{6}$/', $error->codigo);
        $respuesta->assertSee($error->codigo);
        $respuesta->assertSee('Ya quedó registrado');
        $respuesta->assertDontSee('RuntimeException');
        $respuesta->assertDontSee('Falla de prueba');
        $this->assertSame(1, $error->ocurrencias);
    }

    public function test_mismo_error_incrementa_ocurrencias_sin_fila_nueva(): void
    {
        $this->get('/_prueba-error');
        $codigo = ErrorSistema::first()->codigo;
        $this->get('/_prueba-error');

        $this->assertSame(1, ErrorSistema::count());
        $error = ErrorSistema::first();
        $this->assertSame(2, $error->ocurrencias);
        $this->assertSame($codigo, $error->codigo);
    }

    public function test_error_resuelto_que_reaparece_crea_registro_nuevo(): void
    {
        $this->get('/_prueba-error');
        ErrorSistema::first()->update(['resuelto' => true]);
        $this->get('/_prueba-error');

        $this->assertSame(2, ErrorSistema::count());
    }

    public function test_404_no_registra(): void
    {
        $this->get('/no-existe-esta-ruta')->assertStatus(404)->assertSee('No encontramos');
        $this->assertSame(0, ErrorSistema::count());
    }

    public function test_abrir_con_el_navegador_una_direccion_de_boton_regresa_sin_error(): void
    {
        // Le pasó a Rosa: /salir o /livewire/update abiertos desde el historial o al recargar daban el "405" genérico.
        $this->actingAs($this->vendedor());
        $this->get('/salir')->assertRedirect(url('/'));
        $this->get('/livewire/update', ['referer' => url('/pedidos')])->assertRedirect(url('/pedidos'));
        $this->get('/salir', ['referer' => 'https://otro-sitio.com/x'])->assertRedirect(url('/'));
        $this->get('/salir', ['referer' => url('/livewire/update')])->assertRedirect(url('/'));

        // Lo que no es el navegador abriendo una página sigue respondiendo 405, con pantalla en español.
        $this->post('/salud')->assertStatus(405)->assertSee('Esa página no se abre así');
        $this->getJson('/api/sicar/ventas')->assertStatus(405);
        $this->assertSame(0, ErrorSistema::count());
    }

    public function test_403_no_registra_y_muestra_pantalla(): void
    {
        $this->actingAs($this->vendedor())->get('/errores')->assertStatus(403);
        $this->assertSame(0, ErrorSistema::count());
    }

    public function test_correo_al_admin_con_limite_por_hora(): void
    {
        Mail::fake();
        $this->configurarCorreo();
        $admin = $this->admin();
        $this->vendedor();

        $this->get('/_prueba-error');
        Mail::assertSent(ErrorRegistradoMail::class, function ($m) use ($admin) {
            return $m->hasTo($admin->email) && str_contains($m->envelope()->subject, ErrorSistema::first()->codigo);
        });
        Mail::assertSent(ErrorRegistradoMail::class, 1);

        // Reaparece resuelto dentro de la misma hora: no manda otro.
        ErrorSistema::first()->update(['resuelto' => true]);
        $this->get('/_prueba-error');
        Mail::assertSent(ErrorRegistradoMail::class, 1);
    }

    public function test_sin_credenciales_no_manda_correo_ni_falla(): void
    {
        Mail::fake();
        config(['mail.mailers.smtp_ventas.username' => null, 'mail.mailers.smtp_ventas.password' => null]);
        $this->admin();

        $this->get('/_prueba-error')->assertStatus(500);
        Mail::assertNothingSent();
        $this->assertSame(1, ErrorSistema::count());
    }

    public function test_contexto_no_guarda_contrasenas_ni_tokens(): void
    {
        $this->post('/_prueba-error-post', [
            'email' => 'a@b.com',
            'password' => 'secreto123',
            'password_confirmation' => 'secreto123',
            'api_token' => 'tok',
            'nombre' => 'Juan',
        ]);

        $contexto = ErrorSistema::firstOrFail()->contexto;
        $this->assertSame('Juan', $contexto['nombre']);
        $this->assertSame('[oculto]', $contexto['password']);
        $this->assertStringNotContainsString('secreto123', json_encode($contexto));
        $this->assertStringNotContainsString('tok', json_encode(array_diff_key($contexto, ['nombre' => 1, 'email' => 1, 'api_token' => 1, 'password' => 1, 'password_confirmation' => 1])));
        $this->assertSame('[oculto]', $contexto['api_token']);
    }

    public function test_errores_y_salud_solo_admin(): void
    {
        $this->get('/errores')->assertRedirect();
        $this->get('/salud')->assertRedirect();

        $v = $this->vendedor();
        $this->actingAs($v)->get('/errores')->assertForbidden();
        $this->actingAs($v)->get('/salud')->assertForbidden();

        $a = $this->admin();
        $this->actingAs($a)->get('/errores')->assertOk();
        $this->actingAs($a)->get('/salud')->assertOk()->assertSee('sin respaldos')->assertSee('PHP del servidor')->assertSee(PHP_VERSION);
    }

    public function test_salud_dice_si_el_php_trae_el_parche_de_octubre_2026(): void
    {
        $php = fn (string $v) => [SaludController::php($v)['parchado'], SaludController::php($v)['minimo']];
        $this->assertSame([false, '8.3.36'], $php('8.3.35'));   // lo que servía Hostinger el 21-oct-2026
        $this->assertSame([true, '8.3.36'], $php('8.3.36'));
        $this->assertSame([false, '8.4.27'], $php('8.4.26'));
        $this->assertSame([true, '8.4.27'], $php('8.4.30'));
        $this->assertSame([true, '8.5.12'], $php('8.5.12'));
        $this->assertSame([false, '8.3.36'], $php('8.1.30'));   // rama sin soporte: nunca cuenta como parchada
        $this->assertStringContainsString('session.upload_progress.enabled = 0', file_get_contents(public_path('.user.ini')));
        $this->assertStringContainsString('RewriteRule (^|/)\\.user\\.ini$ - [F,L]', file_get_contents(public_path('.htaccess')));   // no se sirve
    }

    public function test_pantalla_errores_filtra_busca_y_resuelve(): void
    {
        $this->get('/_prueba-error');
        $error = ErrorSistema::first();
        $otro = ErrorSistema::create([
            'codigo' => 'E-ZZZZZZ', 'huella' => 'x', 'clase' => 'X', 'mensaje' => 'otro',
            'ocurrencias' => 1, 'primera_vez' => now(), 'ultima_vez' => now(), 'resuelto' => true,
        ]);

        Livewire::actingAs($this->admin())->test(Index::class)
            ->assertSee($error->codigo)
            ->assertDontSee('E-ZZZZZZ')
            ->set('filtro', 'resueltos')
            ->assertSee('E-ZZZZZZ')
            ->set('filtro', 'todos')
            ->set('buscar', 'ZZZZ')
            ->assertSee('E-ZZZZZZ')
            ->assertDontSee($error->codigo)
            ->set('buscar', '')
            ->set('filtro', 'pendientes')
            ->call('ver', $error->id)
            ->set('nota', 'Se corrigió')
            ->call('resolver', $error->id);

        $error->refresh();
        $this->assertTrue($error->resuelto);
        $this->assertSame('Se corrigió', $error->notas);
        $this->assertNotNull($error->resuelto_por);
        $this->assertTrue($otro->fresh()->resuelto);
    }

    public function test_comando_limpia_resueltos_viejos(): void
    {
        $base = ['huella' => 'h', 'clase' => 'X', 'mensaje' => 'm', 'ocurrencias' => 1, 'primera_vez' => now(), 'ultima_vez' => now()];
        ErrorSistema::create($base + ['codigo' => 'E-VIEJO1', 'resuelto' => true, 'resuelto_en' => now()->subDays(100)]);
        ErrorSistema::create($base + ['codigo' => 'E-NUEVO1', 'resuelto' => true, 'resuelto_en' => now()->subDays(10)]);
        ErrorSistema::create($base + ['codigo' => 'E-PEND01', 'resuelto' => false]);

        $this->artisan('masume:limpiar-errores')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['E-NUEVO1', 'E-PEND01'], ErrorSistema::pluck('codigo')->all());
    }

    public function test_falla_de_bd_al_registrar_no_genera_otro_error(): void
    {
        Schema::drop('errores_sistema');

        $this->get('/_prueba-error')->assertStatus(500)->assertSee('Ya quedó registrado');
    }
}

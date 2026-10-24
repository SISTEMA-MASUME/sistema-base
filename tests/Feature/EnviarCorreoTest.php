<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\Editor;
use App\Livewire\Cotizaciones\EnviarCorreo;
use App\Livewire\Usuarios\Index as UsuariosIndex;
use App\Mail\CotizacionMail;
use App\Models\Cliente;
use App\Models\Contacto;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class EnviarCorreoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['masume.cuentas_correo.ventas.usuario' => 'ventas@ejemplo.com']);
    }

    private function vendedor(?string $cuenta = 'ventas'): User
    {
        return User::factory()->create(['name' => 'Alberto Prueba', 'telefono' => '33 1111 2222', 'cuenta_correo' => $cuenta]);
    }

    private function cotizacion(User $u): Cotizacion
    {
        $cliente = Cliente::factory()->create();
        $contacto = Contacto::factory()->create(['cliente_id' => $cliente->id, 'correo' => 'cliente@example.com']);
        $cot = Cotizacion::factory()->create([
            'cliente_id' => $cliente->id, 'contacto_id' => $contacto->id, 'vendedor_id' => $u->id, 'estado' => 'borrador',
        ]);
        $prov = Proveedor::factory()->create(['nombre' => 'PROVEEDOR SECRETO']);
        CotizacionPartida::factory()->create([
            'cotizacion_id' => $cot->id, 'orden' => 1, 'costo' => 111.11, 'precio' => 200, 'importe' => 200, 'proveedor_id' => $prov->id,
        ]);
        $cot->recalcularTotales();

        return $cot->fresh();
    }

    public function test_envia_con_cuenta_del_vendedor_adjunta_pdf_y_cambia_estado(): void
    {
        Mail::fake();
        $u = $this->vendedor();
        $cot = $this->cotizacion($u);
        $this->actingAs($u);

        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])
            ->call('abrir')
            ->assertSet('para', 'cliente@example.com')
            ->assertSet('cc', 'ventas@ejemplo.com')
            ->assertSet('asunto', "Cotización {$cot->folio} - MASUME Industrial")
            ->set('para', 'cliente@example.com, otro@example.com')
            ->call('enviar')
            ->assertHasNoErrors()
            ->assertDispatched('envio-registrado', estado: 'enviada');

        Mail::assertSent(CotizacionMail::class, function (CotizacionMail $m) use ($cot) {
            $this->assertStringStartsWith('%PDF', $m->pdf);
            $html = $m->render();
            $this->assertStringNotContainsString('PROVEEDOR SECRETO', $html);
            $this->assertStringNotContainsString('111.11', $html);
            $this->assertStringContainsString('Alberto Prueba', $html);
            $this->assertStringContainsString('33 1111 2222', $html);

            return $m->hasTo('cliente@example.com') && $m->hasTo('otro@example.com')
                && $m->hasCc('ventas@ejemplo.com')
                && $m->hasFrom('ventas@ejemplo.com', 'Alberto Prueba · MASUME Industrial')
                && $m->hasAttachedData($m->pdf, $cot->nombreArchivoPdf(), ['mime' => 'application/pdf']);
        });

        $cot->refresh();
        $this->assertSame('enviada', $cot->estado->value);
        $this->assertNotNull($cot->enviada_at);
        $envio = $cot->envios()->first();
        $this->assertTrue($envio->exito);
        $this->assertSame('cliente@example.com, otro@example.com', $envio->para);
    }

    public function test_usa_el_mailer_de_la_cuenta_del_usuario(): void
    {
        Mail::fake();
        config(['masume.cuentas_correo.ventas2.usuario' => 'ventas2@ejemplo.com']);
        $u = $this->vendedor('ventas2');
        $cot = $this->cotizacion($u);
        $this->actingAs($u);

        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])->call('abrir')->call('enviar');

        Mail::assertSent(CotizacionMail::class, fn ($m) => $m->hasFrom('ventas2@ejemplo.com'));
        $this->assertNotNull(config('mail.mailers.smtp_ventas2'));
    }

    public function test_error_de_transporte_se_registra_y_no_cambia_estado(): void
    {
        Mail::shouldReceive('mailer')->once()->andThrow(new \RuntimeException('Connection refused'));
        $u = $this->vendedor();
        $cot = $this->cotizacion($u);
        $this->actingAs($u);

        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])
            ->call('abrir')
            ->call('enviar')
            ->assertSee('No se pudo enviar el correo');

        $cot->refresh();
        $this->assertSame('borrador', $cot->estado->value);
        $this->assertNull($cot->enviada_at);
        $envio = $cot->envios()->first();
        $this->assertFalse($envio->exito);
        $this->assertStringContainsString('Connection refused', $envio->error);
    }

    public function test_usuario_sin_cuenta_no_puede_enviar(): void
    {
        Mail::fake();
        $u = $this->vendedor(null);
        $cot = $this->cotizacion($u);
        $this->actingAs($u);

        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])
            ->assertSee('Tu usuario no tiene cuenta de correo de salida.')
            ->assertDontSee('Enviar por correo')
            ->call('abrir')
            ->assertSet('abierto', false)
            ->call('enviar')
            ->assertSee('no tiene cuenta de correo');

        Mail::assertNothingSent();
        $this->assertSame(0, $cot->envios()->count());
    }

    public function test_valida_correos(): void
    {
        Mail::fake();
        $u = $this->vendedor();
        $cot = $this->cotizacion($u);
        $this->actingAs($u);

        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])
            ->call('abrir')
            ->set('para', '')
            ->call('enviar')
            ->assertHasErrors('para')
            ->set('para', 'bueno@example.com, malo')
            ->call('enviar')
            ->assertHasErrors('para')
            ->set('para', 'bueno@example.com')
            ->set('cc', 'no es correo')
            ->call('enviar')
            ->assertHasErrors('cc');

        Mail::assertNothingSent();
        $this->assertSame('borrador', $cot->fresh()->estado->value);
    }

    public function test_no_baja_el_estado_si_ya_no_era_borrador(): void
    {
        Mail::fake();
        $u = $this->vendedor();
        $cot = $this->cotizacion($u);
        $cot->update(['estado' => 'aceptada']);
        $this->actingAs($u);

        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])->call('abrir')->call('enviar');

        $this->assertSame('aceptada', $cot->fresh()->estado->value);
    }

    public function test_editor_muestra_boton_e_historial(): void
    {
        Mail::fake();
        $u = $this->vendedor();
        $cot = $this->cotizacion($u);
        $cot->envios()->create(['user_id' => $u->id, 'para' => 'previo@example.com', 'asunto' => 'x', 'exito' => false, 'error' => 'fallo previo']);
        $this->actingAs($u);

        Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->assertSee('Enviar por correo')
            ->assertSee('Historial de envíos')
            ->assertSee('previo@example.com')
            ->assertSee('fallo previo')
            ->dispatch('envio-registrado', estado: 'enviada')
            ->assertSet('enc.estado', 'enviada');
    }

    public function test_admin_edita_cuenta_de_correo_en_usuarios(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        $u = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(UsuariosIndex::class)
            ->call('editar', $u->id)
            ->set('cuenta_correo', 'ventas2')
            ->call('guardar')
            ->assertHasNoErrors();
        $this->assertSame('ventas2', $u->fresh()->cuenta_correo);

        Livewire::test(UsuariosIndex::class)
            ->call('editar', $u->id)
            ->set('cuenta_correo', 'inexistente')
            ->call('guardar')
            ->assertHasErrors('cuenta_correo');

        Livewire::test(UsuariosIndex::class)
            ->call('editar', $u->id)
            ->set('cuenta_correo', '')
            ->call('guardar');
        $this->assertNull($u->fresh()->cuenta_correo);
    }
}

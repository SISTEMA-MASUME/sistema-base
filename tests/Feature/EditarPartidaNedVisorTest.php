<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\EnviarCorreo;
use App\Livewire\Pedidos\Ver;
use App\Mail\CotizacionMail;
use App\Models\Contacto;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Ned\NedCotizacion;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Cambiar el producto de una partida de la tarjeta con sugerencias, mandar por correo ambos PDF (MASUME y Ned) y el visor de PDF. */
class EditarPartidaNedVisorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // En CI no hay .env con las cuentas de correo.
        config(['masume.cuentas_correo.ventas.usuario' => 'ventas@ejemplo.com']);
    }

    public function test_al_editar_la_partida_sugiere_productos_cotizados_y_lo_anota(): void
    {
        $pedido = Pedido::factory()->create();
        $p = PedidoPartida::factory()->create(['pedido_id' => $pedido->id, 'descripcion' => 'LLAVE PLASTICO', 'cantidad' => 5, 'costo' => 10, 'precio' => 20]);
        $prov = Proveedor::create(['nombre' => 'URREA DIRECTO']);
        $cot = Cotizacion::factory()->create(['cliente_id' => $pedido->cliente_id]);
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'descripcion' => 'LLAVE ESPAÑOLA 3/4 URREA', 'modelo' => 'URR-34',
            'unidad' => 'PZA', 'costo' => 85.5, 'proveedor_id' => $prov->id]);
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));

        $t = Livewire::test(Ver::class, ['pedido' => $pedido])
            ->call('editarPartida', $p->id)
            ->set('e_descripcion', 'llave española 3/4')
            ->assertSee('LLAVE ESPAÑOLA 3/4 URREA')
            ->call('usarSugerencia', 0)
            ->assertSet('e_modelo', 'URR-34')->assertSet('e_costo', '85.5')->assertSet('e_proveedor', 'URREA DIRECTO')
            ->call('guardarPartida')->assertHasNoErrors();

        $p->refresh();
        $this->assertSame('LLAVE ESPAÑOLA 3/4 URREA', $p->descripcion);
        $this->assertSame($prov->id, $p->proveedor_id);
        $this->assertEquals(20, (float) $p->precio); // el precio de venta no cambia
        $this->assertTrue(PedidoActividad::where('pedido_id', $pedido->id)->where('descripcion', 'like', '%tomó el producto de la cotización '.$cot->folio.'%')->exists());
        $this->assertTrue(PedidoActividad::where('pedido_id', $pedido->id)->where('descripcion', 'like', '%cambió la descripción de "LLAVE PLASTICO"%')->exists());
    }

    public function test_correo_con_ambos_pdf_masume_y_ned_con_60_por_ciento(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create(['rol' => 'admin', 'cuenta_correo' => 'ventas']));
        $cot = Cotizacion::factory()->create();
        $cot->update(['contacto_id' => Contacto::factory()->create(['cliente_id' => $cot->cliente_id, 'correo' => 'cliente@example.com'])->id]);
        $a = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'DISCO', 'cantidad' => 2, 'costo' => 100, 'incluir' => true]);

        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])->call('abrir')
            ->assertSee('Adjuntar también el PDF de Ned Tracking')
            ->set('conNed', true)->call('enviar')->assertHasNoErrors()->assertSet('errorEnvio', '');

        $ned = NedCotizacion::where('cotizacion_id', $cot->id)->firstOrFail();
        $this->assertEquals(160, (float) $ned->partidas()->first()->precio);
        $this->assertEquals(320, (float) $ned->subtotal);
        Mail::assertSent(CotizacionMail::class, fn ($m) => count($m->attachments()) === 2 && isset($m->extras[$ned->nombreArchivoPdf()]));

        // Al volver a mandarla con otro costo se actualiza la misma de Ned (mismo folio).
        $a->update(['costo' => 50]);
        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])->call('abrir')->set('conNed', true)->call('enviar');
        $this->assertSame(1, NedCotizacion::count());
        $this->assertEquals(80, (float) $ned->fresh()->partidas()->first()->precio);

        // Sin costo no se puede calcular el precio de Ned: no se manda nada.
        $a->update(['costo' => null]);
        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])->call('abrir')->set('conNed', true)->call('enviar')
            ->assertSet('errorEnvio', 'Para el PDF de Ned falta el costo de: DISCO.');
        Mail::assertSentCount(2);

        // Sin la palomita va solo el de MASUME.
        $a->update(['costo' => 10]);
        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])->call('abrir')->call('enviar');
        Mail::assertSent(CotizacionMail::class, fn ($m) => count($m->attachments()) === 1);
    }

    public function test_vendedor_sin_acceso_a_ned_no_ve_la_palomita(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'acceso_ned' => false, 'cuenta_correo' => 'ventas']));
        $cot = Cotizacion::factory()->create();

        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])->call('abrir')
            ->assertDontSee('Adjuntar también el PDF de Ned Tracking');
    }

    public function test_visor_de_pdf_con_flecha_para_regresar(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));

        $this->get('/visor-pdf?u='.urlencode('/cotizaciones/5/pdf').'&volver='.urlencode('/cotizaciones/5').'&t=Ver+PDF')
            ->assertOk()->assertSee('← Regresar', false)->assertSee('pdf.min.mjs', false)->assertSee('Ver PDF');
        $this->get('/visor-pdf?u='.urlencode('https://otro.com/x.pdf'))->assertNotFound();
        $this->get('/visor-pdf?u='.urlencode('//otro.com/x.pdf'))->assertNotFound();

        // Pedro (Ned Tracking) también lo usa.
        $this->actingAs(User::factory()->create(['rol' => 'nedtracking']));
        $this->get('/visor-pdf?u='.urlencode('/ned/cotizaciones/1/pdf'))->assertOk();
    }
}

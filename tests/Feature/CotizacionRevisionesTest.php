<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\Editor;
use App\Livewire\Cotizaciones\EnviarCorreo;
use App\Models\Cotizacion;
use App\Models\CotizacionCambio;
use App\Models\CotizacionPartida;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\CotizacionPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class CotizacionRevisionesTest extends TestCase
{
    use RefreshDatabase;

    private User $rosa;

    protected function setUp(): void
    {
        parent::setUp();
        config(['masume.cuentas_correo.ventas.usuario' => 'ventas@ejemplo.com']);
        $this->rosa = User::factory()->create(['name' => 'Rosa', 'cuenta_correo' => 'ventas']);
        $this->actingAs($this->rosa);
    }

    private function cotizacion(): Cotizacion
    {
        $cot = Cotizacion::factory()->create(['vendedor_id' => $this->rosa->id]);
        CotizacionPartida::factory()->create([
            'cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'LLAVE AJUSTABLE', 'cantidad' => 5,
            'precio' => 100, 'importe' => 500,
        ]);

        return $cot->fresh();
    }

    private function enviar(Cotizacion $cot): void
    {
        Mail::fake();
        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])
            ->call('abrir')->set('para', 'cliente@example.com')->call('enviar')->assertHasNoErrors();
    }

    public function test_borrador_sin_envio_no_registra(): void
    {
        $cot = $this->cotizacion();
        $cot->partidas()->first()->update(['cantidad' => 8]);
        $cot->partidas()->create(['orden' => 2, 'descripcion' => 'X', 'cantidad' => 1, 'precio' => 1, 'importe' => 1]);
        $cot->update(['notas' => 'algo']);

        $this->assertSame(0, CotizacionCambio::count());
        $this->assertSame(0, $cot->fresh()->revision);
    }

    public function test_el_envio_no_registra_su_propio_cambio_de_estado(): void
    {
        $cot = $this->cotizacion();
        $this->enviar($cot);

        $this->assertSame(0, CotizacionCambio::count());
        $this->assertSame(0, $cot->fresh()->revision);
        $this->assertSame(0, $cot->envios()->first()->revision);
    }

    public function test_cambio_tras_envio_registra_y_sube_a_rev_1_una_sola_vez(): void
    {
        $cot = $this->cotizacion();
        $this->enviar($cot);
        $partida = $cot->partidas()->first();

        $partida->update(['cantidad' => 8]);
        $partida->update(['precio' => 120, 'importe' => 960]);

        $this->assertSame(1, $cot->fresh()->revision);
        $this->assertSame(1, CotizacionCambio::where('accion', 'revision')->count());
        $c = CotizacionCambio::where('campo', 'cantidad')->first();
        $this->assertSame('partida_editada', $c->accion);
        $this->assertSame($this->rosa->id, $c->user_id);
        $this->assertSame('5', $c->valor_anterior);
        $this->assertSame('8', $c->valor_nuevo);
        $this->assertStringContainsString('Partida 1 (LLAVE AJUSTABLE): cantidad 5 → 8', $c->texto());
        $this->assertNull(CotizacionCambio::where('campo', 'importe')->first());
    }

    public function test_valores_equivalentes_y_derivados_no_son_cambio(): void
    {
        $cot = $this->cotizacion();
        $this->enviar($cot);

        $cot->partidas()->first()->update(['cantidad' => '5.000', 'orden' => 3]);
        $cot->recalcularTotales();

        $this->assertSame(0, CotizacionCambio::count());
        $this->assertSame(0, $cot->fresh()->revision);
    }

    public function test_agregar_eliminar_y_proveedor_por_nombre(): void
    {
        $cot = $this->cotizacion();
        $this->enviar($cot);

        $nueva = $cot->partidas()->create(['orden' => 2, 'descripcion' => 'MARTILLO', 'cantidad' => 1, 'precio' => 1, 'importe' => 1]);
        $nueva->update(['proveedor_id' => Proveedor::factory()->create(['nombre' => 'DISNOR'])->id]);
        $nueva->delete();

        $acciones = CotizacionCambio::orderBy('id')->pluck('accion')->all();
        $this->assertSame(['revision', 'partida_agregada', 'partida_editada', 'partida_eliminada'], $acciones);
        $this->assertSame('DISNOR', CotizacionCambio::where('campo', 'proveedor')->first()->valor_nuevo);
        $this->assertStringContainsString('agregó la partida 2: MARTILLO', CotizacionCambio::where('accion', 'partida_agregada')->first()->texto());
        $this->assertStringContainsString('eliminó la partida 2', CotizacionCambio::where('accion', 'partida_eliminada')->first()->texto());
    }

    public function test_nuevo_envio_guarda_revision_y_siguiente_cambio_sube_a_rev_2(): void
    {
        $cot = $this->cotizacion();
        $this->enviar($cot);
        $cot->partidas()->first()->update(['cantidad' => 8]);

        $this->enviar($cot->fresh());
        $this->assertSame(1, $cot->envios()->reorder('id', 'desc')->first()->revision);
        $this->assertSame(1, $cot->fresh()->revision);

        $cot->partidas()->first()->update(['cantidad' => 9]);
        $this->assertSame(2, $cot->fresh()->revision);
        $this->assertSame(2, CotizacionCambio::where('accion', 'revision')->count());

        Mail::fake();
        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])
            ->call('abrir')->assertSet('asunto', "Cotización {$cot->folio} Rev. 2 - MASUME Industrial");
    }

    public function test_encabezado_y_estado(): void
    {
        $cot = $this->cotizacion();
        $this->enviar($cot);

        $cot->fresh()->update(['estado' => 'aceptada']);
        $this->assertSame(0, $cot->fresh()->revision);   // el estado se anota pero no abre revisión
        $this->assertSame('estado', CotizacionCambio::first()->accion);

        $cot->fresh()->update(['forma_pago' => 'Crédito 30 días']);
        $this->assertSame(1, $cot->fresh()->revision);
        $this->assertSame('Crédito 30 días', CotizacionCambio::where('campo', 'forma_pago')->first()->valor_nuevo);
    }

    public function test_editor_muestra_historial_y_revision(): void
    {
        $cot = $this->cotizacion();
        $this->enviar($cot);
        $cot->partidas()->first()->update(['cantidad' => 8]);

        Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->assertSee('Historial de cambios (2)')
            ->assertSee('Rev. 1')
            ->assertSee('Rev. 1 iniciada')
            ->assertSee('Rosa')
            ->assertSee('cantidad 5 → 8')
            ->assertSee('Enviado');
    }

    public function test_editar_desde_el_editor_registra(): void
    {
        $cot = $this->cotizacion();
        $this->enviar($cot);

        Livewire::test(Editor::class, ['cotizacion' => $cot])->set('partidas.0.cantidad', '12');

        $c = CotizacionCambio::where('campo', 'cantidad')->first();
        $this->assertSame('12', $c->valor_nuevo);
        $this->assertSame(1, $cot->fresh()->revision);
    }

    public function test_pdf_muestra_revision_y_nombre_de_archivo(): void
    {
        $cot = $this->cotizacion();
        $this->assertStringNotContainsString('Rev.', CotizacionPdf::html($cot));
        $this->assertStringNotContainsString('_Rev', $cot->nombreArchivoPdf());

        $cot->forceFill(['revision' => 1])->save();
        $cot = $cot->fresh();

        $this->assertStringContainsString("No. {$cot->folio} · Rev. 1", CotizacionPdf::html($cot));
        $this->assertStringContainsString("Cotización {$cot->folio} · Rev. 1", CotizacionPdf::pie($cot));
        $this->assertStringContainsString("_Cotizacion_{$cot->folio}_Rev1_", $cot->nombreArchivoPdf());
    }

    public function test_ir_a_folio(): void
    {
        $cot = $this->cotizacion();

        $this->get(route('cotizaciones.folio', $cot->folio))->assertRedirect(route('cotizaciones.editar', $cot));

        $this->from(route('cotizaciones.index'))->get(route('cotizaciones.folio', 99999999))
            ->assertRedirect(route('cotizaciones.index'))
            ->assertSessionHas('error', 'No existe la cotización 99999999.');

        $this->get(route('cotizaciones.index'))->assertSee('Ir a folio');
    }

    public function test_importador_no_genera_cambios(): void
    {
        User::factory()->create(['rol' => 'admin']);
        $ruta = tempnam(sys_get_temp_dir(), 'reg');
        file_put_contents($ruta, json_encode([
            'consecutivo' => 9100,
            'clientes' => [['n' => 1, 'nombre' => 'PROVEX', 'direccion' => '', 'contacto' => '', 'correo' => '']],
            'hdr' => [['cliente' => 'PROVEX', 'responsable' => 'Rosa', 'uid' => '9000', 'fecha' => '26/06/2026', 'link_pdf' => '', 'id_cot' => '', 'formato' => '', 'status' => '']],
            'det' => [['uid_linea' => '9000.01', 'uid' => 9000, 'cantidad' => 1, 'descripcion' => 'A', 'um' => 'PZA', 'proveedor' => 'DISNOR', 'vendedor_prov' => '', 'modelo' => '', 'costo_sin' => 10, 'costo_con' => 11.6, 'leadtime' => '', 'utilidad' => 25, 'precio_sin' => 12.5, 'precio_neto' => 14.5, 'status' => 'COTIZADO']],
        ]));
        Cotizacion::factory()->create(['folio' => 5, 'enviada_at' => now()]);

        $this->artisan('masume:importar-registro', ['archivo' => $ruta])->assertSuccessful();

        $this->assertSame(0, CotizacionCambio::count());
        $this->assertSame(0, Cotizacion::where('folio', 9000)->first()->revision);
    }

    public function test_la_bitacora_es_solo_insercion(): void
    {
        $cot = $this->cotizacion();
        $c = CotizacionCambio::create(['cotizacion_id' => $cot->id, 'accion' => 'encabezado', 'campo' => 'notas']);

        $c->update(['valor_nuevo' => 'x']);
        $c->delete();

        $this->assertNull($c->fresh()->valor_nuevo);
        $this->assertSame(1, CotizacionCambio::count());
    }
}

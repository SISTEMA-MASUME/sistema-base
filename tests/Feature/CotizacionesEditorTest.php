<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\Editor;
use App\Livewire\Cotizaciones\Index;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CotizacionesEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['iniciales' => 'ALBY']);
        $this->actingAs($this->user);
    }

    private function cotizacion(): Cotizacion
    {
        return Cotizacion::factory()->create(['vendedor_id' => $this->user->id]);
    }

    public function test_al_elegir_cliente_crea_borrador_con_folio(): void
    {
        $cliente = Cliente::factory()->create();

        Livewire::test(Editor::class)->call('seleccionarCliente', $cliente->id)->assertRedirect();

        $cot = Cotizacion::first();
        $this->assertNotNull($cot->folio);
        $this->assertSame($cliente->id, $cot->cliente_id);
        $this->assertSame($this->user->id, $cot->vendedor_id);
        $this->assertSame('borrador', $cot->estado->value);
    }

    public function test_pantallas_responden(): void
    {
        $cot = $this->cotizacion();
        $this->get('/cotizaciones')->assertOk();
        $this->get('/cotizaciones/nueva')->assertOk();
        $this->get('/cotizaciones/'.$cot->id)->assertOk()->assertSee((string) $cot->folio);
    }

    public function test_precio_desde_costo_y_utilidad_y_totales(): void
    {
        $cot = $this->cotizacion();
        $c = Livewire::test(Editor::class, ['cotizacion' => $cot])->call('agregarPartida');

        $c->set('partidas.0.cantidad', '2')
            ->set('partidas.0.costo_sin', '100')
            ->set('partidas.0.utilidad_pct', '30')
            ->assertSet('partidas.0.precio', '130.00');

        $p = CotizacionPartida::first();
        $this->assertEquals(100, $p->costo);
        $this->assertEquals(130, $p->precio);
        $this->assertEquals(260, $p->importe);

        $cot->refresh();
        $this->assertEquals(260, $cot->subtotal);
        $this->assertEquals(301.6, $cot->total);
        $c->assertSee('$260.00');
    }

    public function test_costo_con_iva_se_guarda_sin_iva(): void
    {
        $cot = $this->cotizacion();
        $c = Livewire::test(Editor::class, ['cotizacion' => $cot])->call('agregarPartida');

        $c->set('partidas.0.costo_con', '116')->assertSet('partidas.0.costo_sin', '100.00');
        $this->assertEquals(100, CotizacionPartida::first()->costo);
    }

    public function test_precio_calcula_utilidad(): void
    {
        $cot = $this->cotizacion();
        $c = Livewire::test(Editor::class, ['cotizacion' => $cot])->call('agregarPartida');

        $c->set('partidas.0.costo_sin', '100')->set('partidas.0.precio', '150')->assertSet('partidas.0.utilidad_pct', '50');
        $this->assertEquals(50, CotizacionPartida::first()->utilidad_pct);
    }

    public function test_excluir_partida_saca_del_total(): void
    {
        $cot = $this->cotizacion();
        $c = Livewire::test(Editor::class, ['cotizacion' => $cot])->call('agregarPartida')->call('agregarPartida');
        $c->set('partidas.0.precio', '100')->set('partidas.1.precio', '50');
        $this->assertEquals(150, $cot->fresh()->subtotal);

        $c->set('partidas.1.incluir', false);
        $this->assertEquals(100, $cot->fresh()->subtotal);
        $this->assertEquals(116, $cot->fresh()->total);
    }

    public function test_duplicar_cotizacion(): void
    {
        $cot = $this->cotizacion();
        CotizacionPartida::factory()->count(2)->create(['cotizacion_id' => $cot->id]);

        Livewire::test(Editor::class, ['cotizacion' => $cot])->call('duplicarCotizacion')->assertRedirect();

        $this->assertSame(2, Cotizacion::count());
        $nueva = Cotizacion::where('id', '!=', $cot->id)->first();
        $this->assertNotEquals($cot->folio, $nueva->folio);
        $this->assertSame(2, $nueva->partidas()->count());
        $this->assertSame($cot->cliente_id, $nueva->cliente_id);
    }

    public function test_alta_rapida_de_cliente_sin_duplicados(): void
    {
        Cliente::factory()->create(['nombre' => 'Provex']);

        Livewire::test(Editor::class)
            ->set('nuevoCliente', 'PROVEX.')
            ->call('altaCliente')
            ->assertHasErrors('nuevoCliente');
        $this->assertSame(1, Cliente::count());
        $this->assertSame(0, Cotizacion::count());

        Livewire::test(Editor::class)->set('nuevoCliente', 'Cliente Nuevo')->call('altaCliente')->assertRedirect();
        $this->assertSame(2, Cliente::count());
        $this->assertSame('CLIENTE NUEVO', Cotizacion::first()->cliente->nombre);
    }

    public function test_proveedor_nuevo_se_crea_al_vuelo_y_no_se_duplica(): void
    {
        $existente = Proveedor::factory()->create(['nombre' => 'Truper']);
        $cot = $this->cotizacion();
        $c = Livewire::test(Editor::class, ['cotizacion' => $cot])->call('agregarPartida');

        $c->set('partidas.0.proveedor', 'truper.');
        $this->assertSame($existente->id, CotizacionPartida::first()->proveedor_id);

        $c->set('partidas.0.proveedor', 'Proveedor Nuevo');
        $this->assertSame('PROVEEDOR NUEVO', CotizacionPartida::first()->proveedor->nombre);
        $this->assertSame(2, Proveedor::count());
    }

    public function test_subir_imagen_redimensiona(): void
    {
        Storage::fake('public');
        $cot = $this->cotizacion();
        $c = Livewire::test(Editor::class, ['cotizacion' => $cot])->call('agregarPartida');

        $c->set('imagenes.0', UploadedFile::fake()->image('foto.jpg', 1600, 1200))->assertHasNoErrors();

        $ruta = CotizacionPartida::first()->imagen_path;
        $this->assertNotNull($ruta);
        Storage::disk('public')->assertExists($ruta);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($ruta));
        $this->assertSame(800, $w);
        $this->assertSame(600, $h);

        $c->call('quitarImagen', 0);
        Storage::disk('public')->assertMissing($ruta);
        $this->assertNull(CotizacionPartida::first()->imagen_path);
    }

    public function test_rechaza_archivo_no_imagen(): void
    {
        Storage::fake('public');
        $cot = $this->cotizacion();
        $c = Livewire::test(Editor::class, ['cotizacion' => $cot])->call('agregarPartida');

        $c->set('imagenes.0', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'))->assertHasErrors('imagenes.0');
        $this->assertNull(CotizacionPartida::first()->imagen_path);
    }

    public function test_reordenar_partidas(): void
    {
        $cot = $this->cotizacion();
        $c = Livewire::test(Editor::class, ['cotizacion' => $cot])->call('agregarPartida')->call('agregarPartida');
        $c->set('partidas.0.descripcion', 'PRIMERA')->set('partidas.1.descripcion', 'SEGUNDA');

        $c->call('subir', 1)->assertSet('partidas.0.descripcion', 'SEGUNDA');
        $this->assertSame(['SEGUNDA', 'PRIMERA'], $cot->partidas()->pluck('descripcion')->all());
    }

    public function test_encabezado_y_estado_se_guardan(): void
    {
        $cot = $this->cotizacion();
        Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->set('enc.forma_pago', 'Contado')->set('enc.estado', 'enviada');

        $this->assertSame('Contado', $cot->fresh()->forma_pago);
        $this->assertSame('enviada', $cot->fresh()->estado->value);
    }

    public function test_ruta_pdf_responde_pdf(): void
    {
        $cot = $this->cotizacion();
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id]);

        $r = $this->get(route('cotizaciones.pdf', $cot))->assertOk();
        $this->assertStringContainsString('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $r->headers->get('Content-Disposition'));

        $this->get(route('cotizaciones.pdf', ['cotizacion' => $cot, 'descargar' => 1]))
            ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="'.$cot->fresh()->nombreArchivoPdf().'"');
    }

    public function test_filtros_de_la_lista(): void
    {
        $otro = User::factory()->create();
        $a = Cliente::factory()->create(['nombre' => 'ALFA SA']);
        $b = Cliente::factory()->create(['nombre' => 'BETA SA']);
        $c1 = Cotizacion::factory()->create(['cliente_id' => $a->id, 'vendedor_id' => $this->user->id, 'fecha' => '2026-01-10']);
        $c2 = Cotizacion::factory()->create(['cliente_id' => $b->id, 'vendedor_id' => $otro->id, 'fecha' => '2026-03-10', 'estado' => 'enviada']);

        $folios = fn ($t) => $t->viewData('cotizaciones')->pluck('folio')->sort()->values()->all();
        $t = Livewire::test(Index::class);
        $this->assertSame([$c1->folio, $c2->folio], $folios($t));

        $t->set('vendedor', (string) $otro->id);
        $this->assertSame([$c2->folio], $folios($t));
        $t->set('vendedor', '')->set('estado', 'enviada');
        $this->assertSame([$c2->folio], $folios($t));
        $t->set('estado', '')->set('cliente', (string) $a->id);
        $this->assertSame([$c1->folio], $folios($t));
        $t->set('cliente', '')->set('desde', '2026-02-01');
        $this->assertSame([$c2->folio], $folios($t));
        $t->set('desde', '')->set('hasta', '2026-02-01');
        $this->assertSame([$c1->folio], $folios($t));
        $t->call('limpiar')->set('buscar', (string) $c2->folio);
        $this->assertSame([$c2->folio], $folios($t));
        $t->set('buscar', 'alfa');
        $this->assertSame([$c1->folio], $folios($t));
    }

    public function test_lista_busca_por_articulo_o_modelo(): void
    {
        $c1 = $this->cotizacion();
        $c2 = $this->cotizacion();
        $c3 = $this->cotizacion();
        $c1->partidas()->create(['orden' => 1, 'descripcion' => 'Silicón para altas temperaturas', 'modelo' => 'SPA-2800', 'unidad' => 'PZA', 'cantidad' => 3, 'precio' => 10, 'importe' => 30, 'incluir' => true]);
        $c2->partidas()->create(['orden' => 1, 'descripcion' => 'Bomba SPA2800 repuesto', 'unidad' => 'PZA', 'cantidad' => 1, 'precio' => 10, 'importe' => 10, 'incluir' => true]);
        $c3->partidas()->create(['orden' => 1, 'descripcion' => 'Guantes de nitrilo', 'modelo' => 'G-100', 'unidad' => 'CAJA', 'cantidad' => 2, 'precio' => 10, 'importe' => 20, 'incluir' => true]);

        $folios = fn ($t) => $t->viewData('cotizaciones')->pluck('folio')->sort()->values()->all();
        $t = Livewire::test(Index::class);
        foreach (['SPA2800', 'spa-2800', 'SPA 2800'] as $busqueda) {
            $t->set('buscar', $busqueda);
            $this->assertSame([$c1->folio, $c2->folio], $folios($t), $busqueda);
        }
        $t->assertSee('Silicón para altas temperaturas')->assertSee('SPA-2800')->assertDontSee('Guantes de nitrilo');

        $t->set('buscar', 'nitrilo guantes');
        $this->assertSame([$c3->folio], $folios($t));
        $t->set('buscar', (string) $c3->folio);
        $this->assertSame([$c3->folio], $folios($t));
        // Sin buscar artículo no se listan partidas debajo del cliente.
        $t->set('buscar', '')->assertDontSee('Guantes de nitrilo');
    }

    public function test_lista_ordenada_por_fecha_las_mas_recientes_arriba(): void
    {
        $vieja = Cotizacion::factory()->create(['folio' => 95000, 'vendedor_id' => $this->user->id, 'fecha' => '2026-01-10']);
        $nueva = Cotizacion::factory()->create(['folio' => 1200, 'vendedor_id' => $this->user->id, 'fecha' => '2026-09-30']);

        $this->assertSame([$nueva->id, $vieja->id], Livewire::test(Index::class)->viewData('cotizaciones')->pluck('id')->all());
    }

    public function test_lista_pagina_de_25_en_25(): void
    {
        Cotizacion::factory()->count(27)->create(['vendedor_id' => $this->user->id]);
        $this->assertSame(25, Livewire::test(Index::class)->viewData('cotizaciones')->count());
    }
}

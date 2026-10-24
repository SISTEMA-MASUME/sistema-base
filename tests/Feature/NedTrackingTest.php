<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\Editor as EditorMasume;
use App\Livewire\Ned\Datos;
use App\Livewire\Ned\Editor;
use App\Livewire\Ned\Index;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Ned\NedCotizacion;
use App\Models\Ned\NedDatos;
use App\Models\User;
use App\Services\Ned\NedCotizacionPdf;
use App\Support\ListaPartidas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Módulo de cotizaciones de Ned Tracking (usuario externo) y "pedir cotización sin precios" desde MASUME. */
class NedTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function pedro(): User
    {
        $u = User::factory()->create(['rol' => 'nedtracking', 'name' => 'Pedro Ramírez']);
        $this->actingAs($u);

        return $u;
    }

    public function test_el_usuario_de_ned_solo_ve_su_modulo(): void
    {
        $this->pedro();
        $this->get('/')->assertRedirect(route('ned.index'));
        $this->get(route('ned.index'))->assertOk()->assertSee('Mis cotizaciones')->assertDontSee('Cotizaciones</a>', false);
        foreach (['/cotizaciones', '/pedidos', '/clientes', '/proveedores', '/ordenes-proveedor', '/facturacion'] as $ruta) {
            $this->get($ruta)->assertRedirect(route('ned.index'));
        }
    }

    public function test_vendedores_y_campo_no_entran_a_ned_pero_el_admin_si(): void
    {
        foreach (['vendedor', 'campo'] as $rol) {
            $this->actingAs(User::factory()->create(['rol' => $rol]));
            $this->get(route('ned.index'))->assertForbidden();
        }
        Livewire::test(Index::class)->assertForbidden();

        // Daniel (admin) entra a Ned sin cambiar de sesión y sigue viendo MASUME.
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $this->get(route('ned.index'))->assertOk()->assertSee('Nueva cotización')->assertSee('Datos y logo de Ned');
        $this->get('/cotizaciones')->assertOk();
    }

    public function test_el_admin_pasa_una_cotizacion_de_masume_a_ned_y_aplica_su_utilidad(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $cot = Cotizacion::factory()->create(['cliente_id' => Cliente::factory()->create(['nombre' => 'CENTRAL'])->id]);
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'CUCHILLAS SK4 18 MM',
            'cantidad' => 20, 'unidad' => 'PZA', 'costo' => 12.3456, 'precio' => 31.11, 'importe' => 622.20]);
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 2, 'descripcion' => 'NO INCLUIDA', 'incluir' => false]);

        Livewire::test(EditorMasume::class, ['cotizacion' => $cot])->assertSee('Pasar a Ned Tracking')
            ->call('pasarANed')->assertRedirect();

        $ned = NedCotizacion::firstOrFail();
        $this->assertSame('HOTEL CENTRAL', $ned->cliente_nombre);   // no se pasa el de MASUME: va el predeterminado de Ned
        $this->assertSame('SOFÍA LUNA', $ned->cliente_contacto);
        $this->assertEquals(0, (float) $ned->total);
        $this->assertSame([['CUCHILLAS SK4 18 MM', 20.0, 'PZA', 0.0]],
            $ned->partidas->map(fn ($p) => [$p->descripcion, (float) $p->cantidad, $p->unidad, (float) $p->precio])->all());
        $this->assertEquals(12.3456, (float) $ned->partidas->first()->costo);   // el costo sí pasa (interno)

        // Daniel aplica 40% de utilidad a todas: precio = costo × 1.40.
        Livewire::test(Editor::class, ['cotizacion' => $ned])
            ->assertSet('partidas.0.precio', '')->assertSet('partidas.0.costo', '12.3456')
            ->assertSee('Utilidad para todas las partidas')
            ->set('utilidad', '40')->call('aplicarUtilidad')->assertHasNoErrors()
            ->assertSet('partidas.0.precio', '17.28')->assertSee('aplicada a 1 partidas');
        $ned->refresh();
        $this->assertEquals(17.28, (float) $ned->partidas->first()->precio);
        $this->assertEquals(345.60, (float) $ned->subtotal);   // 20 × 17.28
        $this->assertStringNotContainsString('12.34', NedCotizacionPdf::html($ned));   // el costo nunca sale en el PDF

        // Pedro no ve el costo ni el selector, y al guardar no se pierde el costo.
        $this->pedro();
        Livewire::test(Editor::class, ['cotizacion' => $ned])
            ->assertSet('partidas.0.costo', '')->assertDontSee('Utilidad para todas las partidas')
            ->set('partidas.0.precio', '18')->call('guardar')->assertHasNoErrors()
            ->call('aplicarUtilidad')->assertForbidden();
        $this->assertEquals(12.3456, (float) $ned->partidas()->first()->costo);
        $this->assertEquals(18, (float) $ned->partidas()->first()->precio);

        // La de MASUME queda igual.
        $this->assertEquals(31.11, (float) $cot->partidas()->first()->precio);

        // Un vendedor sin acceso a Ned no ve el botón ni puede hacerlo.
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $cot->refresh();
        Livewire::test(EditorMasume::class, ['cotizacion' => $cot])->assertDontSee('Pasar a Ned Tracking')
            ->call('pasarANed')->assertForbidden();
    }

    public function test_vendedor_con_acceso_a_ned_pasa_cotizaciones_y_ve_el_costo(): void
    {
        $rosa = User::factory()->create(['rol' => 'vendedor', 'name' => 'Rosa Ruiz', 'acceso_ned' => true]);
        $this->actingAs($rosa);
        $this->get(route('ned.index'))->assertOk();
        $this->get('/cotizaciones')->assertOk()->assertSee('href="'.url('/ned').'"', false);

        $cot = Cotizacion::factory()->create();
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'descripcion' => 'GUANTES', 'cantidad' => 10, 'costo' => 50]);
        Livewire::test(EditorMasume::class, ['cotizacion' => $cot])->assertSee('Pasar a Ned Tracking')->call('pasarANed')->assertRedirect();
        Livewire::test(Editor::class, ['cotizacion' => NedCotizacion::firstOrFail()])
            ->assertSet('partidas.0.costo', '50')
            ->set('utilidad', '40')->call('aplicarUtilidad')->assertSet('partidas.0.precio', '70.00');

        // Otro vendedor sin acceso no entra.
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $this->get(route('ned.index'))->assertForbidden();
        $this->get('/cotizaciones')->assertOk()->assertDontSee('href="'.url('/ned').'"', false);
    }

    public function test_el_admin_da_o_quita_el_acceso_a_ned_desde_usuarios(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        $this->actingAs($admin);
        $alberto = User::factory()->create(['rol' => 'vendedor', 'name' => 'Alberto Ramos']);
        Livewire::test(\App\Livewire\Usuarios\Index::class)
            ->call('editar', $alberto->id)->assertSee('Acceso a Ned Tracking')
            ->set('acceso_ned', true)->call('guardar')->assertHasNoErrors();
        $this->assertTrue($alberto->fresh()->puedeUsarNed());

        // Campo nunca, aunque se marque.
        $hugo = User::factory()->create(['rol' => 'campo']);
        Livewire::test(\App\Livewire\Usuarios\Index::class)->call('editar', $hugo->id)->set('acceso_ned', true)->call('guardar');
        $this->assertFalse($hugo->fresh()->puedeUsarNed());
    }

    public function test_mario_captura_sus_partidas_y_sus_precios_y_genera_su_pdf(): void
    {
        $this->pedro();
        Livewire::test(Index::class)->call('nueva');
        $c = NedCotizacion::firstOrFail();
        $this->assertSame('NT-0001', $c->folioTexto());

        // Pega la lista que le mandaron por WhatsApp (sin precios) y pone los suyos.
        $lista = "Hola, ¿me puedes cotizar lo siguiente?\n\n1) 20 PZA | CUCHILLAS SK4 18 MM PARA CUTTER TRUPER (10PZ) | Marca: TRUPER\n2) 3 JGO | LLAVES ALLEN\n\nGracias.";
        Livewire::test(Editor::class, ['cotizacion' => $c])
            ->set('cliente_nombre', 'Servicios Turisticos Ejemplo')
            ->set('lista', $lista)->call('pegarLista')
            ->assertSee('2 partidas agregadas')
            ->assertSet('partidas.0.cantidad', '20')->assertSet('partidas.0.precio', '')
            ->set('partidas.0.precio', '35')->set('partidas.1.precio', '120.50')
            ->call('guardar')->assertHasNoErrors();

        $c->refresh();
        $this->assertSame('SERVICIOS TURISTICOS EJEMPLO', $c->cliente_nombre);
        $this->assertEquals(1061.50, (float) $c->subtotal);   // 20×35 + 3×120.50
        $this->assertEquals(1231.34, (float) $c->total);
        $this->assertSame('CUCHILLAS SK4 18 MM PARA CUTTER TRUPER (10PZ) | MARCA: TRUPER', $c->partidas()->first()->descripcion);

        $html = NedCotizacionPdf::html($c);
        $this->assertStringContainsString('NOMBRE DEL TITULAR', $html);
        $this->assertStringContainsString('BBBB010101BB2', NedCotizacionPdf::generar($c) ? 'BBBB010101BB2' : '');
        $this->assertStringNotContainsString('MASUME', preg_replace('/src="[^"]*"/', '', $html));   // fuera de la ruta del logo
        $this->get(route('ned.pdf', $c))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_cotizacion_nueva_trae_a_hotel_central_y_sus_dos_contactos(): void
    {
        $this->pedro();
        Livewire::test(Index::class)->call('nueva');
        $c = NedCotizacion::firstOrFail();
        $this->assertSame('HOTEL CENTRAL', $c->cliente_nombre);
        $this->assertSame('SOFÍA LUNA', $c->cliente_contacto);

        Livewire::test(Editor::class, ['cotizacion' => $c])
            ->assertSee('LAURA MARTÍNEZ')
            ->call('$set', 'cliente_contacto', 'LAURA MARTÍNEZ')->call('guardar')->assertHasNoErrors();
        $this->assertSame('LAURA MARTÍNEZ', $c->fresh()->cliente_contacto);
    }

    public function test_mario_edita_sus_datos_y_logo(): void
    {
        Storage::fake('local');
        $this->pedro();
        // Sin subir nada ya sale el logo de Ned Tracking de fábrica.
        $this->get(route('ned.logo'))->assertOk();
        $this->assertStringContainsString('logo-ned-tracking.png', NedCotizacionPdf::html(NedCotizacion::create(['folio' => 99, 'cliente_nombre' => 'X', 'fecha' => now(), 'vigencia_dias' => 15])));
        Livewire::test(Datos::class)
            ->assertSet('rfc', 'BBBB010101BB2')
            ->set('telefono', '3300000000')->call('guardar')->assertHasNoErrors()
            ->set('logo', UploadedFile::fake()->image('logo.png', 400, 120))->assertSee('Logo actualizado');
        $this->assertSame('3300000000', NedDatos::actuales()->telefono);
        Storage::disk('local')->assertExists(NedDatos::actuales()->logo_path);
        $this->get(route('ned.logo'))->assertOk();
    }

    public function test_mario_cambia_el_consecutivo_y_el_numero_de_una_cotizacion(): void
    {
        $this->pedro();
        Livewire::test(Index::class)->call('nueva');
        $primera = NedCotizacion::firstOrFail();   // NT-0001

        // Sigue su numeración anterior: la próxima será la 250.
        Livewire::test(Datos::class)->assertSet('proximoFolio', 2)
            ->set('proximoFolio', '1')->call('guardarFolio')->assertHasErrors('proximoFolio')   // ya existe
            ->set('proximoFolio', '250')->call('guardarFolio')->assertHasNoErrors()->assertSee('NT-0250');
        Livewire::test(Index::class)->call('nueva');
        $this->assertTrue(NedCotizacion::where('folio', 250)->exists());

        // Cambia el número de una cotización ya hecha; no deja repetir.
        Livewire::test(Editor::class, ['cotizacion' => $primera])
            ->set('folio', '250')->call('guardar')->assertHasErrors('folio')
            ->set('folio', '251')->call('guardar')->assertHasNoErrors()->assertSee('NT-0251');
        $this->assertSame(251, (int) $primera->fresh()->folio);

        // La siguiente nueva se salta la 251 que ya se usó a mano.
        Livewire::test(Index::class)->call('nueva');
        $this->assertTrue(NedCotizacion::where('folio', 252)->exists());
    }

    public function test_masume_pide_cotizacion_sin_precios(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $cot = Cotizacion::factory()->create(['cliente_id' => Cliente::factory()->create(['nombre' => 'CENTRAL'])->id]);
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'CUCHILLAS SK4', 'cantidad' => 20,
            'unidad' => 'PZA', 'marca' => 'TRUPER', 'costo' => 12.3456, 'precio' => 31.11, 'importe' => 622.20]);
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 2, 'descripcion' => 'NO INCLUIDA', 'incluir' => false]);

        $lista = Livewire::test(EditorMasume::class, ['cotizacion' => $cot])
            ->assertDontSee('Pedir cotización a otro proveedor (sin precios)')->assertSee('Copiar lista sin precios')->viewData('listaSinPrecios');
        $this->assertSame('1) 20 PZA | CUCHILLAS SK4 | Marca: TRUPER', $lista);   // solo las incluidas

        // El texto nunca lleva costos ni precios.
        $texto = ListaPartidas::texto($cot->partidasIncluidas()->get()->toArray());
        $this->assertStringNotContainsString('31.11', $texto);
        $this->assertStringNotContainsString('12.34', $texto);
        $this->assertSame([['cantidad' => 20.0, 'unidad' => 'PZA', 'descripcion' => 'CUCHILLAS SK4 | Marca: TRUPER']], ListaPartidas::interpretar($texto));
    }

    public function test_interpreta_listas_de_excel_y_renglones_sueltos(): void
    {
        $this->assertSame(
            [
                ['cantidad' => 5.0, 'unidad' => 'MT', 'descripcion' => 'CABLE CALIBRE 12'],
                ['cantidad' => 2.0, 'unidad' => 'PZA', 'descripcion' => 'GUANTES DE CARNAZA'],
                ['cantidad' => 1.0, 'unidad' => 'PZA', 'descripcion' => 'DESARMADOR PLANO'],
            ],
            ListaPartidas::interpretar("5\tMT\tCABLE CALIBRE 12\n2 PZA - GUANTES DE CARNAZA\nDESARMADOR PLANO"),
        );
    }

    public function test_la_lista_de_ned_tiene_boton_para_mandar_el_pdf(): void
    {
        $this->pedro();
        $c = NedCotizacion::create(['folio' => 7, 'cliente_nombre' => 'HOTEL CENTRAL', 'fecha' => now(), 'vigencia_dias' => 15]);

        $this->get(route('ned.index'))->assertOk()->assertSee('Mandar PDF')->assertSee('ned\\/cotizaciones\\/'.$c->id.'\\/pdf', false);
    }
}

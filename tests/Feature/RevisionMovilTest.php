<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\Editor;
use App\Livewire\Cotizaciones\Revision;
use App\Livewire\Cotizaciones\RevisionMovil;
use App\Models\Cotizacion;
use App\Models\CotizacionCambio;
use App\Models\CotizacionPartida;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RevisionMovilTest extends TestCase
{
    use RefreshDatabase;

    private function cotizacion(): array
    {
        $usuario = User::factory()->create(['name' => 'Daniel', 'rol' => 'admin']);
        $this->actingAs($usuario);
        $cot = Cotizacion::factory()->create(['vendedor_id' => $usuario->id, 'revision_pedida_at' => now()]);
        $p = CotizacionPartida::factory()->create([
            'cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'Descripción completa para revisión',
            'proveedor_id' => Proveedor::factory()->create(['nombre' => 'TRUPER'])->id,
            'cantidad' => 2, 'costo' => 100, 'utilidad_pct' => 30, 'precio' => 130, 'importe' => 260, 'incluir' => true,
        ]);
        $cot->recalcularTotales();

        return [$cot->fresh(), $p];
    }

    public function test_whatsapp_abre_tarjetas_y_la_captura_conserva_su_editor(): void
    {
        [$cot] = $this->cotizacion();
        $url = Livewire::test(Revision::class, ['cotizacionId' => $cot->id])->call('pedirRevision')->effects['returns'][0];
        $this->assertStringContainsString(route('cotizaciones.revision', $cot), rawurldecode($url));
        $this->get(route('cotizaciones.revision', $cot))->assertOk()
            ->assertSee('TRUPER')->assertSee('Descripción completa para revisión')
            ->assertSee('Utilidad total de la cotización')->assertSee('30%')->assertSee('$60.00');
        $this->get(route('cotizaciones.editar', $cot))->assertOk()->assertSeeLivewire(Editor::class);
    }

    public function test_editar_utilidad_actualiza_importes_totales_y_bitacora_sin_pisar_otros_campos(): void
    {
        [$cot, $p] = $this->cotizacion();
        $pagina = Livewire::test(RevisionMovil::class, ['cotizacion' => $cot]);
        $version = RevisionMovil::version($p);
        $p->update(['descripcion' => 'Descripción corregida en el cotizador']);
        $cot->forceFill(['revisada_at' => now(), 'revisada_por' => auth()->id()])->save();
        $pagina->call('guardarUtilidad', $p->id, '25,5', $version)->assertHasNoErrors()
            ->assertSee('25.5%')->assertSee('$51.00')->assertSee('Descripción corregida en el cotizador')
            ->assertViewHas('totales', fn ($t) => $t['subtotal'] === 251.0 && $t['utilidad'] === 51.0 && $t['total'] === 291.16);
        $this->assertEquals(125.5, (float) $p->fresh()->precio);
        $this->assertEquals(251, (float) $p->fresh()->importe);
        $this->assertEquals(291.16, (float) $cot->fresh()->total);
        $this->assertSame('Descripción corregida en el cotizador', $p->fresh()->descripcion);
        $this->assertFalse($cot->fresh()->estaRevisada());
        $this->assertDatabaseHas('cotizacion_cambios', ['cotizacion_id' => $cot->id, 'campo' => 'utilidad_pct', 'user_id' => auth()->id()]);
        $pagina->call('marcarRevisada')->assertSee('Revisada por Daniel');
        $this->assertTrue($cot->fresh()->estaRevisada());
    }

    public function test_no_pisa_un_costo_o_cantidad_cambiados_desde_otra_pantalla(): void
    {
        [$cot, $p] = $this->cotizacion();
        $pagina = Livewire::test(RevisionMovil::class, ['cotizacion' => $cot]);
        $version = RevisionMovil::version($p);
        Livewire::test(Editor::class, ['cotizacion' => $cot])->call('guardarCampos', $p->id, ['costo_sin' => '200', 'cantidad' => '3']);
        $antes = $p->fresh()->getAttributes();
        $pagina->call('guardarUtilidad', $p->id, '25', $version)->assertHasErrors('utilidades.'.$p->id)
            ->assertSee('Los valores cambiaron en otra pantalla');
        $this->assertSame($antes, $p->fresh()->getAttributes());
        $pagina->call('guardarUtilidad', $p->id, '25', RevisionMovil::version($p->fresh()))->assertHasNoErrors();
        $this->assertEquals(250, (float) $p->fresh()->precio);
        $this->assertEquals(750, (float) $p->fresh()->importe);
    }

    public function test_valores_invalidos_y_partidas_sin_costo_no_guardan(): void
    {
        [$cot, $p] = $this->cotizacion();
        $pagina = Livewire::test(RevisionMovil::class, ['cotizacion' => $cot]);
        $version = RevisionMovil::version($p);
        foreach (['', 'texto', '-101', '100000', '1e309'] as $valor) {
            $pagina->call('guardarUtilidad', $p->id, $valor, $version)->assertHasErrors('utilidades.'.$p->id);
            $this->assertEquals(130, (float) $p->fresh()->precio);
        }
        $p->update(['costo' => null]);
        $pagina->call('guardarUtilidad', $p->id, '25', RevisionMovil::version($p->fresh()))
            ->assertHasErrors('utilidades.'.$p->id)->assertSee('Primero captura el costo')
            ->assertViewHas('totales', fn ($t) => $t['utilidad'] === null && $t['sin_costo'] === 1);
        $this->assertEquals(130, (float) $p->fresh()->precio);
    }

    public function test_los_limites_revierten_el_guardado_y_sus_eventos(): void
    {
        [$cot, $p] = $this->cotizacion();
        $p->update(['costo' => 9000000000, 'precio' => 9000000000, 'cantidad' => 1, 'importe' => 9000000000, 'utilidad_pct' => 0]);
        $cot->recalcularTotales();
        $antes = $p->fresh()->getAttributes();
        $total = $cot->fresh()->total;
        $eventos = CotizacionCambio::count();
        Livewire::test(RevisionMovil::class, ['cotizacion' => $cot])->call('guardarUtilidad', $p->id, '20', RevisionMovil::version($p->fresh()))
            ->assertHasErrors('utilidades.'.$p->id);
        $this->assertSame($antes, $p->fresh()->getAttributes());
        $this->assertSame($total, $cot->fresh()->total);
        $this->assertSame($eventos, CotizacionCambio::count());
    }

    public function test_solo_las_partidas_incluidas_suman_con_iva_y_utilidad_sobre_costo(): void
    {
        [$cot, $p] = $this->cotizacion();
        CotizacionPartida::factory()->create([
            'cotizacion_id' => $cot->id, 'incluir' => false, 'costo' => null, 'precio' => 500, 'importe' => 500,
        ]);
        config(['masume.iva' => 0.08]);
        Livewire::test(RevisionMovil::class, ['cotizacion' => $cot])->assertSee('Fuera de la cotización')
            ->assertViewHas('totales', fn ($t) => $t['incluidas'] === 1 && $t['sin_costo'] === 0 && $t['utilidad_pct'] === 30.0 && $t['total'] === 280.8);
        Livewire::test(RevisionMovil::class, ['cotizacion' => $cot])->call('guardarUtilidad', $p->id, '0', RevisionMovil::version($p))
            ->assertHasNoErrors()->assertSee('0%');
        $this->assertEquals(100, (float) $p->fresh()->precio);
        $this->assertEquals(216, (float) $cot->fresh()->total);
    }

    public function test_otra_cotizacion_no_se_puede_editar_desde_esta_revision(): void
    {
        [$cot] = $this->cotizacion();
        $otra = CotizacionPartida::factory()->create();
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(RevisionMovil::class, ['cotizacion' => $cot])
            ->call('guardarUtilidad', $otra->id, '25', RevisionMovil::version($otra));
    }

    public function test_la_revision_exige_sesion_y_no_abre_acceso_a_campo_o_ned(): void
    {
        $cot = Cotizacion::factory()->create();
        $this->get(route('cotizaciones.revision', $cot))->assertRedirect(route('login'));
        $campo = User::factory()->create(['rol' => 'campo']);
        $ned = User::factory()->create(['rol' => 'nedtracking']);
        $this->actingAs($campo)->get(route('cotizaciones.revision', $cot))->assertForbidden();
        $this->actingAs($ned)->get(route('cotizaciones.revision', $cot))->assertRedirect(route('ned.index'));
        foreach (['campo', 'nedtracking'] as $rol) {
            $usuario = User::factory()->create(['rol' => $rol]);
            Livewire::actingAs($usuario)->test(RevisionMovil::class, ['cotizacion' => $cot])->assertForbidden();
        }
        $usuario = User::factory()->create(['rol' => 'vendedor']);
        $pagina = Livewire::actingAs($usuario)->test(RevisionMovil::class, ['cotizacion' => $cot]);
        $usuario->update(['activo' => false]);
        auth()->setUser($usuario->fresh());
        $pagina->call('marcarRevisada')->assertForbidden();
        $this->assertNull($cot->fresh()->revisada_at);
    }
}

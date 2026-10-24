<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\Editor;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\User;
use App\Support\CompartirDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RevisionAuditoriaAdicionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_cambiar_notas_o_cliente_quita_la_revision(): void
    {
        $u = User::factory()->create(['rol' => 'admin']);
        $this->actingAs($u);
        $cot = Cotizacion::factory()->create(['revisada_at' => now(), 'revisada_por' => $u->id]);
        $cot->update(['notas' => 'Nuevas condiciones']);
        $this->assertFalse($cot->fresh()->estaRevisada());
        $this->assertDatabaseHas('cotizacion_cambios', ['cotizacion_id' => $cot->id, 'accion' => 'revision_anulada']);
    }

    public function test_cambiar_entrega_lenta_quita_la_revision_pero_cambiar_estado_no(): void
    {
        $cot = Cotizacion::factory()->create();
        $p = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'entrega_lenta' => false]);
        $cot->update(['revisada_at' => now()]);
        $cot->update(['estado' => 'enviada']);
        $this->assertTrue($cot->fresh()->estaRevisada());
        $p->update(['entrega_lenta' => true]);
        $this->assertFalse($cot->fresh()->estaRevisada());
    }

    public function test_enlace_versionado_sin_copia_no_muestra_el_pdf_actual(): void
    {
        Storage::fake('local');
        $u = User::factory()->create();
        $cot = Cotizacion::factory()->create();
        $enlace = CompartirDocumento::enlace($cot, $u->id);
        $cot->update(['notas' => 'Contenido posterior al envío']);
        $this->get($enlace)->assertStatus(410);
        Storage::disk('local')->put(CompartirDocumento::rutaCopia($cot, 0), '%PDF-copia-fija');
        $this->get($enlace)->assertOk()->assertContent('%PDF-copia-fija');
    }

    public function test_quitar_imagen_no_restaura_un_precio_de_una_pantalla_vieja(): void
    {
        $u = User::factory()->create();
        $cot = Cotizacion::factory()->create();
        $p = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'precio' => 100, 'costo' => null]);
        $a = Livewire::actingAs($u)->test(Editor::class, ['cotizacion' => $cot]);
        $b = Livewire::actingAs($u)->test(Editor::class, ['cotizacion' => $cot]);
        $a->call('guardarCampos', $p->id, ['precio' => '200']);
        $b->call('quitarImagen', 0);
        $this->assertEquals(200, (float) $p->fresh()->precio);
    }

    public function test_cambiar_costo_usa_la_utilidad_actual_de_la_base(): void
    {
        $u = User::factory()->create();
        $cot = Cotizacion::factory()->create();
        $p = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'precio' => 120, 'costo' => 100, 'utilidad_pct' => 20]);
        $a = Livewire::actingAs($u)->test(Editor::class, ['cotizacion' => $cot]);
        $b = Livewire::actingAs($u)->test(Editor::class, ['cotizacion' => $cot]);
        $a->call('guardarCampos', $p->id, ['utilidad_pct' => '40']);
        $b->call('guardarCampos', $p->id, ['costo_sin' => '200']);
        $this->assertEquals(40, (float) $p->fresh()->utilidad_pct);
        $this->assertEquals(280, (float) $p->fresh()->precio);
    }

    public function test_utilidad_fuera_del_decimal_se_rechaza_antes_de_guardar(): void
    {
        $cot = Cotizacion::factory()->create();
        $p = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id]);
        $this->expectException(ValidationException::class);
        $p->update(['utilidad_pct' => 1000000]);
    }

    public function test_total_con_iva_no_puede_desbordar_aunque_cada_partida_quepa(): void
    {
        $cot = Cotizacion::factory()->create();
        for ($i = 0; $i < 9; $i++) {
            CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'cantidad' => 10,
                'precio' => 9000000000, 'costo' => null, 'utilidad_pct' => null, 'importe' => 90000000000]);
        }
        $this->expectException(ValidationException::class);
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'cantidad' => 10,
            'precio' => 9000000000, 'costo' => null, 'utilidad_pct' => null, 'importe' => 90000000000]);
    }
}

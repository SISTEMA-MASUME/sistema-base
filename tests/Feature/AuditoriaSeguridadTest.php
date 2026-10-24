<?php

namespace Tests\Feature;

use App\Livewire\Clientes\Index;
use App\Livewire\Cotizaciones\Editor;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Mcp\McpToken;
use App\Models\Pedido;
use App\Models\User;
use App\Support\CompartirDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/** Hallazgos de la auditoría del 3-oct-2026: permisos que se revocan con la pantalla abierta y el visor de PDF. */
class AuditoriaSeguridadTest extends TestCase
{
    use RefreshDatabase;

    /** Manda una acción de Livewire con el snapshot que ya tenía la pantalla abierta (como el navegador). */
    private function accion(string $html, string $metodo, array $updates = []): TestResponse
    {
        preg_match('/wire:snapshot="([^"]+)"/', $html, $m);
        $snapshot = html_entity_decode($m[1]);

        return $this->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
            '_token' => csrf_token(),
            'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => [['path' => '', 'method' => $metodo, 'params' => []]]]],
        ]);
    }

    public function test_admin_degradado_con_la_pantalla_abierta_ya_no_crea_usuarios(): void
    {
        $u = User::factory()->create(['rol' => 'admin']);
        $html = $this->actingAs($u)->get('/usuarios')->assertOk()->getContent();
        $u->update(['rol' => 'vendedor']);

        $this->get('/usuarios')->assertForbidden();
        $this->accion($html, 'guardar', ['name' => 'Intruso', 'email' => 'x@x.mx', 'rol' => 'admin', 'password' => 'secreto123'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'x@x.mx']);
    }

    public function test_quitar_permiso_de_finanzas_con_la_pantalla_abierta(): void
    {
        $u = User::factory()->create(['rol' => 'vendedor', 'permisos' => ['cobranza']]);
        $html = $this->actingAs($u)->get(route('finanzas.cobranza'))->assertOk()->getContent();
        $u->update(['permisos' => []]);

        $this->accion($html, '$refresh')->assertForbidden();
    }

    public function test_visor_solo_abre_documentos_del_sistema_y_no_arma_html_con_la_liga(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));

        $this->get('/visor-pdf?u='.urlencode('/archivo-inexistente"><img src=x onerror="window.x=1">'))->assertNotFound();
        $this->get('/visor-pdf?u='.urlencode('/cotizaciones/5/pdf"><img src=x>'))->assertNotFound();
        $html = $this->get('/visor-pdf?u='.urlencode('/pedidos/3/oc-cliente?v=abc123'))->assertOk()->getContent();
        $this->assertStringNotContainsString('innerHTML', $html);
        $this->assertStringContainsString('isEvalSupported: false', $html);
        $this->assertStringNotContainsString('3.11.174', $html);
    }

    public function test_dos_pantallas_del_cotizador_no_se_pisan_los_cambios(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $cot = Cotizacion::factory()->create();
        $p = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'ORIGINAL', 'cantidad' => 1, 'precio' => 100, 'costo' => 50]);

        $a = Livewire::test(Editor::class, ['cotizacion' => $cot]);
        $b = Livewire::test(Editor::class, ['cotizacion' => $cot]);
        $a->call('guardarCampos', $p->id, ['descripcion' => 'DESCRIPCION NUEVA']);
        $b->call('guardarCampos', $p->id, ['cantidad' => '2']);

        $p->refresh();
        $this->assertSame('DESCRIPCION NUEVA', $p->descripcion);
        $this->assertEquals(2, (float) $p->cantidad);
        $this->assertEquals(200, (float) $p->importe);
    }

    public function test_numeros_que_no_caben_no_se_guardan(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $cot = Cotizacion::factory()->create();
        $p = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'cantidad' => 1, 'precio' => 100]);

        Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->call('guardarCampos', $p->id, ['cantidad' => '1000000000000'])
            ->assertSee('La cantidad es demasiado grande');
        $this->assertEquals(1, (float) $p->fresh()->cantidad);
    }

    public function test_no_se_borra_un_cliente_con_pedidos_y_el_pedido_no_pierde_su_cliente(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $pedido = Pedido::factory()->create();
        $cliente = $pedido->cliente;

        Livewire::test(Index::class)->call('borrar', $cliente->id)->assertSee('porque tiene pedidos');
        $this->assertNotSoftDeleted($cliente);

        $cliente->delete(); // p. ej. unido en Duplicados
        $this->assertStringContainsString($cliente->nombre, $pedido->fresh()->titulo());
    }

    public function test_cambiar_despues_de_revisar_quita_la_revision_y_el_enlace_conserva_lo_enviado(): void
    {
        Storage::fake('local');
        $u = User::factory()->create(['rol' => 'admin']);
        $this->actingAs($u);
        $cot = Cotizacion::factory()->create(['revisada_at' => now(), 'revisada_por' => $u->id]);
        $p = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'precio' => 100]);
        $cot->forceFill(['revisada_at' => now()])->save();

        $p->update(['precio' => 200]);
        $this->assertFalse($cot->fresh()->estaRevisada());

        // Al compartir se guarda la copia de esa revisión; el enlace lleva la revisión.
        CompartirDocumento::registrar($cot->fresh(), 'descarga');
        $cot->refresh();
        $ruta = CompartirDocumento::rutaCopia($cot, (int) $cot->revision);
        Storage::disk('local')->assertExists($ruta);
        Storage::disk('local')->put($ruta, '%PDF-copia-enviada');
        $enlace = CompartirDocumento::enlace($cot, $u->id);
        auth()->logout();
        $this->assertStringContainsString('%PDF-copia-enviada', $this->get($enlace)->assertOk()->getContent());
    }

    public function test_un_codigo_oauth_no_se_gasta_con_un_intento_malo(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        DB::table('mcp_codigos')->insert([
            'codigo_hash' => McpToken::hash('codigo-1'), 'client_id' => 'c1', 'user_id' => $admin->id,
            'redirect_uri' => 'https://claude.ai/cb', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '='),
            'expira_at' => now()->addMinutes(5), 'created_at' => now(),
        ]);

        $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'code' => 'codigo-1', 'code_verifier' => 'malo', 'client_id' => 'c1'])->assertStatus(400);
        $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'code' => 'codigo-1', 'code_verifier' => str_repeat('v', 64), 'client_id' => 'c1'])->assertOk();
        $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'code' => 'codigo-1', 'code_verifier' => str_repeat('v', 64), 'client_id' => 'c1'])->assertStatus(400);
    }
}

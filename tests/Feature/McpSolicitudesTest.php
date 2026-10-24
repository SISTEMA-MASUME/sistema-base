<?php

namespace Tests\Feature;

use App\Livewire\Solicitudes\Index;
use App\Models\Cliente;
use App\Models\Mcp\McpToken;
use App\Models\SolicitudCotizacion;
use App\Models\SolicitudEvento;
use App\Models\User;
use App\Support\Folios;
use App\Support\Solicitudes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Peticiones reales al MCP, en una base aislada y sin llamadas de IA. */
class McpSolicitudesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
    }

    private function llave(?User $u = null): string
    {
        [, $llave] = McpToken::emitir($u ?? User::factory()->create(['rol' => 'admin', 'name' => 'Daniel']), 'Prueba por cotizar', null, true);

        return $llave;
    }

    private function rpc(string $llave, string $metodo, array $p = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.$llave)->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $metodo, 'params' => $p,
        ]);
    }

    private function llamar(string $llave, string $nombre, array $args = []): array
    {
        return $this->rpc($llave, 'tools/call', ['name' => $nombre, 'arguments' => $args])->assertOk()->json('result');
    }

    private function crear(string $llave, array $cambios = []): array
    {
        return $this->llamar($llave, 'crear_solicitud_por_cotizar', array_replace([
            'clave_solicitud' => 'whatsapp-parque-ejemplo-20261003-1', 'cliente' => 'PARQUE RECREATIVO EJEMPLO',
            'contacto' => 'Santiago; compras@example.test; 3312345678', 'canal' => 'whatsapp',
            'detalle' => 'Llegó de la página a WhatsApp; atender el lunes.',
            'partidas' => [
                ['descripcion' => 'PINZA URREA PUNTA LARGA', 'modelo' => '2292GCA', 'cantidad' => 1, 'unidad' => 'pza'],
                ['descripcion' => 'RODAMIENTO SKF 6310 Z C3', 'modelo' => '6310 Z C3', 'cantidad' => 2],
            ],
        ], $cambios));
    }

    public function test_anuncia_herramientas_y_distingue_por_cotizar_de_cotizaciones(): void
    {
        $llave = $this->llave();
        $r = $this->rpc($llave, 'tools/list')->assertOk()->json('result.tools');
        $herramientas = collect($r)->keyBy('name');
        foreach (['crear_solicitud_por_cotizar', 'buscar_solicitudes', 'ver_solicitud'] as $nombre) {
            $this->assertTrue($herramientas->has($nombre));
        }
        $this->assertFalse($herramientas['crear_solicitud_por_cotizar']['annotations']['readOnlyHint']);
        $this->assertTrue($herramientas['crear_solicitud_por_cotizar']['annotations']['idempotentHint']);
        $this->assertTrue($herramientas['buscar_solicitudes']['annotations']['readOnlyHint']);
        $instrucciones = $this->rpc($llave, 'initialize')->assertOk()->json('result.instructions');
        $this->assertStringContainsString('nunca crear_cotizacion_borrador', $instrucciones);
        $this->assertStringContainsString('crear_solicitud_por_cotizar', $herramientas['crear_cotizacion_borrador']['description']);
    }

    public function test_prospecto_queda_pendiente_sin_crear_cliente_ni_cotizacion_y_vendedor_lo_ve(): void
    {
        $r = $this->crear($this->llave());
        $this->assertFalse($r['isError']);
        $datos = $r['structuredContent'];
        $s = SolicitudCotizacion::sole();
        $this->assertSame($s->folioTexto(), $datos['folio']);
        $this->assertSame('pendiente', $s->estatus);
        $this->assertNull($s->cliente_id);
        $this->assertSame('PARQUE RECREATIVO EJEMPLO', $s->cliente_texto);
        $this->assertSame('PZA', $s->partidas[0]['unidad']);
        $this->assertEquals(2, $s->partidas[1]['cantidad']);
        $this->assertNull($s->tomada_por);
        $this->assertNull($s->cotizacion_id);
        $this->assertSame(route('solicitudes.ver', $s), $datos['url']);
        $this->assertDatabaseCount('clientes', 0);
        $this->assertDatabaseCount('cotizaciones', 0);
        $this->assertDatabaseCount('solicitud_cotizacion_eventos', 1);
        $this->assertSame($s->creada_por, SolicitudEvento::sole()->user_id);
        $vendedor = User::factory()->create(['rol' => 'vendedor']);
        Livewire::actingAs($vendedor)->test(Index::class)->assertSee('PARQUE RECREATIVO EJEMPLO');
        $this->actingAs($vendedor)->get($datos['url'])->assertOk()->assertSee('2292GCA');
        Http::assertNothingSent();
    }

    public function test_cliente_existente_por_id_o_nombre_y_fecha_en_mexico(): void
    {
        $cli = Cliente::factory()->create(['nombre' => 'PROVEX']);
        $llave = $this->llave();
        $r = $this->crear($llave, ['cliente_id' => $cli->id, 'cliente' => null, 'recibida_at' => '2026-10-03T22:00:00Z']);
        $this->assertFalse($r['isError']);
        $s = SolicitudCotizacion::sole();
        $this->assertSame($cli->id, $s->cliente_id);
        $this->assertSame('2026-10-03 16:00:00', $s->recibida_at->format('Y-m-d H:i:s'));
        $this->assertFalse($this->crear($llave, ['clave_solicitud' => 'otra', 'cliente' => 'provex'])['isError']);
        $this->assertSame([$cli->id, $cli->id], SolicitudCotizacion::pluck('cliente_id')->all());
        $this->assertDatabaseCount('clientes', 1);
    }

    public function test_solicitud_solo_con_detalle_no_inventa_partidas(): void
    {
        $r = $this->crear($this->llave(), ['partidas' => [], 'detalle' => 'Pide revisar la foto que dejó en WhatsApp.']);
        $this->assertFalse($r['isError']);
        $this->assertSame([], $r['structuredContent']['partidas']);
        $this->assertDatabaseCount('cotizaciones', 0);
    }

    public function test_reintento_conserva_folio_despues_de_que_el_equipo_la_toma(): void
    {
        $llave = $this->llave();
        $cli = Cliente::factory()->create(['nombre' => 'PARQUE RECREATIVO EJEMPLO']);
        $primera = $this->crear($llave)['structuredContent'];
        $this->travel(8)->minutes();
        $s = SolicitudCotizacion::sole();
        $vendedor = User::factory()->create(['rol' => 'vendedor']);
        $cot = Solicitudes::tomar($s, $vendedor);
        $r = $this->crear($llave);
        $this->assertFalse($r['isError']);
        $this->assertSame($primera['folio'], $r['structuredContent']['folio']);
        $this->assertSame('en_proceso', $r['structuredContent']['estatus']);
        $this->assertSame($cot->folio, $r['structuredContent']['cotizacion_folio']);
        $this->assertDatabaseCount('solicitudes_cotizacion', 1);
        $this->assertDatabaseCount('cotizaciones', 1);
        $this->assertSame(1, SolicitudEvento::where('tipo', 'creada')->count());
        $this->assertSame($cli->id, $s->fresh()->cliente_id);
    }

    public function test_clave_reutilizada_con_otros_datos_avisa_y_no_duplica(): void
    {
        $llave = $this->llave();
        $this->crear($llave);
        $r = $this->crear($llave, ['detalle' => 'Una solicitud diferente.']);
        $this->assertTrue($r['isError']);
        $this->assertStringContainsString('ya se usó con otros datos', $r['content'][0]['text']);
        $this->assertDatabaseCount('solicitudes_cotizacion', 1);
    }

    public function test_reintento_de_prospecto_conserva_la_solicitud_aunque_se_de_de_alta_el_cliente(): void
    {
        $llave = $this->llave();
        $primera = $this->crear($llave)['structuredContent'];
        $cli = Cliente::factory()->create(['nombre' => 'PARQUE RECREATIVO EJEMPLO']);
        SolicitudCotizacion::sole()->update(['cliente_id' => $cli->id, 'cliente_texto' => null]);
        $r = $this->crear($llave);
        $this->assertFalse($r['isError']);
        $this->assertSame($primera['folio'], $r['structuredContent']['folio']);
        $this->assertSame($cli->id, $r['structuredContent']['cliente_id']);
        $this->assertDatabaseCount('solicitudes_cotizacion', 1);
    }

    public function test_claves_distintas_permiten_dos_pedidos_iguales_y_cada_admin_tiene_su_espacio(): void
    {
        $llave = $this->llave();
        $this->crear($llave);
        $this->crear($llave, ['clave_solicitud' => 'solicitud-nueva']);
        $this->crear($this->llave());
        $this->assertDatabaseCount('solicitudes_cotizacion', 3);
    }

    public function test_validaciones_no_dejan_basura_y_responden_en_espanol(): void
    {
        $llave = $this->llave();
        $errores = [
            ['clave_solicitud' => ''], ['cliente' => '', 'cliente_id' => null], ['cliente_id' => 999999],
            ['detalle' => '', 'partidas' => []], ['canal' => 'facebook'], ['recibida_at' => 'ayer-a-las-cuatro'],
            ['partidas' => [['descripcion' => 'BROCA', 'cantidad' => 1e12]]],
            ['partidas' => [['descripcion' => 'BROCA', 'cantidad' => -1]]],
            ['partidas' => [['descripcion' => 'BROCA', 'cantidad' => 0.00001]]],
            ['partidas' => [['descripcion' => '  ', 'cantidad' => 1]]],
            ['partidas' => [['descripcion' => 'BROCA', 'cantidad' => 1, 'ligada' => ['partida_id' => 99]]]],
            ['cliente' => str_repeat('x', 251)], ['contacto' => str_repeat('x', 251)], ['detalle' => str_repeat('x', 5001)],
        ];
        foreach ($errores as $args) {
            $r = $this->crear($llave, $args);
            $this->assertTrue($r['isError'], json_encode($args));
            $this->assertStringNotContainsString('Error interno', $r['content'][0]['text']);
            $this->assertStringNotContainsString('required', $r['content'][0]['text']);
        }
        $this->assertDatabaseCount('solicitudes_cotizacion', 0);
        $this->assertDatabaseCount('solicitud_cotizacion_eventos', 0);
        Http::assertNothingSent();
    }

    public function test_rechaza_id_y_nombre_distintos_y_cliente_borrado(): void
    {
        $llave = $this->llave();
        $cli = Cliente::factory()->create(['nombre' => 'PROVEX']);
        $this->assertTrue($this->crear($llave, ['cliente_id' => $cli->id])['isError']);
        $cli->delete();
        $this->assertTrue($this->crear($llave, ['cliente_id' => $cli->id, 'cliente' => null])['isError']);
        $this->assertDatabaseCount('solicitudes_cotizacion', 0);
    }

    public function test_no_permite_inyectar_responsable_estado_o_folio(): void
    {
        $r = $this->crear($this->llave(), ['estatus' => 'cotizada', 'tomada_por' => 999, 'folio' => 10649,
            'cotizacion_id' => 999, 'mcp_clave' => 'falsa']);
        $this->assertFalse($r['isError']);
        $s = SolicitudCotizacion::sole();
        $this->assertSame('pendiente', $s->estatus);
        $this->assertNull($s->tomada_por);
        $this->assertNull($s->cotizacion_id);
        $this->assertNotEquals(10649, $s->folio);
        $this->assertNotSame('falsa', $s->mcp_clave);
    }

    public function test_busqueda_filtra_cliente_articulo_estado_y_fechas_y_detalle_muestra_bitacora(): void
    {
        $llave = $this->llave();
        $cli = Cliente::factory()->create(['nombre' => 'PROVEX']);
        $this->crear($llave, ['cliente' => 'PROVEX', 'recibida_at' => '2026-10-03 10:00:00']);
        $s = SolicitudCotizacion::sole();
        $this->crear($llave, ['clave_solicitud' => 'vieja', 'recibida_at' => '2026-10-01 10:00:00']);
        $r = $this->llamar($llave, 'buscar_solicitudes', ['texto' => 'PROVEX', 'estatus' => 'pendiente', 'desde' => '2026-10-03', 'hasta' => '2026-10-03']);
        $this->assertFalse($r['isError']);
        $this->assertCount(1, $r['structuredContent']['solicitudes']);
        $this->assertSame($cli->id, $r['structuredContent']['solicitudes'][0]['cliente_id']);
        $r = $this->llamar($llave, 'buscar_solicitudes', ['texto' => '2292GCA', 'limite' => 1]);
        $this->assertCount(1, $r['structuredContent']['solicitudes']);
        $r = $this->llamar($llave, 'ver_solicitud', ['folio' => $s->folioTexto()]);
        $this->assertFalse($r['isError']);
        $this->assertCount(2, $r['structuredContent']['partidas']);
        $this->assertSame('creada', $r['structuredContent']['bitacora'][0]['tipo']);
        $this->assertSame([], $r['structuredContent']['archivos']);
        $this->assertTrue($this->llamar($llave, 'ver_solicitud', ['folio' => 'P-12'])['isError']);
        $this->assertTrue($this->llamar($llave, 'ver_solicitud', ['folio' => 'S-999999'])['isError']);
        $this->assertTrue($this->llamar($llave, 'buscar_solicitudes', ['estatus' => 'otra'])['isError']);
        $this->assertTrue($this->llamar($llave, 'buscar_solicitudes', ['desde' => 'ayer'])['isError']);
    }

    public function test_solo_admin_activo_con_llave_valida_puede_usar_herramientas(): void
    {
        foreach (['vendedor', 'campo', 'nedtracking'] as $rol) {
            $u = User::factory()->create(['rol' => $rol]);
            $this->rpc($this->llave($u), 'tools/list')->assertUnauthorized();
        }
        $admin = User::factory()->create(['rol' => 'admin']);
        $llave = $this->llave($admin);
        $admin->update(['activo' => false]);
        $this->rpc($llave, 'tools/call', ['name' => 'crear_solicitud_por_cotizar'])->assertUnauthorized();
        $this->assertDatabaseCount('solicitudes_cotizacion', 0);
    }

    public function test_indice_unico_impide_dos_filas_con_la_misma_clave(): void
    {
        $this->crear($this->llave());
        $s = SolicitudCotizacion::sole();
        $this->expectException(UniqueConstraintViolationException::class);
        SolicitudCotizacion::create(['folio' => Folios::siguiente('solicitud'), 'recibida_at' => now(), 'mcp_clave' => $s->mcp_clave]);
    }

    public function test_fallo_de_bitacora_revierte_alta_y_folio(): void
    {
        $llave = $this->llave();
        $contador = DB::table('contadores')->where('clave', 'solicitud')->value('ultimo');
        SolicitudEvento::creating(fn () => throw new \RuntimeException('Fallo simulado de bitácora.'));
        try {
            $r = $this->crear($llave);
            $this->assertTrue($r['isError']);
            $this->assertDatabaseCount('solicitudes_cotizacion', 0);
            $this->assertSame($contador, DB::table('contadores')->where('clave', 'solicitud')->value('ultimo'));
        } finally {
            SolicitudEvento::flushEventListeners();
        }
    }
}

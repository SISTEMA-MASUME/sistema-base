<?php

namespace Tests\Feature;

use App\Enums\ColumnaPedido;
use App\Enums\EstadoCotizacion;
use App\Enums\EstadoPartidaPedido;
use App\Enums\TipoPedido;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Mcp\McpToken;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class McpPedidosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function llave(?User $user = null): string
    {
        [, $llave] = McpToken::emitir($user ?? User::factory()->create(['rol' => 'admin']), 'Prueba pedidos', null, true);

        return $llave;
    }

    private function rpc(string $llave, string $metodo, array $params = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.$llave)->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $metodo, 'params' => $params,
        ]);
    }

    private function llamar(string $llave, string $nombre, array $args = []): array
    {
        return $this->rpc($llave, 'tools/call', ['name' => $nombre, 'arguments' => $args])->assertOk()->json('result');
    }

    private function cotizacion(array $cambios = []): Cotizacion
    {
        $cliente = Cliente::factory()->create(['nombre' => 'ACME MEXICO']);
        $cot = Cotizacion::factory()->create(array_replace(['folio' => 10759, 'cliente_id' => $cliente->id, 'estado' => 'enviada'], $cambios));
        $proveedor = Proveedor::factory()->create();
        CotizacionPartida::factory()->create([
            'cotizacion_id' => $cot->id, 'incluir' => true, 'cantidad' => 5, 'precio' => 77.33,
            'costo' => 56.0345, 'utilidad_pct' => 38, 'importe' => 386.65, 'proveedor_id' => $proveedor->id,
            'descripcion' => 'DADO HEXAGONAL TRUPER 13255', 'marca' => 'TRUPER', 'modelo' => '13255',
            'link_compra' => 'https://example.test/13255', 'imagen_path' => 'partidas/dado.png',
        ]);
        CotizacionPartida::factory()->create([
            'cotizacion_id' => $cot->id, 'orden' => 2, 'incluir' => false, 'descripcion' => 'OPCION NO ELEGIDA',
        ]);
        $cot->recalcularTotales();

        return $cot->fresh();
    }

    private function convertir(string $llave, Cotizacion $cot, array $cambios = []): array
    {
        return $this->llamar($llave, 'convertir_cotizacion_a_pedido', array_replace([
            'folio' => $cot->folio, 'motivo_sin_oc' => 'Entregar con esta cotización; ACME enviará la OC después.',
            'columna' => 'entregados_por_facturar',
        ], $cambios));
    }

    public function test_anuncia_el_tablero_interno_y_sus_columnas(): void
    {
        $llave = $this->llave();
        $tools = collect($this->rpc($llave, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');
        $this->assertTrue($tools['columnas_pedidos']['annotations']['readOnlyHint']);
        foreach (['convertir_cotizacion_a_pedido', 'mover_pedido'] as $name) {
            $this->assertFalse($tools[$name]['annotations']['readOnlyHint']);
            $this->assertTrue($tools[$name]['annotations']['idempotentHint']);
        }
        $r = $this->llamar($llave, 'columnas_pedidos');
        $this->assertFalse($r['isError']);
        $this->assertCount(count(ColumnaPedido::cases()), $r['structuredContent']['columnas']);
        $this->assertContains(['clave' => 'entregados_por_facturar', 'nombre' => 'Entregados por facturar (notas y cot.)'], $r['structuredContent']['columnas']);
        $this->assertStringContainsString('INTERNO de MASUME', $this->rpc($llave, 'initialize')->json('result.instructions'));
    }

    public function test_acme_sin_oc_conserva_precios_y_no_marca_entrega_ni_factura(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        $cot = $this->cotizacion();
        $r = $this->convertir($this->llave($admin), $cot);
        $this->assertFalse($r['isError'], $r['content'][0]['text']);
        $p = Pedido::sole();
        $this->assertTrue($r['structuredContent']['creado']);
        $this->assertSame('P-'.$p->folio, $r['structuredContent']['folio']);
        $this->assertSame(url('/pedidos?pedido='.$p->id), $r['structuredContent']['url']);
        $this->assertSame($cot->cliente_id, $p->cliente_id);
        $this->assertSame($admin->id, $p->responsable_id);
        $this->assertNull($p->numero_oc_cliente);
        $this->assertNull($p->oc_cliente_path);
        $this->assertSame(TipoPedido::NotaVenta, $p->tipo);
        $this->assertSame(ColumnaPedido::EntregadosPorFacturar, $p->columna);
        $this->assertContains('Esperando OC del cliente', array_column($p->etiquetas(), 'texto'));
        $this->assertStringContainsString('Entrega con cotización 10759', $p->notas);
        $partida = PedidoPartida::sole();
        $original = $cot->partidasIncluidas()->sole();
        foreach (['cantidad', 'precio', 'costo', 'proveedor_id', 'descripcion', 'marca', 'modelo', 'link_compra', 'imagen_path'] as $campo) {
            $this->assertSame($original->$campo, $partida->$campo, $campo);
        }
        $this->assertSame($original->id, $partida->cotizacion_partida_id);
        $this->assertSame(EstadoPartidaPedido::Pendiente, $partida->estado);
        $this->assertFalse($partida->facturada);
        $this->assertEquals(386.65, $p->total_venta);
        $this->assertSame(EstadoCotizacion::Aceptada, $cot->fresh()->estado);
        $this->assertDatabaseCount('pedido_actividades', 2);
        $this->assertSame([$admin->id, $admin->id], PedidoActividad::pluck('user_id')->all());
        $this->assertDatabaseCount('ordenes_proveedor', 0);
        $this->assertDatabaseCount('documento_envios', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_reintento_devuelve_el_pedido_y_no_cambia_columna_notas_ni_folio(): void
    {
        $cot = $this->cotizacion();
        $llave = $this->llave();
        $primero = $this->convertir($llave, $cot)['structuredContent'];
        $contador = DB::table('contadores')->where('clave', 'pedido')->value('ultimo');
        $r = $this->convertir($llave, $cot, ['columna' => 'por_facturar', 'notas' => 'No debe reemplazar notas']);
        $this->assertFalse($r['isError'], $r['content'][0]['text']);
        $this->assertFalse($r['structuredContent']['creado']);
        $this->assertSame($primero['folio'], $r['structuredContent']['folio']);
        $this->assertSame($primero['notas'], $r['structuredContent']['notas']);
        $this->assertSame($primero['lista'], $r['structuredContent']['lista']);
        $this->assertSame($contador, DB::table('contadores')->where('clave', 'pedido')->value('ultimo'));
        $this->assertDatabaseCount('pedidos', 1);
        $this->assertDatabaseCount('pedido_partidas', 1);
        $this->assertDatabaseCount('pedido_actividades', 2);
    }

    public function test_conversion_parcial_o_modificada_no_crea_otro_pedido(): void
    {
        $cot = $this->cotizacion();
        $original = $cot->partidasIncluidas()->sole();
        $p = Pedido::factory()->create(['cliente_id' => $cot->cliente_id]);
        PedidoPartida::desdeCotizacion($original, $p, 1, 2);
        $r = $this->convertir($this->llave(), $cot);
        $this->assertTrue($r['isError']);
        $this->assertStringContainsString('parcialmente', $r['content'][0]['text']);
        $this->assertDatabaseCount('pedidos', 1);
        $this->assertDatabaseCount('pedido_actividades', 0);
        $this->assertSame(EstadoCotizacion::Enviada, $cot->fresh()->estado);
    }

    public function test_colocacion_previa_no_se_duplica_aunque_haya_sido_creada_en_pantalla(): void
    {
        $cot = $this->cotizacion();
        $p = Pedido::factory()->create(['cliente_id' => $cot->cliente_id]);
        PedidoPartida::desdeCotizacion($cot->partidasIncluidas()->sole(), $p, 1);
        $r = $this->convertir($this->llave(), $cot);
        $this->assertFalse($r['isError'], $r['content'][0]['text']);
        $this->assertFalse($r['structuredContent']['creado']);
        $this->assertSame('P-'.$p->folio, $r['structuredContent']['folio']);
        $this->assertDatabaseCount('pedidos', 1);
    }

    public function test_validacion_y_campos_extra_no_dejan_pedido_ni_actividad(): void
    {
        $cot = $this->cotizacion();
        $llave = $this->llave();
        foreach ([
            ['folio' => 'P-12'], ['folio' => 999999], ['motivo_sin_oc' => '    '],
            ['motivo_sin_oc' => 'corto'], ['columna' => 'trello'], ['responsable_id' => 999999],
            ['fecha_compromiso' => 'ayer'], ['precio' => 1], ['numero_oc_cliente' => 'OC falsa'],
            ['estado' => 'entregada'], ['facturada' => true],
        ] as $cambio) {
            $r = $this->convertir($llave, $cot, $cambio);
            $this->assertTrue($r['isError'], json_encode($cambio));
            $this->assertStringNotContainsString('Error interno', $r['content'][0]['text']);
        }
        $this->assertDatabaseCount('pedidos', 0);
        $this->assertDatabaseCount('pedido_actividades', 0);
        $this->assertSame(EstadoCotizacion::Enviada, $cot->fresh()->estado);
    }

    public function test_no_devuelve_un_pedido_asociado_a_otro_cliente(): void
    {
        $cot = $this->cotizacion();
        $p = Pedido::factory()->create();
        PedidoPartida::desdeCotizacion($cot->partidasIncluidas()->sole(), $p, 1);
        $r = $this->convertir($this->llave(), $cot);
        $this->assertTrue($r['isError']);
        $this->assertStringContainsString('otro cliente', $r['content'][0]['text']);
        $this->assertDatabaseCount('pedidos', 1);
    }

    public function test_no_convierte_rechazada_vacia_ni_cliente_borrado(): void
    {
        $cot = $this->cotizacion(['estado' => 'rechazada']);
        $llave = $this->llave();
        $this->assertTrue($this->convertir($llave, $cot)['isError']);
        $cot->update(['estado' => 'enviada']);
        $cot->partidas()->update(['incluir' => false]);
        $this->assertTrue($this->convertir($llave, $cot)['isError']);
        $cot->partidas()->first()->update(['incluir' => true]);
        $cot->cliente->delete();
        $this->assertTrue($this->convertir($llave, $cot)['isError']);
        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_responsable_fecha_y_reglas_de_portal_se_respetan(): void
    {
        $cot = $this->cotizacion(['estado' => 'borrador']);
        $cot->cliente->update(['oc_despues_de_facturar' => true]);
        $responsable = User::factory()->create();
        $r = $this->convertir($this->llave(), $cot, ['responsable_id' => $responsable->id, 'fecha_compromiso' => '2026-10-12']);
        $this->assertFalse($r['isError'], $r['content'][0]['text']);
        $p = Pedido::sole();
        $this->assertSame($responsable->id, $p->responsable_id);
        $this->assertSame('2026-10-12', $p->fecha_compromiso->toDateString());
        $this->assertContains('Subir factura al portal', array_column($p->etiquetas(), 'texto'));
        $this->assertNotContains('Esperando OC del cliente', array_column($p->etiquetas(), 'texto'));
    }

    public function test_mover_es_idempotente_y_conserva_oc_notas_y_estado_de_partidas(): void
    {
        $p = Pedido::factory()->create(['notas' => 'Entrega futura', 'columna' => 'en_tramite']);
        $original = CotizacionPartida::factory()->create();
        $partida = PedidoPartida::desdeCotizacion($original, $p, 1);
        $partida->update(['estado' => 'recibida', 'facturada' => true]);
        $llave = $this->llave();
        $r = $this->llamar($llave, 'mover_pedido', ['folio' => $p->folio, 'columna' => 'entregados_por_facturar']);
        $this->assertFalse($r['isError'], $r['content'][0]['text']);
        $this->assertTrue($r['structuredContent']['movido']);
        $this->assertSame('Entrega futura', $p->fresh()->notas);
        $this->assertSame($p->numero_oc_cliente, $p->fresh()->numero_oc_cliente);
        $this->assertSame(EstadoPartidaPedido::Recibida, $partida->fresh()->estado);
        $this->assertTrue($partida->fresh()->facturada);
        $this->assertFalse($this->llamar($llave, 'mover_pedido', ['folio' => $p->folio, 'columna' => 'entregados_por_facturar'])['structuredContent']['movido']);
        $this->assertDatabaseCount('pedido_actividades', 1);
        $this->assertTrue($this->llamar($llave, 'mover_pedido', ['folio' => $p->folio, 'columna' => 'inventada'])['isError']);
        $this->assertSame(ColumnaPedido::EntregadosPorFacturar, $p->fresh()->columna);
    }

    public function test_pedido_archivado_o_cancelado_no_se_mueve_ni_se_duplica(): void
    {
        $cot = $this->cotizacion();
        $llave = $this->llave();
        $this->convertir($llave, $cot);
        $p = Pedido::sole();
        foreach (['archivado_at', 'cancelado_at'] as $campo) {
            $p->update([$campo => now()]);
            $this->assertTrue($this->convertir($llave, $cot)['isError']);
            $this->assertTrue($this->llamar($llave, 'mover_pedido', ['folio' => $p->folio, 'columna' => 'por_facturar'])['isError']);
            $p->update([$campo => null]);
        }
        $this->assertDatabaseCount('pedidos', 1);
        $this->assertDatabaseCount('pedido_actividades', 2);
    }

    public function test_fallo_de_actividad_revierte_pedido_partidas_estado_y_contador(): void
    {
        $cot = $this->cotizacion();
        $llave = $this->llave();
        $contador = DB::table('contadores')->where('clave', 'pedido')->value('ultimo');
        PedidoActividad::creating(fn () => throw new \RuntimeException('Fallo simulado de actividad.'));
        try {
            $this->assertTrue($this->convertir($llave, $cot)['isError']);
            $this->assertDatabaseCount('pedidos', 0);
            $this->assertDatabaseCount('pedido_partidas', 0);
            $this->assertSame(EstadoCotizacion::Enviada, $cot->fresh()->estado);
            $this->assertSame($contador, DB::table('contadores')->where('clave', 'pedido')->value('ultimo'));
        } finally {
            PedidoActividad::flushEventListeners();
        }
    }

    public function test_solo_admin_activo_con_token_puede_crear_pedidos(): void
    {
        $cot = $this->cotizacion();
        foreach (['campo', 'vendedor'] as $rol) {
            $llave = $this->llave(User::factory()->create(['rol' => $rol]));
            $this->rpc($llave, 'tools/call', ['name' => 'convertir_cotizacion_a_pedido', 'arguments' => ['folio' => $cot->folio]])->assertUnauthorized();
        }
        $admin = User::factory()->create(['rol' => 'admin']);
        $llave = $this->llave($admin);
        $admin->update(['activo' => false]);
        $this->rpc($llave, 'tools/call', ['name' => 'mover_pedido', 'arguments' => []])->assertUnauthorized();
        $this->assertDatabaseCount('pedidos', 0);
    }
}

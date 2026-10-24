<?php

namespace Tests\Feature;

use App\Livewire\Mcp\Conexion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\Mcp\McpToken;
use App\Models\Nomina;
use App\Models\Proveedor;
use App\Models\User;
use App\Models\Vacacion;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** MCP para Claude/ChatGPT: OAuth (registro, permiso del admin, PKCE, renovación) y herramientas. */
class McpTest extends TestCase
{
    use RefreshDatabase;

    private function rpc(string $llave, string $metodo, array $params = [], int $id = 1)
    {
        return $this->withHeader('Authorization', 'Bearer '.$llave)
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $metodo, 'params' => $params]);
    }

    private function herramienta(string $llave, string $nombre, array $args = []): array
    {
        $r = $this->rpc($llave, 'tools/call', ['name' => $nombre, 'arguments' => $args])->assertOk()->json('result');
        $this->assertFalse($r['isError'], $r['content'][0]['text'] ?? '');

        return $r['structuredContent'];
    }

    /** Hace el flujo completo como Claude/ChatGPT y devuelve [access_token, refresh_token]. */
    private function conectar(User $admin): array
    {
        $this->getJson('/.well-known/oauth-protected-resource')->assertOk()->assertJsonPath('resource', url('/mcp'));
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('code_challenge_methods_supported', ['S256']);

        $cliente = $this->postJson('/oauth/registro', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])
            ->assertCreated()->json();

        $verificador = str_repeat('v', 64);
        $reto = rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');
        $params = ['response_type' => 'code', 'client_id' => $cliente['client_id'], 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'state' => 'abc', 'code_challenge' => $reto, 'code_challenge_method' => 'S256'];

        // Sin sesión manda al login.
        $this->get('/oauth/autorizar?'.http_build_query($params))->assertRedirect(route('login'));

        $this->actingAs($admin);
        $this->get('/oauth/autorizar?'.http_build_query($params))->assertOk()->assertSee('Sí, permitir');
        $destino = $this->post('/oauth/autorizar', $params + ['permitir' => 1])->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://claude.ai/api/mcp/auth_callback?code=', $destino);
        parse_str(parse_url($destino, PHP_URL_QUERY), $q);
        $this->assertSame('abc', $q['state']);
        auth()->logout();

        // Verificador equivocado: no.
        $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'code_verifier' => 'otro', 'client_id' => $cliente['client_id']])
            ->assertStatus(400);
        // El código ya se gastó: hay que pedir otro.
        $destino = $this->actingAs($admin)->post('/oauth/autorizar', $params + ['permitir' => 1])->headers->get('Location');
        parse_str(parse_url($destino, PHP_URL_QUERY), $q);
        auth()->logout();
        $tok = $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'code_verifier' => $verificador,
            'client_id' => $cliente['client_id'], 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback'])->assertOk()->json();
        $this->assertSame('Bearer', $tok['token_type']);

        return [$tok['access_token'], $tok['refresh_token']];
    }

    public function test_conexion_oauth_y_herramientas_de_consulta(): void
    {
        $admin = User::factory()->create(['rol' => 'admin', 'name' => 'Daniel']);
        $cli = Cliente::factory()->create(['nombre' => 'PLAZA NORTE']);
        $prov = Proveedor::factory()->create(['nombre' => 'DISNOR']);
        $cot = Cotizacion::factory()->create(['folio' => 10600, 'cliente_id' => $cli->id, 'estado' => 'enviada']);
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'descripcion' => 'ROTOMARTILLO DEWALT 800W', 'cantidad' => 2,
            'costo' => 1000, 'utilidad_pct' => 30, 'precio' => 1300, 'importe' => 2600, 'proveedor_id' => $prov->id]);

        // Sin llave: 401 con la pista para OAuth.
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource').'"');

        [$llave, $refresh] = $this->conectar($admin);

        $this->rpc($llave, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'claude']])
            ->assertOk()->assertJsonPath('result.serverInfo.name', 'masume')->assertJsonPath('result.protocolVersion', '2025-06-18');
        $this->withHeader('Authorization', 'Bearer '.$llave)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
        $nombres = collect($this->rpc($llave, 'tools/list')->json('result.tools'))->pluck('name');
        $this->assertTrue($nombres->contains('search') && $nombres->contains('fetch') && $nombres->contains('crear_cotizacion_borrador'));

        $r = $this->herramienta($llave, 'search', ['query' => 'rotomartillo']);
        $this->assertSame('cotizacion:10600', $r['results'][0]['id']);
        $f = $this->herramienta($llave, 'fetch', ['id' => 'cotizacion:10600']);
        $this->assertStringContainsString('ROTOMARTILLO', $f['text']);

        $precios = $this->herramienta($llave, 'precios_anteriores', ['texto' => 'rotomartillo dewalt']);
        $this->assertEquals(1000, $precios['partidas'][0]['costo_sin_iva']);
        $this->assertSame('DISNOR', $precios['partidas'][0]['proveedor']);
        $this->assertSame('PLAZA NORTE', $precios['partidas'][0]['cliente']);

        $this->assertSame(10600, $this->herramienta($llave, 'buscar_cotizaciones', ['cliente' => 'plaza'])['cotizaciones'][0]['folio']);
        $this->assertSame('PLAZA NORTE', $this->herramienta($llave, 'buscar_clientes', ['texto' => 'plaza'])['clientes'][0]['nombre']);

        // Error de uso: vuelve como isError (no truena).
        $err = $this->rpc($llave, 'tools/call', ['name' => 'ver_cotizacion', 'arguments' => ['folio' => 1]])->json('result');
        $this->assertTrue($err['isError']);

        // Renovar: la llave vieja deja de servir.
        $nueva = $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refresh])->assertOk()->json('access_token');
        $this->rpc($llave, 'tools/list')->assertStatus(401);
        $this->rpc($nueva, 'tools/list')->assertOk();

        // Quitar acceso desde la pantalla.
        $this->actingAs($admin);
        $t = McpToken::whereNull('revocado_at')->firstOrFail();
        Livewire::test(Conexion::class)->assertSee('Claude')->call('revocar', $t->id)->assertSee('Se quitó el acceso');
        $this->rpc($nueva, 'tools/list')->assertStatus(401);
    }

    public function test_crea_cotizacion_en_borrador_y_agrega_partidas(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        Cliente::factory()->create(['nombre' => 'HOTEL AURORA EJECUTIVO']);
        $this->actingAs($admin);
        Livewire::test(Conexion::class)->call('crearLlave');
        $llave = Livewire::test(Conexion::class)->call('crearLlave')->get('llaveNueva');
        auth()->logout();

        $r = $this->herramienta($llave, 'crear_cotizacion_borrador', ['cliente' => 'hotel aurora ejecutivo', 'partidas' => [
            ['descripcion' => 'guantes de carnaza', 'cantidad' => 10, 'costo' => 50, 'utilidad_pct' => 40, 'proveedor' => 'DISNOR'],
            ['descripcion' => 'cinta aislar', 'cantidad' => 5, 'unidad' => 'pza', 'precio' => 20],
        ]]);
        $this->assertTrue($r['creada']);
        $cot = Cotizacion::where('folio', $r['folio'])->firstOrFail();
        $this->assertSame('borrador', $cot->estado->value);
        $this->assertSame($admin->id, $cot->vendedor_id);
        $this->assertEquals(800, (float) $cot->subtotal);   // 10×70 + 5×20
        $this->assertSame('GUANTES DE CARNAZA', $cot->partidas()->orderBy('orden')->first()->descripcion);

        $this->herramienta($llave, 'agregar_partidas', ['folio' => $cot->folio, 'partidas' => [['descripcion' => 'lentes', 'cantidad' => 1, 'precio' => 100]]]);
        $this->assertSame(3, $cot->partidas()->count());

        // Cliente que no existe: avisa (no lo crea) salvo que se pida.
        $err = $this->rpc($llave, 'tools/call', ['name' => 'crear_cotizacion_borrador', 'arguments' => ['cliente' => 'NUEVO SA', 'partidas' => [['descripcion' => 'x', 'cantidad' => 1]]]])->json('result');
        $this->assertTrue($err['isError']);
        $this->assertStringContainsString('No existe el cliente', $err['content'][0]['text']);

        // Ya enviada: no se le agregan partidas.
        $cot->update(['estado' => 'enviada']);
        $err = $this->rpc($llave, 'tools/call', ['name' => 'agregar_partidas', 'arguments' => ['folio' => $cot->folio, 'partidas' => [['descripcion' => 'x', 'cantidad' => 1]]]])->json('result');
        $this->assertTrue($err['isError']);
    }

    public function test_edita_y_elimina_cotizaciones_ya_hechas(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        Cliente::factory()->create(['nombre' => 'PROVEX']);
        Cliente::factory()->create(['nombre' => 'OPERADORA EJEMPLO']);
        $this->actingAs($admin);
        $llave = Livewire::test(Conexion::class)->call('crearLlave')->get('llaveNueva');
        auth()->logout();

        $r = $this->herramienta($llave, 'crear_cotizacion_borrador', ['cliente' => 'provex', 'partidas' => [
            ['descripcion' => 'guantes', 'cantidad' => 10, 'costo' => 50, 'utilidad_pct' => 40],
            ['descripcion' => 'cinta', 'cantidad' => 5, 'precio' => 20],
            ['descripcion' => 'lentes', 'cantidad' => 1, 'precio' => 100],
        ]]);
        $cot = Cotizacion::where('folio', $r['folio'])->firstOrFail();

        $e = $this->herramienta($llave, 'editar_cotizacion', [
            'folio' => $cot->folio, 'cliente' => 'operadora ejemplo', 'notas' => 'Precios con entrega',
            'cambios' => [['partida' => 1, 'utilidad_pct' => 50], ['partida' => 2, 'cantidad' => 8, 'descripcion' => 'cinta de aislar']],
            'quitar' => [3],
            'agregar' => [['descripcion' => 'casco', 'cantidad' => 2, 'precio' => 150]],
        ]);
        $this->assertTrue($e['editada']);
        $cot->refresh();
        $this->assertSame('OPERADORA EJEMPLO', $cot->cliente->nombre);
        $this->assertSame('Precios con entrega', $cot->notas);
        $p = $cot->partidas()->orderBy('orden')->get();
        $this->assertCount(3, $p);
        $this->assertEquals(75, (float) $p[0]->precio);          // 50 × 1.5
        $this->assertSame('CINTA DE AISLAR', $p[1]->descripcion);
        $this->assertEquals(8, (float) $p[1]->cantidad);
        $this->assertSame('CASCO', $p[2]->descripcion);
        $this->assertEquals(750 + 160 + 300, (float) $cot->subtotal);
        $this->assertSame(1, Cotizacion::count()); // no se creó otra

        // Partida que no existe: avisa.
        $err = $this->rpc($llave, 'tools/call', ['name' => 'editar_cotizacion', 'arguments' => ['folio' => $cot->folio, 'cambios' => [['partida' => 9, 'cantidad' => 1]]]])->json('result');
        $this->assertTrue($err['isError']);

        // Enviada: también se edita (queda en la bitácora como revisión); eliminar solo borradores.
        $cot->update(['estado' => 'enviada']);
        $this->herramienta($llave, 'editar_cotizacion', ['folio' => $cot->folio, 'cambios' => [['partida' => 1, 'precio' => 80]]]);
        $this->assertEquals(80, (float) $cot->partidas()->orderBy('orden')->first()->precio);
        $err = $this->rpc($llave, 'tools/call', ['name' => 'eliminar_cotizacion', 'arguments' => ['folio' => $cot->folio]])->json('result');
        $this->assertTrue($err['isError']);

        $cot->update(['estado' => 'borrador']);
        $this->assertTrue($this->herramienta($llave, 'eliminar_cotizacion', ['folio' => $cot->folio])['eliminada']);
        $this->assertSoftDeleted('cotizaciones', ['id' => $cot->id]);
    }

    public function test_buscar_ventas_con_resumen(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        [, $llave] = McpToken::emitir($admin, 'x', null, personal: true);
        Venta::create(['tipo' => 'factura', 'folio' => '5-1', 'cliente_nombre' => 'ACME MEXICO', 'fecha' => '2026-01-05',
            'subtotal' => 1000, 'costo' => 700, 'iva' => 160, 'total' => 1160, 'estatus' => 'pendiente', 'origen' => 'sicar', 'sicar_id' => 'F:1']);
        Venta::create(['tipo' => 'factura', 'folio' => '5-2', 'cliente_nombre' => 'ACME MEXICO', 'fecha' => '2026-01-06',
            'subtotal' => 500, 'costo' => 400, 'iva' => 80, 'total' => 580, 'estatus' => 'cancelada', 'origen' => 'sheets']);

        $r = $this->herramienta($llave, 'buscar_ventas', ['texto' => 'acme', 'desde' => '2026-01-01', 'hasta' => '2026-01-31']);
        $this->assertSame(2, $r['resumen']['documentos']);
        $this->assertSame(1000.0, (float) $r['resumen']['venta_sin_iva']);   // sin la cancelada
        $this->assertSame(300.0, (float) $r['resumen']['utilidad']);
        $this->assertSame(1160.0, (float) $r['resumen']['por_cobrar_con_iva']);
        $this->assertSame(['sheets' => 1, 'sicar' => 1], $r['resumen']['por_origen']);
        $this->assertSame('5-2', $r['ventas'][0]['folio']);

        $this->assertCount(1, $this->herramienta($llave, 'buscar_ventas', ['origen' => 'sicar'])['ventas']);
    }

    public function test_consultas_de_finanzas_y_vacaciones(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $admin = User::factory()->create(['rol' => 'admin', 'name' => 'Daniel']);
        [, $llave] = McpToken::emitir($admin, 'x', null, personal: true);
        $cli = Cliente::factory()->create(['nombre' => 'ACME MEXICO', 'dias_credito' => 30]);
        Venta::create(['tipo' => 'factura', 'folio' => '5-10', 'cliente_id' => $cli->id, 'cliente_nombre' => 'ACME MEXICO', 'fecha' => '2026-08-01',
            'subtotal' => 1000, 'costo' => 700, 'iva' => 160, 'total' => 1160, 'estatus' => 'pendiente']);
        Venta::create(['tipo' => 'factura', 'folio' => '5-11', 'cliente_nombre' => 'OTRO', 'fecha' => '2026-09-28',
            'subtotal' => 2000, 'costo' => 1000, 'iva' => 320, 'total' => 2320, 'estatus' => 'pagada', 'fecha_pago' => '2026-09-29']);
        Gasto::create(['fecha' => '2026-09-10', 'monto' => 500, 'de' => 'masume', 'tipo' => 'gasto', 'categoria' => 'GASOLINA', 'descripcion' => 'Magna']);
        $rosa = User::factory()->create(['name' => 'Rosa', 'en_nomina' => true, 'sueldo_base' => 2000, 'comision_pct' => 35, 'fecha_ingreso' => '2023-07-03']);

        $c = $this->herramienta($llave, 'cobranza');
        $this->assertSame(1160, (int) $c['por_cobrar']);
        $this->assertSame(1160, (int) $c['vencido']);
        $this->assertSame('ACME MEXICO', $c['clientes'][0]['cliente']);
        $c = $this->herramienta($llave, 'cobranza', ['cliente' => 'acme']);
        $this->assertSame('5-10', $c['documentos_pendientes'][0]['folio']);

        $n = $this->herramienta($llave, 'nomina');
        $this->assertSame(40, $n['semana']);
        $this->assertStringContainsString('vista previa', $n['estatus']);
        $this->assertSame(100, (int) $n['bolsa_comision']);                       // 10% de (2000 − 1000)
        $this->assertSame(2035, (int) collect($n['lineas'])->firstWhere('empleado', 'Rosa')['total']);
        $this->assertSame(0, Nomina::count());                     // no guardó nada

        $g = $this->herramienta($llave, 'gastos', ['mes' => 9, 'detalle' => true]);
        $this->assertSame(500, (int) $g['total']);
        $this->assertSame('GASOLINA', $g['por_categoria'][0]['categoria']);
        $this->assertSame('Magna', $g['detalle'][0]['descripcion']);

        $t = $this->herramienta($llave, 'tablero', ['mes' => 9]);
        $this->assertSame(2000, (int) $t['resumen']['venta']);
        $this->assertCount(12, $t['meses']);

        $this->assertArrayHasKey('resumen', $this->herramienta($llave, 'mis_finanzas'));

        Vacacion::create(['user_id' => $rosa->id, 'tipo' => 'tomadas', 'desde' => '2026-10-12', 'hasta' => '2026-10-13', 'dias' => 2, 'estatus' => 'registrada']);
        $v = $this->herramienta($llave, 'vacaciones', ['empleado' => 'rosa']);
        $this->assertSame(16, (int) $v['empleados'][0]['dias_del_periodo']);
        $this->assertSame('2026-10-12', $v['proximas_vacaciones'][0]['desde']);

        $nombres = array_column($this->rpc($llave, 'tools/list')->json('result.tools'), 'name');
        foreach (['cobranza', 'nomina', 'gastos', 'tablero', 'mis_finanzas', 'vacaciones'] as $h) {
            $this->assertContains($h, $nombres);
        }
        Carbon::setTestNow();
    }

    public function test_registra_gastos_sin_duplicar(): void
    {
        Carbon::setTestNow('2026-10-13 21:00:00');
        $admin = User::factory()->create(['rol' => 'admin', 'name' => 'Daniel']);
        [, $llave] = McpToken::emitir($admin, 'x', null, personal: true);
        $bbva = Cuenta::create(['nombre' => 'BBVA DEBITO 9263', 'tipo' => 'debito', 'activa' => true]);
        Cuenta::create(['nombre' => 'BANAMEX DEBITO', 'tipo' => 'debito', 'activa' => true]);

        $g = $this->herramienta($llave, 'registrar_gasto', ['clave_gasto' => '5B7DCC41A7', 'fecha' => '2026-10-13', 'monto' => 12546,
            'categoria' => 'impuestos', 'descripcion' => 'Impuestos SAT septiembre', 'cuenta' => '9263']);
        $this->assertSame('BBVA DEBITO 9263', $g['cuenta']);
        $this->assertSame('IMPUESTOS', $g['categoria']);
        $this->assertSame('ia', Gasto::first()->origen);
        $this->assertSame($admin->id, Gasto::first()->user_id);

        // Reintento con la misma clave: no duplica.
        $g2 = $this->herramienta($llave, 'registrar_gasto', ['clave_gasto' => '5B7DCC41A7', 'fecha' => '2026-10-13', 'monto' => 12546,
            'categoria' => 'IMPUESTOS', 'cuenta' => 'BBVA DEBITO 9263']);
        $this->assertSame($g['id'], $g2['id']);
        $this->assertSame(1, Gasto::count());

        // Otra clave pero misma fecha, monto y cuenta: pide confirmar.
        $args = ['clave_gasto' => 'otra', 'fecha' => '2026-10-13', 'monto' => 12546, 'categoria' => 'IMPUESTOS', 'cuenta' => 'bbva debito 9263'];
        $r = $this->rpc($llave, 'tools/call', ['name' => 'registrar_gasto', 'arguments' => $args])->json('result');
        $this->assertTrue($r['isError']);
        $this->assertStringContainsString('confirmar_duplicado', $r['content'][0]['text']);
        $this->herramienta($llave, 'registrar_gasto', $args + ['confirmar_duplicado' => true]);
        $this->assertSame(2, Gasto::count());

        // Cuenta que no existe o ambigua: no se inventa, enseña la lista.
        $r = $this->rpc($llave, 'tools/call', ['name' => 'registrar_gasto', 'arguments' => ['clave_gasto' => 'c', 'fecha' => '2026-10-13', 'monto' => 650,
            'categoria' => 'CONTADOR Y TIMBRES', 'cuenta' => 'SANTANDER']])->json('result');
        $this->assertTrue($r['isError']);
        $this->assertStringContainsString('BANAMEX DEBITO', $r['content'][0]['text']);
        $r = $this->rpc($llave, 'tools/call', ['name' => 'registrar_gasto', 'arguments' => ['clave_gasto' => 'c', 'fecha' => '2026-10-13', 'monto' => 650,
            'categoria' => 'CONTADOR Y TIMBRES', 'cuenta' => 'DEBITO']])->json('result');
        $this->assertStringContainsString('varias cuentas', $r['content'][0]['text']);

        // Categorías del sistema o inventadas: no.
        foreach (['NOMINA', 'COSAS'] as $cat) {
            $r = $this->rpc($llave, 'tools/call', ['name' => 'registrar_gasto', 'arguments' => ['clave_gasto' => 'n'.$cat, 'fecha' => '2026-10-13', 'monto' => 10,
                'categoria' => $cat, 'cuenta' => '9263']])->json('result');
            $this->assertTrue($r['isError']);
        }
        $this->assertSame(2, Gasto::count());

        // La consulta de gastos ya lo ve.
        $this->assertSame(25092, (int) $this->herramienta($llave, 'gastos', ['mes' => 10])['total']);
        $this->assertContains('registrar_gasto', array_column($this->rpc($llave, 'tools/list')->json('result.tools'), 'name'));
        Carbon::setTestNow();
    }

    public function test_solo_el_admin_autoriza_y_usa_el_mcp(): void
    {
        $vendedor = User::factory()->create(['rol' => 'vendedor']);
        $cliente = $this->postJson('/oauth/registro', ['client_name' => 'ChatGPT', 'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect']])->json();
        $this->actingAs($vendedor)->get('/oauth/autorizar?'.http_build_query(['response_type' => 'code', 'client_id' => $cliente['client_id'],
            'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect', 'code_challenge' => 'x', 'code_challenge_method' => 'S256']))->assertForbidden();
        $this->get('/conexion-ia')->assertForbidden();

        // Una llave de alguien que deja de ser admin ya no sirve.
        [, $llave] = McpToken::emitir($vendedor, 'x', null, personal: true);
        $this->rpc($llave, 'tools/list')->assertStatus(401);

        // redirect_uri no registrada: no.
        $this->actingAs(User::factory()->create(['rol' => 'admin']))->get('/oauth/autorizar?'.http_build_query(['response_type' => 'code', 'client_id' => $cliente['client_id'],
            'redirect_uri' => 'https://malo.com/cb', 'code_challenge' => 'x']))->assertStatus(400);
    }
}

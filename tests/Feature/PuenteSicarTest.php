<?php

namespace Tests\Feature;

use App\Enums\EstadoLoteSicar;
use App\Livewire\Sicar\Alta;
use App\Livewire\Sicar\Lotes;
use App\Models\Ajuste;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\SicarArticulo;
use App\Models\SicarLote;
use App\Models\SicarProveedor;
use App\Models\User;
use App\Support\PuenteSicar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PuenteSicarTest extends TestCase
{
    use RefreshDatabase;

    private const H = ['Authorization' => 'Bearer secreto'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sicar.token' => 'secreto']);
    }

    private function lote(EstadoLoteSicar $estado, array $extra = [], ?Pedido $pedido = null): SicarLote
    {
        $l = SicarLote::create(array_merge(['estado' => $estado, 'datos' => ['pedidos' => [], 'articulos' => [], 'claves_sat' => [], 'compras' => []]], $extra));
        if ($pedido) {
            $l->pedidos()->attach($pedido->id);
        }

        return $l;
    }

    private function art(int $id, string $clave, string $desc, array $extra = []): SicarArticulo
    {
        return SicarArticulo::create(array_merge([
            'art_id' => $id, 'clave' => $clave, 'descripcion' => $desc, 'unidad' => 'PZA', 'precio_compra' => 100, 'precio1' => 130,
            'status' => 1, 'clave_llave' => SicarArticulo::llave($clave), 'sincronizado_at' => now(),
        ], $extra));
    }

    private function prov(string $nombre, ?int $proId = 194): Proveedor
    {
        return Proveedor::factory()->create(['nombre' => $nombre, 'sicar_pro_id' => $proId]);
    }

    private function pedido(array $extra = []): Pedido
    {
        return Pedido::factory()->create(array_merge(['folio' => 55, 'numero_oc_cliente' => 'MX4003699'], $extra));
    }

    private function partida(Pedido $pedido, array $datos = []): PedidoPartida
    {
        return PedidoPartida::factory()->create(array_merge(['pedido_id' => $pedido->id], $datos));
    }

    // ------------------------------------------------------------ API

    public function test_token_invalido_da_401(): void
    {
        $this->getJson('/api/sicar/puente')->assertUnauthorized();
        $this->getJson('/api/sicar/puente', ['Authorization' => 'Bearer malo'])->assertUnauthorized();
        $this->postJson('/api/sicar/puente/lotes/1', ['estado' => 'simulado'])->assertUnauthorized();
        $this->postJson('/api/sicar/proveedores', ['proveedores' => []])->assertUnauthorized();
    }

    public function test_get_devuelve_confirmado_antes_que_pendiente_y_registra_conexion(): void
    {
        $this->lote(EstadoLoteSicar::Pendiente);
        $conf = $this->lote(EstadoLoteSicar::Confirmado);
        $this->assertNull(Ajuste::ultimaConexionPuente());

        $this->getJson('/api/sicar/puente', self::H)->assertOk()
            ->assertJsonPath('activo', true)
            ->assertJsonPath('lote.id', $conf->id)
            ->assertJsonPath('lote.estado', 'confirmado')
            ->assertJsonStructure(['lote' => ['datos' => ['pedidos', 'articulos', 'claves_sat', 'compras']]]);
        $this->assertNotNull(Ajuste::ultimaConexionPuente());
    }

    public function test_get_devuelve_el_pendiente_mas_antiguo_y_null_si_no_hay(): void
    {
        $this->getJson('/api/sicar/puente', self::H)->assertOk()->assertJsonPath('lote', null);
        $a = $this->lote(EstadoLoteSicar::Pendiente);
        $this->lote(EstadoLoteSicar::Pendiente);
        $this->lote(EstadoLoteSicar::Simulado);

        $this->getJson('/api/sicar/puente', self::H)->assertJsonPath('lote.id', $a->id);
    }

    public function test_puente_apagado_no_entrega_lote(): void
    {
        $this->lote(EstadoLoteSicar::Pendiente);
        Ajuste::ponerPuenteActivo(false);

        $this->getJson('/api/sicar/puente', self::H)->assertOk()->assertJsonPath('activo', false)->assertJsonPath('lote', null);
        $this->assertNotNull(Ajuste::ultimaConexionPuente());
    }

    public function test_transiciones_validas_e_invalidas(): void
    {
        $l = $this->lote(EstadoLoteSicar::Pendiente);
        $url = "/api/sicar/puente/lotes/{$l->id}";

        $this->postJson($url, ['estado' => 'aplicado'], self::H)->assertStatus(409);
        $this->postJson($url, ['estado' => 'raro'], self::H)->assertStatus(422);
        $this->postJson($url, ['estado' => 'simulado', 'resultado' => ['avisos' => ['ojo'], 'bloqueos' => []]], self::H)->assertOk();
        $l->refresh();
        $this->assertSame(EstadoLoteSicar::Simulado, $l->estado);
        $this->assertNotNull($l->simulado_at);
        $this->assertSame(['ojo'], $l->resultado['avisos']);

        // simulado no lo mueve la PC (falta que alguien confirme)
        $this->postJson($url, ['estado' => 'aplicado'], self::H)->assertStatus(409);
        $this->postJson($url, ['estado' => 'error'], self::H)->assertStatus(409);
        $this->postJson('/api/sicar/puente/lotes/9999', ['estado' => 'simulado'], self::H)->assertNotFound();

        $l2 = $this->lote(EstadoLoteSicar::Pendiente);
        $this->postJson("/api/sicar/puente/lotes/{$l2->id}", ['estado' => 'error', 'mensaje' => 'SICAR no responde'], self::H)->assertOk();
        $this->assertSame(EstadoLoteSicar::Error, $l2->fresh()->estado);
        $this->assertSame('SICAR no responde', $l2->fresh()->mensaje);
    }

    public function test_aplicado_marca_pedidos_y_guarda_art_id(): void
    {
        $confirmador = User::factory()->create(['name' => 'Rosa']);
        $pedido = $this->pedido();
        $p1 = $this->partida($pedido, ['sicar_clave' => 'TC9114', 'sicar_clave_sat' => '23241801', 'sicar_unidad' => 'PZA']);
        $p2 = $this->partida($pedido);
        $l = $this->lote(EstadoLoteSicar::Confirmado, ['confirmado_por' => $confirmador->id], $pedido);

        $this->postJson("/api/sicar/puente/lotes/{$l->id}", [
            'estado' => 'aplicado',
            'resultado' => ['articulos' => [['clave' => 'TC9114', 'art_id' => 10840, 'partida_ids' => [$p1->id]]], 'compras' => [], 'respaldo' => 'x.sql'],
        ], self::H)->assertOk();

        $this->assertSame(EstadoLoteSicar::Aplicado, $l->fresh()->estado);
        $this->assertNotNull($l->fresh()->aplicado_at);
        $this->assertTrue($pedido->fresh()->alta_sicar);
        $p1->refresh();
        $this->assertSame(10840, (int) $p1->sicar_art_id);
        $this->assertNull($p1->sicar_clave);
        $this->assertNull($p1->sicar_clave_sat);
        $this->assertNull($p2->fresh()->sicar_art_id);
        $this->assertDatabaseHas('pedido_actividades', ['pedido_id' => $pedido->id, 'tipo' => 'alta_sicar',
            'descripcion' => "Dado de alta en SICAR (lote {$l->id}) — confirmó Rosa"]);
    }

    public function test_error_libera_los_pedidos(): void
    {
        $pedido = $this->pedido();
        $l = $this->lote(EstadoLoteSicar::Confirmado, [], $pedido);

        $this->postJson("/api/sicar/puente/lotes/{$l->id}", ['estado' => 'error', 'mensaje' => 'falló'], self::H)->assertOk();

        $this->assertFalse($pedido->fresh()->alta_sicar);
        $this->assertSame([], (new PuenteSicar)->pedidosEnLoteAbierto([$pedido->id]));
    }

    public function test_proveedores_upsert(): void
    {
        $this->postJson('/api/sicar/proveedores', ['proveedores' => [
            ['pro_id' => 400, 'nombre' => 'HIDROMAX', 'status' => 1], ['pro_id' => 194, 'nombre' => 'URREA', 'status' => 1],
        ]], self::H)->assertOk()->assertJsonPath('recibidos', 2);
        $this->postJson('/api/sicar/proveedores', ['proveedores' => [['pro_id' => 400, 'nombre' => 'HIDROMAX SA', 'status' => 0]]], self::H)->assertOk();

        $this->assertSame(2, SicarProveedor::count());
        $this->assertSame('HIDROMAX SA', SicarProveedor::where('pro_id', 400)->first()->nombre);
        $this->assertSame(0, SicarProveedor::where('pro_id', 400)->first()->status);
    }

    // ------------------------------------------------------------ Alta: mandar a SICAR

    public function test_mandar_arma_datos_con_el_formato_exacto(): void
    {
        $vendedor = User::factory()->create();
        $urrea = $this->prov('URREA', 194);
        $this->art(10840, '44349', 'LLAVE EXISTENTE 44349', ['unidad' => 'PZA', 'clave_prod_serv' => '', 'precio_compra' => 100]);
        $this->art(10841, 'ABC123', 'DISCO ABC123 EXISTENTE', ['clave_prod_serv' => '31161507']);
        $ped1 = $this->pedido(['folio' => 55]);
        $ped2 = $this->pedido(['folio' => 56, 'numero_oc_cliente' => 'OC2']);
        $nuevo = $this->partida($ped1, ['descripcion' => 'Cortador especial TC9114', 'modelo' => 'TC9114', 'cantidad' => 1, 'costo' => 26505, 'precio' => 30480.75, 'proveedor_id' => $urrea->id]);
        $sinSat = $this->partida($ped1, ['descripcion' => 'x', 'modelo' => '44349', 'cantidad' => 2, 'costo' => 100, 'precio' => 130, 'proveedor_id' => $urrea->id, 'unidad' => 'PZA']);
        $con = $this->partida($ped1, ['descripcion' => 'x', 'modelo' => 'ABC123', 'cantidad' => 3, 'costo' => 10, 'precio' => 13, 'proveedor_id' => $urrea->id]);
        // mismo artículo nuevo en otro pedido: se consolida (costo ponderado)
        $nuevo2 = $this->partida($ped2, ['descripcion' => 'Cortador especial TC9114', 'modelo' => 'TC9114', 'cantidad' => 3, 'costo' => 26505 + 4, 'precio' => 30480.75, 'proveedor_id' => $urrea->id]);
        // el de SICAR 44349 sin SAT: propuesta guardada en la partida
        $sinSat->update(['sicar_art_id' => 10840, 'sicar_clave_sat' => '31161507']);
        // el ABC123 ya tiene clave SAT en SICAR pero la IA propone otra: se corrige y se manda la anterior
        $con->update(['sicar_art_id' => 10841, 'sicar_clave_sat' => '27112700']);

        Livewire::actingAs($vendedor)->test(Alta::class)
            ->set('seleccion', [$ped1->id, $ped2->id])->call('mandar')
            ->assertSet('error', '')->assertSee('Lote '.SicarLote::firstOrFail()->id.' mandado', false);

        $lote = SicarLote::firstOrFail();
        $this->assertSame(EstadoLoteSicar::Pendiente, $lote->estado);
        $this->assertSame($vendedor->id, $lote->creado_por);
        $this->assertEqualsCanonicalizing([$ped1->id, $ped2->id], $lote->pedidos->pluck('id')->all());

        $d = $lote->datos;
        $this->assertSame(['pedidos', 'articulos', 'claves_sat', 'compras'], array_keys($d));
        $this->assertSame(['id', 'folio', 'cliente', 'oc'], array_keys($d['pedidos'][0]));
        $this->assertSame('P-55', $d['pedidos'][0]['folio']);
        $this->assertSame('MX4003699', $d['pedidos'][0]['oc']);

        $this->assertCount(1, $d['articulos']);
        $a = $d['articulos'][0];
        $this->assertSame(['clave', 'descripcion', 'unidad', 'categoria', 'clave_sat', 'costo', 'precio', 'sicar_pro_id', 'modelo', 'partida_ids'], array_keys($a));
        $this->assertSame('TC9114', $a['clave']);
        $this->assertSame('FERRETERIA', $a['categoria']);
        $this->assertSame(194, $a['sicar_pro_id']);
        $this->assertSame('TC9114', $a['modelo']);
        $this->assertEqualsCanonicalizing([$nuevo->id, $nuevo2->id], $a['partida_ids']);
        $this->assertEqualsWithDelta(26508.0, $a['costo'], 0.0001);   // (1*26505 + 3*26509)/4
        $this->assertEqualsWithDelta(30480.75, $a['precio'], 0.001);

        $this->assertEqualsCanonicalizing([
            ['art_id' => 10840, 'clave' => '44349', 'clave_sat' => '31161507', 'anterior' => ''],
            ['art_id' => 10841, 'clave' => 'ABC123', 'clave_sat' => '27112700', 'anterior' => '31161507'],
        ], $d['claves_sat']);

        $this->assertCount(1, $d['compras']);
        $c = $d['compras'][0];
        $this->assertSame(194, $c['sicar_pro_id']);
        $this->assertSame('URREA', $c['proveedor']);
        $this->assertCount(3, $c['renglones']);   // TC9114 consolidado, 44349 y ABC123
        $tc = collect($c['renglones'])->firstWhere('clave', 'TC9114');
        $this->assertSame(['clave', 'art_id', 'descripcion', 'cantidad', 'costo', 'pedidos'], array_keys($tc));
        $this->assertNull($tc['art_id']);
        $this->assertEquals(4, $tc['cantidad']);
        $this->assertEqualsWithDelta(26508.0, $tc['costo'], 0.0001);
        $this->assertSame(['P-55', 'P-56'], $tc['pedidos']);
        $ex = collect($c['renglones'])->firstWhere('clave', 'ABC123');
        $this->assertSame(10841, $ex['art_id']);
    }

    public function test_proveedor_sin_mapeo_bloquea_y_se_guarda_desde_la_pantalla(): void
    {
        $vendedor = User::factory()->create();
        $hs = $this->prov('HIDROMAX SUCURSAL SUR', null);
        SicarProveedor::create(['pro_id' => 400, 'nombre' => 'HIDROMAX', 'status' => 1]);
        SicarProveedor::create(['pro_id' => 500, 'nombre' => 'OTRO', 'status' => 1]);
        $ped = $this->pedido();
        $this->partida($ped, ['proveedor_id' => $hs->id]);

        $c = Livewire::actingAs($vendedor)->test(Alta::class)->set('seleccion', [$ped->id])
            ->assertSee('Proveedores en SICAR')->assertSee('HIDROMAX (400) — sugerido')
            ->call('mandar')->assertSee('No se puede mandar', false);
        $this->assertSame(0, SicarLote::count());

        $c->set('proveedorSicar', [$hs->id => '400'])->call('guardarProveedorSicar', $hs->id)->assertSet('error', '');
        $this->assertSame(400, (int) $hs->fresh()->sicar_pro_id);

        $c->call('mandar')->assertSet('error', '');
        $this->assertSame(1, SicarLote::count());
    }

    public function test_partida_sin_proveedor_bloquea(): void
    {
        $ped = $this->pedido();
        $this->partida($ped, ['proveedor_id' => null]);

        Livewire::actingAs(User::factory()->create())->test(Alta::class)->set('seleccion', [$ped->id])->call('mandar')
            ->assertSee('sin proveedor');
        $this->assertSame(0, SicarLote::count());
    }

    public function test_posible_sin_decidir_bloquea(): void
    {
        $this->art(1, 'ZZ1', 'DISCO CORTE METAL 4 1/2 X 3/32 PULGADAS');
        $ped = $this->pedido();
        $this->partida($ped, ['descripcion' => 'Disco de corte para metal 4 1/2 x 3/32 pulgadas', 'modelo' => null, 'proveedor_id' => $this->prov('URREA')->id]);

        $c = Livewire::actingAs(User::factory()->create())->test(Alta::class)->set('seleccion', [$ped->id])->call('mandar');
        $c->assertSee('por decidir');
        $this->assertSame(0, SicarLote::count());
    }

    public function test_pedido_en_lote_abierto_bloquea_y_sin_tarjetas_no_manda(): void
    {
        $ped = $this->pedido();
        $this->partida($ped, ['proveedor_id' => $this->prov('URREA')->id]);
        $this->lote(EstadoLoteSicar::Simulado, [], $ped);

        Livewire::actingAs(User::factory()->create())->test(Alta::class)->set('seleccion', [$ped->id])->call('mandar')
            ->assertSee('ya está en el lote');
        $this->assertSame(1, SicarLote::count());

        Livewire::actingAs(User::factory()->create())->test(Alta::class)->call('mandar');
        $this->assertSame(1, SicarLote::count());
    }

    public function test_lote_cancelado_libera_al_pedido(): void
    {
        $ped = $this->pedido();
        $this->partida($ped, ['proveedor_id' => $this->prov('URREA')->id]);
        $this->lote(EstadoLoteSicar::Cancelado, [], $ped);

        Livewire::actingAs(User::factory()->create())->test(Alta::class)->set('seleccion', [$ped->id])->call('mandar')->assertSet('error', '');
        $this->assertSame(2, SicarLote::count());
    }

    // ------------------------------------------------------------ Lotes

    public function test_confirmar_solo_en_simulado_y_sin_bloqueos(): void
    {
        $vendedor = User::factory()->create();
        $sim = $this->lote(EstadoLoteSicar::Simulado, ['resultado' => ['bloqueos' => [], 'avisos' => ['ojo']]]);
        $bloq = $this->lote(EstadoLoteSicar::Simulado, ['resultado' => ['bloqueos' => ['falta algo']]]);
        $pend = $this->lote(EstadoLoteSicar::Pendiente);

        $c = Livewire::actingAs($vendedor)->test(Lotes::class);
        $c->call('confirmar', $bloq->id)->call('confirmar', $pend->id);
        $this->assertSame(EstadoLoteSicar::Simulado, $bloq->fresh()->estado);
        $this->assertSame(EstadoLoteSicar::Pendiente, $pend->fresh()->estado);

        $c->call('confirmar', $sim->id);
        $sim->refresh();
        $this->assertSame(EstadoLoteSicar::Confirmado, $sim->estado);
        $this->assertSame($vendedor->id, $sim->confirmado_por);
        $this->assertNotNull($sim->confirmado_at);
    }

    public function test_cancelar_libera_pedidos_solo_en_estados_permitidos(): void
    {
        $ped = $this->pedido();
        $sim = $this->lote(EstadoLoteSicar::Simulado, [], $ped);
        $conf = $this->lote(EstadoLoteSicar::Confirmado);

        $c = Livewire::actingAs(User::factory()->create())->test(Lotes::class);
        $c->call('cancelar', $conf->id);
        $this->assertSame(EstadoLoteSicar::Confirmado, $conf->fresh()->estado);
        $c->call('cancelar', $sim->id);
        $this->assertSame(EstadoLoteSicar::Cancelado, $sim->fresh()->estado);
        $this->assertSame([], (new PuenteSicar)->pedidosEnLoteAbierto([$ped->id]));
    }

    public function test_pantalla_lotes_muestra_detalle_y_conexion(): void
    {
        $l = $this->lote(EstadoLoteSicar::Simulado, [
            'datos' => ['pedidos' => [], 'articulos' => [], 'claves_sat' => [], 'compras' => [
                ['sicar_pro_id' => 194, 'proveedor' => 'URREA', 'renglones' => [['clave' => 'A', 'art_id' => null, 'descripcion' => 'X', 'cantidad' => 2, 'costo' => 100, 'pedidos' => ['P-1']]]],
            ]],
            'resultado' => ['avisos' => ['Aviso amarillo'], 'bloqueos' => ['Bloqueo rojo']],
        ]);

        Livewire::actingAs(User::factory()->create())->test(Lotes::class)
            ->assertSee('La PC nunca se ha conectado')->assertSee('La PC de Daniel no responde.')
            ->call('detalle', $l->id)->assertSee('Aviso amarillo')->assertSee('Bloqueo rojo')
            ->assertSee('$200.00')->assertSee('$232.00')->assertDontSee('Confirmar');

        Ajuste::registrarConexionPuente();
        Livewire::actingAs(User::factory()->create())->test(Lotes::class)->assertSee('La PC se conectó hace 0 min')->assertDontSee('los lotes esperan');
    }

    public function test_campo_y_ned_reciben_403_y_vendedor_entra(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'campo']))->get('/sicar/lotes')->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => 'nedtracking']))->get('/sicar/lotes')->assertRedirect();
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']))->get('/sicar/lotes')->assertOk();
        $this->actingAs(User::factory()->create(['rol' => 'admin']))->get('/sicar/lotes')->assertOk();
    }

    public function test_solo_admin_mueve_el_interruptor(): void
    {
        Livewire::actingAs(User::factory()->create(['rol' => 'vendedor']))->test(Lotes::class)
            ->assertSee('Encendido')->assertDontSee('Apagar puente')->call('alternarPuente')->assertForbidden();
        $this->assertTrue(Ajuste::puenteActivo());

        Livewire::actingAs(User::factory()->create(['rol' => 'admin']))->test(Lotes::class)->call('alternarPuente')->assertSee('Apagado');
        $this->assertFalse(Ajuste::puenteActivo());
    }
}

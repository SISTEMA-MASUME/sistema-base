<?php

namespace Tests\Feature;

use App\Livewire\Sicar\Alta;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\SicarArticulo;
use App\Models\SicarSincronizacion;
use App\Models\User;
use App\Support\AltaSicar;
use App\Support\EmparejadorSicar;
use App\Support\HojaAltasSicar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AltaSicarTest extends TestCase
{
    use RefreshDatabase;

    private function art(int $id, string $clave, string $desc, array $extra = []): SicarArticulo
    {
        return SicarArticulo::create(array_merge([
            'art_id' => $id, 'clave' => $clave, 'descripcion' => $desc, 'unidad' => 'PZA', 'precio_compra' => 100, 'precio1' => 130,
            'status' => 1, 'clave_llave' => SicarArticulo::llave($clave), 'sincronizado_at' => now(),
        ], $extra));
    }

    private function partida(Pedido $pedido, array $datos = []): PedidoPartida
    {
        return PedidoPartida::factory()->create(array_merge(['pedido_id' => $pedido->id], $datos));
    }

    // ------------------------------------------------------------ endpoint

    private function lote(array $articulos, int $lote = 1, int $total = 1, ?int $sync = null): array
    {
        return ['lote' => $lote, 'total_lotes' => $total, 'sincronizacion_id' => $sync, 'articulos' => $articulos];
    }

    private function unArticulo(int $id = 1, array $extra = []): array
    {
        return array_merge(['art_id' => $id, 'clave' => 'ABC-1', 'clave_alterna' => '', 'descripcion' => 'Tornillo', 'unidad' => 'PZA',
            'precio_compra' => 1.5, 'precio1' => 2.5, 'clave_prod_serv' => '31161500', 'categoria' => 'FERRETERIA', 'status' => 1], $extra);
    }

    public function test_sin_token_o_con_token_invalido_da_401(): void
    {
        config(['services.sicar.token' => 'secreto']);

        $this->postJson('/api/sicar/catalogo', $this->lote([$this->unArticulo()]))->assertUnauthorized();
        $this->postJson('/api/sicar/catalogo', $this->lote([$this->unArticulo()]), ['Authorization' => 'Bearer malo'])->assertUnauthorized();
        $this->assertSame(0, SicarArticulo::count());
    }

    public function test_sin_token_configurado_nunca_autoriza(): void
    {
        config(['services.sicar.token' => '']);

        $this->postJson('/api/sicar/catalogo', $this->lote([$this->unArticulo()]), ['Authorization' => 'Bearer '])->assertUnauthorized();
    }

    public function test_sincroniza_hace_upsert_y_normaliza_la_clave(): void
    {
        config(['services.sicar.token' => 'secreto']);
        $h = ['Authorization' => 'Bearer secreto'];

        $r = $this->postJson('/api/sicar/catalogo', $this->lote([$this->unArticulo(1), $this->unArticulo(2, ['clave' => 'x-2.b'])]), $h)
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('recibidos', 2);
        $this->assertSame('X2B', SicarArticulo::find(2)->clave_llave);
        $this->assertNotNull($r->json('sincronizacion_id'));

        // Segunda sincronización: cambia el precio y el status del artículo 1; no duplica.
        $this->postJson('/api/sicar/catalogo', $this->lote([$this->unArticulo(1, ['precio_compra' => 9, 'status' => 0])]), $h)->assertOk();
        $this->assertSame(2, SicarArticulo::count());
        $this->assertSame(0, SicarArticulo::find(1)->status);
        $this->assertEquals(9, SicarArticulo::find(1)->precio_compra);
    }

    public function test_lotes_acumulan_y_el_ultimo_cierra_la_sincronizacion(): void
    {
        config(['services.sicar.token' => 'secreto']);
        $h = ['Authorization' => 'Bearer secreto'];

        $r1 = $this->postJson('/api/sicar/catalogo', $this->lote([$this->unArticulo(1), $this->unArticulo(2, ['status' => 0])], 1, 2), $h)->assertOk();
        $id = $r1->json('sincronizacion_id');
        $this->assertNull(SicarSincronizacion::ultimaCompleta());

        $this->postJson('/api/sicar/catalogo', $this->lote([$this->unArticulo(3)], 2, 2, $id), $h)
            ->assertOk()->assertJsonPath('sincronizacion_id', $id)->assertJsonPath('recibidos', 3);

        $sync = SicarSincronizacion::ultimaCompleta();
        $this->assertSame($id, $sync->id);
        $this->assertSame(3, $sync->recibidos);
        $this->assertSame(2, $sync->activos);
        $this->assertNotNull($sync->fin);
        $this->assertNotNull(SicarArticulo::find(3)->sincronizado_at);
    }

    // ------------------------------------------------------------ emparejador

    public function test_existe_por_modelo(): void
    {
        $this->art(1, '6203-2RS', 'BALERO 6203 2RS');

        $r = (new EmparejadorSicar)->emparejar(['descripcion' => 'Rodamiento cualquiera', 'modelo' => '6203 2rs', 'unidad' => 'PZA', 'costo' => 100]);

        $this->assertSame('existe', $r['estado']);
        $this->assertSame(1, $r['articulo']->art_id);
        $this->assertSame([], $r['avisos']);
    }

    public function test_existe_por_clave_al_inicio_de_la_descripcion_y_avisa_unidad_y_costo(): void
    {
        $this->art(1, 'CINTA100', 'CINTA AISLANTE 19MM', ['unidad' => 'ROLLO', 'precio_compra' => 100]);

        $r = (new EmparejadorSicar)->emparejar(['descripcion' => 'CINTA100 aislante negra', 'modelo' => null, 'unidad' => 'PZA', 'costo' => 150]);

        $this->assertSame('existe', $r['estado']);
        $this->assertCount(2, $r['avisos']);
        $this->assertStringContainsString('Unidad distinta', $r['avisos'][0]);
        $this->assertStringContainsString('Costo distinto', $r['avisos'][1]);
    }

    public function test_posible_por_descripcion_parecida_y_no_empareja_inactivos(): void
    {
        $this->art(1, 'ZZ1', 'DISCO CORTE METAL 4 1/2 X 3/32 PULGADAS');
        $this->art(2, 'ZZ2', 'DISCO CORTE METAL 9 PULGADAS', ['status' => 0]);

        $e = new EmparejadorSicar;
        $r = $e->emparejar(['descripcion' => 'Disco de corte para metal 4 1/2 x 3/32 pulgadas', 'modelo' => '', 'unidad' => 'PZA', 'costo' => 0]);
        $this->assertSame('posible', $r['estado']);
        $this->assertSame(1, $r['articulo']->art_id);
        $this->assertGreaterThanOrEqual(0.6, $r['similitud']);

        // Medidas distintas = artículo distinto.
        $this->assertSame('nuevo', $e->emparejar(['descripcion' => 'Disco de corte para metal 9 pulgadas', 'modelo' => '', 'unidad' => 'PZA'])['estado']);
    }

    public function test_nuevo_con_clave_propuesta_que_no_choca_y_sin_sat_copiada_de_sicar(): void
    {
        $this->art(1, 'GUANTE-1', 'GUANTE DE CARNAZA SOLDADOR LARGO', ['clave_prod_serv' => '46181500']);
        $this->art(2, 'GUANTE-2', 'GUANTE CARNAZA CORTO', ['clave_prod_serv' => '46181500']);
        $this->art(3, 'GUANTE-3', 'GUANTE NITRILO CARNAZA', ['clave_prod_serv' => '01010101']);
        $this->art(4, 'OTRO', 'SOLDADOR GUANTE', ['clave_prod_serv' => '01010101']);
        $this->art(5, 'MODELO-X', 'ALGO DISTINTO');

        $e = new EmparejadorSicar;
        $p = $e->proponer(['descripcion' => 'Guante de carnaza para soldador largo XL', 'modelo' => 'MODELO-X']);

        $this->assertSame('', $p['clave_sat'], 'la clave SAT no se copia de los artículos de SICAR');
        $this->assertNotSame('MODELO-X', $p['clave']); // ya existe: cae a la descripción
        $this->assertSame('GUANTE DE CARNAZA PARA SOLDADOR LARGO XL', $p['clave']);

        // Modelo libre: se usa tal cual (mayúsculas).
        $this->assertSame('ABC-99', $e->proponer(['descripcion' => 'Nada que ver zzzz', 'modelo' => 'abc-99'])['clave']);
        // Un mismo artículo nuevo repetido conserva su clave; otro con el mismo texto de clave lleva sufijo.
        $this->assertSame('ABC-99', $e->proponerClave(['descripcion' => 'Nada que ver zzzz', 'modelo' => 'ABC 99']));
        $this->assertSame('ABC-99', $e->proponerClave(['descripcion' => 'Nada que ver zzzz', 'modelo' => 'abc-99']));
    }

    public function test_clave_por_descripcion_se_recorta_a_45_sin_cortar_palabras_y_no_se_repite(): void
    {
        $e = new EmparejadorSicar;
        $larga = 'TORNILLO HEXAGONAL GRADO 5 ROSCA ORDINARIA ZINCADO 1/2 X 3 PULGADAS';
        $c1 = $e->proponerClave(['descripcion' => $larga, 'modelo' => '']);
        $this->assertLessThanOrEqual(45, mb_strlen($c1));
        $this->assertStringStartsWith('TORNILLO HEXAGONAL GRADO 5 ROSCA', $c1);
        $this->assertTrue(str_contains($larga, $c1));
        $this->assertStringEndsNotWith(' ', $c1);

        $c2 = $e->proponerClave(['descripcion' => $larga.' GALVANIZADO', 'modelo' => '']);
        $this->assertNotSame($c1, $c2);
        $this->assertLessThanOrEqual(45, mb_strlen($c2));
        $this->assertSame(0, strcasecmp(substr($c2, -2), ' 2'));
    }

    // ------------------------------------------------------------ pantalla

    private function entrar(string $rol = 'vendedor'): User
    {
        $u = User::factory()->create(['rol' => $rol]);
        $this->actingAs($u);

        return $u;
    }

    public function test_pantalla_lista_los_pedidos_de_la_columna_y_avisa_si_no_hay_sincronizacion(): void
    {
        $this->entrar();
        $a = Pedido::factory()->create(['columna' => 'en_tramite', 'numero_oc_cliente' => '111']);
        $b = Pedido::factory()->create(['columna' => 'oc_recibida', 'numero_oc_cliente' => '222']);
        $c = Pedido::factory()->create(['columna' => 'en_tramite', 'numero_oc_cliente' => '333', 'alta_sicar' => true]);
        $d = Pedido::factory()->create(['columna' => 'en_tramite', 'numero_oc_cliente' => '444', 'archivado_at' => now()]);
        $this->partida($a, ['descripcion' => 'TORNILLO PRUEBA UNO']);
        $this->partida($b, ['descripcion' => 'BROCA PRUEBA DOS']);

        $this->get(route('sicar.alta', ['columna' => 'en_tramite']))->assertOk()
            ->assertSee('OC 111')->assertDontSee('TORNILLO PRUEBA UNO')
            ->assertDontSee('OC 222')->assertDontSee('OC 333')->assertDontSee('OC 444')
            ->assertSee('nunca se ha sincronizado');

        $this->get(route('sicar.alta'))->assertOk()->assertSee('OC 111')->assertSee('OC 222')->assertDontSee('OC 333');
        Livewire::test(Alta::class)->set('seleccion', [$a->id])->assertSee('TORNILLO PRUEBA UNO')->assertDontSee('BROCA PRUEBA DOS')
            ->call('marcarTodas')->assertSee('BROCA PRUEBA DOS')->call('quitarTodas')->assertDontSee('TORNILLO PRUEBA UNO');
        Livewire::withQueryParams(['pedido' => $b->id])->test(Alta::class)->assertSet('seleccion', [$b->id])->assertSee('BROCA PRUEBA DOS');

        SicarSincronizacion::create(['inicio' => now(), 'fin' => now(), 'recibidos' => 1, 'activos' => 1]);
        $this->get(route('sicar.alta'))->assertDontSee('nunca se ha sincronizado')->assertDontSee('más de 24 horas');
        SicarSincronizacion::query()->update(['fin' => now()->subHours(30)]);
        $this->get(route('sicar.alta'))->assertSee('más de 24 horas');
    }

    public function test_pantalla_muestra_existe_posible_y_nuevo_y_guarda_decisiones(): void
    {
        $this->entrar();
        $this->art(1, 'BAL-6203', 'BALERO 6203');
        $this->art(2, 'DSC-1', 'DISCO CORTE METAL 4 1/2 X 3/32 PULGADAS');
        $pedido = Pedido::factory()->create();
        $pExiste = $this->partida($pedido, ['descripcion' => 'Balero', 'modelo' => 'BAL-6203', 'orden' => 1]);
        $pPosible = $this->partida($pedido, ['descripcion' => 'Disco corte metal 4 1/2 x 3/32 pulgadas', 'modelo' => null, 'orden' => 2]);
        $pNuevo = $this->partida($pedido, ['descripcion' => 'Llave especial rara', 'modelo' => 'LLV-9', 'orden' => 3]);

        $t = Livewire::test(Alta::class)->call('marcarTodas')
            ->assertSee('✅ Existe')->assertSee('🟡 Posible')->assertSee('🆕 Nuevo')->assertSee('LLV-9')->assertSee('Dar de alta + compra: 1')->assertSee('Solo compra: 1');

        $t->call('esEste', $pPosible->id, 2);
        $this->assertSame(2, $pPosible->fresh()->sicar_art_id);

        $t->call('guardarCampo', $pNuevo->id, 'clave', 'llv-9 especial')->call('guardarCampo', $pNuevo->id, 'sat', '27111500')
            ->call('guardarCampo', $pNuevo->id, 'unidad', 'juego');
        $f = $pNuevo->fresh();
        $this->assertSame('LLV-9 ESPECIAL', $f->sicar_clave);
        $this->assertSame('27111500', $f->sicar_clave_sat);
        $this->assertSame('JUEGO', $f->sicar_unidad);

        // Clave que ya existe en SICAR: se rechaza.
        $t->call('guardarCampo', $pNuevo->id, 'clave', 'bal-6203');
        $this->assertSame('LLV-9 ESPECIAL', $pNuevo->fresh()->sicar_clave);
        $this->assertStringContainsString('ya existe', $t->get('error'));

        $t->call('deshacer', $pNuevo->id);
        $this->assertNull($pNuevo->fresh()->sicar_clave);
    }

    // ------------------------------------------------------------ Excel para capturar a mano

    public function test_excel_trae_una_fila_por_partida_nuevos_primero_con_todo_para_capturar(): void
    {
        $this->entrar();
        $this->art(1, 'BAL-6203', 'BALERO 6203', ['clave_prod_serv' => '31171500', 'categoria' => 'BALEROS']);
        $prov = Proveedor::factory()->create(['nombre' => 'FERRETERIA NORTE']);
        $p1 = Pedido::factory()->create(['numero_oc_cliente' => 'OC-A']);
        $this->partida($p1, ['descripcion' => 'Balero', 'modelo' => 'BAL-6203', 'cantidad' => 3, 'costo' => 100, 'precio' => 130, 'proveedor_id' => $prov->id, 'orden' => 1]);
        $this->partida($p1, ['descripcion' => 'Grasa   litio  especial', 'modelo' => 'GRS-1', 'unidad' => 'PZ', 'cantidad' => 2, 'costo' => 50.5, 'precio' => 80, 'proveedor_id' => $prov->id, 'orden' => 2]);

        $filas = (new AltaSicar)->filas(Pedido::with(['cliente', 'partidas.proveedor'])->get());
        $ruta = (new HojaAltasSicar)->generar($filas);
        $h = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        $f0 = HojaAltasSicar::FILA_ENCABEZADOS;
        $col = array_flip(array_keys(HojaAltasSicar::COLUMNAS));   // llave => índice desde 0
        $v = fn (string $llave, int $fila) => $h->getCell([$col[$llave] + 1, $f0 + $fila])->getValue();
        $enc = [];
        for ($c = 1; $c <= count(HojaAltasSicar::COLUMNAS); $c++) {
            $enc[] = $h->getCell([$c, $f0])->getValue();
        }
        $this->assertSame(HojaAltasSicar::encabezados(), $enc);
        $this->assertStringContainsString('Altas en SICAR', (string) $h->getCell([1, 1])->getValue());

        // El nuevo va primero aunque en el pedido sea la segunda partida.
        $this->assertSame('DAR DE ALTA + COMPRA', $v('accion', 1));
        $this->assertSame('P-'.$p1->folio, $v('pedido', 1));
        $this->assertSame('OC-A', $v('oc', 1));
        $this->assertSame('GRS-1', $v('clave', 1));
        $this->assertSame('GRS-1', $v('modelo', 1));
        $this->assertSame('GRASA LITIO ESPECIAL', $v('descripcion', 1));
        $this->assertSame('PZA', $v('unidad', 1));
        $this->assertEquals(2, $v('cantidad', 1));
        $this->assertEquals(50.5, $v('costo', 1));
        $this->assertEquals(58.58, $v('costo_iva', 1));
        $this->assertEquals(80, $v('precio', 1));
        $this->assertEquals(92.8, $v('precio_iva', 1));
        $this->assertEquals(58.42, $v('utilidad', 1));
        $this->assertSame('FERRETERIA', $v('categoria', 1));
        $this->assertSame('FERRETERIA NORTE', $v('proveedor', 1));

        // El que ya existe trae los datos de SICAR y, como la descripción es distinta, también la nuestra.
        $this->assertSame('SOLO COMPRA', $v('accion', 2));
        $this->assertSame('BAL-6203', $v('clave', 2));
        $this->assertSame('BALERO 6203', $v('descripcion', 2));
        $this->assertSame('BALERO', $v('nuestra', 2));
        $this->assertEquals(30, $v('utilidad', 2));
        $this->assertSame('31171500', $v('sat', 2));
        $this->assertSame('BALEROS', $v('categoria', 2));
        $this->assertSame('', (string) $v('accion', 3));
    }

    public function test_escoba_57052_no_casa_con_la_clave_generica_escoba_y_avisa_si_hay_dos_con_el_modelo(): void
    {
        // Caso P-88: en SICAR hay un artículo con clave "ESCOBA" y dos escobas cuya descripción trae 57052.
        $this->art(10, 'ESCOBA', 'ESCOBA');
        $this->art(11, 'ESCOBA ABANICO DE PLASTICO TRUPER', 'ESCOBA ABANICO DE PLASTICO TRUPER CODIGO 57052');
        $this->art(12, 'ESCOBA TIPO ABANICO C/PALO 1.20 MT', 'ESCOBA TIPO ABANICO C/PALO 1.20 MT MOD 57052');
        $e = new EmparejadorSicar;

        $r = $e->emparejar(['descripcion' => 'ESCOBA GRANDE CERDAS DE PVC, TIPO ABANICO, BASTON DE MADERA 57052', 'modelo' => '57052']);
        $this->assertNotSame('ESCOBA', $r['articulo']->clave);
        $this->assertSame('ESCOBA TIPO ABANICO C/PALO 1.20 MT', $r['articulo']->clave);   // la más parecida
        $this->assertSame('posible', $r['estado']);   // hay dos con ese modelo: que lo confirme una persona
        $this->assertStringContainsString('hay 2 artículos con el modelo 57052', $r['avisos'][0]);

        // Con un solo artículo con el modelo, existe directo.
        SicarArticulo::where('art_id', 11)->update(['status' => 0]);
        $r = (new EmparejadorSicar)->emparejar(['descripcion' => 'ESCOBA GRANDE 57052', 'modelo' => '57052']);
        $this->assertSame('existe', $r['estado']);
        $this->assertSame(12, (int) $r['articulo']->art_id);

        // Sin modelo, la clave genérica "ESCOBA" ya no casa por estar al inicio de la descripción.
        $r = (new EmparejadorSicar)->emparejar(['descripcion' => 'ESCOBA DE MIJO', 'modelo' => null]);
        $this->assertNotSame('existe', $r['estado']);
    }

    public function test_el_modelo_no_casa_con_numeros_pegados_de_otra_descripcion(): void
    {
        // P-176: el pasador HERMEX 43713 se dio de alta como la reducción porque "1/2\" 437-130" pegado contiene 43713.
        $this->art(20, '222 00162', 'REDUCCION BUSHING HID. ESP-CEM. C-40 1" X 1/2" 437-130');
        $e = new EmparejadorSicar;

        $r = $e->emparejar(['descripcion' => 'PASADOR DE BARRA PLANA 2" ACABADO CROMO HERMEX 43713', 'modelo' => '43713']);
        $this->assertNotSame('222 00162', $r['articulo']?->clave);
        $this->assertNotSame('existe', $r['estado']);

        // El modelo como palabra sí casa, aunque venga con "MOD", con guion o en dos partes.
        $this->assertTrue($e->modeloEnDescripcion('PASADOR CROMO HERMEX 43713', '43713'));
        $this->assertTrue($e->modeloEnDescripcion('PASADOR CROMO MOD.43713', '43713'));
        $this->assertTrue($e->modeloEnDescripcion('LLAVE ALLEN TRUPER 15-432', SicarArticulo::llave('15432')));
        $this->assertTrue($e->modeloEnDescripcion('REDUCCION 222 00162 HIDRAULICA', SicarArticulo::llave('222 00162')));
        $this->assertFalse($e->modeloEnDescripcion('REDUCCION 1" X 1/2" 437-130', '43713'));
    }

    public function test_posible_no_bloquea_y_sale_para_revisar_con_la_clave_parecida(): void
    {
        $this->art(2, 'DSC-1', 'DISCO CORTE METAL 4 1/2 X 3/32 PULGADAS');
        $pedido = Pedido::factory()->create();
        $this->partida($pedido, ['descripcion' => 'Disco corte metal 4 1/2 x 3/32 pulgadas', 'modelo' => null]);

        $ruta = (new HojaAltasSicar)->generar((new AltaSicar)->filas(Pedido::with(['cliente', 'partidas.proveedor'])->get()));
        $h = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        $fila = HojaAltasSicar::FILA_ENCABEZADOS + 1;
        $notas = array_search('notas', array_keys(HojaAltasSicar::COLUMNAS), true) + 1;
        $this->assertSame('REVISAR', $h->getCell([1, $fila])->getValue());
        $this->assertStringContainsString('DSC-1', $h->getCell([$notas, $fila])->getValue());
    }

    public function test_descargar_desde_la_pantalla_entrega_el_excel_con_fecha(): void
    {
        // El nombre lleva hora con segundos: se congela el reloj para que no cambie a la mitad.
        $this->freezeSecond();
        $this->entrar();
        $p = Pedido::factory()->create();
        $this->partida($p, ['descripcion' => 'Cosa nueva', 'modelo' => 'NVO-1']);

        Livewire::test(Alta::class)->call('descargar')->assertSet('error', 'Marca al menos una tarjeta con partidas.');
        Livewire::test(Alta::class)->set('seleccion', [$p->id])->call('descargar')
            ->assertFileDownloaded('altas_sicar_'.now()->format('Ymd_His').'.xlsx');
    }

    public function test_marcar_como_dados_de_alta(): void
    {
        $u = $this->entrar();
        $a = Pedido::factory()->create(['columna' => 'en_tramite']);
        $b = Pedido::factory()->create(['columna' => 'en_tramite']);
        $this->partida($a);
        $this->partida($b);

        Livewire::test(Alta::class, ['columna' => 'en_tramite'])
            ->set('seleccion', [$a->id])
            ->call('marcarAlta')
            ->assertSet('mensaje', 'Se marcó 1 pedido como dado de alta en SICAR.');

        $this->assertTrue($a->fresh()->alta_sicar);
        $this->assertFalse($b->fresh()->alta_sicar);
        $this->assertTrue(PedidoActividad::where('pedido_id', $a->id)->where('descripcion', 'Dado de alta en SICAR por '.$u->name)->exists());
    }

    public function test_rol_campo_no_entra_y_sin_sesion_redirige(): void
    {
        $this->get(route('sicar.alta'))->assertRedirect();

        $this->entrar('campo');
        $this->get(route('sicar.alta'))->assertForbidden();
    }

    public function test_admin_y_vendedor_entran(): void
    {
        $this->entrar('admin');
        $this->get(route('sicar.alta'))->assertOk();
        $this->entrar('vendedor');
        $this->get(route('sicar.alta'))->assertOk();
    }

    public function test_unidades_se_traducen_al_nombre_exacto_de_sicar(): void
    {
        $this->art(901, 'A1', 'ARTICULO UNO', ['unidad' => 'PZA']);
        $this->art(902, 'A2', 'ARTICULO DOS', ['unidad' => 'bolsa']);
        $this->art(903, 'A3', 'ARTICULO TRES', ['unidad' => 'metro']);
        $alta = new AltaSicar;

        $this->assertSame('PZA', $alta->unidad('PZ'));
        $this->assertSame('bolsa', $alta->unidad('BOLSA'));
        $this->assertSame('metro', $alta->unidad('MTS'));
        [$u, $aviso] = $alta->unidadConAviso('TMO');
        $this->assertSame('PZA', $u);
        $this->assertStringContainsString('TMO', (string) $aviso);
        [$u, $aviso] = $alta->unidadConAviso('XYZ');
        $this->assertSame('PZA', $u);
        $this->assertStringContainsString('no existe en SICAR', (string) $aviso);
    }
}

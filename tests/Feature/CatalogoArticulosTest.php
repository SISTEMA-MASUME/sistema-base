<?php

namespace Tests\Feature;

use App\Livewire\Articulos\Index;
use App\Livewire\Articulos\Ver;
use App\Livewire\Cotizaciones\Editor;
use App\Models\Articulo;
use App\Models\ArticuloNombre;
use App\Models\ArticuloProveedor;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\SatClave;
use App\Models\SicarArticulo;
use App\Models\User;
use App\Support\Articulos;
use App\Support\UnidadesSat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Catálogo de artículos: cada artículo una vez, con quién lo vende, su último costo y sus datos para facturar. */
class CatalogoArticulosTest extends TestCase
{
    use RefreshDatabase;

    private Proveedor $urrea;

    private Proveedor $provex;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->urrea = Proveedor::factory()->create(['nombre' => 'URREA']);
        $this->provex = Proveedor::factory()->create(['nombre' => 'PROVEX']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function partida(array $datos = [], ?Pedido $pedido = null): PedidoPartida
    {
        return PedidoPartida::create($datos + [
            'pedido_id' => ($pedido ?? Pedido::factory()->create())->id, 'orden' => 1, 'descripcion' => 'LLAVE STILLSON 14"', 'marca' => 'TRUPER',
            'modelo' => 'PST-14', 'unidad' => 'PZA', 'cantidad' => 2, 'costo' => 185, 'precio' => 260, 'proveedor_id' => $this->urrea->id,
        ]);
    }

    public function test_unidades_del_sat_solo_se_sugieren_las_seguras(): void
    {
        $this->assertSame(['H87', 'XBX', 'MTR', 'SET', 'KGM', null], array_map([UnidadesSat::class, 'sugerir'], ['pza', 'CJA', 'mts.', 'JGO', 'Kg', 'TRAMO']));
        $this->assertSame('Pieza', UnidadesSat::nombre('H87'));
    }

    public function test_al_entrar_a_un_pedido_nace_el_articulo_con_su_proveedor_y_costo(): void
    {
        $p = $this->partida();
        $a = Articulo::sole();
        $this->assertSame([$a->id, 'LLAVE STILLSON 14"', 'TRUPER', 'PZA', 'H87', 'pedido'], [$p->fresh()->articulo_id, $a->descripcion, $a->marca, $a->unidad, $a->unidad_sat, $a->origen]);
        $fila = ArticuloProveedor::sole();
        $this->assertSame([$this->urrea->id, 'PST-14', '185.0000', '2026-10-18', $p->pedido_id], [$fila->proveedor_id, $fila->clave, $fila->ultimo_costo, $fila->costo_fecha->toDateString(), $fila->pedido_id]);

        // El mismo artículo con otro proveedor: se suma a su lista (por la misma descripción y marca), no se duplica.
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->partida(['proveedor_id' => $this->provex->id, 'costo' => 192, 'modelo' => 'TR-15580']);
        $this->assertSame(1, Articulo::count());
        $this->assertSame(['PROVEX' => '192.0000', 'URREA' => '185.0000'], $a->proveedores()->with('proveedor')->get()->mapWithKeys(fn ($f) => [$f->proveedor->nombre => $f->ultimo_costo])->sortKeys()->all());

        // Otra compra a URREA más cara: se actualiza su costo (es la más reciente) y la clave del proveedor reconoce el artículo.
        $this->partida(['descripcion' => 'STILLSON 14 PULGADAS', 'marca' => null, 'costo' => 199]);
        $this->assertSame(1, Articulo::count());
        $this->assertSame('199.0000', ArticuloProveedor::where('proveedor_id', $this->urrea->id)->value('ultimo_costo'));

        // Corregir el costo de la partida (descuento del proveedor) también lo actualiza.
        $p->update(['costo' => 180]);
        $this->assertSame('199.0000', ArticuloProveedor::where('proveedor_id', $this->urrea->id)->value('ultimo_costo'));   // esa compra es más vieja
    }

    public function test_nunca_adivina_otra_marca_o_descripcion_distinta_es_otro_articulo(): void
    {
        $this->partida();
        $this->partida(['marca' => 'URREA', 'modelo' => 'OTRA']);                // misma descripción, otra marca
        $this->partida(['descripcion' => 'LLAVE STILLSON 18"', 'modelo' => null]); // otra medida
        $this->assertSame(3, Articulo::count());
    }

    public function test_llenado_inicial_desde_sicar_y_pedidos_sin_duplicar(): void
    {
        SicarArticulo::create(['art_id' => 501, 'clave' => 'GNN100', 'descripcion' => 'GUANTE NITRILO NEGRO CAJA 100', 'unidad' => 'CAJA', 'clave_prod_serv' => '46181504', 'status' => 1]);
        SicarArticulo::create(['art_id' => 502, 'clave' => 'VIEJO', 'descripcion' => 'ARTICULO DADO DE BAJA', 'unidad' => 'PZA', 'status' => 0]);
        // Partidas que ya existían antes del catálogo (sin artículo).
        $p1 = $this->partida(['descripcion' => 'GUANTE NITRILO NEGRO CAJA 100', 'marca' => null, 'modelo' => null, 'sicar_art_id' => 501]);
        $p2 = $this->partida();
        $cancelado = Pedido::factory()->create(['cancelado_at' => now()]);
        $p3 = $this->partida(['descripcion' => 'COSA DE PEDIDO CANCELADO'], $cancelado);
        PedidoPartida::query()->update(['articulo_id' => null]);
        Articulo::query()->forceDelete();
        ArticuloProveedor::query()->delete();

        $r = Articulos::llenar();
        $this->assertSame(['sicar' => 1, 'partidas' => 2], $r);
        $guante = Articulo::where('sicar_art_id', 501)->sole();
        $this->assertSame(['sicar', 'XBX', '46181504', 'GNN100'], [$guante->origen, $guante->unidad_sat, $guante->clave_sat, $guante->sicar_clave]);
        $this->assertSame($guante->id, $p1->fresh()->articulo_id);
        $this->assertNotNull($p2->fresh()->articulo_id);
        $this->assertNull($p3->fresh()->articulo_id);
        $this->assertFalse(Articulo::where('descripcion', 'ARTICULO DADO DE BAJA')->exists());

        // Repetirlo (también con el comando) no duplica nada.
        $this->assertSame(['sicar' => 0, 'partidas' => 0], Articulos::llenar());
        $this->artisan('masume:llenar-articulos')->expectsOutputToContain('Listo: 0 artículos nuevos de SICAR')->assertSuccessful();
        $this->assertSame(2, Articulo::count());
    }

    public function test_al_dar_de_alta_en_sicar_el_articulo_recibe_su_clave(): void
    {
        $p = $this->partida();
        SicarArticulo::create(['art_id' => 900, 'clave' => 'STL14', 'descripcion' => 'LLAVE STILLSON 14', 'unidad' => 'PZA', 'clave_prod_serv' => '27111705', 'status' => 1]);
        $p->update(['sicar_art_id' => 900]);
        $a = Articulo::sole();
        $this->assertSame([900, 'STL14', '27111705'], [(int) $a->sicar_art_id, $a->sicar_clave, $a->clave_sat]);
    }

    public function test_lista_busca_y_filtra(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $this->partida();
        $this->partida(['descripcion' => 'CINTA DE AISLAR NEGRA', 'marca' => '3M', 'modelo' => '1600', 'proveedor_id' => $this->provex->id]);
        Articulo::where('descripcion', 'like', 'CINTA%')->update(['clave_sat' => '31201502']);
        ArticuloNombre::create(['articulo_id' => Articulo::where('descripcion', 'like', 'LLAVE%')->value('id'), 'nombre' => 'LLAVE PARA TUBO']);

        $t = Livewire::test(Index::class)->assertSee('LLAVE STILLSON 14&quot;', false)->assertSee('CINTA DE AISLAR NEGRA')->assertSee('$185.00');
        $t->set('buscar', 'stillson truper')->assertSee('LLAVE STILLSON')->assertDontSee('CINTA DE AISLAR');
        $t->set('buscar', 'pst 14')->assertSee('LLAVE STILLSON')->assertDontSee('CINTA DE AISLAR');     // clave del proveedor
        $t->set('buscar', 'para tubo')->assertSee('LLAVE STILLSON');                                       // otro nombre
        $t->set('buscar', '')->call('filtrar', 'fiscal')->assertSee('LLAVE STILLSON')->assertDontSee('CINTA DE AISLAR');

        $this->get(route('articulos.index'))->assertOk()->assertSee('Artículos');
        $this->actingAs(User::factory()->create(['rol' => 'campo']))->get(route('articulos.index'))->assertForbidden();
    }

    public function test_ficha_guarda_datos_fiscales_proveedores_y_otros_nombres(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        SatClave::create(['clave' => '27111705', 'descripcion' => 'Llaves de tubo', 'texto' => 'llaves de tubo']);
        $p = $this->partida();
        $a = Articulo::sole();

        $t = Livewire::test(Ver::class, ['articulo' => $a->id])->assertSee('Quién te lo vende')->assertSee('URREA')->assertSee('$185.00')
            ->assertSee('P-'.$p->pedido->folio)->assertSee('1 pedido');
        $t->set('claveSat', '1234')->call('guardar')->assertHasErrors('claveSat')
            ->set('claveSat', '99999999')->call('guardar')->assertHasErrors('claveSat')
            ->set('claveSat', '27111705')->set('codigoBarras', '7501206613234 ')->call('guardar')->assertHasNoErrors()->assertSee('Llaves de tubo');
        $this->assertSame(['27111705', '7501206613234'], [$a->fresh()->clave_sat, $a->fresh()->codigo_barras]);

        // Agregar a PROVEX a mano; no se puede repetir a URREA.
        $t->call('nuevoProveedor')->set('proveedorTexto', 'prov')->assertSee('PROVEX')->call('elegirProveedor', $this->provex->id)
            ->set('clave', 'TR-15580')->set('costo', '$192.50')->call('guardarProveedor')->assertHasNoErrors()->assertSee('Se guardó el proveedor PROVEX.');
        $this->assertSame('192.5000', ArticuloProveedor::where('proveedor_id', $this->provex->id)->value('ultimo_costo'));
        $t->call('nuevoProveedor')->call('elegirProveedor', $this->urrea->id)->call('guardarProveedor')->assertHasErrors('proveedorId');
        $t->call('nuevoProveedor')->set('proveedorTexto', 'algo')->call('guardarProveedor')->assertHasErrors('proveedorId');
        $fila = ArticuloProveedor::where('proveedor_id', $this->provex->id)->sole();
        $t->call('quitarProveedor', $fila->id);
        $this->assertSame(1, ArticuloProveedor::count());

        $t->set('nombreNuevo', 'Stillson 14 pulg')->call('agregarNombre')->assertSee('STILLSON 14 PULG')
            ->set('nombreNuevo', 'stillson 14 PULG.')->call('agregarNombre')->assertHasErrors('nombreNuevo');
    }

    public function test_unir_repetidos_junta_todo_y_el_viejo_abre_el_que_quedo(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $p1 = $this->partida();
        Carbon::setTestNow('2026-10-21 12:00:00');
        $p2 = $this->partida(['descripcion' => 'STILLSON 14 PULGADAS', 'marca' => null, 'modelo' => 'OTRA', 'costo' => 170]);
        $p3 = $this->partida(['descripcion' => 'STILLSON 14 PULGADAS', 'marca' => null, 'modelo' => 'X', 'proveedor_id' => $this->provex->id, 'costo' => 195]);
        [$queda, $quita] = [Articulo::find($p1->articulo_id), Articulo::find($p2->articulo_id)];
        $this->assertNotSame($queda->id, $quita->id);

        Livewire::test(Ver::class, ['articulo' => $queda->id])->set('unirTexto', 'pulgadas')->assertSee('STILLSON 14 PULGADAS')
            ->call('unirCon', $quita->id)->assertSee('se unió a este artículo');

        $this->assertSame([$queda->id, $queda->id, $queda->id], [$p1->fresh()->articulo_id, $p2->fresh()->articulo_id, $p3->fresh()->articulo_id]);
        $this->assertSame(['PROVEX' => '195.0000', 'URREA' => '170.0000'], $queda->proveedores()->with('proveedor')->get()
            ->mapWithKeys(fn ($f) => [$f->proveedor->nombre => $f->ultimo_costo])->sortKeys()->all());   // URREA: el más reciente
        $this->assertSame(['STILLSON 14 PULGADAS'], $queda->nombres()->pluck('nombre')->all());
        $this->assertTrue($quita->fresh()->trashed());
        Livewire::test(Ver::class, ['articulo' => $quita->id])->assertSet('articuloId', $queda->id);

        // Una partida nueva con el nombre del repetido ya cae en el que quedó.
        $this->assertSame($queda->id, $this->partida(['descripcion' => 'STILLSON 14 PULGADAS', 'marca' => null, 'modelo' => null, 'proveedor_id' => null])->fresh()->articulo_id);
    }

    public function test_al_unir_proveedores_repetidos_sus_articulos_pasan_al_que_queda(): void
    {
        $this->partida();
        Carbon::setTestNow('2026-10-22 12:00:00');   // la compra a PROVEX es más reciente: su costo se queda
        $this->partida(['proveedor_id' => $this->provex->id, 'costo' => 150]);
        Articulos::moverProveedor($this->provex->id, $this->urrea->id);
        $this->assertSame(1, ArticuloProveedor::count());
        $this->assertSame([$this->urrea->id, '150.0000'], [ArticuloProveedor::sole()->proveedor_id, ArticuloProveedor::sole()->ultimo_costo]);
    }

    public function test_marca_muy_larga_se_recorta_para_la_base_de_datos(): void
    {
        $this->partida(['marca' => str_repeat('MARCA LARGA ', 20)]);
        $this->assertSame(120, mb_strlen(Articulo::sole()->marca));
    }

    public function test_partida_sin_descripcion_no_crea_articulo(): void
    {
        $p = new PedidoPartida(['pedido_id' => Pedido::factory()->create()->id, 'orden' => 1, 'descripcion' => '   ', 'unidad' => 'PZA', 'cantidad' => 1, 'precio' => 10]);
        $p->save();
        $this->assertNull($p->fresh()->articulo_id);
        $this->assertSame(0, Articulo::count());
    }

    /** Candado de velocidad: con 20,000 artículos y 40,000 partidas la lista, la búsqueda y la ficha abren rápido. */
    public function test_la_lista_sigue_rapida_con_muchos_articulos(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $palabras = ['LLAVE', 'STILLSON', 'GUANTE', 'NITRILO', 'DISCO', 'CORTE', 'PINZA', 'BROCA', 'CINTA', 'TUBO'];
        $ahora = now()->toDateTimeString();
        foreach (array_chunk(range(1, 20000), 500) as $bloque) {
            Articulo::insert(array_map(fn ($i) => ['descripcion' => $palabras[$i % 10].' '.$palabras[($i * 7) % 10].' MOD '.$i, 'unidad' => 'PZA',
                'origen' => 'sicar', 'ultimo_pedido_at' => $i % 3 ? null : now()->subMinutes($i)->toDateTimeString(), 'created_at' => $ahora, 'updated_at' => $ahora], $bloque));
        }
        $pedido = Pedido::factory()->create();
        foreach (array_chunk(range(1, 40000), 500) as $bloque) {
            PedidoPartida::insert(array_map(fn ($i) => ['pedido_id' => $pedido->id, 'orden' => 1, 'descripcion' => 'X', 'unidad' => 'PZA', 'cantidad' => 1,
                'precio' => 10, 'estado' => 'pendiente', 'articulo_id' => ($i % 20000) + 1, 'created_at' => $ahora, 'updated_at' => $ahora], $bloque));
        }

        $medir = function (callable $f): float {
            $t = microtime(true);
            $f();

            return microtime(true) - $t;
        };
        $t = Livewire::test(Index::class);
        $this->assertLessThan(1.5, $medir(fn () => $t->call('$refresh')), 'La lista tardó demasiado.');
        $this->assertLessThan(1.5, $medir(fn () => $t->set('buscar', 'llave stillson')), 'La búsqueda tardó demasiado.');
        $this->assertLessThan(1.5, $medir(fn () => $t->set('buscar', '')->call('filtrar', 'fiscal')), 'El filtro tardó demasiado.');
        $this->assertLessThan(1.5, $medir(fn () => Livewire::test(Ver::class, ['articulo' => 3])), 'La ficha tardó demasiado.');
    }

    public function test_es_este_sugiere_del_catalogo_y_liga_la_partida_hasta_el_pedido(): void
    {
        $rosa = User::factory()->create(['rol' => 'vendedor']);
        $this->actingAs($rosa);
        $this->partida();                                                                     // URREA $185, vendido a $260
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->partida(['proveedor_id' => $this->provex->id, 'costo' => 170, 'precio' => 240]);   // PROVEX $170 (más barato)
        $a = Articulo::sole();

        // "¿Es este?": viene del catálogo con el más barato, sus proveedores y la utilidad de la última venta.
        $r = $this->getJson(route('sugerencias.articulos', ['q' => 'stillson 14']))->assertOk()->json();
        $this->assertCount(1, $r);
        $this->assertSame([$a->id, 'LLAVE STILLSON 14"', 'PROVEX', 170.0, 41.18, 240.0], [$r[0]['art'], $r[0]['d'], $r[0]['pr'], (float) $r[0]['c'], (float) $r[0]['ut'], (float) $r[0]['p']]);
        $this->assertSame('PROVEX $170.00 · URREA $185.00', $r[0]['provs']);
        $this->assertSame([], $this->getJson(route('sugerencias.articulos', ['q' => 'st']))->json());
        $this->assertSame([], $this->getJson(route('sugerencias.articulos', ['q' => 'martillo']))->json());

        // El vendedor la elige: la partida queda ligada (y se puede desligar).
        $cot = Cotizacion::factory()->create(['vendedor_id' => $rosa->id, 'cliente_id' => Cliente::factory()->create()->id]);
        $cp = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => '']);
        $t = Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->call('guardarCampos', $cp->id, ['descripcion' => 'LLAVE STILLSON 14"', 'proveedor' => 'PROVEX', 'costo_sin' => '170', 'utilidad_pct' => '41.18', 'articulo_id' => (string) $a->id])
            ->assertSee('✓ Del catálogo');
        $this->assertSame([$a->id, '240.01'], [$cp->fresh()->articulo_id, (string) $cp->fresh()->precio]);
        $t->call('desligarArticulo', $cp->id)->assertDontSee('✓ Del catálogo');
        $this->assertNull($cp->fresh()->articulo_id);
        $t->call('guardarCampos', $cp->id, ['articulo_id' => '999999']);   // uno que no existe no se liga
        $this->assertNull($cp->fresh()->articulo_id);
        $t->call('guardarCampos', $cp->id, ['articulo_id' => (string) $a->id]);

        // Al llegar la OC, el pedido hereda el artículo elegido aunque la descripción cambie un poco.
        $cp->update(['descripcion' => 'LLAVE STILLSON 14 PULGADAS CROMADA']);
        $pp = PedidoPartida::desdeCotizacion($cp->fresh(), Pedido::factory()->create(), 1);
        $this->assertSame($a->id, $pp->fresh()->articulo_id);
        $this->assertSame(1, Articulo::count());

        // Hugo (campo) no consulta el catálogo de ventas.
        $this->actingAs(User::factory()->create(['rol' => 'campo']))->getJson(route('sugerencias.articulos', ['q' => 'stillson']))->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Articulos\Listas;
use App\Livewire\Cotizaciones\Editor;
use App\Models\Articulo;
use App\Models\ArticuloProveedor;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\ListaPrecio;
use App\Models\ListaPrecioRenglon;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\ListasPrecios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Concerns\ArmaExcel;
use Tests\TestCase;

/** Listas de precios de Truper y Urrea: se leen tal como las manda la marca, se calcula el costo y se ligan al catálogo. */
class ListasPreciosTest extends TestCase
{
    use ArmaExcel;
    use RefreshDatabase;

    private Proveedor $truper;

    private Proveedor $urrea;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->actingAs(User::factory()->create());
        $this->truper = Proveedor::factory()->create(['nombre' => 'TRUPER']);
        $this->urrea = Proveedor::factory()->create(['nombre' => 'URREA']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->borrarExceles();
        parent::tearDown();
    }

    /** Como el "Catálogo Truper 2026 ML": hoja BUSCAR de consulta y la lista en "cat 2026". */
    private function listaTruper(bool $conStillson = true): string
    {
        $encabezado = ['código', 'clave', 'descripción', 'caja', 'master', 'unidad', 'ean', 'alta rotación', 'precio mayoreo', 'precio distribuidor',
            'precio público', 'Precio Medio Mayoreo', 'Marca', 'Codigo SAT', 'Descripcion SAT', 'Familia', 'Descripción Familia', 'Peso[Kg]',
            'Volumen[cm3]', 'Código Barras', 'IVA', 'margen_mercado', 'PROMOTRUPER'];
        $filas = [$encabezado,
            [40051, 'ALRE-1560', 'Kilo de alambre recocido calibre 15 en rollo de 60 kg, FIERO', 60, 0, 'kg', 7506240665939, 0, 33, 30, 37, 35, 'Fiero',
                26121500, 'Alambre eléctrico', 'P625', 'Alambre recocido', 1.006, 525, 7506240665939, 16, 'MM2', 'Octubre 2026'],
        ];
        if ($conStillson) {
            $filas[] = [15580, 'PST-14', 'Llave stillson 14", Truper', 6, 24, 'pza', 7501206613234, 1, 250, 232, 290, 260, 'Truper',
                27111705, 'Llaves', 'P100', 'Llaves stillson', 1.2, 900, 7501206613234, 16, 'MM1', 'Octubre 2026'];
        }

        return $this->excel([
            'BUSCAR' => [['BUSCAR', 49135], [null, null, null, 'Código', 'Clave', 'Marca', 'Código Barras'], [null, 'descripción', null, 'Precio Distribuidor', 'Precio Mayoreo']],
            'cat 2026' => $filas,
        ]);
    }

    /** Como la "LISTA DE PRECIOS MENSUAL UHM": precios como texto con $, "CLAVE SAT" = unidad y "CÓDIGO SAT" = producto. */
    private function listaUrrea(): string
    {
        return $this->excel(['LISTA' => [
            ['CÓDIGO', ' FDC ', 'CLAVE', 'MARCA', 'DESCRIPCIÓN LARGA', ' PRECIO DE LISTA ', ' P.DIST ', ' P.DIST (CON IVA) ', ' PRECIO SUBDISTRIBUIDOR (CON IVA) ',
                ' PRECIO MAYOREO (CON IVA) ', 'USO', 'UNIDAD DE MEDIDA', 'FAMILIA DE VENTA', 'CÓDIGO DE BARRA', 'CÓDIGO DE BARRA SIN CERO', 'CLAVE SAT', 'CÓDIGO SAT'],
            ['99820', ' ', '99820', 'Urrea', 'Juego maestro industrial combinado 937 piezas, con gabinetes', ' $345,227 ', ' $276,181.60 ', ' $320,370.66 ', ' $378,037 ',
                ' $415,841 ', 'Uso pesado', ' - ', 'Juegos de herramientas', '0660731684568', 660731684568, 'SET', '27113201'],
            ['1610', null, '1610', 'Urrea', 'Pinza de presión 10" mordaza curva', ' $500 ', ' $400.00 ', ' $464.00 ', ' $520 ', ' $560 ', 'Uso pesado', null,
                'Pinzas', '0660731001610', 660731001610, 'H87', '27112100'],
            [null, null, null, null, 'SUBTÍTULO SIN CLAVE'],
        ]]);
    }

    public function test_analiza_truper_encuentra_la_hoja_y_sugiere_el_precio_distribuidor_con_iva(): void
    {
        $a = ListasPrecios::analizar($this->listaTruper());
        $this->assertSame(['cat 2026', 0, 2], [$a['hoja'], $a['encabezado'], $a['total']]);
        $this->assertSame('precio distribuidor', $a['precios'][$a['sugerido']['precio']]);
        $this->assertTrue($a['sugerido']['con_iva']);
        $this->assertSame(['ALRE-1560', '40051', 'KG', '26121500', '7506240665939'], [$a['muestra'][0]['clave'], $a['muestra'][0]['codigo'], $a['muestra'][0]['unidad'], $a['muestra'][0]['clave_sat'], $a['muestra'][0]['codigo_barras']]);
    }

    public function test_analiza_urrea_precio_de_lista_sin_iva_y_unidad_del_sat(): void
    {
        $a = ListasPrecios::analizar($this->listaUrrea());
        $this->assertSame(['LISTA', 2, 'URREA'], [$a['hoja'], $a['total'], $a['sugerido']['marca']]);
        $this->assertSame('PRECIO DE LISTA', $a['precios'][$a['sugerido']['precio']]);
        $this->assertFalse($a['sugerido']['con_iva']);
        $m = $a['muestra'][0];
        $this->assertSame(['99820', 'SET', 'JGO', '27113201', '0660731684568'], [$m['clave'], $m['unidad_sat'], $m['unidad'], $m['clave_sat'], $m['codigo_barras']]);
        $this->assertSame(345227.0, ListasPrecios::numero(' $345,227 '));
        $this->assertNull(ListasPrecios::numero(' - '));

        $this->expectException(InvalidArgumentException::class);
        ListasPrecios::analizar($this->excel(['Hoja1' => [['hola', 'adios'], ['1', '2']]]));
    }

    public function test_importa_truper_quita_el_iva_y_completa_el_articulo_que_ya_existia(): void
    {
        // La stillson ya se había vendido comprándola a PROVEX con la clave de Truper.
        $provex = Proveedor::factory()->create(['nombre' => 'PROVEX']);
        PedidoPartida::create(['pedido_id' => Pedido::factory()->create()->id, 'orden' => 1, 'descripcion' => 'LLAVE STILLSON 14"', 'marca' => 'TRUPER',
            'modelo' => 'PST-14', 'unidad' => 'PZA', 'cantidad' => 2, 'costo' => 210, 'precio' => 280, 'proveedor_id' => $provex->id]);
        $stillson = Articulo::sole();

        $a = ListasPrecios::analizar($ruta = $this->listaTruper());
        $r = ListasPrecios::importar($ruta, ['proveedor_id' => $this->truper->id, 'nombre' => 'Truper octubre 2026', 'precio' => $a['sugerido']['precio'], 'con_iva' => true, 'descuento' => 0, 'archivo' => 'Catálogo Truper 2026 ML.xlsx']);
        $this->assertSame(['renglones' => 2, 'ligados' => 1, 'completados' => 1], $r);
        $alambre = ListaPrecioRenglon::where('clave', 'ALRE-1560')->sole();
        $this->assertSame(['25.8621', '30.0000', null], [$alambre->costo, $alambre->precio_lista, $alambre->articulo_id]);   // 30 con IVA → 25.86 sin IVA

        $stillson->refresh();
        $this->assertSame(['7501206613234', '27111705'], [$stillson->codigo_barras, $stillson->clave_sat]);
        $fila = ArticuloProveedor::where('articulo_id', $stillson->id)->where('proveedor_id', $this->truper->id)->sole();
        $this->assertSame(['PST-14', '200.0000'], [$fila->clave, $fila->ultimo_costo]);                                         // 232 / 1.16
        $this->assertNotNull($fila->lista_precio_id);
        $this->assertSame('210.0000', ArticuloProveedor::where('proveedor_id', $provex->id)->value('ultimo_costo'));   // lo de PROVEX no se toca

        // La lista del mes siguiente sin la stillson: se reemplaza (no se duplica) y el descontinuado se quita.
        $r = ListasPrecios::importar($this->listaTruper(false), ['proveedor_id' => $this->truper->id, 'nombre' => 'Truper noviembre 2026', 'precio' => 9, 'con_iva' => true, 'descuento' => 0]);
        $this->assertSame(1, $r['renglones']);
        $this->assertSame(1, ListaPrecio::count());
        $this->assertSame(['ALRE-1560'], ListaPrecioRenglon::pluck('clave')->all());

        // Una compra real a Truper reemplaza el costo de la lista.
        PedidoPartida::create(['pedido_id' => Pedido::factory()->create()->id, 'orden' => 1, 'descripcion' => 'LLAVE STILLSON 14"', 'marca' => 'TRUPER',
            'modelo' => 'PST-14', 'unidad' => 'PZA', 'cantidad' => 1, 'costo' => 195, 'precio' => 270, 'proveedor_id' => $this->truper->id]);
        $this->assertSame(['195.0000', null], [$fila->fresh()->ultimo_costo, $fila->fresh()->lista_precio_id]);
    }

    public function test_importa_urrea_con_descuento_y_nace_el_articulo_solo_al_elegirlo(): void
    {
        $r = ListasPrecios::importar($this->listaUrrea(), ['proveedor_id' => $this->urrea->id, 'nombre' => 'Urrea octubre 2026', 'precio' => 5, 'con_iva' => false, 'descuento' => 20]);
        $this->assertSame(['renglones' => 2, 'ligados' => 0, 'completados' => 0], $r);
        $this->assertSame(0, Articulo::count());   // la lista no llena el catálogo
        $pinza = ListaPrecioRenglon::where('clave', '1610')->sole();
        $this->assertSame(['400.0000', 'H87', 'PZA', '27112100'], [$pinza->costo, $pinza->unidad_sat, $pinza->unidad, $pinza->clave_sat]);

        // Buscar en las listas: por palabras, por clave o por código de barras.
        $this->assertSame(['1610'], ListasPrecios::buscar('pinza presion')->pluck('clave')->all());
        $this->assertSame(['99820'], ListasPrecios::buscar('99820')->pluck('clave')->all());
        $this->assertSame(['1610'], ListasPrecios::buscar('0660731001610')->pluck('clave')->all());

        // Al elegirlo nace el artículo con sus datos para facturar y el costo de la lista con Urrea.
        $a = ListasPrecios::articuloDe($pinza);
        $this->assertSame(['lista', 'URREA', 'H87', '27112100', '0660731001610'], [$a->origen, $a->marca, $a->unidad_sat, $a->clave_sat, $a->codigo_barras]);
        $this->assertSame(['1610', '400.0000'], [$a->proveedores()->sole()->clave, $a->proveedores()->sole()->ultimo_costo]);
        $this->assertSame($a->id, ListasPrecios::articuloDe($pinza->fresh())->id);   // no se duplica
        $this->assertSame(1, Articulo::count());
    }

    public function test_pantalla_sube_revisa_la_muestra_e_importa_solo_daniel(): void
    {
        $daniel = User::factory()->create(['rol' => 'admin']);
        $archivo = UploadedFile::fake()->createWithContent('LISTA DE PRECIOS MENSUAL UHM OCT 2026.xlsx', file_get_contents($this->listaUrrea()));

        $t = Livewire::actingAs($daniel)->test(Listas::class)->assertSee('Sin listas')
            ->set('archivo', $archivo)->assertHasNoErrors()
            ->assertSee('2 productos')->assertSet('proveedorId', $this->urrea->id)->assertSet('conIva', false)
            ->assertSee('$345,227.00')->assertSee('$345,227.00');                       // sin descuento todavía: costo = precio de lista
        $t->set('descuento', '20')->assertSee('$276,181.60')->assertSee('$400.00');      // con 20%: así queda tu costo
        $t->call('importar')->assertHasNoErrors()->assertSee('Lista importada: 2 productos')->assertSee('URREA octubre 2026');
        $this->assertSame(['20', '20.000'], [ListaPrecio::sole()->descuentos, ListaPrecio::sole()->descuento_pct]);

        // Buscar y agregar al catálogo desde la pantalla.
        $pinza = ListaPrecioRenglon::where('clave', '1610')->sole();
        $t->set('buscar', 'pinza')->assertSee('PINZA DE PRESIÓN')->assertSee('Agregar al catálogo')
            ->call('agregarAlCatalogo', $pinza->id)->assertRedirect(route('articulos.ver', $pinza->fresh()->articulo_id));

        // Otro archivo que no es lista avisa.
        $t->set('archivo', UploadedFile::fake()->createWithContent('otro.xlsx', file_get_contents($this->excel(['Hoja1' => [['hola']]]))))
            ->assertHasErrors('archivo');
        $t->set('archivo', UploadedFile::fake()->create('lista.pdf', 10, 'application/pdf'))->assertHasErrors('archivo');

        // Un vendedor ve y busca las listas, pero no las sube.
        Livewire::actingAs(User::factory()->create(['rol' => 'vendedor']))->test(Listas::class)->assertSee('URREA octubre 2026')->assertDontSee('Subir lista del mes')
            ->set('archivo', $archivo)->assertForbidden();
    }

    public function test_es_este_ofrece_lo_de_la_lista_y_al_elegirlo_nace_el_articulo(): void
    {
        ListasPrecios::importar($this->listaUrrea(), ['proveedor_id' => $this->urrea->id, 'nombre' => 'Urrea octubre 2026', 'precio' => 5, 'con_iva' => false, 'descuento' => 20]);
        $r = $this->getJson(route('sugerencias.articulos', ['q' => 'pinza presion']))->assertOk()->json();
        $this->assertCount(1, $r);
        $this->assertSame(['URREA', '1610', 400.0], [$r[0]['lista'], $r[0]['mo'], (float) $r[0]['c']]);
        $this->assertArrayNotHasKey('art', $r[0]);

        $cot = Cotizacion::factory()->create(['cliente_id' => Cliente::factory()->create()->id]);
        $cp = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => '']);
        Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->call('guardarCampos', $cp->id, ['descripcion' => $r[0]['d'], 'proveedor' => 'URREA', 'modelo' => '1610', 'costo_sin' => '400', 'lista_renglon' => (string) $r[0]['lr']])
            ->assertSee('✓ Del catálogo');
        $a = Articulo::sole();
        $this->assertSame([$a->id, 'lista'], [$cp->fresh()->articulo_id, $a->origen]);

        // Ya en el catálogo, la siguiente búsqueda lo ofrece como artículo (no otra vez de la lista).
        $r = $this->getJson(route('sugerencias.articulos', ['q' => 'pinza presion']))->json();
        $this->assertSame([$a->id], array_column($r, 'art'));
        $this->assertSame([], array_column($r, 'lr'));
    }

    /** Candado de tamaño: una lista de 20,000 productos se importa en segundos y buscar en ella es inmediato. */
    public function test_lista_de_20000_productos_se_importa_y_busca_rapido(): void
    {
        $palabras = ['PINZA', 'LLAVE', 'DADO', 'MATRACA', 'DESARMADOR', 'MARTILLO', 'SEGUETA', 'NIVEL', 'FLEXOMETRO', 'CINCEL'];
        $filas = [['CÓDIGO', 'CLAVE', 'MARCA', 'DESCRIPCIÓN LARGA', ' PRECIO DE LISTA ', 'CÓDIGO DE BARRA', 'CLAVE SAT', 'CÓDIGO SAT']];
        for ($i = 1; $i <= 20000; $i++) {
            $filas[] = ['U'.$i, 'U'.$i, 'Urrea', $palabras[$i % 10].' '.$palabras[($i * 3) % 10].' MODELO '.$i, 100 + $i % 900, '0660731'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'H87', '27112100'];
        }
        $ruta = $this->excel(['LISTA' => $filas]);

        $t = microtime(true);
        $r = ListasPrecios::importar($ruta, ['proveedor_id' => $this->urrea->id, 'nombre' => 'Urrea', 'precio' => 4, 'con_iva' => false, 'descuento' => 20]);
        $importar = microtime(true) - $t;
        $this->assertSame(20000, $r['renglones']);
        $this->assertLessThan(60, $importar, "Importar 20,000 tardó {$importar} s.");

        $t = microtime(true);
        $this->getJson(route('sugerencias.articulos', ['q' => 'pinza llave modelo 1']))->assertOk();
        $this->assertSame(1, ListasPrecios::buscar('0660731012345')->count());
        $this->assertLessThan(1.0, microtime(true) - $t, 'Buscar en la lista tardó demasiado.');
    }

    /** Programa comercial M1 de Urrea: 20% base + descuento en factura del trimestre (0, 5 o 10%); cambia cada trimestre. */
    public function test_descuentos_encadenados_y_cambiarlos_cada_trimestre_sin_volver_a_subir_la_lista(): void
    {
        $this->assertSame(['texto' => '20 + 5', 'total' => 24.0], ListasPrecios::descuentos('20% + 5'));     // 0.80 × 0.95, no 25%
        $this->assertSame(['texto' => '20 + 10 + 3', 'total' => 30.16], ListasPrecios::descuentos('20+10+3'));
        $this->assertSame(['texto' => '0', 'total' => 0.0], ListasPrecios::descuentos(''));
        $this->assertNull(ListasPrecios::descuentos('veinte'));
        $this->assertNull(ListasPrecios::descuentos('95'));

        ListasPrecios::importar($this->listaUrrea(), ['proveedor_id' => $this->urrea->id, 'nombre' => 'Urrea', 'precio' => 5, 'con_iva' => false, 'descuento' => '20 + 5']);
        $pinza = ListaPrecioRenglon::where('clave', '1610')->sole();
        $this->assertSame('380.0000', $pinza->costo);                                                          // 500 × 0.80 × 0.95
        $articulo = ListasPrecios::articuloDe($pinza);

        // Nuevo trimestre: compró más de $130,000 → 10% en factura. Se recalcula sin subir el Excel.
        $daniel = User::factory()->create(['rol' => 'admin']);
        $lista = ListaPrecio::sole();
        Livewire::actingAs($daniel)->test(Listas::class)->assertSee('20 + 5%')
            ->call('editarLista', $lista->id)->assertSet('editDescuentos', '20 + 5')
            ->set('editDescuentos', 'mucho')->call('guardarDescuentos')->assertHasErrors('editDescuentos')
            ->set('editDescuentos', '20 + 10')->call('guardarDescuentos')->assertHasNoErrors()
            ->assertSee('ya tienen su costo con 20 + 10% (28% en total)');
        $this->assertSame('360.0000', $pinza->fresh()->costo);                                                  // 500 × 0.80 × 0.90
        $this->assertSame('360.0000', $articulo->proveedores()->sole()->ultimo_costo);                         // el artículo también
        $this->assertSame('248563.4400', ListaPrecioRenglon::where('clave', '99820')->value('costo'));        // 345,227 × 0.72

        // Solo Daniel cambia descuentos.
        Livewire::actingAs(User::factory()->create(['rol' => 'vendedor']))->test(Listas::class)->assertDontSee('Cambiar descuentos')
            ->call('editarLista', $lista->id)->assertForbidden();
    }
}

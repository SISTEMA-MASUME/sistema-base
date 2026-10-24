<?php

namespace Tests\Feature;

use App\Enums\EstadoLoteSicar;
use App\Enums\EstadoOrdenProveedor;
use App\Livewire\OrdenesProveedor\Generar;
use App\Livewire\OrdenesProveedor\Index;
use App\Livewire\OrdenesProveedor\Ver;
use App\Models\Cliente;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\SicarLote;
use App\Models\User;
use App\Services\OrdenProveedorPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrdenesProveedorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function partida(Pedido $pedido, ?Proveedor $prov, array $extra = []): PedidoPartida
    {
        return PedidoPartida::create(array_merge([
            'pedido_id' => $pedido->id,
            'orden' => PedidoPartida::where('pedido_id', $pedido->id)->count() + 1,
            'descripcion' => 'Pieza '.uniqid(),
            'unidad' => 'PZA',
            'cantidad' => 2,
            'proveedor_id' => $prov?->id,
            'costo' => 100,
            'precio' => 777.77,
        ], $extra));
    }

    private function pedidoConDosProveedores(): array
    {
        $pedido = Pedido::factory()->create();
        $a = Proveedor::factory()->create(['nombre' => 'PROVEEDOR A']);
        $b = Proveedor::factory()->create(['nombre' => 'PROVEEDOR B']);
        $pa1 = $this->partida($pedido, $a);
        $pa2 = $this->partida($pedido, $a);
        $pb = $this->partida($pedido, $b);

        return [$pedido, $a, $b, $pa1, $pa2, $pb];
    }

    public function test_agrupa_por_proveedor_con_folios_consecutivos_y_liga_partidas(): void
    {
        [$pedido, $a, $b, $pa1, $pa2, $pb] = $this->pedidoConDosProveedores();

        $this->generarOc($pedido)
            ->set('config.p'.$a->id.'.metodo_pago', 'TARJETA')
            ->set('config.p'.$a->id.'.comentarios', 'Urge')
            ->call('generar')
            ->assertRedirect(route('ordenes-proveedor.index', ['pedido' => $pedido->id]));

        $ordenes = OrdenProveedor::orderBy('folio')->get();
        $this->assertCount(2, $ordenes);
        $this->assertSame($ordenes[0]->folio + 1, $ordenes[1]->folio);
        $this->assertCount(2, $ordenes->pluck('folio')->unique());

        $ordenA = $ordenes->firstWhere('proveedor_id', $a->id);
        $this->assertSame('TARJETA', $ordenA->metodo_pago);
        $this->assertSame('Urge', $ordenA->comentarios);
        $this->assertSame(EstadoOrdenProveedor::Emitida, $ordenA->estado);
        $this->assertSame($this->user->id, $ordenA->user_id);
        $this->assertEquals(400.00, (float) $ordenA->total_costo);

        foreach ([$pa1, $pa2] as $p) {
            $this->assertSame($ordenA->id, $p->fresh()->orden_proveedor_id);
        }
        $this->assertSame('solicitada', $pb->fresh()->estado->value);
        $this->assertNotNull($pb->fresh()->orden_proveedor_id);
    }

    public function test_partida_desmarcada_no_entra_y_las_que_ya_tienen_oc_no_reaparecen(): void
    {
        [$pedido, $a, $b, $pa1, $pa2, $pb] = $this->pedidoConDosProveedores();

        $this->generarOc($pedido)
            ->set('seleccion.'.$pa2->id, false)
            ->set('config.p'.$b->id.'.incluir', false)
            ->call('generar');

        $this->assertNotNull($pa1->fresh()->orden_proveedor_id);
        $this->assertNull($pa2->fresh()->orden_proveedor_id);
        $this->assertNull($pb->fresh()->orden_proveedor_id);
        $this->assertSame('pendiente', $pa2->fresh()->estado->value);

        $this->generarOc($pedido)
            ->assertDontSee($pa1->descripcion)
            ->assertSee($pa2->descripcion)
            ->assertSee($pb->descripcion);
    }

    public function test_grupo_sin_proveedor_exige_elegir_uno(): void
    {
        $pedido = Pedido::factory()->create();
        $prov = Proveedor::factory()->create(['nombre' => 'ELEGIDO SA']);
        $p = $this->partida($pedido, null);

        $this->generarOc($pedido)
            ->assertSee('Sin proveedor')
            ->call('generar')
            ->assertHasErrors(['grupo.sin']);
        $this->assertSame(0, OrdenProveedor::count());
        $this->assertNull($p->fresh()->orden_proveedor_id);

        $this->generarOc($pedido)
            ->call('asignarProveedorNombre', $p->id, 'elegido sa')
            ->call('generar')->assertHasErrors(['grupo.p'.$prov->id])   // falta decir cómo se le paga
            ->set('config.p'.$prov->id.'.metodo_pago', 'TARJETA')
            ->call('generar')
            ->assertHasNoErrors();

        $this->assertSame($prov->id, $p->fresh()->proveedor_id);
        $this->assertSame(1, OrdenProveedor::where('proveedor_id', $prov->id)->count());
    }

    public function test_cancelar_libera_partidas_y_recibida_propaga(): void
    {
        [$pedido, $a, $b, $pa1, $pa2, $pb] = $this->pedidoConDosProveedores();
        $this->generarOc($pedido)->call('generar');
        $ordenA = OrdenProveedor::where('proveedor_id', $a->id)->first();
        $ordenB = OrdenProveedor::where('proveedor_id', $b->id)->first();

        Livewire::test(Ver::class, ['orden' => $ordenA])->call('cancelar');
        $this->assertSame(EstadoOrdenProveedor::Cancelada, $ordenA->fresh()->estado);
        foreach ([$pa1, $pa2] as $p) {
            $this->assertNull($p->fresh()->orden_proveedor_id);
            $this->assertSame('pendiente', $p->fresh()->estado->value);
        }

        Livewire::test(Ver::class, ['orden' => $ordenB])
            ->call('marcarEnviada')
            ->call('pedirRecibida')
            ->assertSet('confirmandoRecibida', true)
            ->call('marcarRecibida', true);
        $this->assertSame(EstadoOrdenProveedor::Recibida, $ordenB->fresh()->estado);
        $this->assertSame('recibida', $pb->fresh()->estado->value);

        // Las partidas liberadas vuelven a aparecer para generar otra OC.
        $this->generarOc($pedido)->assertSee($pa1->descripcion);
    }

    public function test_link_de_compra_en_la_oc(): void
    {
        $pedido = Pedido::factory()->create();
        $ml = Proveedor::factory()->create(['nombre' => 'MERCADO LIBRE']);
        $url = 'https://articulo.mercadolibre.com.mx/MLM-3884022798-engrapadora';
        // El link venía pegado en "Modelo": en la OC sale como link y no como modelo.
        $p = $this->partida($pedido, $ml, ['modelo' => $url]);
        $sinLink = $this->partida($pedido, $ml);
        $this->generarOc($pedido)->call('generar');
        $orden = OrdenProveedor::firstOrFail();

        $pantalla = Livewire::test(Ver::class, ['orden' => $orden])
            ->assertSee('Copiar para pedir compra')->assertSee('href="'.$url.'"', false)->assertSee('+ Poner link de compra');

        // Texto listo para pegarle a Daniel en WhatsApp, con cantidad, cliente y OC del cliente.
        $pedido->load('cliente');
        $texto = $pantalla->viewData('textosCompra')[$p->id];
        $this->assertSame("¿Me puedes apoyar a pedir 2 pza de este link ({$p->descripcion}):\n{$url}\n\nEs para ".$pedido->cliente->nombre
            .' de la '.($pedido->numero_oc_cliente ? 'OC '.$pedido->numero_oc_cliente : 'nota de venta (sin OC)')
            ." (pedido P-{$pedido->folio}, OC-0001). Gracias.", $texto);
        $pantalla->call('registrarPedirCompra', [$p->id])->assertSee('Quedó anotado en la tarjeta');
        $this->assertDatabaseHas('pedido_actividades', ['pedido_id' => $pedido->id, 'tipo' => 'compra_por_link']);
        $this->assertStringNotContainsString('mercadolibre', OrdenProveedorPdf::html($orden->fresh()));

        // El vendedor pone el link a la otra partida desde la OC.
        $pantalla->call('guardarLink', $sinLink->id, 'www.amazon.com.mx/dp/B0TEST')->assertHasNoErrors()->assertSee('Link de compra guardado')
            ->assertSee('Copiar todas para pedir compra');
        $this->assertStringContainsString('1) 2 pza de este link', $pantalla->viewData('textoCompraTodas'));
        $this->assertStringContainsString("2) 2 pza de este link ({$sinLink->descripcion}):\nhttps://www.amazon.com.mx/dp/B0TEST", $pantalla->viewData('textoCompraTodas'));
        $this->assertSame('https://www.amazon.com.mx/dp/B0TEST', $sinLink->fresh()->link_compra);
        $pantalla->call('guardarLink', $sinLink->id, 'no es link')->assertHasErrors('link.'.$sinLink->id);

        // Campo (Hugo) ve el link pero no lo cambia.
        $this->actingAs(User::factory()->create(['rol' => 'campo']));
        Livewire::test(Ver::class, ['orden' => $orden])->assertSee('Ver link')->assertDontSee('Copiar para pedir compra')->assertDontSee('Poner link de compra')
            ->call('guardarLink', $p->id, $url)->assertForbidden();
    }

    public function test_pdf_responde_y_no_incluye_precio_de_venta(): void
    {
        [$pedido, $a, , $pa1] = $this->pedidoConDosProveedores();
        $pa1->update(['proveedor_vendedor' => 'JUAN VENDEDOR']);
        $this->generarOc($pedido)->call('generar');
        $orden = OrdenProveedor::where('proveedor_id', $a->id)->first();

        $html = OrdenProveedorPdf::html($orden);
        $this->assertStringContainsString('ORDEN DE COMPRA', $html);
        $this->assertStringContainsString($orden->folioTexto(), $html);
        $this->assertStringContainsString('JUAN VENDEDOR', $html);
        // El cliente (nuestra referencia) nunca sale en el PDF que recibe el proveedor.
        $pedido->cliente->update(['nombre' => 'CLIENTE SECRETO SA']);
        $html = OrdenProveedorPdf::html($orden->fresh());
        $this->assertStringNotContainsString('CLIENTE SECRETO', $html);
        $this->assertStringContainsString('Pedido P-'.$pedido->folio, $html);   // la referencia a la tarjeta sí va
        $this->assertStringNotContainsString('777.77', $html);
        $this->assertStringNotContainsString('1,555.54', $html);

        $r = $this->get(route('ordenes-proveedor.pdf', $orden));
        $r->assertOk();
        $this->assertStringContainsString('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());

        $d = $this->get(route('ordenes-proveedor.pdf', ['orden' => $orden, 'descargar' => 1]));
        $this->assertStringContainsString('attachment', $d->headers->get('Content-Disposition'));
    }

    public function test_rol_campo_solo_consulta_y_marca_recibida(): void
    {
        $orden = $this->crearOrden(5);
        $this->actingAs($campo = User::factory()->create(['rol' => 'campo']));

        // No genera órdenes.
        $this->get('/pedidos/'.$orden->pedido_id.'/ordenes-proveedor/nueva')->assertForbidden();

        // Sí consulta lista, detalle con costos y PDF.
        $this->get('/ordenes-proveedor')->assertOk()->assertSee('OC-0005');
        $this->get('/ordenes-proveedor/'.$orden->id)->assertOk()->assertSee('Costo s/IVA')
            ->assertDontSee('Cancelar orden')->assertDontSee('Marcar como enviada');
        $this->get('/ordenes-proveedor/'.$orden->id.'/pdf')->assertOk();

        // No cancela ni marca enviada (validado en el servidor).
        Livewire::test(Ver::class, ['orden' => $orden])->call('cancelar')->assertForbidden();
        Livewire::test(Ver::class, ['orden' => $orden])->call('marcarEnviada')->assertForbidden();
        $this->assertSame(EstadoOrdenProveedor::Emitida, $orden->fresh()->estado);

        // Sí marca recibida, con propagación a partidas.
        Livewire::test(Ver::class, ['orden' => $orden])->call('marcarRecibida', true);
        $this->assertSame(EstadoOrdenProveedor::Recibida, $orden->fresh()->estado);
        $this->assertSame('recibida', $orden->partidas()->first()->estado->value);
        $this->assertNotNull($campo);
    }

    public function test_vendedor_ve_pantallas_de_lista_y_detalle(): void
    {
        $orden = $this->crearOrden(7);

        $this->get('/ordenes-proveedor')->assertOk()->assertSee('OC-0007');
        $this->get('/ordenes-proveedor/'.$orden->id)->assertOk()
            ->assertSee('compartir-documento', false)
            ->assertSee(url('/pedidos/'.$orden->pedido_id), false);
        $this->get('/pedidos/'.$orden->pedido_id.'/ordenes-proveedor/nueva')->assertOk();
    }

    public function test_busqueda_por_folio_y_por_cliente(): void
    {
        $o12 = $this->crearOrden(12);
        $o13 = $this->crearOrden(13, 'CLIENTE ZETA');

        Livewire::test(Index::class)
            ->set('buscar', 'OC-0012')
            ->assertSee('OC-0012')->assertDontSee('OC-0013');
        Livewire::test(Index::class)
            ->set('buscar', '13')
            ->assertSee('OC-0013')->assertDontSee('OC-0012');
        Livewire::test(Index::class)
            ->set('buscar', 'zeta')
            ->assertSee('OC-0013')->assertDontSee('OC-0012');
        Livewire::test(Index::class)
            ->set('estado', 'recibida')
            ->assertDontSee('OC-0012');
        $this->assertNotNull($o12);
        $this->assertNotNull($o13);
    }

    private function crearOrden(int $folio, ?string $cliente = null): OrdenProveedor
    {
        $c = Cliente::factory()->create($cliente ? ['nombre' => $cliente] : []);
        $pedido = Pedido::factory()->create(['cliente_id' => $c->id]);
        $prov = Proveedor::factory()->create();
        $orden = OrdenProveedor::create([
            'folio' => $folio, 'pedido_id' => $pedido->id, 'proveedor_id' => $prov->id,
            'user_id' => $this->user->id, 'metodo_pago' => 'EFECTIVO', 'estado' => 'emitida', 'total_costo' => 200,
        ]);
        $this->partida($pedido, $prov, ['orden_proveedor_id' => $orden->id, 'estado' => 'solicitada']);

        return $orden;
    }

    public function test_la_oc_como_imagen_para_whatsapp(): void
    {
        [$pedido, $a, , $pa1] = $this->pedidoConDosProveedores();
        $pa1->update(['modelo' => 'SKU-48379', 'descripcion' => 'LIMPIADOR PARA PVC']);
        $pedido->cliente->update(['nombre' => 'CLIENTE SECRETO SA']);
        $this->generarOc($pedido)->call('generar');
        $orden = OrdenProveedor::where('proveedor_id', $a->id)->firstOrFail();

        $r = $this->get(route('ordenes-proveedor.imagen', $orden))->assertOk()->assertHeader('Content-Type', 'image/png');
        $info = getimagesizefromstring($r->getContent());
        $this->assertSame(1080, $info[0]);
        $this->assertGreaterThan(600, $info[1]);

        // Descargarla cuenta como envío (pasa a enviada); con registrar=0 (botón de WhatsApp) no.
        $this->get(route('ordenes-proveedor.imagen', ['orden' => $orden, 'descargar' => 1, 'registrar' => 0]))->assertOk();
        $this->assertSame('emitida', $orden->fresh()->estado->value);
        $this->get(route('ordenes-proveedor.imagen', ['orden' => $orden, 'descargar' => 1]))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="'.preg_replace('/\\.pdf$/i', '.png', $orden->nombreArchivoPdf()).'"');
        $this->assertSame('enviada', $orden->fresh()->estado->value);

        Livewire::test(Ver::class, ['orden' => $orden])->assertSee('Descargar imagen');
    }

    public function test_corregir_total_por_descuento_ajusta_costos_utilidad_y_avisa_sicar(): void
    {
        // Como la OC-0061: 2 PAQ a $1,600 c/IVA = $3,200; el proveedor cobró $2,900 neto.
        $pedido = Pedido::factory()->create();
        $prov = Proveedor::factory()->create(['nombre' => 'RYASA']);
        $orden = OrdenProveedor::create([
            'folio' => 61, 'pedido_id' => $pedido->id, 'proveedor_id' => $prov->id,
            'user_id' => $this->user->id, 'metodo_pago' => 'TARJETA', 'estado' => 'enviada', 'total_costo' => 2758.62,
        ]);
        $p = $this->partida($pedido, $prov, ['orden_proveedor_id' => $orden->id, 'estado' => 'solicitada', 'cantidad' => 2, 'costo' => 1379.3103, 'precio' => 1800]);
        $otra = $this->partida($pedido, $prov, ['costo' => 50, 'cantidad' => 1, 'precio' => 80]);   // no está en la OC
        $pedido->recalcularTotales();

        Livewire::test(Ver::class, ['orden' => $orden])
            ->call('abrirCorregirCosto')->assertSet('corrigiendoCosto', true)
            ->set('nuevoTotal', '$2,900')->set('motivoCosto', 'descuento del proveedor')
            ->call('corregirCosto')
            ->assertHasNoErrors()->assertSet('corrigiendoCosto', false)
            ->assertSee('Cuando se dé de alta en SICAR entrará con el costo nuevo')
            ->assertSee('$2,900.00');

        $this->assertEqualsWithDelta(1250, (float) $p->fresh()->costo, 0.001);          // 2,900 / 1.16 / 2
        $this->assertEqualsWithDelta(50, (float) $otra->fresh()->costo, 0.0001);        // las de fuera no se tocan
        $this->assertEqualsWithDelta(2500, (float) $orden->fresh()->total_costo, 0.01);
        $this->assertSame(EstadoOrdenProveedor::Enviada, $orden->fresh()->estado);
        $this->assertFalse((bool) $orden->fresh()->requiere_reenvio);                   // el proveedor ya lo sabe
        $this->assertEqualsWithDelta(2550, (float) $pedido->fresh()->total_costo, 0.01);
        $this->assertDatabaseHas('pedido_actividades', ['pedido_id' => $pedido->id, 'tipo' => 'costo_oc',
            'descripcion' => 'corrigió el costo de la OC-0061 de $3,200.00 a $2,900.00 con IVA (descuento del proveedor)']);

        // La imagen que se manda a quien recoge sale con el costo nuevo (no truena).
        $this->get(route('ordenes-proveedor.imagen', $orden))->assertOk();

        // Ya dado de alta en SICAR: avisa que allá hay que corregirlo; en un lote abierto: que lo rehagan.
        $pedido->update(['alta_sicar' => true]);
        Livewire::test(Ver::class, ['orden' => $orden->fresh()])->set('nuevoTotal', '2800')->call('corregirCosto')
            ->assertSee('ya se dio de alta en SICAR con el costo anterior');
        $pedido->update(['alta_sicar' => false]);
        SicarLote::create(['estado' => EstadoLoteSicar::Simulado, 'datos' => ['pedidos' => []]])->pedidos()->attach($pedido->id);
        Livewire::test(Ver::class, ['orden' => $orden->fresh()])->set('nuevoTotal', '2700')->call('corregirCosto')
            ->assertSee('hay un alta a SICAR en proceso');
    }

    public function test_corregir_total_valida_y_campo_no_puede(): void
    {
        $orden = $this->crearOrden(70);   // 2 PZA a $100 → $232 con IVA
        Livewire::test(Ver::class, ['orden' => $orden])->set('nuevoTotal', 'abc')->call('corregirCosto')->assertHasErrors('nuevoTotal');
        Livewire::test(Ver::class, ['orden' => $orden])->set('nuevoTotal', '232')->call('corregirCosto')->assertHasErrors('nuevoTotal');
        Livewire::test(Ver::class, ['orden' => $orden])->set('nuevoTotal', '0')->call('corregirCosto')->assertHasErrors('nuevoTotal');
        $orden->update(['estado' => 'cancelada']);
        Livewire::test(Ver::class, ['orden' => $orden])->set('nuevoTotal', '200')->call('corregirCosto')->assertHasErrors('nuevoTotal');
        $this->assertEqualsWithDelta(100, (float) $orden->partidas()->first()->costo, 0.0001);

        $orden->update(['estado' => 'emitida']);
        $hugo = User::factory()->create(['rol' => 'campo']);
        Livewire::actingAs($hugo)->test(Ver::class, ['orden' => $orden])->assertDontSee('Corregir total')
            ->set('nuevoTotal', '200')->call('corregirCosto')->assertForbidden();
    }

    /** Generar OC con "¿cómo se le paga?" ya elegido (ahora no trae valor por omisión). */
    private function generarOc(Pedido $pedido, string $metodo = 'TRANSFERENCIA')
    {
        $t = Livewire::test(Generar::class, ['pedido' => $pedido]);
        foreach (array_keys((array) $t->get('config')) as $k) {
            if ($t->get("config.$k.metodo_pago") === '') {
                $t->set("config.$k.metodo_pago", $metodo);
            }
        }

        return $t;
    }
}

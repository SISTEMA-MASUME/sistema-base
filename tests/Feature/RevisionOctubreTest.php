<?php

namespace Tests\Feature;

use App\Enums\EstadoPartidaPedido;
use App\Livewire\Cotizaciones\Editor;
use App\Livewire\Cotizaciones\Index as ListaCotizaciones;
use App\Livewire\Pedidos\Nuevo;
use App\Livewire\Pedidos\PorRecoger;
use App\Livewire\Pedidos\Ver;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\ProveedorContacto;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Revisión de octubre: "Ir a folio" para todo, OC que en realidad es una cotización, "✓ Recogí" en la ruta y paginación en español. */
class RevisionOctubreTest extends TestCase
{
    use RefreshDatabase;

    public function test_ir_a_folio_abre_cotizacion_pedido_archivado_orden_y_solicitud(): void
    {
        $rosa = User::factory()->create(['rol' => 'vendedor']);
        $cot = Cotizacion::factory()->create(['folio' => 90123]);
        $pedido = Pedido::factory()->create(['folio' => 47, 'archivado_at' => now()]);
        $prov = Proveedor::factory()->create();
        $orden = OrdenProveedor::create(['folio' => 12, 'pedido_id' => $pedido->id, 'proveedor_id' => $prov->id, 'user_id' => $rosa->id,
            'metodo_pago' => 'TARJETA', 'estado' => 'emitida', 'total_costo' => 100]);
        $sol = SolicitudCotizacion::create(['folio' => 8, 'canal' => 'whatsapp', 'recibida_at' => now(), 'detalle' => 'Brocas',
            'partidas' => [], 'estatus' => 'pendiente']);

        $this->actingAs($rosa);
        $this->get('/ir/90123')->assertRedirect(route('cotizaciones.editar', $cot));
        $this->get('/ir/'.rawurlencode('COT 90123'))->assertRedirect(route('cotizaciones.editar', $cot));
        $this->get('/ir/p-47')->assertRedirect(route('pedidos.index', ['pedido' => $pedido->id]));   // aunque esté archivado
        $this->get('/ir/'.rawurlencode('P 47'))->assertRedirect(route('pedidos.index', ['pedido' => $pedido->id]));
        $this->get('/ir/OC-0012')->assertRedirect(route('ordenes-proveedor.ver', $orden));
        $this->get('/ir/s-8')->assertRedirect(route('solicitudes.ver', $sol));
        $this->from('/pedidos')->get('/ir/P-999')->assertRedirect('/pedidos')->assertSessionHas('error', fn ($e) => str_contains($e, 'No encontré P-999'));
        $this->from('/pedidos')->get('/ir/hola')->assertSessionHas('error');

        // Hugo (campo) abre pedidos y órdenes, pero no cotizaciones ni solicitudes (traen precios de venta).
        $this->actingAs(User::factory()->create(['rol' => 'campo']));
        $this->get('/ir/P-47')->assertRedirect(route('pedidos.index', ['pedido' => $pedido->id]));
        $this->get('/ir/OC-12')->assertRedirect(route('ordenes-proveedor.ver', $orden));
        $this->from('/pedidos')->get('/ir/90123')->assertRedirect('/pedidos')->assertSessionHas('error', fn ($e) => str_contains($e, 'solo puedes abrir pedidos'));
        $this->from('/pedidos')->get('/ir/S-8')->assertSessionHas('error');

        // El menú ya manda a la ruta nueva.
        $this->actingAs($rosa)->get('/pedidos')->assertSee(url('/ir')."/'", false)->assertDontSee(url('/cotizaciones/folio'), false);
    }

    public function test_el_folio_de_una_cotizacion_no_se_acepta_como_oc_del_cliente(): void
    {
        $this->assertTrue(Pedido::esFolioDeCotizacion('COT 90006'));
        $this->assertTrue(Pedido::esFolioDeCotizacion('cot-10572'));
        $this->assertTrue(Pedido::esFolioDeCotizacion('Cotización 90123'));
        $this->assertFalse(Pedido::esFolioDeCotizacion('4500012345'));
        $this->assertFalse(Pedido::esFolioDeCotizacion('COSTCO 12'));
        $this->assertFalse(Pedido::esFolioDeCotizacion('OC 4567'));

        $user = User::factory()->create();
        $this->actingAs($user);
        $cot = Cotizacion::factory()->create(['vendedor_id' => $user->id, 'cliente_id' => Cliente::factory()->create()->id, 'estado' => 'enviada']);
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'cantidad' => 2, 'costo' => 100, 'precio' => 150]);
        $id = (string) $cot->partidas()->value('id');

        // Al crear el pedido.
        $t = Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)
            ->set('seleccion', [$id])->set('numeroOc', 'COT 90006')->set('justificacion', 'La OC llega por correo después')
            ->call('crear')->assertHasErrors('numeroOc')->assertNoRedirect();
        $this->assertSame(Pedido::AVISO_OC_ES_COTIZACION, $t->errors()->first('numeroOc'));
        $this->assertSame(0, Pedido::count());

        // Al capturarla después en la tarjeta.
        $pedido = Pedido::factory()->create(['numero_oc_cliente' => null, 'tipo' => 'nota_venta']);
        Livewire::test(Ver::class, ['pedido' => $pedido])->set('numeroOc', 'COT 10572')->call('guardarNumeroOc')
            ->assertHasErrors('numeroOc')->assertSee('Eso es el folio de una cotización');
        $this->assertNull($pedido->fresh()->numero_oc_cliente);
        $this->assertContains('Esperando OC del cliente', array_column($pedido->fresh()->etiquetas(), 'texto'));
    }

    public function test_hugo_marca_recogi_desde_la_ruta_y_ve_el_whatsapp_del_proveedor(): void
    {
        $urrea = Proveedor::factory()->create(['nombre' => 'URREA', 'telefono' => null]);
        ProveedorContacto::create(['proveedor_id' => $urrea->id, 'nombre' => 'Lalo', 'telefono' => '33 1234 5678', 'principal' => true]);
        $pedido = Pedido::factory()->create(['cliente_id' => Cliente::factory()->create(['nombre' => 'HOTEL CENTRAL'])->id]);
        $guante = PedidoPartida::create(['pedido_id' => $pedido->id, 'orden' => 1, 'descripcion' => 'GUANTE NITRILO NEGRO', 'unidad' => 'CAJA',
            'cantidad' => 10, 'costo' => 185, 'precio' => 250, 'estado' => 'solicitada', 'proveedor_id' => $urrea->id]);
        $llave = PedidoPartida::create(['pedido_id' => $pedido->id, 'orden' => 2, 'descripcion' => 'LLAVE STILLSON', 'unidad' => 'PZA',
            'cantidad' => 1, 'costo' => 300, 'precio' => 420, 'estado' => 'solicitada', 'proveedor_id' => $urrea->id]);

        $t = Livewire::actingAs(User::factory()->create(['rol' => 'campo', 'name' => 'Hugo']))->test(PorRecoger::class)
            ->assertSee('✓ Recogí')->assertSee('https://wa.me/523312345678', false)->assertDontSee('250.00');

        // Una pieza suelta se anota de una vez.
        $t->call('recoger', $llave->id)->assertSet('recogiendoId', null)->assertSee('"LLAVE STILLSON" quedó recogida completa.');
        $this->assertSame(EstadoPartidaPedido::Recibida, $llave->fresh()->estado);

        // Varias piezas: pregunta cuántas; el proveedor solo dio 6 de 10.
        $t->call('recoger', $guante->id)->assertSet('recogiendoId', $guante->id)->assertSet('cantidad', '10')->assertSee('¿Cuántas recogiste?')
            ->set('cantidad', '0')->call('guardarRecogida')->assertHasErrors('cantidad')
            ->set('cantidad', '6')->call('guardarRecogida')->assertSet('recogiendoId', null)
            ->assertSee('faltan 4 caja por recoger')->assertSee('Faltan 4 CAJA · ya se recogieron 6 de 10');
        $this->assertEquals(6, (float) $guante->fresh()->cantidad_recogida);
        $this->assertSame(EstadoPartidaPedido::Solicitada, $guante->fresh()->estado);
        $this->assertTrue(PedidoActividad::where('pedido_id', $pedido->id)->where('descripcion', 'like', 'recogió 6 caja%')->exists());

        // Lo que falta (aunque escriban de más, no pasa de 10) y desaparece de la ruta.
        $t->call('recoger', $guante->id)->assertSet('cantidad', '4')->set('cantidad', '9')->call('guardarRecogida')
            ->assertSee('quedó recogida completa')->assertSee('No hay nada pendiente de recoger');
        $this->assertEquals(10, (float) $guante->fresh()->cantidad_recogida);

        // Otra vez (alguien más ya la marcó): solo avisa.
        $t->call('recoger', $guante->id)->assertSee('ya no está por recoger');
    }

    public function test_paginacion_en_espanol_y_foto_del_cotizador_con_un_toque(): void
    {
        app()->setLocale('es');
        $this->assertSame('&larr; Anterior', __('pagination.previous'));
        $this->assertSame('Siguiente &rarr;', __('pagination.next'));
        $this->assertSame('Mostrando', __('Showing'));

        $editor = file_get_contents(resource_path('views/livewire/cotizaciones/editor.blade.php'));
        $this->assertStringContainsString("matchMedia('(pointer: coarse)').matches ? \$refs.archivo.click()", $editor);
        $this->assertStringContainsString('📷 Imagen', $editor);
    }

    public function test_lista_de_cotizaciones_busca_el_cliente_escribiendo_y_pinta_el_estado_de_color(): void
    {
        $this->actingAs(User::factory()->create());
        $central = Cliente::factory()->create(['nombre' => 'HOTEL CENTRAL']);
        $kappa = Cliente::factory()->create(['nombre' => 'KAPPA']);
        Cotizacion::factory()->create(['folio' => 91001, 'cliente_id' => $central->id, 'estado' => 'aceptada']);
        Cotizacion::factory()->create(['folio' => 91002, 'cliente_id' => $kappa->id, 'estado' => 'borrador']);

        $t = Livewire::test(ListaCotizaciones::class)->assertSee('91001')->assertSee('91002')
            ->assertSee('bg-green-100 text-green-800', false)->assertSee('Aceptada (OC)');
        $t->set('clienteTexto', 'barc')->assertSee('HOTEL CENTRAL')
            ->call('elegirCliente', $central->id)->assertSet('cliente', (string) $central->id)->assertSet('clienteTexto', '')
            ->assertSee('91001')->assertDontSee('91002')->assertSee('Quitar filtro de cliente');
        $t->call('quitarCliente')->assertSee('91002');
        $t->set('clienteTexto', 'zzz')->assertSee('Ningún cliente con ese nombre.');
    }

    public function test_cotizador_con_barra_de_totales_fija_y_aviso_de_guardado(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $cot = Cotizacion::factory()->create(['vendedor_id' => $user->id, 'cliente_id' => Cliente::factory()->create()->id]);
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'cantidad' => 2, 'costo' => 100, 'precio' => 150]);

        Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->assertSee('data-barra-totales', false)->assertSee('Total con IVA')->assertSee('+ Agregar partida')
            ->assertSee('Guardando…')->assertSee('✓ Guardado')
            ->assertSee("\$store.guardado.pendientes ? 'pointer-events-none opacity-60' : ''", false);
        $js = file_get_contents(resource_path('js/partidas.js'));
        $this->assertStringContainsString("Alpine.store('guardado'", $js);
        $this->assertStringContainsString('aviso.fin(ok)', $js);
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\CambiosPrecio\Index;
use App\Livewire\Pedidos\Nuevo;
use App\Livewire\Pedidos\Ver;
use App\Models\Ajuste;
use App\Models\CambioPrecio;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\CambiosPrecio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CambioPrecioTest extends TestCase
{
    use RefreshDatabase;

    private const MOTIVO = 'La OC de Central pide la pija 8x1 a 0.21';

    protected function setUp(): void
    {
        parent::setUp();
        // Las pruebas de autorización corren con el interruptor encendido; el modo actual (apagado) tiene su prueba abajo.
        Ajuste::guardar(CambiosPrecio::REQUIERE_AUTORIZACION, '1');
    }

    private function vendedor(): User
    {
        return User::factory()->create(['rol' => 'vendedor', 'name' => 'Rosa']);
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'admin', 'name' => 'Daniel']);
    }

    /** Cotización con una partida de 100 piezas a $280 (costo 200): un cambio de $10 o más por pieza pide motivo. */
    private function partida(array $extra = []): CotizacionPartida
    {
        $cot = Cotizacion::factory()->create(['cliente_id' => Cliente::factory()->create()->id, 'estado' => 'enviada']);

        return CotizacionPartida::factory()->create(array_merge([
            'cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'PIJA 8X3/4', 'modelo' => null, 'cantidad' => 100, 'unidad' => 'PZA', 'costo' => 200, 'precio' => 280,
        ], $extra));
    }

    private function nuevo(CotizacionPartida $p)
    {
        return Livewire::withQueryParams(['cotizacion' => $p->cotizacion_id])->test(Nuevo::class)
            ->set('seleccion', [(string) $p->id])
            ->set('numeroOc', 'BAR-100')->set('justificacion', 'La OC llega por correo después');
    }

    public function test_vendedor_pide_cambio_de_precio_y_queda_pendiente_con_el_precio_cotizado(): void
    {
        $this->actingAs($this->vendedor());
        $p = $this->partida();

        $this->nuevo($p)->assertSet("precios.{$p->id}", 280.0)
            ->set("precios.{$p->id}", '210')->set("motivosPrecio.{$p->id}", self::MOTIVO)->set("costosPrecio.{$p->id}", '150')
            ->call('crear')->assertHasNoErrors()->assertRedirect();

        $pedido = Pedido::firstOrFail();
        $pp = $pedido->partidas->first();
        $this->assertEquals(280.0, (float) $pp->precio);
        $this->assertEquals(28000.0, (float) $pedido->total_venta);
        $c = CambioPrecio::firstOrFail();
        $this->assertSame('pendiente', $c->estatus);
        $this->assertSame($pp->id, $c->pedido_partida_id);
        $this->assertEquals(280.0, (float) $c->precio_anterior);
        $this->assertEquals(210.0, (float) $c->precio_solicitado);
        $this->assertEquals(150.0, (float) $c->costo_nuevo);
        $this->assertEquals(100, (float) $c->cantidad);
        $this->assertEquals(8000.0, (float) $c->utilidad_antes);
        $this->assertEquals(6000.0, (float) $c->utilidad_despues);
        $this->assertTrue($pedido->actividades()->where('descripcion', 'like', '%pidió cambiar el precio de "PIJA 8X3/4" de $280.00 a $210.00%falta autorización de Daniel%')->exists());
    }

    public function test_sin_motivo_no_deja_crear_y_precio_negativo_tampoco(): void
    {
        $this->actingAs($this->vendedor());
        $p = $this->partida();

        $this->nuevo($p)->set("precios.{$p->id}", '210')->set("motivosPrecio.{$p->id}", 'corto')
            ->call('crear')->assertHasErrors(["motivosPrecio.{$p->id}"])->assertNoRedirect();
        $this->assertSame(0, Pedido::count());

        $this->nuevo($p)->set("precios.{$p->id}", '-1')->set("motivosPrecio.{$p->id}", self::MOTIVO)
            ->call('crear')->assertHasErrors(["precios.{$p->id}"])->assertNoRedirect();
        $this->assertSame(0, Pedido::count());

        // Tampoco deja pasar al paso 3 sin motivo.
        $this->nuevo($p)->set("precios.{$p->id}", '210')->call('irAPaso', 3)->assertHasErrors(['motivoGeneral'])->assertSet('paso', 2);
    }

    public function test_sin_tocar_el_precio_no_se_pide_nada(): void
    {
        $this->actingAs($this->vendedor());
        $p = $this->partida();

        $this->nuevo($p)->call('crear')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(0, CambioPrecio::count());
    }

    public function test_la_suma_y_la_conciliacion_usan_el_precio_escrito(): void
    {
        Storage::fake('local');
        config(['services.openai.key' => 'sk-test', 'services.openai.modelo' => 'gpt-6-luna', 'services.openai.tope_mensual_usd' => 10.0]);
        $this->actingAs($this->vendedor());
        $p = $this->partida(['precio' => 100, 'costo' => 60, 'cantidad' => 10]);
        $renglon = ['num_renglon' => 1, 'codigo_cliente' => null, 'descripcion' => 'PIJA 8X3/4', 'marca' => null, 'modelo' => null, 'cantidad' => 10,
            'unidad' => 'PZA', 'precio_unitario' => 70, 'importe' => null, 'fecha_entrega' => null, 'origen' => 'tabla', 'confianza' => 'alta', 'texto_original' => 'PIJA'];
        $extraccion = ['numero_oc' => 'OC-1', 'fecha_oc' => '2026-09-28', 'cliente_detectado' => null, 'moneda' => 'MXN', 'precios_incluyen_iva' => false,
            'renglones' => [$renglon], 'subtotal' => null, 'iva' => null, 'total' => null, 'observaciones_generales' => null, 'advertencias' => []];
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['id' => 'resp_1', 'status' => 'queued'], 200),
            'api.openai.com/v1/responses/*' => Http::response(['id' => 'resp_1', 'status' => 'completed', 'output' => [
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($extraccion)]]]],
                'usage' => ['input_tokens' => 6000, 'output_tokens' => 1000]], 200),
        ]);

        $t = Livewire::withQueryParams(['cotizacion' => $p->cotizacion_id])->test(Nuevo::class)
            ->set('seleccion', [(string) $p->id])->call('irAPaso', 3)
            ->set('archivo', UploadedFile::fake()->create('oc.pdf', 200, 'application/pdf'))
            ->call('consultarOc');
        // Con el precio cotizado ($100) la OC ($70) trae un precio distinto.
        $this->assertSame(1000.0, $t->viewData('sumaMarcadas')['subtotal']);
        $this->assertTrue($t->viewData('resultadoOc')['hay_diferencias']);

        // Con el precio escrito ($70) la suma y la revisión de la OC cuadran.
        $t->set("precios.{$p->id}", '70');
        $this->assertSame(700.0, $t->viewData('sumaMarcadas')['subtotal']);
        $this->assertSame(812.0, $t->viewData('sumaMarcadas')['total']);
        $this->assertFalse($t->viewData('resultadoOc')['hay_diferencias']);
    }

    public function test_admin_cambia_el_precio_directo_y_queda_aplicado(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $prov = Proveedor::factory()->create(['nombre' => 'TORNILLOS DEL BAJIO']);
        $p = $this->partida();

        $this->nuevo($p)->set("precios.{$p->id}", '210')->set("motivosPrecio.{$p->id}", self::MOTIVO)
            ->set("costosPrecio.{$p->id}", '150')->set("proveedoresPrecio.{$p->id}", 'tornillos del bajio')
            ->call('crear')->assertHasNoErrors()->assertRedirect();

        $pedido = Pedido::firstOrFail();
        $pp = $pedido->partidas->first();
        $this->assertEquals(210.0, (float) $pp->precio);
        $this->assertEquals(150.0, (float) $pp->costo);
        $this->assertSame($prov->id, $pp->proveedor_id);
        $this->assertEquals(21000.0, (float) $pedido->total_venta);
        $this->assertEquals(15000.0, (float) $pedido->total_costo);
        $c = CambioPrecio::firstOrFail();
        $this->assertSame('directo', $c->estatus);
        $this->assertSame($admin->id, $c->resuelto_por);
        $this->assertEquals(210.0, (float) $c->precio_final);
        $this->assertEquals(8000.0, (float) $c->utilidad_antes);
        $this->assertEquals(6000.0, (float) $c->utilidad_despues);
        $this->assertTrue($pedido->actividades()->where('descripcion', 'like', '%cambió el precio de "PIJA 8X3/4" de $280.00 a $210.00%')->exists());
    }

    /** Pedido creado por un vendedor con un cambio pendiente. */
    private function pendiente(): array
    {
        $vendedor = $this->vendedor();
        $this->actingAs($vendedor);
        $p = $this->partida();
        $this->nuevo($p)->set("precios.{$p->id}", '210')->set("motivosPrecio.{$p->id}", self::MOTIVO)->call('crear')->assertRedirect();

        return [Pedido::firstOrFail(), CambioPrecio::firstOrFail()];
    }

    public function test_autorizar_aplica_precio_y_costo_ajustados_y_recalcula(): void
    {
        [$pedido, $c] = $this->pendiente();
        $admin = $this->admin();
        $this->actingAs($admin);

        Livewire::test(Index::class)
            ->assertSee('Pedido P-'.$pedido->folio)->assertSee(self::MOTIVO)
            ->set("precioFinal.{$c->id}", '220')->set("costoFinal.{$c->id}", '170')
            ->call('autorizar', $c->id)->assertHasNoErrors();

        $c->refresh();
        $this->assertSame('autorizado', $c->estatus);
        $this->assertSame($admin->id, $c->resuelto_por);
        $this->assertEquals(220.0, (float) $c->precio_final);
        $this->assertEquals(170.0, (float) $c->costo_nuevo);
        $this->assertEquals(8000.0, (float) $c->utilidad_antes);
        $this->assertEquals(5000.0, (float) $c->utilidad_despues);
        $pp = $pedido->partidas()->first();
        $this->assertEquals(220.0, (float) $pp->precio);
        $this->assertEquals(170.0, (float) $pp->costo);
        $pedido->refresh();
        $this->assertEquals(22000.0, (float) $pedido->total_venta);
        $this->assertEquals(17000.0, (float) $pedido->total_costo);
        $this->assertTrue($pedido->actividades()->where('tipo', 'precio_autorizado')->exists());
    }

    public function test_comparativa_se_recalcula_en_vivo(): void
    {
        [$pedido, $c] = $this->pendiente();
        $this->actingAs($this->admin());

        $t = Livewire::test(Index::class)->set("precioFinal.{$c->id}", '300');
        $cmp = $t->viewData('comparativas')[$c->id];
        $this->assertEquals(8000.0, $cmp['antes']['total']);
        $this->assertEquals(10000.0, $cmp['despues']['total']);
        $this->assertEquals(8000.0, $cmp['pedidoAntes']);
        $this->assertEquals(10000.0, $cmp['pedidoDespues']);
    }

    public function test_rechazar_exige_nota_y_deja_el_precio_cotizado(): void
    {
        [$pedido, $c] = $this->pendiente();
        $this->actingAs($this->admin());

        Livewire::test(Index::class)
            ->call('rechazar', $c->id)->assertHasErrors(["nota.{$c->id}"]);
        $this->assertSame('pendiente', $c->fresh()->estatus);

        Livewire::test(Index::class)->set("nota.{$c->id}", 'Con ese precio no hay utilidad')->call('rechazar', $c->id)->assertHasNoErrors();
        $c->refresh();
        $this->assertSame('rechazado', $c->estatus);
        $this->assertSame('Con ese precio no hay utilidad', $c->nota_resolucion);
        $this->assertEquals(280.0, (float) $pedido->partidas()->first()->precio);
        $this->assertTrue($pedido->actividades()->where('tipo', 'precio_rechazado')->exists());
    }

    public function test_solo_el_admin_entra_a_la_pantalla_de_autorizacion(): void
    {
        [$pedido, $c] = $this->pendiente();

        $this->actingAs($this->vendedor())->get('/cambios-precio')->assertForbidden();
        $this->actingAs($this->vendedor())->get("/cambios-precio/{$c->id}")->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => 'campo']))->get('/cambios-precio')->assertForbidden();
        $this->actingAs($this->admin())->get('/cambios-precio')->assertOk()->assertSee('Pedido P-'.$pedido->folio);
        $this->actingAs($this->admin())->get("/cambios-precio/{$c->id}")->assertOk()->assertSee(self::MOTIVO);
    }

    public function test_el_formulario_editar_de_la_tarjeta_ya_no_acepta_precio(): void
    {
        $this->actingAs($this->vendedor());
        $pedido = Pedido::factory()->create();
        $pp = PedidoPartida::factory()->create(['pedido_id' => $pedido->id, 'descripcion' => 'LLAVE', 'cantidad' => 5, 'costo' => 10, 'precio' => 20]);

        $t = Livewire::test(Ver::class, ['pedido' => $pedido])->call('editarPartida', $pp->id)
            ->assertDontSee('Precio unitario (sin IVA)');
        $this->assertArrayNotHasKey('e_precio', $t->snapshot['data'] ?? []);
        $t->set('e_cantidad', '6')->call('guardarPartida')->assertHasNoErrors();
        $this->assertEquals(20.0, (float) $pp->fresh()->precio);
        $this->assertEquals(6.0, (float) $pp->fresh()->cantidad);
    }

    public function test_la_tarjeta_muestra_el_precio_por_autorizar_y_campo_no_ve_nada(): void
    {
        [$pedido, $c] = $this->pendiente();

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->assertSee('Precio por autorizar: $210.00 (cotizado $280.00)')->assertSee('Avisar a Daniel por WhatsApp')
            ->assertDontSee('/cambios-precio/');
        $this->actingAs($this->admin());
        Livewire::test(Ver::class, ['pedido' => $pedido])->assertSee('Precio por autorizar: $210.00')->assertSee('/cambios-precio/'.$c->id)->assertDontSee('Avisar a Daniel');
        $this->actingAs(User::factory()->create(['rol' => 'campo']));
        Livewire::test(Ver::class, ['pedido' => $pedido])->assertDontSee('Precio por autorizar')->assertDontSee('$210.00');

        $this->actingAs($this->admin());
        Livewire::test(Index::class)->set("precioFinal.{$c->id}", '210')->call('autorizar', $c->id);
        Livewire::test(Ver::class, ['pedido' => $pedido])->assertSee('Precio ajustado (antes $280.00)');
    }

    public function test_el_globito_del_menu_cuenta_pendientes_solo_para_el_admin(): void
    {
        [$pedido, $c] = $this->pendiente();

        $this->actingAs($this->admin())->get('/pedidos')->assertSee('Precios por autorizar')->assertSeeInOrder(['Precios por autorizar', '>1<']);
        $this->actingAs($this->vendedor())->get('/pedidos')->assertDontSee('Precios por autorizar');
    }

    public function test_con_la_autorizacion_apagada_el_vendedor_cambia_directo_y_avisa(): void
    {
        Ajuste::guardar(CambiosPrecio::REQUIERE_AUTORIZACION, '0');
        $p = $this->partida();
        $this->actingAs($v = $this->vendedor());
        $this->nuevo($p)->set("precios.{$p->id}", '210')->set("motivosPrecio.{$p->id}", self::MOTIVO)->call('crear')->assertHasNoErrors();

        $pedido = Pedido::latest('id')->first();
        $c = CambioPrecio::sole();
        $this->assertSame('directo', $c->estatus);
        $this->assertSame('210.00', number_format((float) $pedido->partidas()->first()->precio, 2));
        $this->assertSame(0, CambiosPrecio::pendientes());

        Livewire::actingAs($v)->test(Ver::class, ['pedido' => $pedido])
            ->assertSee('Avísale a Daniel por WhatsApp')->call('marcarAvisoPrecio')
            ->assertDontSee('Avísale a Daniel por WhatsApp');
        $this->assertNotNull($c->fresh()->avisado_at);

        // El admin puede volver a encender la autorización.
        Livewire::actingAs($this->admin())->test(Index::class)
            ->assertSee('Autorización apagada')->call('cambiarRequisito')->assertSee('Autorización encendida');
        $this->assertTrue(CambiosPrecio::requiereAutorizacion());
    }

    public function test_cambio_menor_a_diez_pesos_se_aplica_sin_motivo_ni_autorizacion_ni_aviso(): void
    {
        // Aun con la autorización encendida, un cambio de centavos (o de menos de $10 por pieza) pasa directo.
        $tornillo = $this->partida(['precio' => 0.28, 'costo' => 0.20]);
        $this->actingAs($v = $this->vendedor());
        $this->nuevo($tornillo)->set("precios.{$tornillo->id}", '0.27')
            ->assertViewHas('preciosUsados', fn ($x) => $x[$tornillo->id]['menor'] === true)
            ->assertSee('Ajuste menor')->assertDontSee('Motivo del cambio de precio')
            ->call('crear')->assertHasNoErrors()->assertRedirect();

        $pedido = Pedido::latest('id')->first();
        $c = CambioPrecio::sole();
        $this->assertSame('directo', $c->estatus);
        $this->assertSame(CambiosPrecio::MOTIVO_MENOR, $c->motivo);
        $this->assertNotNull($c->avisado_at);
        $this->assertSame(0, CambiosPrecio::pendientes());
        $this->assertEquals(0.27, (float) $pedido->partidas()->first()->precio);
        $this->assertTrue($pedido->actividades()->where('descripcion', 'like', '%ajustó el precio de "PIJA 8X3/4" de $0.28 a $0.27 (cambio menor a $10 por pieza; no requiere aviso)%')->exists());
        Livewire::actingAs($v)->test(Ver::class, ['pedido' => $pedido])
            ->assertDontSee('Avísale a Daniel por WhatsApp')->assertDontSee('Avisar a Daniel por WhatsApp');

        // $9.99 menos por pieza también es menor; $10 exactos ya pide motivo.
        $this->assertTrue(CambiosPrecio::esMenor(280, 270.01));
        $this->assertTrue(CambiosPrecio::esMenor(280, 289.99));
        $this->assertFalse(CambiosPrecio::esMenor(280, 270));
        $this->assertFalse(CambiosPrecio::esMenor(280, 290));
        $p = $this->partida();
        $this->nuevo($p)->set("precios.{$p->id}", '270')->call('crear')->assertHasErrors(['motivoGeneral'])->assertNoRedirect();
    }

    public function test_un_solo_motivo_general_sirve_para_todas_las_partidas(): void
    {
        Ajuste::guardar(CambiosPrecio::REQUIERE_AUTORIZACION, '0');
        $a = $this->partida();
        $b = CotizacionPartida::factory()->create(['cotizacion_id' => $a->cotizacion_id, 'orden' => 2, 'descripcion' => 'TALADRO', 'modelo' => null,
            'cantidad' => 2, 'unidad' => 'PZA', 'costo' => 1000, 'precio' => 1500]);
        $c = CotizacionPartida::factory()->create(['cotizacion_id' => $a->cotizacion_id, 'orden' => 3, 'descripcion' => 'BROCA', 'modelo' => null,
            'cantidad' => 5, 'unidad' => 'PZA', 'costo' => 50, 'precio' => 80]);
        $this->actingAs($v = $this->vendedor());

        $t = $this->nuevo($a)->set('seleccion', [(string) $a->id, (string) $b->id, (string) $c->id])
            ->set("precios.{$a->id}", '250')->set("precios.{$b->id}", '1400')->set("precios.{$c->id}", '79.50');
        // Sin motivo general no deja pasar; corto en una partida tampoco.
        $t->call('irAPaso', 3)->assertHasErrors(['motivoGeneral'])->assertSet('paso', 2)
            ->assertSee('Motivo del cambio de precio · 2 partidas con cambio de $10 o más');
        $t->set('motivoGeneral', 'El cliente negoció toda la OC')->set("motivosPrecio.{$b->id}", 'corto')
            ->call('crear')->assertHasErrors(["motivosPrecio.{$b->id}"])->assertNoRedirect();
        $t->set("motivosPrecio.{$b->id}", '')->call('crear')->assertHasNoErrors()->assertRedirect();

        $cambios = CambioPrecio::orderBy('id')->get();
        $this->assertCount(3, $cambios);
        $this->assertSame(['El cliente negoció toda la OC', 'El cliente negoció toda la OC', 'El cliente negoció toda la OC'], $cambios->pluck('motivo')->all());
        $this->assertNull($cambios[0]->avisado_at);
        $this->assertNotNull($cambios[2]->avisado_at);   // la broca bajó 50 centavos: no hace falta avisar

        // El WhatsApp a Daniel lleva solo los cambios grandes y el motivo una sola vez.
        $pedido = Pedido::latest('id')->first();
        $wa = urldecode((string) Livewire::actingAs($v)->test(Ver::class, ['pedido' => $pedido])->viewData('avisoPrecioWa'));
        $this->assertStringContainsString('PIJA 8X3/4: de $280.00 a $250.00', $wa);
        $this->assertStringContainsString('TALADRO: de $1,500.00 a $1,400.00', $wa);
        $this->assertStringNotContainsString('BROCA', $wa);
        $this->assertSame(1, substr_count($wa, 'El cliente negoció toda la OC'));
    }
}

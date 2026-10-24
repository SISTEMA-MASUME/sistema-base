<?php

namespace Tests\Feature;

use App\Livewire\Pedidos\Nuevo;
use App\Livewire\Pedidos\Ver;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Pedido;
use App\Models\PedidoConciliacion;
use App\Models\PedidoPartida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PedidosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['iniciales' => 'ALBY']);
        $this->actingAs($this->user);
    }

    private function cotizacionConPartidas(int $n = 2, ?Cliente $cliente = null): Cotizacion
    {
        $cot = Cotizacion::factory()->create([
            'vendedor_id' => $this->user->id,
            'cliente_id' => ($cliente ?? Cliente::factory()->create())->id,
            'estado' => 'enviada',
        ]);
        for ($i = 1; $i <= $n; $i++) {
            CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => $i, 'cantidad' => 10, 'costo' => 111.1111, 'precio' => 200]);
        }

        return $cot->fresh();
    }

    public function test_sin_oc_con_justificacion_corta_dice_por_que_no_avanza(): void
    {
        $cot = $this->cotizacionConPartidas(1);
        Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)
            ->set('seleccion', [(string) $cot->partidas[0]->id])->call('irAPaso', 3)
            ->set('sinOc', true)->set('justificacion', 'corto')
            ->assertSee('Faltan')
            ->assertSee('No puedes avanzar todavía: Escribe por qué no hay OC del cliente (mínimo 10 letras).')
            ->call('irAPaso', 4)->assertSet('paso', 3)->assertHasErrors('paso')
            ->set('justificacion', 'Se surte con nota de venta')->call('irAPaso', 4)->assertSet('paso', 4);
    }

    public function test_pedido_a_medio_capturar_se_retoma_en_el_mismo_paso(): void
    {
        $cot = $this->cotizacionConPartidas(2);
        $p = $cot->partidas;
        Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)
            ->set('seleccion', [(string) $p[0]->id])->set('cantidades.'.$p[0]->id, 4)->call('irAPaso', 3)
            ->set('sinOc', true)->set('justificacion', 'Se surte con nota');
        // Se cerró la ventana: al volver se ofrece retomarlo (no se carga solo).
        $this->assertDatabaseHas('pedido_borradores', ['user_id' => $this->user->id, 'cliente_id' => $cot->cliente_id, 'paso' => 3, 'partidas' => 1]);

        Livewire::withQueryParams([])->test(Nuevo::class)
            ->assertSet('ofrecerBorrador', true)->assertSee('Tienes un pedido sin terminar')->assertSee('te quedaste en el paso 3')
            ->assertSet('paso', 1)->assertSet('seleccion', [])
            ->call('retomarBorrador')
            ->assertSet('paso', 3)->assertSet('clienteId', $cot->cliente_id)->assertSet('seleccion', [$p[0]->id])
            ->assertSet('cantidades.'.$p[0]->id, 4)->assertSet('sinOc', true)->assertSet('justificacion', 'Se surte con nota')
            ->assertDontSee('Tienes un pedido sin terminar')
            ->call('irAPaso', 4)->set('numeroOc', '')->call('crear')->assertHasNoErrors();

        // Ya creado, no queda nada por retomar.
        $this->assertDatabaseCount('pedidos', 1);
        $this->assertDatabaseMissing('pedido_borradores', ['user_id' => $this->user->id]);
        Livewire::withQueryParams([])->test(Nuevo::class)->assertSet('ofrecerBorrador', false)->assertDontSee('Tienes un pedido sin terminar');
    }

    public function test_empezar_de_nuevo_tira_el_pedido_sin_terminar_y_cada_quien_ve_el_suyo(): void
    {
        $cot = $this->cotizacionConPartidas(1);
        Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)->set('seleccion', [(string) $cot->partidas[0]->id]);

        // Otro vendedor no ve el pedido sin terminar de Alberto.
        $this->actingAs(User::factory()->create());
        Livewire::withQueryParams([])->test(Nuevo::class)->assertSet('ofrecerBorrador', false);

        $this->actingAs($this->user);
        Livewire::withQueryParams([])->test(Nuevo::class)->assertSet('ofrecerBorrador', true)
            ->call('descartarBorrador')->assertSet('ofrecerBorrador', false)->assertSet('seleccion', []);
        $this->assertDatabaseMissing('pedido_borradores', ['user_id' => $this->user->id]);
    }

    public function test_crear_pedido_desde_cotizacion_con_cantidad_parcial_y_trazabilidad(): void
    {
        $cot = $this->cotizacionConPartidas(2);
        [$a, $b] = $cot->partidas;

        Livewire::withQueryParams(['cotizacion' => $cot->id])
            ->test(Nuevo::class)
            ->assertSet('clienteId', $cot->cliente_id)
            ->set('seleccion', [(string) $a->id, (string) $b->id])
            ->set("cantidades.{$a->id}", 4)
            ->set('numeroOc', '617489')
            ->set('justificacion', 'La OC llega por correo después')
            ->call('crear')
            ->assertHasNoErrors()
            ->assertRedirect();

        $pedido = Pedido::firstOrFail();
        $this->assertSame('oc_recibida', $pedido->columna->value);
        $this->assertSame(0, $pedido->posicion);
        $this->assertCount(2, $pedido->partidas);
        $pa = $pedido->partidas->firstWhere('cotizacion_partida_id', $a->id);
        $this->assertEquals(4, (float) $pa->cantidad);
        $this->assertEquals(10, (float) $pedido->partidas->firstWhere('cotizacion_partida_id', $b->id)->cantidad);
        $this->assertEquals(200 * 4 + 200 * 10, (float) $pedido->total_venta);
        $this->assertSame('aceptada', $cot->fresh()->estado->value);
    }

    public function test_suma_de_lo_marcado_con_las_cantidades_capturadas(): void
    {
        $cot = $this->cotizacionConPartidas(3);
        [$a, $b] = $cot->partidas;

        Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)
            ->set('seleccion', [(string) $a->id, (string) $b->id])
            ->set("cantidades.{$a->id}", 4)
            ->assertSeeHtml('>2</strong> partidas marcadas')
            ->assertSee('$2,800.00')        // (4 + 10) × 200
            ->assertSee('$3,248.00');       // con IVA
    }

    public function test_cotizacion_con_partidas_sin_colocar_no_pasa_a_aceptada(): void
    {
        $cot = $this->cotizacionConPartidas(2);

        Livewire::test(Nuevo::class)
            ->call('seleccionarCliente', $cot->cliente_id)
            ->set('seleccion', [(string) $cot->partidas[0]->id])
            ->set('numeroOc', 'A1')->set('justificacion', 'La OC llega por correo después')
            ->call('crear')->assertRedirect();

        $this->assertSame('enviada', $cot->fresh()->estado->value);
    }

    public function test_oc_duplicada_del_mismo_cliente_se_rechaza_pero_45_y_4567_son_distintas(): void
    {
        $cot = $this->cotizacionConPartidas(3);
        $ids = $cot->partidas->pluck('id')->map(fn ($i) => (string) $i)->all();
        Pedido::factory()->create(['cliente_id' => $cot->cliente_id, 'numero_oc_cliente' => '4567']);

        $c = Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)
            ->set('seleccion', [$ids[0]])->set('numeroOc', '45')->set('justificacion', 'La OC llega por correo después')->call('crear')->assertRedirect();

        Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)
            ->set('seleccion', [$ids[1]])->set('numeroOc', 'OC 4567')->set('justificacion', 'La OC llega por correo después')
            ->call('irAPaso', 4)->assertSee('Ya existe el pedido de la OC 4567 de este cliente')
            ->call('crear')
            ->assertHasErrors('numeroOc')
            ->assertNoRedirect();

        $this->assertSame(2, Pedido::count());
    }

    public function test_misma_oc_de_otro_cliente_si_se_permite(): void
    {
        Pedido::factory()->create(['numero_oc_cliente' => '900']);
        $cot = $this->cotizacionConPartidas(1);

        Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)
            ->set('seleccion', [(string) $cot->partidas[0]->id])->set('numeroOc', '900')->set('justificacion', 'La OC llega por correo después')
            ->call('crear')->assertHasNoErrors()->assertRedirect();
    }

    public function test_sin_oc_solo_se_sigue_con_justificacion_y_queda_como_nota_de_venta(): void
    {
        $cot = $this->cotizacionConPartidas(1);
        $id = (string) $cot->partidas[0]->id;

        // Sin OC ni justificación: no deja pasar a los datos ni crear.
        $t = Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)
            ->set('seleccion', [$id])->call('irAPaso', 3)->assertSet('paso', 3)
            ->call('irAPaso', 4)->assertHasErrors('paso')->assertSet('paso', 3)
            ->call('crear')->assertHasErrors('paso')->assertNoRedirect();

        $t->set('sinOc', true)->set('justificacion', 'corta')->call('irAPaso', 4)->assertHasErrors('paso')
            ->set('justificacion', 'Se surte con nota de venta, la OC llega la próxima semana')
            ->call('irAPaso', 4)->assertSet('paso', 4)
            ->call('crear')->assertHasNoErrors()->assertRedirect();

        $p = Pedido::firstOrFail();
        $this->assertSame('nota_venta', $p->tipo->value);
        $this->assertStringStartsWith('Sin OC del cliente: Se surte con nota de venta', $p->notas);
        $this->assertTrue($p->actividades()->where('tipo', 'sin_oc')->exists());
    }

    /** Pasos 1→4: se sube la OC, se revisa contra lo marcado y explica las diferencias en palabras sencillas. */
    public function test_subir_la_oc_la_revisa_contra_lo_marcado_y_explica_las_diferencias(): void
    {
        Storage::fake('local');
        config(['services.openai.key' => 'sk-test', 'services.openai.modelo' => 'gpt-6-luna', 'services.openai.tope_mensual_usd' => 10.0]);
        $cot = $this->cotizacionConPartidas(0);
        $a = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'GUANTES DE CARNAZA', 'modelo' => null, 'cantidad' => 10, 'unidad' => 'PAR', 'precio' => 50]);
        $b = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 2, 'descripcion' => 'CINTA DE AISLAR NEGRA', 'modelo' => null, 'cantidad' => 5, 'unidad' => 'PZA', 'precio' => 20]);
        $renglon = fn ($d, $c, $u, $pr) => ['num_renglon' => 1, 'codigo_cliente' => null, 'descripcion' => $d, 'marca' => null, 'modelo' => null, 'cantidad' => $c,
            'unidad' => $u, 'precio_unitario' => $pr, 'importe' => null, 'fecha_entrega' => null, 'origen' => 'tabla', 'confianza' => 'alta', 'texto_original' => $d];
        $extraccion = ['numero_oc' => 'OC-7788', 'fecha_oc' => '2026-09-28', 'cliente_detectado' => null, 'moneda' => 'MXN', 'precios_incluyen_iva' => false,
            'renglones' => [$renglon('GUANTES DE CARNAZA', 12, 'PAR', 50), $renglon('LENTES DE SEGURIDAD', 3, 'PZA', 80)],
            'subtotal' => null, 'iva' => null, 'total' => null, 'observaciones_generales' => null, 'advertencias' => []];
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['id' => 'resp_1', 'status' => 'queued'], 200),
            'api.openai.com/v1/responses/*' => Http::response(['id' => 'resp_1', 'status' => 'completed', 'output' => [
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($extraccion)]]]],
                'usage' => ['input_tokens' => 6000, 'output_tokens' => 1000]], 200),
        ]);

        $t = Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)
            ->set('seleccion', [(string) $a->id, (string) $b->id])->call('irAPaso', 3)
            ->set('archivo', UploadedFile::fake()->create('oc.pdf', 200, 'application/pdf'))
            ->assertSee('Revisando la OC')
            ->call('consultarOc')
            ->assertSet('numeroOc', 'OC-7788')->assertSet('fechaOc', '2026-09-28')
            ->assertSee('la OC pide 12 y tú pusiste 10')
            ->assertSee('Marcaste «CINTA DE AISLAR NEGRA» pero no viene en la OC')
            ->assertSee('La OC pide «LENTES DE SEGURIDAD» (3 PZA) y no lo marcaste');

        // Corrige con los botones: usa la cantidad de la OC y quita la que no viene.
        $t->call('usarCantidadOc', $a->id, 12)->call('desmarcar', $b->id)
            ->assertDontSee('tú pusiste 10')->assertDontSee('Marcaste «CINTA');

        // Aún falta un renglón de la OC: para crear hay que palomear que se revisaron las diferencias.
        $t->call('irAPaso', 4)->assertSet('paso', 4)
            ->call('crear')->assertHasErrors('aceptoDiferencias')->assertNoRedirect();

        // Si regresa al material, las diferencias van con él como guía.
        $t->call('irAPaso', 2)->assertSee('Diferencias con la OC del cliente (1)')->assertSee('LENTES DE SEGURIDAD')
            ->call('irAPaso', 3);

        // Se arregla ahí mismo: se agrega tal como viene en la OC y ya no hay diferencias.
        $clave = collect($t->viewData('diferencias'))->firstWhere('tipo', 'falta_en_pedido')['clave'];
        $t->call('agregarDeLaOc', $clave)->assertSee('De la OC, sin cotizar')->assertSee('Todo coincide')
            ->call('irAPaso', 4)->call('crear')->assertHasNoErrors()->assertRedirect();

        $p = Pedido::with('partidas')->firstOrFail();
        $this->assertSame('OC-7788', $p->numero_oc_cliente);
        $this->assertEquals(12, (float) $p->partidas->first()->cantidad);
        $this->assertCount(2, $p->partidas);
        $lentes = $p->partidas->firstWhere('descripcion', 'LENTES DE SEGURIDAD');
        $this->assertEquals(3, (float) $lentes->cantidad);
        $this->assertEquals(80, (float) $lentes->precio);
        $this->assertNull($lentes->costo);
        $this->assertTrue($p->actividades()->where('tipo', 'partidas_de_la_oc')->exists());
        Storage::disk('local')->assertExists($p->oc_cliente_path);
        // La revisión queda en la tarjeta (con las partidas del pedido).
        $c = PedidoConciliacion::where('pedido_id', $p->id)->firstOrFail();
        $this->assertFalse($c->hay_diferencias);
        $this->assertTrue($p->actividades()->where('tipo', 'conciliacion')->exists());
        Http::assertSentCount(2);
    }

    public function test_archivo_invalido_o_muy_grande_se_rechaza(): void
    {
        Storage::fake('local');
        $cot = $this->cotizacionConPartidas(1);

        Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)
            ->set('archivo', UploadedFile::fake()->create('oc.exe', 10))
            ->assertHasErrors('archivo');
        Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)
            ->set('archivo', UploadedFile::fake()->create('oc.pdf', 21000, 'application/pdf'))
            ->assertHasErrors('archivo');
    }

    public function test_buscador_filtra_por_palabras_folio_y_marca_partidas_ya_colocadas(): void
    {
        $cliente = Cliente::factory()->create();
        $c1 = $this->cotizacionConPartidas(0, $cliente);
        $c2 = $this->cotizacionConPartidas(0, $cliente);
        $p1 = CotizacionPartida::factory()->create(['cotizacion_id' => $c1->id, 'descripcion' => 'TORNILLO HEXAGONAL 1/2 ACERO']);
        $p2 = CotizacionPartida::factory()->create(['cotizacion_id' => $c1->id, 'descripcion' => 'BROCA COBALTO 3/8']);
        $p3 = CotizacionPartida::factory()->create(['cotizacion_id' => $c2->id, 'descripcion' => 'TORNILLO ALLEN NEGRO']);
        $otro = CotizacionPartida::factory()->create(['cotizacion_id' => $this->cotizacionConPartidas(0)->id, 'descripcion' => 'TORNILLO DE OTRO CLIENTE']);

        $ya = Pedido::factory()->create(['cliente_id' => $cliente->id]);
        PedidoPartida::desdeCotizacion($p1, $ya, 1);

        $t = Livewire::test(Nuevo::class)->call('seleccionarCliente', $cliente->id);
        $t->assertSee('BROCA COBALTO')->assertSee('TORNILLO ALLEN')->assertDontSee('OTRO CLIENTE')
            ->assertSee('ya en pedido P-'.$ya->folio);

        $t->set('fTexto', 'tornillo acero')->assertSee('HEXAGONAL')->assertDontSee('ALLEN')->assertDontSee('BROCA');
        $t->set('fTexto', '')->set('fFolios', (string) $c2->folio)->assertSee('ALLEN')->assertDontSee('BROCA');
        $t->set('fFolios', "{$c1->folio}, {$c2->folio}")->assertSee('BROCA')->assertSee('ALLEN');
    }

    public function test_campo_no_puede_crear_pedidos(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'campo']));
        $this->get('/pedidos/nuevo')->assertForbidden();
    }

    private function pedidoConPartidas(): Pedido
    {
        $pedido = Pedido::factory()->create(['responsable_id' => $this->user->id]);
        foreach ([1, 2] as $i) {
            PedidoPartida::create(['pedido_id' => $pedido->id, 'orden' => $i, 'descripcion' => "PARTIDA DE PRUEBA $i", 'unidad' => 'PZA', 'costo' => 123.4567, 'precio' => 987.65, 'cantidad' => 2]);
        }
        $pedido->recalcularTotales();

        return $pedido->fresh();
    }

    public function test_avanzar_estados_cambia_las_etiquetas(): void
    {
        $pedido = $this->pedidoConPartidas();
        [$a, $b] = $pedido->partidas;

        $t = Livewire::test(Ver::class, ['pedido' => $pedido]);
        $t->call('establecerEstado', $a->id, 'solicitada')->assertSee('Solicitado parcial');
        $t->call('establecerEstado', $b->id, 'solicitada')->assertSee('Material solicitado');
        $t->call('establecerEstado', $a->id, 'recibida')->call('establecerEstado', $b->id, 'recibida')->assertSee('Recibido');
        $t->call('alternarFacturada', $a->id)->assertSee('Factura parcial');
        $this->assertSame('recibida', $a->fresh()->estado->value);
    }

    public function test_accion_masiva_y_banderas_y_columna(): void
    {
        $pedido = $this->pedidoConPartidas();

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->set('seleccion', $pedido->partidas->pluck('id')->map(fn ($i) => (string) $i)->all())
            ->call('accionMasiva', 'entregada')
            ->assertSee('Material entregado')
            ->set('seleccion', $pedido->partidas->pluck('id')->map(fn ($i) => (string) $i)->all())
            ->call('accionMasiva', 'facturada')
            ->assertSee('Facturada completa')
            ->call('alternarBandera', 'revisar')
            ->call('alternarCancelado')
            ->call('mover', 'por_facturar');

        $p = $pedido->fresh();
        $this->assertTrue($p->revisar);
        $this->assertNotNull($p->cancelado_at);
        $this->assertSame('por_facturar', $p->columna->value);
        $this->assertSame(0, $p->partidas()->where('estado', '!=', 'entregada')->count());
    }

    public function test_agregar_partida_manual_recalcula_totales(): void
    {
        $pedido = $this->pedidoConPartidas();
        $antes = (float) $pedido->total_venta;

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->set('n_descripcion', 'flete especial')->set('n_cantidad', '2')->set('n_precio', '50')
            ->call('agregarPartida')->assertHasNoErrors();

        $this->assertEquals($antes + 100, (float) $pedido->fresh()->total_venta);
        $this->assertNull($pedido->partidas()->latest('id')->first()->cotizacion_partida_id);
    }

    public function test_campo_ve_costos_pero_no_precio_de_venta_y_solo_recibe_o_entrega(): void
    {
        $pedido = $this->pedidoConPartidas();
        $this->actingAs(User::factory()->create(['rol' => 'campo']));

        $t = Livewire::test(Ver::class, ['pedido' => $pedido])
            ->assertOk()
            ->assertSee('123.46')
            ->assertSee('246.91')
            ->assertSee('OC a proveedores')
            ->assertDontSee('987.65')
            ->assertDontSee('1,975.30')
            ->assertDontSee('Utilidad')
            ->assertDontSee('Generar OC a proveedores')
            ->assertDontSee('Total venta');

        $a = $pedido->partidas[0];
        $t->call('establecerEstado', $a->id, 'entregada');
        $this->assertSame('entregada', $a->fresh()->estado->value);
        $t->call('mover', 'envios_embarcados');
        Livewire::test(Ver::class, ['pedido' => $pedido])->call('establecerEstado', $a->id, 'solicitada')->assertForbidden();
        Livewire::test(Ver::class, ['pedido' => $pedido])->call('alternarFacturada', $a->id)->assertForbidden();
        $this->assertSame('envios_embarcados', $pedido->fresh()->columna->value);
    }

    public function test_vendedor_ve_costos_y_seccion_de_oc_a_proveedores(): void
    {
        $pedido = $this->pedidoConPartidas();

        $this->get(route('pedidos.ver', $pedido))->assertOk()
            ->assertSee('987.65')
            ->assertSee('OC a proveedores')
            ->assertSee('/pedidos/'.$pedido->id.'/ordenes-proveedor/nueva', false);
    }

    public function test_buscar_por_folio_encuentra_cotizaciones_viejas_aunque_haya_filtro_de_fechas(): void
    {
        $cot = $this->cotizacionConPartidas(4);
        $cot->update(['fecha' => now()->subDays(103)->toDateString()]);   // como la 9218 de junio

        Livewire::actingAs($this->user)->test(Nuevo::class)
            ->call('seleccionarCliente', $cot->cliente_id)
            ->assertSet('fDesde', now()->subDays(60)->toDateString())   // filtro por omisión
            ->set('fFolios', (string) $cot->folio)
            ->assertSet('fDesde', '')
            ->assertSee($cot->partidas()->first()->descripcion);
    }

    public function test_falta_en_la_oc_se_resuelve_con_una_partida_cotizada_parecida(): void
    {
        Storage::fake('local');
        $cot = $this->cotizacionConPartidas(0);
        $a = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'GUANTES DE CARNAZA', 'modelo' => null, 'cantidad' => 10, 'unidad' => 'PAR', 'precio' => 50]);
        $lentes = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 2, 'descripcion' => 'LENTES DE SEGURIDAD CLAROS 3M', 'modelo' => null, 'cantidad' => 1, 'unidad' => 'PZA', 'precio' => 80]);
        $renglon = fn ($d, $c, $pr) => ['num_renglon' => 1, 'codigo_cliente' => null, 'descripcion' => $d, 'marca' => null, 'modelo' => null, 'cantidad' => $c,
            'unidad' => 'PZA', 'precio_unitario' => $pr, 'importe' => null, 'fecha_entrega' => null, 'origen' => 'tabla', 'confianza' => 'alta', 'texto_original' => $d];
        // La misma OC ya se leyó antes: se reutiliza la lectura (sin llamar a la IA).
        $contenido = '%PDF-1.4 oc del cliente';
        PedidoConciliacion::create(['pedido_id' => null, 'user_id' => $this->user->id, 'archivo_hash' => hash('sha256', $contenido), 'modelo' => 'gpt-6-luna',
            'estado' => 'completada', 'extraccion' => ['numero_oc' => '55', 'fecha_oc' => null, 'precios_incluyen_iva' => false,
                'renglones' => [$renglon('GUANTES DE CARNAZA', 10, 50), $renglon('LENTES DE SEGURIDAD', 4, 80)], 'subtotal' => null, 'iva' => null, 'total' => null, 'advertencias' => []]]);
        Http::fake();

        $t = Livewire::withQueryParams(['cotizacion' => $cot->id])->test(Nuevo::class)
            ->set('seleccion', [(string) $a->id])->call('irAPaso', 3)
            ->set('archivo', UploadedFile::fake()->createWithContent('oc.pdf', $contenido))
            ->assertSee('La OC pide «LENTES DE SEGURIDAD» (4 PZA) y no lo marcaste')
            ->assertSee('LENTES DE SEGURIDAD CLAROS 3M')->assertSee('Usar esta');

        $t->call('usarCotizada', $lentes->id, 4)->assertSee('Todo coincide')
            ->call('irAPaso', 4)->call('crear')->assertHasNoErrors()->assertRedirect();

        $p = Pedido::with('partidas')->firstOrFail();
        $this->assertEquals(4, (float) $p->partidas->firstWhere('cotizacion_partida_id', $lentes->id)->cantidad);
        Http::assertNothingSent();
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\OrdenesProveedor\Generar;
use App\Livewire\Pedidos\Conciliacion;
use App\Livewire\Tablero\Index;
use App\Models\ErrorSistema;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\PedidoConciliacion;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Ia\OpenAiExtractorOc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Nunca llama a la API real: todo con Http::fake(). */
class ConciliacionOcTest extends TestCase
{
    use RefreshDatabase;

    private const POST = 'api.openai.com/v1/responses';

    private const GET = 'api.openai.com/v1/responses/*';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        config(['services.openai.key' => 'sk-test', 'services.openai.modelo' => 'gpt-6-luna', 'services.openai.tope_mensual_usd' => 10.0]);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    // ---------------------------------------------------------------- ayudas

    private function pedidoConOc(array $extra = []): Pedido
    {
        Storage::disk('local')->put('pedidos/oc-cliente/oc.pdf', '%PDF-1.4 falso '.uniqid());

        return Pedido::factory()->create(array_merge(['oc_cliente_path' => 'pedidos/oc-cliente/oc.pdf', 'numero_oc_cliente' => '12345'], $extra));
    }

    private function partida(Pedido $pedido, array $o = []): PedidoPartida
    {
        return PedidoPartida::create(array_merge([
            'pedido_id' => $pedido->id,
            'orden' => PedidoPartida::where('pedido_id', $pedido->id)->count() + 1,
            'descripcion' => 'LAMPARA XXXX',
            'modelo' => 'XXXX',
            'unidad' => 'PZA',
            'cantidad' => 1,
            'costo' => 50,
            'precio' => 100,
        ], $o));
    }

    private function renglon(array $o = []): array
    {
        return array_merge([
            'num_renglon' => 1, 'codigo_cliente' => null, 'descripcion' => 'LAMPARA XXXX', 'marca' => null, 'modelo' => 'XXXX',
            'cantidad' => 10, 'unidad' => 'PZA', 'precio_unitario' => 100, 'importe' => 1000, 'fecha_entrega' => null,
            'origen' => 'tabla', 'confianza' => 'alta', 'texto_original' => '10 LAMPARA XXXX',
        ], $o);
    }

    private function extraccion(?array $renglones = null, array $o = []): array
    {
        return array_merge([
            'numero_oc' => '12345', 'fecha_oc' => null, 'cliente_detectado' => 'DELTA', 'moneda' => 'MXN',
            'precios_incluyen_iva' => false, 'renglones' => $renglones ?? [$this->renglon()],
            'subtotal' => null, 'iva' => null, 'total' => null, 'observaciones_generales' => null, 'advertencias' => [],
        ], $o);
    }

    private function completada(array $extraccion): array
    {
        return [
            'id' => 'resp_1',
            'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($extraccion)]]],
            ],
            'usage' => [
                'input_tokens' => 6000, 'output_tokens' => 2000,
                'input_tokens_details' => ['cached_tokens' => 1024],
                'output_tokens_details' => ['reasoning_tokens' => 900],
            ],
        ];
    }

    /** POST → queued; GET → in_progress y luego la respuesta indicada. */
    private function fingir(array $final): void
    {
        Http::fake([
            self::POST => Http::response(['id' => 'resp_1', 'status' => 'queued'], 200),
            self::GET => Http::sequence()
                ->push(['id' => 'resp_1', 'status' => 'in_progress'], 200)
                ->push($final, 200),
        ]);
    }

    private function revisarYTerminar(Pedido $pedido, array $final)
    {
        $this->fingir($final);

        return Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])
            ->call('revisar')
            ->call('consultar')   // in_progress
            ->call('consultar');  // final
    }

    // ---------------------------------------------------------------- flujo

    public function test_codigo_distinto_del_cliente_con_misma_cantidad_y_precio_sale_dudoso_y_se_empareja_a_mano(): void
    {
        $pedido = $this->pedidoConOc();
        $contacto = $this->partida($pedido, ['descripcion' => 'CONTACTO DUPLEX DE 20 AMPERES BLANCO CR20 LEVITON', 'modelo' => 'Cr20 SKU 238328', 'cantidad' => 5, 'precio' => 73.28]);
        $tapa = $this->partida($pedido, ['descripcion' => 'TAPA CIEGA ACERO', 'modelo' => 'TC-1', 'cantidad' => 2, 'precio' => 10]);
        $final = $this->completada($this->extraccion([
            $this->renglon(['descripcion' => 'CONTACTO DUPLEX BCO', 'modelo' => 'OCR20-00W', 'cantidad' => 5, 'precio_unitario' => 73.28]),
            $this->renglon(['num_renglon' => 2, 'descripcion' => 'GUANTE NITRILO', 'modelo' => 'GN-9', 'cantidad' => 3, 'precio_unitario' => 44]),
        ]));

        $c = $this->revisarYTerminar($pedido, $final);
        $filas = collect($c->viewData('resultado')['filas']);
        // Antes: "falta" + "sobra". Ahora, con misma cantidad y precio, se propone como duda.
        $this->assertContains('dudoso', $filas->firstWhere('clave', 'r0')['diferencias']);
        $this->assertSame($contacto->id, $filas->firstWhere('clave', 'r0')['partida']['id']);

        // El guante no tiene pareja automática: la persona decide que es la tapa (ejemplo) y luego lo deshace.
        $this->assertSame('falta_en_pedido', $filas->firstWhere('clave', 'r1')['estado']);
        $c->assertSee('Es la misma que…')->call('emparejarAMano', 'r1', $tapa->id)->assertSee('Emparejada a mano por');
        $fila = collect($c->viewData('resultado')['filas'])->firstWhere('clave', 'r1');
        $this->assertSame($tapa->id, $fila['partida']['id']);
        $this->assertSame('manual', $fila['metodo']);
        $this->assertNotContains('modelo_distinto', $fila['diferencias']);
        $this->assertContains('cantidad_distinta', $fila['diferencias']);   // lo que no cuadra se sigue marcando
        $this->assertFalse(collect($c->viewData('resultado')['filas'])->contains(fn ($f) => ! $f['renglon'] && ($f['partida']['id'] ?? 0) === $tapa->id));

        $c->call('deshacerPareja', 'r1');
        $this->assertSame('falta_en_pedido', collect($c->viewData('resultado')['filas'])->firstWhere('clave', 'r1')['estado']);
    }

    public function test_flujo_completo_post_get_queued_get_completed(): void
    {
        $pedido = $this->pedidoConOc();
        $this->partida($pedido);
        $this->fingir($this->completada($this->extraccion()));

        $c = Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])
            ->call('revisar');

        $this->assertSame('procesando', PedidoConciliacion::first()->estado);
        $c->assertSee('Leyendo la OC…');

        $c->call('consultar');
        $this->assertSame('procesando', PedidoConciliacion::first()->estado);

        $c->call('consultar')->assertSee('Cantidad distinta')->assertSee('Hay diferencias con la OC del cliente');

        $con = PedidoConciliacion::first();
        $this->assertSame('completada', $con->estado);
        $this->assertSame('resp_1', $con->response_id);
        $this->assertSame('gpt-6-luna', $con->modelo);
        $this->assertSame(64, strlen($con->archivo_hash));
        $this->assertSame(6000, $con->tokens_entrada);
        $this->assertSame(2000, $con->tokens_salida);
        $this->assertSame(1024, $con->tokens_cache);
        $this->assertEqualsWithDelta(0.0016, (float) $con->costo_usd, 0.000001);
        $this->assertTrue($con->hay_diferencias);
        $this->assertSame('1 cantidad distinta', $con->resumen);
        $this->assertSame($this->user->id, $con->user_id);
        $this->assertSame('LAMPARA XXXX', $con->extraccion['renglones'][0]['descripcion']);
        $this->assertSame('cantidad_distinta', $con->resultado['filas'][0]['estado']);

        Http::assertSentCount(3);
        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'POST') {
                return false;
            }
            $b = $r->data();

            return $r->hasHeader('Authorization', 'Bearer sk-test')
                && $b['model'] === 'gpt-6-luna'
                && $b['background'] === true
                && $b['store'] === false
                && $b['reasoning'] === ['effort' => 'medium']
                && $b['max_output_tokens'] === 8000
                && $b['input'][0]['role'] === 'developer'
                && $b['input'][1]['content'][1]['type'] === 'input_file'
                && $b['input'][1]['content'][1]['detail'] === 'high'
                && str_starts_with($b['input'][1]['content'][1]['file_data'], 'data:application/pdf;base64,')
                && $b['text']['format']['type'] === 'json_schema'
                && $b['text']['format']['name'] === 'oc_cliente_v1'
                && $b['text']['format']['strict'] === true
                && $b['text']['format']['schema']['additionalProperties'] === false;
        });
    }

    public function test_imagen_se_manda_como_input_image(): void
    {
        Storage::disk('local')->put('pedidos/oc-cliente/foto.png', 'png-falso');
        $pedido = Pedido::factory()->create(['oc_cliente_path' => 'pedidos/oc-cliente/foto.png']);
        $this->fingir($this->completada($this->extraccion()));

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('revisar');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->data()['input'][1]['content'][1]['type'] === 'input_image'
            && str_starts_with($r->data()['input'][1]['content'][1]['image_url'], 'data:image/png;base64,'));
    }

    public function test_fotos_usan_el_modelo_de_imagenes_y_pdf_el_economico(): void
    {
        config(['services.openai.modelo' => 'gpt-6-luna', 'services.openai.modelo_imagenes' => 'gpt-6-sol']);

        Storage::disk('local')->put('pedidos/oc-cliente/foto.jpg', 'jpg-falso');
        $foto = Pedido::factory()->create(['oc_cliente_path' => 'pedidos/oc-cliente/foto.jpg']);
        $this->fingir($this->completada($this->extraccion()));
        Livewire::test(Conciliacion::class, ['pedidoId' => $foto->id])->call('revisar');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data()['model'] === 'gpt-6-sol');

        $pdf = $this->pedidoConOc();
        $this->fingir($this->completada($this->extraccion()));
        Livewire::test(Conciliacion::class, ['pedidoId' => $pdf->id])->call('revisar');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data()['model'] === 'gpt-6-luna');
    }

    public function test_esquema_es_estricto(): void
    {
        $esquema = OpenAiExtractorOc::esquema();
        $revisar = function (array $obj) use (&$revisar) {
            $this->assertFalse($obj['additionalProperties']);
            $this->assertEqualsCanonicalizing(array_keys($obj['properties']), $obj['required']);
            foreach ($obj['properties'] as $p) {
                if (($p['type'] ?? null) === 'object') {
                    $revisar($p);
                }
                if (($p['type'] ?? null) === 'array' && ($p['items']['type'] ?? null) === 'object') {
                    $revisar($p['items']);
                }
            }
        };
        $revisar($esquema);
        $this->assertSame(['alta', 'media', 'baja'], $esquema['properties']['renglones']['items']['properties']['confianza']['enum']);
        $this->assertSame(['tabla', 'observaciones'], $esquema['properties']['renglones']['items']['properties']['origen']['enum']);
        $this->assertArrayHasKey('observaciones_generales', $esquema['properties']);
    }

    public function test_sondeo_usa_wire_poll_solo_mientras_procesa(): void
    {
        $pedido = $this->pedidoConOc();
        $this->fingir($this->completada($this->extraccion()));

        $c = Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('revisar');
        $c->assertSeeHtml('wire:poll.3s="consultar"');
        $c->call('consultar')->call('consultar')->assertDontSeeHtml('wire:poll.3s');
    }

    // ---------------------------------------------------------------- resultados

    public function test_caso_daido_10_contra_1(): void
    {
        $pedido = $this->pedidoConOc();
        $this->partida($pedido, ['cantidad' => 1]);

        $this->revisarYTerminar($pedido, $this->completada($this->extraccion()));

        $fila = PedidoConciliacion::first()->resultado['filas'][0];
        $this->assertSame('cantidad_distinta', $fila['estado']);
        $this->assertSame(10.0, (float) $fila['renglon']['cantidad']);
        $this->assertSame(1.0, (float) $fila['partida']['cantidad']);
    }

    public function test_falta_y_sobra_en_pantalla(): void
    {
        $pedido = $this->pedidoConOc();
        $this->partida($pedido, ['descripcion' => 'MARTILLO', 'modelo' => 'MZ-1', 'cantidad' => 10, 'precio' => 55]);

        $this->revisarYTerminar($pedido, $this->completada($this->extraccion()))
            ->assertSee('Falta en el pedido')->assertSee('Sobra en el pedido');

        $this->assertSame('1 falta en el pedido, 1 sobra en el pedido', PedidoConciliacion::first()->resumen);
    }

    public function test_pedido_que_coincide_no_tiene_diferencias(): void
    {
        $pedido = $this->pedidoConOc();
        $this->partida($pedido, ['cantidad' => 10, 'modelo' => 'xxxx']);

        $this->revisarYTerminar($pedido, $this->completada($this->extraccion()))->assertSee('El pedido coincide con la OC del cliente');

        $this->assertFalse(PedidoConciliacion::first()->hay_diferencias);
    }

    public function test_numero_de_oc_distinto_muestra_advertencia(): void
    {
        $pedido = $this->pedidoConOc();
        $this->partida($pedido, ['cantidad' => 10]);

        $this->revisarYTerminar($pedido, $this->completada($this->extraccion(null, ['numero_oc' => '99999'])))
            ->assertSee('no coincide con el capturado');
    }

    // ---------------------------------------------------------------- reutilización y tope

    public function test_mismo_archivo_reutiliza_la_extraccion_sin_llamar_a_la_api(): void
    {
        $pedido = $this->pedidoConOc();
        $p = $this->partida($pedido, ['cantidad' => 1]);
        $comp = $this->revisarYTerminar($pedido, $this->completada($this->extraccion()));
        Http::assertSentCount(3);

        // Se corrige la partida a mano y se vuelve a revisar: sin llamadas nuevas.
        $p->update(['cantidad' => 10]);
        $comp->call('revisar');

        Http::assertSentCount(3);
        $this->assertSame(2, PedidoConciliacion::count());
        $nueva = PedidoConciliacion::latest('id')->first();
        $this->assertSame('completada', $nueva->estado);
        $this->assertFalse($nueva->hay_diferencias);
        $this->assertSame(0.0, (float) $nueva->costo_usd);
        $this->assertSame(PedidoConciliacion::first()->archivo_hash, $nueva->archivo_hash);
    }

    public function test_archivo_distinto_vuelve_a_llamar(): void
    {
        $pedido = $this->pedidoConOc();
        $this->partida($pedido);
        $comp = $this->revisarYTerminar($pedido, $this->completada($this->extraccion()));

        Storage::disk('local')->put($pedido->oc_cliente_path, 'OTRO CONTENIDO');
        $this->fingir($this->completada($this->extraccion()));
        $comp->call('revisar');

        Http::assertSentCount(1);   // solo el POST de la segunda pasada
    }

    public function test_tope_mensual_bloquea_sin_llamar_a_la_api(): void
    {
        $pedido = $this->pedidoConOc();
        config(['services.openai.tope_mensual_usd' => 10.0]);
        PedidoConciliacion::create([
            'pedido_id' => $pedido->id, 'user_id' => $this->user->id, 'archivo_hash' => 'x', 'modelo' => 'gpt-6-luna',
            'estado' => 'completada', 'costo_usd' => 9.999,
        ]);
        Http::fake();

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])
            ->call('revisar')
            ->assertSee('tope mensual de IA');

        Http::assertNothingSent();
        $this->assertSame(1, PedidoConciliacion::count());
    }

    public function test_gasto_de_meses_anteriores_no_cuenta_para_el_tope(): void
    {
        $pedido = $this->pedidoConOc();
        $viejo = PedidoConciliacion::create([
            'pedido_id' => $pedido->id, 'user_id' => $this->user->id, 'archivo_hash' => 'x', 'modelo' => 'gpt-6-luna',
            'estado' => 'completada', 'costo_usd' => 50,
        ]);
        $viejo->forceFill(['created_at' => now()->subMonths(2)])->save();
        $this->fingir($this->completada($this->extraccion()));

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('revisar');

        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------- acciones

    private function conDaido(): array
    {
        $pedido = $this->pedidoConOc();
        $partida = $this->partida($pedido, ['cantidad' => 1]);
        $comp = $this->revisarYTerminar($pedido, $this->completada($this->extraccion()));

        return [$pedido, $partida, $comp];
    }

    public function test_usar_cantidad_de_la_oc_actualiza_partida_y_totales(): void
    {
        [$pedido, $partida, $comp] = $this->conDaido();
        $pedido->recalcularTotales();
        $this->assertEquals(100, $pedido->fresh()->total_venta);

        $comp->call('usarCantidad', 'r0')->assertSee('El pedido coincide con la OC del cliente');

        $this->assertEquals(10, $partida->fresh()->cantidad);
        $this->assertEquals(1000, $pedido->fresh()->total_venta);
        $this->assertEquals(500, $pedido->fresh()->total_costo);
        $this->assertFalse(PedidoConciliacion::first()->hay_diferencias);
    }

    public function test_agregar_al_pedido_crea_partida_manual_sin_costo(): void
    {
        $pedido = $this->pedidoConOc();
        $this->partida($pedido, ['cantidad' => 10]);
        $extra = $this->renglon(['descripcion' => 'Cinta métrica 5 m', 'modelo' => 'CM5', 'cantidad' => 3, 'unidad' => 'pza', 'precio_unitario' => 80]);
        $comp = $this->revisarYTerminar($pedido, $this->completada($this->extraccion([$this->renglon(), $extra])));

        $comp->call('agregarAlPedido', 'r1');

        $nueva = $pedido->partidas()->get()->last();
        $this->assertSame('CINTA MÉTRICA 5 M', $nueva->descripcion);
        $this->assertSame('CM5', $nueva->modelo);
        $this->assertEquals(3, $nueva->cantidad);
        $this->assertSame('PZA', $nueva->unidad);
        $this->assertEquals(80, $nueva->precio);
        $this->assertNull($nueva->costo);
        $this->assertSame('pendiente', $nueva->estado->value);
        $this->assertEquals(1240, $pedido->fresh()->total_venta);   // 10×100 + 3×80
        $this->assertFalse(PedidoConciliacion::first()->hay_diferencias);
    }

    public function test_agregar_con_precios_con_iva_guarda_precio_sin_iva(): void
    {
        $pedido = $this->pedidoConOc();
        $extra = $this->renglon(['precio_unitario' => 116, 'cantidad' => 2]);
        $comp = $this->revisarYTerminar($pedido, $this->completada($this->extraccion([$extra], ['precios_incluyen_iva' => true])));

        $comp->call('agregarAlPedido', 'r0');

        $this->assertEquals(100, $pedido->partidas()->first()->precio);
    }

    public function test_ignorar_exige_motivo_y_lo_guarda_con_usuario_y_fecha(): void
    {
        [$pedido, , $comp] = $this->conDaido();

        $comp->call('pedirIgnorar', 'r0')
            ->set('motivo', '')
            ->call('ignorar')
            ->assertHasErrors(['motivo' => 'required']);
        $this->assertNull(PedidoConciliacion::first()->resoluciones);

        $comp->set('motivo', 'El cliente confirmó por teléfono que son 2')
            ->call('ignorar')
            ->assertHasNoErrors()
            ->assertSee('Ignorada por '.$this->user->name);

        $con = PedidoConciliacion::first();
        $res = $con->resoluciones['r0'];
        $this->assertSame('El cliente confirmó por teléfono que son 2', $res['motivo']);
        $this->assertSame($this->user->id, $res['user_id']);
        $this->assertSame($this->user->name, $res['usuario']);
        $this->assertNotEmpty($res['fecha']);
        $this->assertFalse($con->hay_diferencias);
        $this->assertStringContainsString('1 ignorada', $con->resumen);
    }

    public function test_campo_no_ve_el_boton_ni_puede_actuar_pero_ve_el_resultado_sin_dinero(): void
    {
        [$pedido] = $this->conDaido();
        $campo = User::factory()->create(['rol' => 'campo']);
        $this->actingAs($campo);

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])
            ->assertDontSee('Revisar contra la OC')
            ->assertDontSee('Usar cantidad de la OC')
            ->assertDontSee('$100.00')
            ->assertSee('Cantidad distinta')
            ->call('revisar')->assertForbidden();

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('usarCantidad', 'r0')->assertForbidden();
        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('pedirIgnorar', 'r0')->assertForbidden();
        $this->assertEquals(1, $pedido->partidas()->first()->cantidad);
    }

    public function test_vendedor_ve_el_boton_y_sin_archivo_no_hay_boton(): void
    {
        $pedido = $this->pedidoConOc();
        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->assertSee('Revisar contra la OC');

        $sin = Pedido::factory()->create(['oc_cliente_path' => null]);
        Livewire::test(Conciliacion::class, ['pedidoId' => $sin->id])->assertDontSee('Revisar contra la OC');
    }

    public function test_el_componente_esta_incrustado_en_el_detalle_del_pedido(): void
    {
        $pedido = $this->pedidoConOc();
        $this->partida($pedido);

        $this->get(route('pedidos.ver', $pedido))->assertOk()
            ->assertSee('Conciliación con la OC del cliente')->assertSee('Revisar contra la OC');
    }

    // ---------------------------------------------------------------- errores

    /** @return array<string, array{0: array, 1: string}> */
    public static function respuestasConError(): array
    {
        $usoVacio = ['input_tokens' => 100, 'output_tokens' => 0];

        return [
            'failed' => [['status' => 'failed', 'error' => ['message' => 'server_error'], 'usage' => $usoVacio], 'server_error'],
            'incomplete' => [['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'usage' => $usoVacio], 'demasiado larga'],
            'cancelled' => [['status' => 'cancelled'], 'cancelada'],
            'refusal' => [['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No puedo ayudar']]]]], 'No puedo ayudar'],
            'sin texto' => [['status' => 'completed', 'output' => []], 'no devolvió texto'],
            'json invalido' => [['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{esto no es json']]]]], 'JSON inválido'],
            'json sin renglones' => [['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"numero_oc":"1"}']]]]], 'JSON inválido'],
        ];
    }

    #[DataProvider('respuestasConError')]
    public function test_respuestas_fallidas_dejan_la_conciliacion_en_error(array $final, string $fragmento): void
    {
        $pedido = $this->pedidoConOc();
        $this->partida($pedido);

        $this->revisarYTerminar($pedido, ['id' => 'resp_1'] + $final)->assertSee('No se pudo leer la OC');

        $con = PedidoConciliacion::first();
        $this->assertSame('error', $con->estado);
        $this->assertStringStartsWith('No se pudo leer la OC: ', $con->error);
        $this->assertStringContainsString($fragmento, $con->error);
        $this->assertNull($con->extraccion);
        $this->assertFalse($con->hay_diferencias);

        // Después de un error se puede volver a intentar.
        $this->fingir($this->completada($this->extraccion()));
        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('revisar');
        $this->assertSame('procesando', PedidoConciliacion::latest('id')->first()->estado);
    }

    public function test_error_de_una_respuesta_fallida_cuenta_sus_tokens_para_el_tope(): void
    {
        $pedido = $this->pedidoConOc();
        $this->revisarYTerminar($pedido, ['id' => 'resp_1', 'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'],
            'usage' => ['input_tokens' => 10000, 'output_tokens' => 8000]]);

        $this->assertEqualsWithDelta(0.005, (float) PedidoConciliacion::first()->costo_usd, 0.000001);
    }

    public function test_tiempo_agotado_despues_de_9_minutos(): void
    {
        $pedido = $this->pedidoConOc();
        Http::fake();
        $c = PedidoConciliacion::create([
            'pedido_id' => $pedido->id, 'user_id' => $this->user->id, 'archivo_hash' => 'x', 'modelo' => 'gpt-6-luna',
            'estado' => 'procesando', 'response_id' => 'resp_1',
        ]);
        $c->forceFill(['created_at' => now()->subMinutes(10)])->save();

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('consultar')->assertSee('tiempo agotado');

        $this->assertSame('error', $c->fresh()->estado);
        $this->assertStringContainsString('tiempo agotado', $c->fresh()->error);
        Http::assertNothingSent();
    }

    public function test_antes_de_9_minutos_sigue_procesando(): void
    {
        $pedido = $this->pedidoConOc();
        Http::fake([self::GET => Http::response(['id' => 'resp_1', 'status' => 'in_progress'], 200)]);
        $c = PedidoConciliacion::create([
            'pedido_id' => $pedido->id, 'user_id' => $this->user->id, 'archivo_hash' => 'x', 'modelo' => 'gpt-6-luna',
            'estado' => 'procesando', 'response_id' => 'resp_1',
        ]);
        $c->forceFill(['created_at' => now()->subMinutes(8)])->save();

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('consultar');

        $this->assertSame('procesando', $c->fresh()->estado);
    }

    public function test_401_no_se_reintenta_y_se_registra_el_error(): void
    {
        $pedido = $this->pedidoConOc();
        Http::fake([self::POST => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('revisar')->assertSee('La llave de OpenAI no es válida');

        Http::assertSentCount(1);
        $this->assertSame('error', PedidoConciliacion::first()->estado);
        $this->assertStringStartsWith('No se pudo leer la OC: ', PedidoConciliacion::first()->error);
        $this->assertSame(1, ErrorSistema::count());
    }

    public function test_400_no_se_reintenta(): void
    {
        $pedido = $this->pedidoConOc();
        Http::fake([self::POST => Http::response(['error' => ['message' => 'Invalid schema']], 400)]);

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('revisar')->assertSee('Invalid schema');

        Http::assertSentCount(1);
    }

    public function test_429_y_5xx_se_reintentan_con_espera(): void
    {
        $pedido = $this->pedidoConOc();
        Http::fake([self::POST => Http::sequence()
            ->push(['error' => ['message' => 'rate']], 429)
            ->push(['error' => ['message' => 'boom']], 503)
            ->push(['id' => 'resp_9', 'status' => 'queued'], 200)]);

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('revisar');

        Http::assertSentCount(3);
        $this->assertSame('resp_9', PedidoConciliacion::first()->response_id);
        $this->assertSame('procesando', PedidoConciliacion::first()->estado);
    }

    public function test_5xx_persistente_termina_en_error_entendible(): void
    {
        $pedido = $this->pedidoConOc();
        Http::fake([self::POST => Http::response('caído', 500)]);

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('revisar')->assertSee('OpenAI no está disponible');

        Http::assertSentCount(3);
        $this->assertSame('error', PedidoConciliacion::first()->estado);
    }

    public function test_sin_llave_configurada_deja_error_claro(): void
    {
        $pedido = $this->pedidoConOc();
        config(['services.openai.key' => null]);
        Http::fake();

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('revisar')->assertSee('OPENAI_API_KEY');

        Http::assertNothingSent();
    }

    public function test_archivo_que_no_es_pdf_ni_imagen_no_se_manda(): void
    {
        Storage::disk('local')->put('pedidos/oc-cliente/oc.docx', 'x');
        $pedido = Pedido::factory()->create(['oc_cliente_path' => 'pedidos/oc-cliente/oc.docx']);
        Http::fake();

        Livewire::test(Conciliacion::class, ['pedidoId' => $pedido->id])->call('revisar')->assertSee('no es PDF ni imagen');

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- candado

    private function ordenablePedido(): array
    {
        $pedido = $this->pedidoConOc();
        $prov = Proveedor::factory()->create();
        $this->partida($pedido, ['cantidad' => 1, 'proveedor_id' => $prov->id]);

        return [$pedido, $prov];
    }

    public function test_candado_sin_conciliar_exige_motivo_y_lo_guarda(): void
    {
        [$pedido] = $this->ordenablePedido();

        $this->generarOc($pedido)
            ->assertSee('Pedido sin conciliar con la OC del cliente')
            ->call('generar')
            ->assertHasErrors('motivoSinConciliar');
        $this->assertSame(0, OrdenProveedor::count());

        $this->generarOc($pedido)
            ->set('motivoSinConciliar', 'Urgente, el cliente ya confirmó por teléfono')
            ->call('generar')
            ->assertHasNoErrors();

        $orden = OrdenProveedor::first();
        $this->assertSame('Urgente, el cliente ya confirmó por teléfono', $orden->motivo_sin_conciliar);
        $this->assertSame($this->user->id, $orden->autorizo_sin_conciliar_id);
    }

    public function test_candado_con_diferencias_sin_resolver_bloquea(): void
    {
        [$pedido] = $this->ordenablePedido();
        $this->revisarYTerminar($pedido, $this->completada($this->extraccion()));   // OC 10 vs pedido 1

        $this->generarOc($pedido)
            ->assertSee('diferencias sin resolver')
            ->call('generar')
            ->assertHasErrors('motivoSinConciliar');
        $this->assertSame(0, OrdenProveedor::count());
    }

    public function test_candado_se_recalcula_contra_las_partidas_de_ahora(): void
    {
        [$pedido] = $this->ordenablePedido();
        $this->revisarYTerminar($pedido, $this->completada($this->extraccion()));
        $pedido->partidas()->update(['cantidad' => 10]);   // se corrigió sin volver a pulsar "Revisar"

        $this->generarOc($pedido)
            ->assertDontSee('Pedido sin conciliar')
            ->call('generar')
            ->assertHasNoErrors();

        $orden = OrdenProveedor::first();
        $this->assertNull($orden->motivo_sin_conciliar);
        $this->assertNull($orden->autorizo_sin_conciliar_id);
    }

    public function test_candado_no_bloquea_si_la_diferencia_fue_ignorada_con_motivo(): void
    {
        [$pedido] = $this->ordenablePedido();
        $comp = $this->revisarYTerminar($pedido, $this->completada($this->extraccion()));
        $comp->call('pedirIgnorar', 'r0')->set('motivo', 'Confirmado con el cliente')->call('ignorar');

        $this->generarOc($pedido)->call('generar')->assertHasNoErrors();

        $this->assertSame(1, OrdenProveedor::count());
    }

    public function test_sin_archivo_de_oc_solo_aviso_amarillo_sin_bloquear(): void
    {
        $pedido = Pedido::factory()->create(['oc_cliente_path' => null]);
        $prov = Proveedor::factory()->create();
        $this->partida($pedido, ['proveedor_id' => $prov->id]);

        $this->generarOc($pedido)
            ->assertSee('no tiene archivo de OC del cliente')
            ->assertDontSee('Pedido sin conciliar')
            ->call('generar')
            ->assertHasNoErrors();

        $orden = OrdenProveedor::first();
        $this->assertNull($orden->motivo_sin_conciliar);
    }

    public function test_conciliacion_fallida_no_cuenta_como_conciliada_para_el_candado(): void
    {
        [$pedido] = $this->ordenablePedido();
        $this->revisarYTerminar($pedido, ['id' => 'resp_1', 'status' => 'failed', 'error' => ['message' => 'x']]);

        $this->generarOc($pedido)->call('generar')->assertHasErrors('motivoSinConciliar');
    }

    // ---------------------------------------------------------------- tablero

    public function test_tablero_muestra_etiquetas_de_conciliacion_sin_n_mas_uno(): void
    {
        $conflictos = Pedido::factory()->create(['numero_oc_cliente' => '111', 'oc_cliente_path' => 'pedidos/oc-cliente/a.pdf']);
        $limpio = Pedido::factory()->create(['numero_oc_cliente' => '222', 'oc_cliente_path' => 'pedidos/oc-cliente/b.pdf']);
        $ninguno = Pedido::factory()->create(['numero_oc_cliente' => '333']);
        foreach ([[$conflictos, true, 'a'], [$limpio, false, 'b']] as [$p, $hay, $h]) {
            PedidoConciliacion::create(['pedido_id' => $p->id, 'user_id' => $this->user->id, 'archivo_hash' => $h,
                'modelo' => 'gpt-6-luna', 'estado' => 'completada', 'hay_diferencias' => $hay]);
        }
        // Una revisión posterior fallida no reemplaza a la última completada.
        PedidoConciliacion::create(['pedido_id' => $limpio->id, 'user_id' => $this->user->id, 'archivo_hash' => 'c',
            'modelo' => 'gpt-6-luna', 'estado' => 'error', 'error' => 'x']);

        Livewire::test(Index::class)->assertSee('Diferencias con OC')->assertSee('Conciliada con OC');

        $this->assertSame('Diferencias con OC', collect($conflictos->fresh()->load('ultimaConciliacion')->etiquetas())->firstWhere('color', 'rojo')['texto']);
        $this->assertNotContains('Conciliada con OC', array_column($ninguno->fresh()->load('ultimaConciliacion')->etiquetas(), 'texto'));
        $this->assertNotContains('Diferencias con OC', array_column($ninguno->fresh()->etiquetas(), 'texto'));

        // Si se quita el archivo de la OC, la comparación vieja deja de mostrarse.
        $conflictos->update(['oc_cliente_path' => null]);
        $this->assertNotContains('Diferencias con OC', array_column($conflictos->fresh()->load('ultimaConciliacion')->etiquetas(), 'texto'));
    }

    public function test_etiquetas_no_consultan_la_conciliacion_si_no_esta_cargada(): void
    {
        $p = Pedido::factory()->create();
        PedidoConciliacion::create(['pedido_id' => $p->id, 'user_id' => $this->user->id, 'archivo_hash' => 'a',
            'modelo' => 'gpt-6-luna', 'estado' => 'completada', 'hay_diferencias' => false]);
        $p = $p->fresh();
        $p->setRelation('partidas', collect());

        DB::enableQueryLog();
        $textos = array_column($p->etiquetas(), 'texto');
        $consultas = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'pedido_conciliaciones'));
        DB::disableQueryLog();

        $this->assertCount(0, $consultas);
        $this->assertNotContains('Conciliada con OC', $textos);
    }

    public function test_tablero_no_hace_una_consulta_de_conciliacion_por_tarjeta(): void
    {
        foreach (range(1, 12) as $i) {
            $p = Pedido::factory()->create();
            PedidoConciliacion::create(['pedido_id' => $p->id, 'user_id' => $this->user->id, 'archivo_hash' => 'h'.$i,
                'modelo' => 'gpt-6-luna', 'estado' => 'completada', 'hay_diferencias' => $i % 2 === 0]);
        }

        DB::enableQueryLog();
        Livewire::test(Index::class);
        $consultas = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'pedido_conciliaciones'))->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $consultas);
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

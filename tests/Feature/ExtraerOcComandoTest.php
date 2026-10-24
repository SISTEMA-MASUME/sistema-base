<?php

namespace Tests\Feature;

use App\Services\Ia\CalificadorExtraccion;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** El comando llama a la API de verdad; aquí siempre con Http::fake(). */
class ExtraerOcComandoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['services.openai.key' => 'sk-test', 'services.openai.modelo' => 'gpt-6-luna']);
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oc-prueba-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function extraccion(string $numero, array $renglones, array $o = []): array
    {
        return array_merge([
            'numero_oc' => $numero, 'fecha_oc' => null, 'cliente_detectado' => null, 'moneda' => 'MXN', 'precios_incluyen_iva' => false,
            'renglones' => $renglones, 'subtotal' => null, 'iva' => null, 'total' => null, 'observaciones_generales' => null, 'advertencias' => [],
        ], $o);
    }

    private function renglon(string $desc, float $cant, ?float $precio, array $o = []): array
    {
        return array_merge([
            'num_renglon' => 1, 'codigo_cliente' => null, 'descripcion' => $desc, 'marca' => null, 'modelo' => null, 'cantidad' => $cant,
            'unidad' => 'PZA', 'precio_unitario' => $precio, 'importe' => null, 'fecha_entrega' => null, 'origen' => 'tabla',
            'confianza' => 'alta', 'texto_original' => $desc,
        ], $o);
    }

    private function respuesta(array $extraccion): array
    {
        return [
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($extraccion)]]]],
            'usage' => ['input_tokens' => 5000, 'output_tokens' => 1000, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 400]],
        ];
    }

    public function test_un_archivo_imprime_json_tokens_costo_y_segundos(): void
    {
        file_put_contents($this->dir.'/delta.pdf', '%PDF falso');
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['id' => 'resp_1', 'status' => 'queued'], 200),
            'api.openai.com/v1/responses/*' => Http::sequence()
                ->push(['status' => 'in_progress'], 200)
                ->push($this->respuesta($this->extraccion('10777', [$this->renglon('RTV SILICONE SEALANT 13 OZ. 24105', 10, 510.28, ['origen' => 'observaciones'])])), 200),
        ]);

        $this->artisan('masume:extraer-oc', ['archivo' => $this->dir.'/delta.pdf'])
            ->expectsOutputToContain('RTV SILICONE SEALANT')
            ->expectsOutputToContain('Costo total: US$0.00100')
            ->assertExitCode(0);

        Http::assertSentCount(3);
    }

    public function test_modelo_opcional_y_calificacion_contra_esperado(): void
    {
        file_put_contents($this->dir.'/delta.pdf', '%PDF falso');
        file_put_contents($this->dir.'/esperado.json', json_encode(['numero_oc' => '10777', 'renglones' => [
            ['clave' => ['rtv', 'silicone'], 'cantidad' => 10, 'unidad' => 'PZA', 'precio' => 510.28, 'obs' => true],
        ]]));
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['id' => 'resp_1', 'status' => 'queued'], 200),
            'api.openai.com/v1/responses/*' => Http::response($this->respuesta($this->extraccion('10777', [$this->renglon('rtv silicone sealant', 10, 510.28)])), 200),
        ]);

        $this->artisan('masume:extraer-oc', ['archivo' => $this->dir.'/delta.pdf', '--modelo' => 'gpt-6-sol', '--esperado' => $this->dir.'/esperado.json'])
            ->expectsOutputToContain('Modelo: gpt-6-sol')
            ->expectsOutputToContain('1/1')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data()['model'] === 'gpt-6-sol');
    }

    public function test_carpeta_corre_todos_y_califica_por_archivo_con_totales(): void
    {
        file_put_contents($this->dir.'/a.pdf', '%PDF a');
        file_put_contents($this->dir.'/b.jpg', 'jpg b');
        file_put_contents($this->dir.'/notas.txt', 'ignorar');
        file_put_contents($this->dir.'/esperado.json', json_encode([
            'a.pdf' => ['numero_oc' => '1', 'subtotal' => 100, 'renglones' => [['clave' => ['foco'], 'cantidad' => 2, 'precio' => 50]]],
            'b.jpg' => ['numero_oc' => '2', 'renglones' => [['clave' => ['cinta'], 'cantidad' => 5, 'precio' => 10], ['clave' => ['taladro'], 'cantidad' => 1, 'precio' => 900]]],
        ]));

        $resp = [
            'a.pdf' => $this->extraccion('1', [$this->renglon('FOCO LED', 2, 50)], ['subtotal' => 100]),
            'b.jpg' => $this->extraccion('999', [$this->renglon('CINTA AISLANTE', 4, 10)]),   // cantidad mal, falta taladro, OC mal
        ];
        $ids = [];
        Http::fake([
            'api.openai.com/v1/responses' => function (Request $r) use (&$ids) {
                $id = 'resp_'.(count($ids) + 1);
                $ids[$id] = str_starts_with($r->data()['input'][1]['content'][1]['type'], 'input_image') ? 'b.jpg' : 'a.pdf';

                return Http::response(['id' => $id, 'status' => 'queued'], 200);
            },
            'api.openai.com/v1/responses/*' => function (Request $r) use (&$ids, $resp) {
                $id = basename($r->url());

                return Http::response($this->respuesta($resp[$ids[$id]]), 200);
            },
        ]);

        $this->artisan('masume:extraer-oc', ['--carpeta' => $this->dir, '--esperado' => $this->dir.'/esperado.json'])
            ->expectsOutputToContain('Archivos: 2')
            ->expectsOutputToContain('TOTAL')
            ->expectsOutputToContain('Costo total: US$0.00200')
            ->assertExitCode(0);

        Http::assertSentCount(4);   // 2 POST + 2 GET (notas.txt y esperado.json no se mandan)
    }

    public function test_sin_argumentos_o_archivo_inexistente_falla_sin_llamar(): void
    {
        Http::fake();
        $this->artisan('masume:extraer-oc')->expectsOutputToContain('Indica un archivo')->assertExitCode(1);
        $this->artisan('masume:extraer-oc', ['archivo' => $this->dir.'/no-existe.pdf'])->expectsOutputToContain('No existe')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_error_de_la_api_sale_con_codigo_de_falla(): void
    {
        file_put_contents($this->dir.'/a.pdf', '%PDF a');
        Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'mala llave']], 401)]);

        $this->artisan('masume:extraer-oc', ['archivo' => $this->dir.'/a.pdf'])
            ->expectsOutputToContain('La llave de OpenAI no es válida')
            ->assertExitCode(1);
    }

    public function test_calificador_empareja_por_palabras_sin_acentos_y_una_vez(): void
    {
        $datos = $this->extraccion('OC-0045', [
            $this->renglon('Flexómetro 5 m ( 12 PZ )', 1, 3106.20),
            $this->renglon('Flexómetro 8 m', 3, 100),
            $this->renglon('Cinta', 1, null),
        ]);
        $esperado = [
            'numero_oc' => '45', 'subtotal' => 100,
            'renglones' => [
                ['clave' => ['FLEXOMETRO', '12'], 'cantidad' => 1, 'precio' => 3106.20],
                ['clave' => ['flexometro'], 'cantidad' => 3, 'precio' => 100.5],   // precio mal
                ['clave' => ['tornillo'], 'cantidad' => 9, 'precio' => 1],        // no aparece
                ['clave' => ['cinta'], 'cantidad' => 1],                          // sin precio esperado
            ],
        ];

        $c = (new CalificadorExtraccion)->calificar($datos, $esperado);

        $this->assertSame(4, $c['esperados']);
        $this->assertSame(3, $c['encontrados']);
        $this->assertSame(3, $c['cantidades_ok']);
        $this->assertSame(3, $c['precios_esperados']);
        $this->assertSame(1, $c['precios_ok']);
        $this->assertTrue($c['numero_ok']);
        $this->assertFalse($c['subtotal_ok']);
    }
}

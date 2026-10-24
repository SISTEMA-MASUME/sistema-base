<?php

namespace Tests\Feature;

use App\Livewire\Sicar\Alta;
use App\Models\IaConsumo;
use App\Models\Pedido;
use App\Models\PedidoConciliacion;
use App\Models\PedidoPartida;
use App\Models\SatClave;
use App\Models\SicarArticulo;
use App\Models\User;
use App\Services\Ia\SugeridorClaveSat;
use App\Support\AltaSicar;
use App\Support\CatalogoSat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ClaveSatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            ['01010101', 'No existe en el catálogo', 'Público en general'],
            ['46181504', 'Guantes de protección', 'Guantes protectores'],
            ['49211606', 'Guantes de golf', ''],
            ['27112838', 'Disco de corte', ''],
            ['31171500', 'Rodamientos', 'Baleros'],
            ['50404505', 'Lenteja pardina española', ''],
        ] as [$c, $d, $s]) {
            SatClave::create(['clave' => $c, 'descripcion' => $d, 'similares' => $s ?: null, 'texto' => CatalogoSat::normalizar($d.' '.$s)]);
        }
        config(['services.openai.key' => 'sk-prueba', 'services.openai.modelo' => 'gpt-6-luna', 'services.openai.tope_mensual_usd' => 10]);
    }

    private function respuesta(array $articulos, int $entrada = 1000, int $salida = 200): array
    {
        return ['status' => 'completed', 'usage' => ['input_tokens' => $entrada, 'output_tokens' => $salida],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['articulos' => $articulos])]]]]];
    }

    public function test_raices_quitan_plural_numeros_y_palabras_vacias(): void
    {
        $this->assertSame(['GUANT', 'CARNAZA', 'SOLDADOR'], CatalogoSat::raices('Guantes de carnaza para soldador 14" TRUPER'));
        $this->assertSame(['LLAV', 'ESPANOLA'], CatalogoSat::raices('Llaves española 1/2'));
    }

    public function test_candidatos_ponen_primero_lo_que_es_el_articulo_y_nunca_la_generica(): void
    {
        $c = (new CatalogoSat)->candidatos('Guante de carnaza para soldador', 5);
        $this->assertContains('46181504', $c->pluck('clave')->all());
        $this->assertNotContains('01010101', $c->pluck('clave')->all());
        $this->assertSame('31171500', (new CatalogoSat)->candidatos('Balero 6203 2RS')->first()?->clave); // por "similares"
        $this->assertSame([], (new CatalogoSat)->candidatos('1/2 x 3')->all());
    }

    public function test_nombres_y_avisos_de_clave_sat_en_las_filas(): void
    {
        SicarArticulo::create(['art_id' => 1, 'clave' => 'BAL-1', 'descripcion' => 'BALERO', 'unidad' => 'PZA', 'status' => 1, 'clave_llave' => 'BAL1', 'clave_prod_serv' => '01010101']);
        SicarArticulo::create(['art_id' => 2, 'clave' => 'GUA-1', 'descripcion' => 'GUANTE', 'unidad' => 'PZA', 'status' => 1, 'clave_llave' => 'GUA1', 'clave_prod_serv' => '99999999']);
        SicarArticulo::create(['art_id' => 3, 'clave' => 'DSC-1', 'descripcion' => 'DISCO', 'unidad' => 'PZA', 'status' => 1, 'clave_llave' => 'DSC1', 'clave_prod_serv' => '27112838']);
        $pedido = Pedido::factory()->create();
        $mk = fn (array $d) => PedidoPartida::factory()->create(['pedido_id' => $pedido->id, ...$d]);
        $mk(['descripcion' => 'Balero', 'modelo' => 'BAL-1', 'orden' => 1]);
        $mk(['descripcion' => 'Guante', 'modelo' => 'GUA-1', 'orden' => 2, 'sicar_clave_sat' => '46181504', 'sicar_sat_ia' => 'alta']);
        $mk(['descripcion' => 'Disco', 'modelo' => 'DSC-1', 'orden' => 3]);
        $mk(['descripcion' => 'Cosa nueva', 'modelo' => 'NVO-1', 'orden' => 4, 'sicar_clave_sat' => '27112838', 'sicar_sat_ia' => 'media']);

        $f = (new AltaSicar)->filas(Pedido::with(['cliente', 'partidas.proveedor'])->get())->keyBy(fn ($f) => $f['partida']->orden);

        $this->assertStringContainsString('genérica 01010101', implode(' ', $f[1]['avisos']));
        $this->assertStringContainsString('(99999999) no existe en el catálogo del SAT', implode(' ', $f[2]['avisos']));
        $this->assertSame('46181504', $f[2]['sat_sugerida']);
        $this->assertStringContainsString('La IA sugiere cambiar la clave SAT en SICAR a 46181504 (Guantes de protección)', implode(' ', $f[2]['avisos']));
        $this->assertSame('Disco de corte', $f[3]['sat_nombre']);
        $this->assertSame([], $f[3]['avisos']);
        $this->assertSame('nuevo', $f[4]['estado']);
        $this->assertSame('27112838', $f[4]['sat']);
        $this->assertSame('Disco de corte', $f[4]['sat_nombre']);
        $this->assertSame('media', $f[4]['sat_ia']);
    }

    public function test_ia_elige_clave_valida_rechaza_inventadas_y_genericas_y_registra_gasto(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->respuesta([
            ['id' => 1, 'clave' => '46181504', 'confianza' => 'alta', 'motivo' => 'Es un guante de protección'],
            ['id' => 2, 'clave' => '12345678', 'confianza' => 'alta', 'motivo' => 'inventada'],
            ['id' => 3, 'clave' => '01010101', 'confianza' => 'baja', 'motivo' => 'no sé'],
            ['id' => 99, 'clave' => '27112838', 'confianza' => 'alta', 'motivo' => 'id que no se pidió'],
        ]))]);

        $r = (new SugeridorClaveSat)->sugerir([
            ['id' => 1, 'descripcion' => 'Guante de carnaza para soldador', 'unidad' => 'PAR'],
            ['id' => 2, 'descripcion' => 'Cosa rara'],
            ['id' => 3, 'descripcion' => 'Otra cosa', 'clave_actual' => '31171500'],
        ]);

        $this->assertSame([1], array_keys($r));
        $this->assertSame(['clave' => '46181504', 'nombre' => 'Guantes de protección', 'confianza' => 'alta', 'motivo' => 'Es un guante de protección'], $r[1]);

        Http::assertSent(function (Request $req) {
            $cuerpo = $req->data();
            $entrada = json_decode($cuerpo['input'][1]['content'][0]['text'], true)['articulos'];

            return $cuerpo['model'] === 'gpt-6-luna' && $cuerpo['store'] === false
                && in_array('46181504 — Guantes de protección', $entrada[0]['candidatas'], true)
                && $entrada[2]['candidatas'][0] === '31171500 — Rodamientos'; // la clave actual va primero
        });

        $consumo = IaConsumo::sole();
        $this->assertSame('clave_sat', $consumo->uso);
        $this->assertEquals(0.0002, (float) $consumo->costo_usd); // 1000×0.10 + 200×0.50 por millón
        $this->assertEquals(0.0002, PedidoConciliacion::gastoDelMes());
    }

    public function test_ia_manda_lotes_de_diez(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->respuesta([]))]);
        $arts = array_map(fn ($i) => ['id' => $i, 'descripcion' => 'Disco de corte '.$i], range(1, 23));
        (new SugeridorClaveSat)->sugerir($arts);
        Http::assertSentCount(3);
    }

    public function test_ia_respeta_el_tope_mensual_y_la_falta_de_llave(): void
    {
        Http::fake();
        IaConsumo::create(['uso' => 'clave_sat', 'modelo' => 'gpt-6-luna', 'costo_usd' => 10]);
        try {
            (new SugeridorClaveSat)->sugerir([['id' => 1, 'descripcion' => 'Guante']]);
            $this->fail('Debió detenerse por el tope');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tope mensual', $e->getMessage());
        }

        config(['services.openai.key' => null]);
        $this->expectExceptionMessage('OPENAI_API_KEY');
        (new SugeridorClaveSat)->sugerir([['id' => 1, 'descripcion' => 'Guante']]);
        Http::assertNothingSent();
    }

    public function test_ia_con_error_de_openai_da_mensaje_claro(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'x']], 401)]);
        $this->expectExceptionMessage('La llave de OpenAI no es válida');
        (new SugeridorClaveSat)->sugerir([['id' => 1, 'descripcion' => 'Guante']]);
    }

    public function test_comando_importa_el_catalogo_y_es_idempotente(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'sat').'.csv.gz';
        $g = gzopen($ruta, 'wb');
        gzwrite($g, "clave,descripcion,similares\n46181504,Guantes de protección modificado,Guantes\n11111111,Nueva clave,\nMALA,no,\n");
        gzclose($g);

        $this->artisan('masume:importar-catalogo-sat', ['--archivo' => $ruta])->assertSuccessful();
        $this->artisan('masume:importar-catalogo-sat', ['--archivo' => $ruta])->assertSuccessful();
        @unlink($ruta);

        $this->assertSame('Guantes de protección modificado', SatClave::find('46181504')->descripcion);
        $this->assertSame('NUEVA CLAVE', SatClave::find('11111111')->texto);
        $this->assertNull(SatClave::find('MALA'));
        // Archivo parcial (menos de 50 mil claves): no borra las demás.
        $this->assertNotNull(SatClave::find('27112838'));
    }

    public function test_comando_guarda_la_sugerencia_en_las_partidas_y_no_repite(): void
    {
        SicarArticulo::create(['art_id' => 3, 'clave' => 'DSC-1', 'descripcion' => 'DISCO', 'unidad' => 'PZA', 'status' => 1, 'clave_llave' => 'DSC1', 'clave_prod_serv' => '27112838']);
        SicarArticulo::create(['art_id' => 4, 'clave' => 'BAL-1', 'descripcion' => 'BALERO', 'unidad' => 'PZA', 'status' => 1, 'clave_llave' => 'BAL1', 'clave_prod_serv' => '01010101']);
        $pedido = Pedido::factory()->create();
        $nuevo = PedidoPartida::factory()->create(['pedido_id' => $pedido->id, 'descripcion' => 'Guante de carnaza', 'modelo' => 'GUA-9', 'orden' => 1]);
        $bien = PedidoPartida::factory()->create(['pedido_id' => $pedido->id, 'descripcion' => 'Disco', 'modelo' => 'DSC-1', 'orden' => 2]);
        $mal = PedidoPartida::factory()->create(['pedido_id' => $pedido->id, 'descripcion' => 'Balero', 'modelo' => 'BAL-1', 'orden' => 3]);
        $archivado = Pedido::factory()->create(['archivado_at' => now()]);
        PedidoPartida::factory()->create(['pedido_id' => $archivado->id, 'descripcion' => 'Otro', 'modelo' => 'X-1']);

        Http::fake(['api.openai.com/*' => Http::sequence()->push($this->respuesta([
            ['id' => $nuevo->id, 'clave' => '46181504', 'confianza' => 'alta', 'motivo' => 'guante'],
            ['id' => $mal->id, 'clave' => '31171500', 'confianza' => 'alta', 'motivo' => 'balero'],
        ]))]);

        $this->artisan('masume:sugerir-claves-sat')->expectsOutputToContain('Revisadas: 2')->assertSuccessful();
        // La que ya está bien en SICAR no se manda a la IA.
        Http::assertSent(fn (Request $r) => ! str_contains($r->data()['input'][1]['content'][0]['text'], '"Disco"'));

        $this->assertSame('46181504', $nuevo->fresh()->sicar_clave_sat);
        $this->assertSame('alta', $nuevo->fresh()->sicar_sat_ia);
        $this->assertSame('31171500', $mal->fresh()->sicar_clave_sat);
        $this->assertNull($bien->fresh()->sicar_sat_ia);

        // Segunda vez: ya revisadas, no se vuelve a pagar.
        $this->artisan('masume:sugerir-claves-sat')->expectsOutputToContain('Revisadas: 0')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_boton_de_la_pantalla_sugiere_y_la_clave_se_puede_corregir_a_mano(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $pedido = Pedido::factory()->create();
        $p = PedidoPartida::factory()->create(['pedido_id' => $pedido->id, 'descripcion' => 'Guante de carnaza', 'modelo' => 'GUA-9']);
        Http::fake(['api.openai.com/*' => Http::response($this->respuesta([
            ['id' => $p->id, 'clave' => '46181504', 'confianza' => 'media', 'motivo' => 'guante'],
        ]))]);

        $t = Livewire::test(Alta::class)->set('seleccion', [$pedido->id])
            ->assertSee('Sugerir clave SAT con IA (1)')
            ->call('sugerirSat')
            ->assertSet('mensaje', 'La IA eligió la clave SAT de 1 artículo. Revisa que el nombre de cada clave corresponda al artículo.')
            ->assertSee('Guantes de protección')->assertSee('IA: confianza media')
            ->assertDontSee('Sugerir clave SAT con IA');

        $t->call('guardarSat', $p->id, '12345678')->assertSet('error', 'La clave SAT 12345678 no existe en el catálogo del SAT (o es la genérica 01010101).');
        $this->assertSame('46181504', $p->fresh()->sicar_clave_sat);
        $t->call('guardarSat', $p->id, '4918-1504')->assertSet('error', 'La clave SAT 49181504 no existe en el catálogo del SAT (o es la genérica 01010101).');
        $t->call('guardarSat', $p->id, '49211606')->assertSet('error', '')->assertSee('Guantes de golf')->assertSee('Capturada a mano');
        $this->assertSame('manual', $p->fresh()->sicar_sat_ia);
    }

    public function test_boton_con_ia_caida_muestra_error_claro(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $pedido = Pedido::factory()->create();
        PedidoPartida::factory()->create(['pedido_id' => $pedido->id, 'descripcion' => 'Guante', 'modelo' => 'GUA-9']);
        Http::fake(['api.openai.com/*' => Http::response([], 500)]);

        Livewire::test(Alta::class)->set('seleccion', [$pedido->id])->call('sugerirSat')
            ->assertSet('error', 'No se pudo consultar la IA: OpenAI no está disponible por el momento. Intenta en unos minutos.');
    }

    public function test_el_archivo_del_catalogo_del_sat_viene_completo(): void
    {
        $f = gzopen(database_path('datos/sat_clave_prod_serv.csv.gz'), 'rb');
        $n = 0;
        $guantes = null;
        while (($r = fgetcsv($f)) !== false) {
            $n++;
            if ($r[0] === '46181504') {
                $guantes = $r[1];
            }
        }
        gzclose($f);
        $this->assertGreaterThan(50000, $n);
        $this->assertSame('Guantes de protección', $guantes);
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Solicitudes\Nueva;
use App\Models\Pedido;
use App\Models\PedidoConciliacion;
use App\Models\SolicitudArchivo;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use App\Support\Folios;
use App\Support\LecturaOc;
use App\Support\LecturaSolicitud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RecuperarLecturasIaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['services.openai.key' => 'solo-pruebas', 'services.openai.modelo' => 'gpt-6-luna',
            'services.openai.modelo_imagenes' => 'gpt-6-sol', 'services.openai.tope_mensual_usd' => 10]);
    }

    private function oc(array $campos = []): PedidoConciliacion
    {
        return PedidoConciliacion::create($campos + ['pedido_id' => null, 'user_id' => User::factory()->create()->id,
            'archivo_hash' => 'prueba', 'modelo' => 'gpt-6-luna', 'response_id' => 'resp_oc', 'estado' => 'procesando']);
    }

    private function solicitud(array $campos = []): SolicitudCotizacion
    {
        $s = SolicitudCotizacion::create($campos + ['folio' => Folios::siguiente('solicitud'), 'canal' => 'whatsapp',
            'recibida_at' => now(), 'estatus' => 'pendiente', 'creada_por' => User::factory()->create()->id,
            'partidas' => [], 'ia_estado' => 'leyendo', 'ia_respuesta_id' => 'resp_solicitud', 'ia_inicio_at' => now()]);
        Storage::disk('local')->put('solicitudes/prueba.pdf', '%PDF-prueba');
        $a = SolicitudArchivo::create(['solicitud_id' => $s->id, 'ruta' => 'solicitudes/prueba.pdf',
            'nombre' => 'prueba.pdf', 'mime' => 'application/pdf', 'bytes' => 11]);
        $s->update(['ia_archivo_id' => $a->id]);

        return $s;
    }

    private function respuesta(array $datos, string $estado = 'completed'): array
    {
        return ['status' => $estado, 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($datos)]]]],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 200]];
    }

    private function datosSolicitud(): array
    {
        return ['partidas' => [['descripcion' => 'BROCA', 'cantidad' => 2, 'unidad' => 'PZA', 'modelo' => 'B-1']],
            'cliente_nombre' => null, 'contacto' => null, 'notas' => null];
    }

    public function test_recupera_oc_y_solicitud_sin_navegador_y_no_repite_consumo(): void
    {
        $c = $this->oc(['pedido_id' => Pedido::factory()->create()->id]);
        $s = $this->solicitud();
        Http::fake(['*/responses/resp_oc' => Http::response($this->respuesta(['renglones' => []])),
            '*/responses/resp_solicitud' => Http::response($this->respuesta($this->datosSolicitud()))]);

        $this->artisan('masume:recuperar-lecturas-ia')->assertSuccessful();
        $this->assertSame('completada', $c->fresh()->estado);
        $this->assertNotNull($c->fresh()->resultado);
        $this->assertGreaterThan(0, (float) $c->fresh()->costo_usd);
        $this->assertSame('lista', $s->fresh()->ia_estado);
        $this->assertCount(1, $s->fresh()->partidas);
        $this->assertDatabaseCount('ia_consumos', 1);
        $this->assertNotNull($s->archivos()->first()->leido_at);
        $this->artisan('masume:recuperar-lecturas-ia')->assertSuccessful();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ia_consumos', 1);
    }

    public function test_polling_y_cron_no_aplican_dos_veces_la_misma_respuesta(): void
    {
        $s = $this->solicitud();
        $entro = false;
        Http::fake(function () use ($s, &$entro) {
            if (! $entro) {
                $entro = true;
                LecturaSolicitud::consultar($s->fresh());
            }

            return Http::response($this->respuesta($this->datosSolicitud()));
        });
        $this->assertNull(LecturaSolicitud::consultar($s));
        $this->assertDatabaseCount('ia_consumos', 1);
        $this->assertCount(1, $s->fresh()->partidas);
    }

    public function test_la_pantalla_incorpora_el_resultado_del_cron_sin_borrar_un_cambio_manual(): void
    {
        $s = $this->solicitud(['partidas' => [['descripcion' => 'MANUAL', 'cantidad' => 1, 'unidad' => 'PZA', 'modelo' => '']]]);
        $pantalla = Livewire::actingAs(User::find($s->creada_por))->test(Nueva::class, ['solicitud' => $s])
            ->set('partidas.0.descripcion', 'MANUAL CORREGIDA');
        Http::fake(['*/responses/*' => Http::response($this->respuesta($this->datosSolicitud()))]);
        $this->artisan('masume:recuperar-lecturas-ia')->assertSuccessful();
        $pantalla->call('consultarIa')->assertSet('partidas.0.descripcion', 'MANUAL CORREGIDA')
            ->assertSet('partidas.1.descripcion', 'BROCA')->assertSet('iaEstado', 'lista');
        $pantalla->call('consultarIa');
        $this->assertCount(2, $pantalla->get('partidas'));
    }

    public function test_continua_con_el_siguiente_archivo_de_la_solicitud(): void
    {
        $s = $this->solicitud();
        Storage::disk('local')->put('solicitudes/segundo.pdf', '%PDF-segundo');
        SolicitudArchivo::create(['solicitud_id' => $s->id, 'ruta' => 'solicitudes/segundo.pdf',
            'nombre' => 'segundo.pdf', 'mime' => 'application/pdf', 'bytes' => 12]);
        Http::fake(['*/responses/resp_solicitud' => Http::response($this->respuesta($this->datosSolicitud())),
            '*/responses' => Http::response(['id' => 'resp_segundo']),
            '*/responses/resp_segundo' => Http::response($this->respuesta($this->datosSolicitud()))]);
        $this->artisan('masume:recuperar-lecturas-ia')->assertSuccessful();
        $this->assertSame('resp_segundo', $s->fresh()->ia_respuesta_id);
        $this->artisan('masume:recuperar-lecturas-ia')->assertSuccessful();
        $this->assertSame('lista', $s->fresh()->ia_estado);
        $this->assertCount(2, $s->fresh()->partidas);
        $this->assertDatabaseCount('ia_consumos', 2);
    }

    public function test_guardar_antes_del_polling_conserva_lo_que_recupero_el_cron(): void
    {
        $s = $this->solicitud(['cliente_texto' => 'Cliente de prueba', 'partidas' => [['descripcion' => 'MANUAL', 'cantidad' => 1, 'unidad' => 'PZA', 'modelo' => '']]]);
        $pantalla = Livewire::actingAs(User::find($s->creada_por))->test(Nueva::class, ['solicitud' => $s])
            ->set('partidas.0.descripcion', 'MANUAL CORREGIDA');
        Http::fake(['*/responses/*' => Http::response($this->respuesta($this->datosSolicitud()))]);
        $this->artisan('masume:recuperar-lecturas-ia')->assertSuccessful();
        $pantalla->call('guardar')->assertRedirect(route('solicitudes.ver', $s));
        $this->assertSame('MANUAL CORREGIDA', $s->fresh()->partidas[0]['descripcion']);
        $this->assertSame('BROCA', $s->fresh()->partidas[1]['descripcion'] ?? null);
        $this->assertDatabaseCount('ia_consumos', 1);
        Http::assertSentCount(1);
    }

    public function test_un_fallo_no_impide_recuperar_las_otras_lecturas(): void
    {
        $c = $this->oc();
        $s = $this->solicitud();
        Http::fake(['*/responses/resp_oc' => Http::response(['status' => 'failed', 'error' => ['message' => 'No se pudo leer'],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 200]]),
            '*/responses/resp_solicitud' => Http::response($this->respuesta($this->datosSolicitud()))]);
        $this->artisan('masume:recuperar-lecturas-ia')->assertFailed();
        $this->assertSame('error', $c->fresh()->estado);
        $this->assertGreaterThan(0, (float) $c->fresh()->costo_usd);
        $this->assertSame('lista', $s->fresh()->ia_estado);
    }

    public function test_cron_hace_una_ultima_consulta_entre_nueve_y_diez_minutos(): void
    {
        $c = $this->oc(['created_at' => now()->subMinutes(9)->subSeconds(20)]);
        Http::fake(['*/responses/*' => Http::response($this->respuesta(['renglones' => []]))]);
        $this->artisan('masume:recuperar-lecturas-ia')->assertSuccessful();
        $this->assertSame('completada', $c->fresh()->estado);
    }

    public function test_vencidas_y_arranques_huerfanos_se_cierran_sin_nuevos_cargos(): void
    {
        $c = $this->oc(['created_at' => now()->subMinutes(11)]);
        $s = $this->solicitud(['ia_inicio_at' => now()->subMinutes(11)]);
        $huerfana = $this->oc(['response_id' => null, 'created_at' => now()->subMinutes(4)]);
        $arrancando = $this->oc(['response_id' => null, 'created_at' => now()->subSeconds(90)]);
        $this->artisan('masume:recuperar-lecturas-ia')->assertFailed();
        foreach ([$c, $huerfana] as $lectura) {
            $this->assertSame('error', $lectura->fresh()->estado);
        }
        $this->assertSame('error', $s->fresh()->ia_estado);
        $this->assertSame('procesando', $arrancando->fresh()->estado);
        Http::assertNothingSent();
    }

    public function test_respeta_el_candado_de_otra_ejecucion(): void
    {
        $this->oc();
        $candado = Cache::lock('masume:recuperar-lecturas-ia', 900);
        $this->assertTrue($candado->get());
        $this->artisan('masume:recuperar-lecturas-ia')->expectsOutput('Otra recuperación sigue en curso.')->assertSuccessful();
        Http::assertNothingSent();
        $candado->release();
    }

    public function test_el_limite_se_aplica_a_ambas_bandejas_por_antiguedad(): void
    {
        $c = $this->oc();
        $s = $this->solicitud(['ia_inicio_at' => now()->subMinute()]);
        Http::fake(['*/responses/*' => Http::response($this->respuesta($this->datosSolicitud()))]);
        $this->artisan('masume:recuperar-lecturas-ia', ['--limite' => 1])->assertSuccessful();
        $this->assertSame('lista', $s->fresh()->ia_estado);
        $this->assertSame('procesando', $c->fresh()->estado);
        Http::assertSentCount(1);
        $this->artisan('masume:recuperar-lecturas-ia', ['--limite' => 0])->assertFailed();
    }

    public function test_aparta_el_costo_del_modelo_real_y_rechaza_otra_reserva(): void
    {
        config(['services.openai.tope_mensual_usd' => 0.105]);
        $c = LecturaOc::reservar(['pedido_id' => null, 'user_id' => User::factory()->create()->id,
            'archivo_hash' => 'prueba', 'modelo' => 'gpt-6-sol']);
        $this->assertSame('procesando', $c->estado);
        $this->assertNotNull(LecturaOc::sinPresupuesto('gpt-6-luna'));
        $this->expectException(\DomainException::class);
        LecturaOc::reservar(['pedido_id' => null, 'user_id' => $c->user_id, 'archivo_hash' => 'otro', 'modelo' => 'gpt-6-luna']);
    }
}

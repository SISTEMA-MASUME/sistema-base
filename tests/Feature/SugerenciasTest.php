<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SugerenciasTest extends TestCase
{
    use RefreshDatabase;

    private function partida(Cotizacion $cot, string $desc, array $extra = []): CotizacionPartida
    {
        return CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'descripcion' => $desc] + $extra);
    }

    public function test_requiere_sesion(): void
    {
        $c = Cliente::factory()->create();
        $this->getJson(route('clientes.sugerencias', $c))->assertUnauthorized();
        $this->getJson(route('sugerencias.global', ['q' => 'tornillo']))->assertUnauthorized();
    }

    public function test_cliente_solo_sus_partidas_sin_duplicados_y_la_mas_reciente(): void
    {
        $this->actingAs(User::factory()->create());
        $a = Cliente::factory()->create();
        $b = Cliente::factory()->create();
        $vieja = Cotizacion::factory()->create(['cliente_id' => $a->id, 'fecha' => '2026-01-10']);
        $nueva = Cotizacion::factory()->create(['cliente_id' => $a->id, 'fecha' => '2026-03-05']);
        $otra = Cotizacion::factory()->create(['cliente_id' => $b->id]);

        $this->partida($vieja, 'Tornillo hexagonal 1/2', ['precio' => 10]);
        $this->partida($nueva, 'TORNILLO  hexágonal 1/2', ['precio' => 12]);
        $this->partida($vieja, 'Llave española', ['precio' => 50]);
        $this->partida($otra, 'Cosa de otro cliente');

        $r = $this->getJson(route('clientes.sugerencias', $a))->assertOk();
        $r->assertJsonCount(2);
        $this->assertStringContainsString('private', $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', $r->headers->get('Cache-Control'));
        $this->assertEquals(12.0, $r->json('0.p'));
        $this->assertSame('05/03/2026', $r->json('0.fe'));
        $this->assertSame($nueva->folio, $r->json('0.f'));
        $this->assertNotContains('Cosa de otro cliente', array_column($r->json(), 'd'));
    }

    public function test_global_filtra_por_palabras_y_limita_a_8(): void
    {
        $this->actingAs(User::factory()->create());
        $cl = Cliente::factory()->create(['nombre' => 'ACME']);
        $cot = Cotizacion::factory()->create(['cliente_id' => $cl->id]);
        foreach (range(1, 12) as $n) {
            $this->partida($cot, "Broca acero rapido $n");
        }
        $this->partida($cot, 'Martillo de bola');

        $r = $this->getJson(route('sugerencias.global', ['q' => 'broca rapido']))->assertOk();
        $r->assertJsonCount(8);
        $this->assertSame('ACME', $r->json('0.cl'));
        $this->getJson(route('sugerencias.global', ['q' => 'bro']))->assertOk()->assertJsonCount(0);
        $this->getJson(route('sugerencias.global', ['q' => 'broca martillo']))->assertJsonCount(0);
    }

    public function test_rapido_con_3000_partidas(): void
    {
        $this->actingAs(User::factory()->create());
        $cl = Cliente::factory()->create();
        $cot = Cotizacion::factory()->create(['cliente_id' => $cl->id]);
        $filas = [];
        foreach (range(1, 3000) as $n) {
            $filas[] = ['cotizacion_id' => $cot->id, 'orden' => $n, 'descripcion' => "ARTICULO $n PARA PRUEBA", 'cantidad' => 1,
                'costo' => 10, 'utilidad_pct' => 30, 'precio' => 13, 'importe' => 13, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('cotizacion_partidas')->insert($lote);
        }

        $t = microtime(true);
        $this->getJson(route('clientes.sugerencias', $cl))->assertOk()->assertJsonCount(2000);
        $this->getJson(route('sugerencias.global', ['q' => 'articulo prueba']))->assertOk()->assertJsonCount(8);
        $this->assertLessThan(1.0, microtime(true) - $t);
    }
}

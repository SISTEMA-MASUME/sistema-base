<?php

namespace Tests\Feature;

use App\Enums\EstadoLoteSicar;
use App\Livewire\Pedidos\Ver;
use App\Livewire\Sicar\Alta;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\SicarArticulo;
use App\Models\SicarLote;
use App\Models\User;
use App\Support\AltaSicarPartidas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** P-176: se cambió una partida después de darla de alta en SICAR y nada lo avisaba ni dejaba mandarla. */
class AltaSicarPorPartidaTest extends TestCase
{
    use RefreshDatabase;

    private const H = ['Authorization' => 'Bearer secreto'];

    private User $vendedor;

    private Proveedor $magno;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sicar.token' => 'secreto']);
        $this->vendedor = User::factory()->create(['rol' => 'vendedor']);
        $this->magno = Proveedor::factory()->create(['nombre' => 'MAXIFERRO', 'sicar_pro_id' => 300]);
        foreach ([[1, '43187', 'BISAGRA RECTANGULAR 2"'], [2, '222 00162', 'REDUCCION BUSHING'], [3, '43713', 'PASADOR DE BARRA PLANA 2"']] as [$id, $clave, $d]) {
            SicarArticulo::create(['art_id' => $id, 'clave' => $clave, 'descripcion' => $d, 'unidad' => 'PZA', 'precio_compra' => 20,
                'precio1' => 30, 'status' => 1, 'clave_llave' => SicarArticulo::llave($clave), 'clave_prod_serv' => '31162400', 'sincronizado_at' => now()]);
        }
    }

    private function partida(Pedido $pedido, string $desc, int $artId, array $extra = []): PedidoPartida
    {
        return PedidoPartida::factory()->create(array_merge(['pedido_id' => $pedido->id, 'descripcion' => $desc, 'modelo' => null,
            'cantidad' => 1, 'costo' => 25, 'precio' => 32, 'proveedor_id' => $this->magno->id, 'sicar_art_id' => $artId], $extra));
    }

    /** Manda por la pantalla de Alta y simula que la PC lo guardó en SICAR. */
    private function mandarYAplicar(Pedido $pedido): SicarLote
    {
        Livewire::actingAs($this->vendedor)->test(Alta::class)->set('seleccion', [$pedido->id])->call('mandar')->assertSet('error', '');
        $lote = SicarLote::latest('id')->firstOrFail();
        $lote->update(['estado' => EstadoLoteSicar::Confirmado, 'confirmado_por' => $this->vendedor->id]);
        $this->postJson("/api/sicar/puente/lotes/{$lote->id}", ['estado' => 'aplicado', 'resultado' => ['articulos' => [], 'compras' => []]], self::H)->assertOk();

        return $lote->fresh();
    }

    public function test_cada_partida_queda_marcada_y_lo_que_cambia_despues_falta(): void
    {
        $pedido = Pedido::factory()->create(['folio' => 176, 'alta_sicar' => false]);
        $bisagra = $this->partida($pedido, 'BISAGRA RECTANGULAR 2"', 1);
        $reduccion = $this->partida($pedido, 'REDUCCION BUSHING', 2);

        $lote = $this->mandarYAplicar($pedido);
        $this->assertTrue($pedido->fresh()->alta_sicar);
        $this->assertSame($lote->id, $bisagra->fresh()->sicar_alta_lote_id);
        $this->assertArrayNotHasKey('firmas', $lote->datos);               // el contrato con la PC no cambia
        $this->assertArrayHasKey((string) $bisagra->id, $lote->firmas);
        $this->assertTrue(AltaSicarPartidas::faltantes($pedido->fresh())->isEmpty());

        // Después del alta: la reducción se cambia por el pasador y se agrega otra partida.
        $reduccion->update(['descripcion' => 'PASADOR DE BARRA PLANA 2"', 'sicar_art_id' => 3]);
        $extra = $this->partida($pedido, 'BISAGRA RECTANGULAR 2"', 1, ['cantidad' => 3]);
        $faltan = AltaSicarPartidas::faltantes($pedido->fresh());
        $this->assertEqualsCanonicalizing([$reduccion->id, $extra->id], $faltan->pluck('id')->all());
        $this->assertContains('Falta alta en SICAR', array_column($pedido->fresh()->etiquetas(), 'texto'));

        // La tarjeta lo dice en cada partida y lleva a darlo de alta.
        Livewire::actingAs($this->vendedor)->test(Ver::class, ['pedido' => $pedido->fresh()])
            ->assertSee('Faltan 2 partidas en SICAR')->assertSee('Dar de alta lo que falta')->assertSee('Falta en SICAR')
            ->assertSeeHtml('/sicar/alta?pedido='.$pedido->id);

        // En Alta en SICAR aparece el pedido ya dado de alta, solo con lo que falta, y se manda solo eso.
        Livewire::actingAs($this->vendedor)->test(Alta::class)->assertSee('P-176')->assertSee('Ya dado de alta · faltan 2 en SICAR');
        $lote2 = $this->mandarYAplicar($pedido);
        $renglones = collect($lote2->datos['compras'])->flatMap(fn ($c) => $c['renglones']);
        $this->assertEqualsCanonicalizing(['43713', '43187'], $renglones->pluck('clave')->all());
        $this->assertSame(3.0, (float) $renglones->firstWhere('clave', '43187')['cantidad']);   // solo la bisagra nueva, no la primera
        $this->assertTrue(AltaSicarPartidas::faltantes($pedido->fresh())->isEmpty());
        $this->assertNotContains('Falta alta en SICAR', array_column($pedido->fresh()->etiquetas(), 'texto'));
    }

    public function test_cambiar_la_partida_mientras_el_lote_espera_la_deja_faltando(): void
    {
        $pedido = Pedido::factory()->create(['folio' => 180]);
        $p = $this->partida($pedido, 'BISAGRA RECTANGULAR 2"', 1);
        Livewire::actingAs($this->vendedor)->test(Alta::class)->set('seleccion', [$pedido->id])->call('mandar');
        $lote = SicarLote::firstOrFail();
        $p->update(['cantidad' => 5]);   // se cambió después de mandarse: SICAR se queda con 1
        $lote->update(['estado' => EstadoLoteSicar::Confirmado]);
        $this->postJson("/api/sicar/puente/lotes/{$lote->id}", ['estado' => 'aplicado', 'resultado' => []], self::H)->assertOk();

        $this->assertSame([$p->id], AltaSicarPartidas::faltantes($pedido->fresh())->pluck('id')->all());
    }

    public function test_marcar_a_mano_lo_que_faltaba(): void
    {
        $pedido = Pedido::factory()->create(['folio' => 181, 'alta_sicar' => true]);
        $p = $this->partida($pedido, 'PASADOR DE BARRA PLANA 2"', 3);
        $this->assertTrue(AltaSicarPartidas::falta($p->fresh(), $pedido));

        Livewire::actingAs($this->vendedor)->test(Alta::class)->set('seleccion', [$pedido->id])->call('marcarAlta')
            ->assertSee('Se marcó 1 pedido');
        $this->assertFalse(AltaSicarPartidas::falta($p->fresh(), $pedido->fresh()));
        $this->assertDatabaseHas('pedido_actividades', ['pedido_id' => $pedido->id, 'tipo' => 'alta_sicar']);

        // Poner la etiqueta "Mat. dado de alta" en la tarjeta también da por dado de alta lo que tiene.
        $otro = Pedido::factory()->create(['folio' => 182, 'alta_sicar' => false]);
        $q = $this->partida($otro, 'BISAGRA RECTANGULAR 2"', 1);
        Livewire::actingAs($this->vendedor)->test(Ver::class, ['pedido' => $otro])->call('alternarBandera', 'alta_sicar');
        $this->assertNotNull($q->fresh()->sicar_alta_at);
        $this->assertTrue(AltaSicarPartidas::faltantes($otro->fresh())->isEmpty());
    }

    public function test_respaldo_de_un_lote_viejo_detecta_la_partida_cambiada(): void
    {
        // Como el lote 76 real: se mandó la reducción y después la partida se cambió por el pasador.
        $pedido = Pedido::factory()->create(['folio' => 176, 'alta_sicar' => true]);
        $bisagra = $this->partida($pedido, 'BISAGRA RECTANGULAR 2"', 1);
        $pasador = $this->partida($pedido, 'PASADOR DE BARRA PLANA 2"', 3);
        $lote = SicarLote::create(['estado' => EstadoLoteSicar::Aplicado, 'aplicado_at' => now()->subDay(), 'datos' => [
            'pedidos' => [['id' => $pedido->id, 'folio' => 'P-176']], 'articulos' => [], 'claves_sat' => [],
            'compras' => [['sicar_pro_id' => 300, 'proveedor' => 'MAXIFERRO', 'renglones' => [
                ['clave' => '43187', 'art_id' => 1, 'descripcion' => 'BISAGRA', 'cantidad' => 1, 'costo' => 25, 'pedidos' => ['P-176']],
                ['clave' => '222 00162', 'art_id' => 2, 'descripcion' => 'REDUCCION BUSHING', 'cantidad' => 1, 'costo' => 25, 'pedidos' => ['P-176']],
            ]]],
        ]]);
        // Otro pedido del mismo lote con una partida que tampoco viene en los renglones: Daniel pidió no revisarlo.
        $otro = Pedido::factory()->create(['folio' => 150, 'alta_sicar' => true]);
        $noRevisada = $this->partida($otro, 'PASADOR DE BARRA PLANA 2"', 3);
        $lote->pedidos()->attach([$pedido->id, $otro->id]);
        // Pedido marcado a mano sin lote: todo lo que tiene se da por dado de alta.
        $manual = Pedido::factory()->create(['folio' => 90, 'alta_sicar' => true]);
        $viejo = $this->partida($manual, 'BISAGRA RECTANGULAR 2"', 1);
        $sinAlta = Pedido::factory()->create(['folio' => 91, 'alta_sicar' => false]);
        $nueva = $this->partida($sinAlta, 'BISAGRA RECTANGULAR 2"', 1);

        AltaSicarPartidas::respaldar();

        $this->assertSame($lote->id, $bisagra->fresh()->sicar_alta_lote_id);
        $this->assertNull($pasador->fresh()->sicar_alta_at);
        $this->assertSame([$pasador->id], AltaSicarPartidas::faltantes($pedido->fresh())->pluck('id')->all());
        $this->assertNotNull($viejo->fresh()->sicar_alta_at);
        $this->assertTrue(AltaSicarPartidas::faltantes($manual->fresh())->isEmpty());
        $this->assertNotNull($noRevisada->fresh()->sicar_alta_at);
        $this->assertNull($noRevisada->fresh()->sicar_alta_lote_id);
        $this->assertNull($nueva->fresh()->sicar_alta_at);

        // Cada partida dice cómo está en SICAR.
        Livewire::actingAs($this->vendedor)->test(Ver::class, ['pedido' => $pedido->fresh()])
            ->assertSeeHtml('data-en-sicar')->assertSeeHtml('data-falta-sicar')->assertSee('✓ En SICAR')->assertSee('Falta en SICAR');
        Livewire::actingAs($this->vendedor)->test(Ver::class, ['pedido' => $sinAlta])
            ->assertSee('Sin alta en SICAR')->assertDontSeeHtml('data-en-sicar')->assertDontSee('Faltan');
    }

    public function test_cambiar_el_producto_de_una_partida_le_quita_el_articulo_de_sicar(): void
    {
        // Lo que pasó de verdad: la MISMA partida de la reducción se editó para que dijera pasador.
        $pedido = Pedido::factory()->create(['folio' => 177]);
        $p = $this->partida($pedido, 'REDUCCION BUSHING', 2);
        $this->mandarYAplicar($pedido);
        $this->assertNotNull($p->fresh()->sicar_alta_at);

        $p->fresh()->update(['descripcion' => 'PASADOR DE BARRA PLANA 2" ACABADO CROMO HERMEX 43713']);

        $this->assertNull($p->fresh()->sicar_art_id);
        $this->assertSame([$p->id], AltaSicarPartidas::faltantes($pedido->fresh())->pluck('id')->all());
        // Cambiar otra cosa (estado, facturada) no toca la decisión.
        $q = $this->partida($pedido, 'BISAGRA RECTANGULAR 2"', 1);
        $q->update(['facturada' => true, 'precio' => 40]);
        $this->assertSame(1, (int) $q->fresh()->sicar_art_id);
    }


}

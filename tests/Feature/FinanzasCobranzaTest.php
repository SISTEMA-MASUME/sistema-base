<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Cobranza;
use App\Livewire\Finanzas\Ventas;
use App\Models\Cliente;
use App\Models\User;
use App\Models\Venta;
use App\Support\CalculoNomina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class FinanzasCobranzaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function venta(array $datos): Venta
    {
        static $n = 0;
        $n++;

        return Venta::create($datos + ['tipo' => 'factura', 'folio' => "5-{$n}", 'cliente_nombre' => 'X', 'subtotal' => 100, 'iva' => 16, 'total' => 116, 'estatus' => 'pendiente']);
    }

    public function test_cartera_vencida_esperada_y_por_cliente(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $acme = Cliente::factory()->create(['nombre' => 'ACME MEXICO', 'dias_credito' => 45]);
        $parques = Cliente::factory()->create(['nombre' => 'PARQUES MANTENIMIENTO', 'dias_credito' => 30]);

        // ACME: 45 días → la del 1-ago vence el 15-sep (vencida 16 días); la del 31-ago vence el 15-oct (14 días).
        $this->venta(['cliente_id' => $acme->id, 'cliente_nombre' => 'ACME MEXICO', 'fecha' => '2026-08-01', 'total' => 1000]);
        $this->venta(['cliente_id' => $acme->id, 'cliente_nombre' => 'ACME MEXICO', 'fecha' => '2026-08-31', 'total' => 500]);
        // PARQUES: 30 días → la del 5-sep vence el 5-oct (7 días).
        $this->venta(['cliente_id' => $parques->id, 'cliente_nombre' => 'PARQUES MANTENIMIENTO', 'fecha' => '2026-09-05', 'total' => 300]);
        // Sin cliente ligado: 30 días.
        $this->venta(['cliente_nombre' => 'CLIENTE SUELTO', 'fecha' => '2026-08-15', 'total' => 200]);
        // No cuentan: pagada y cancelada.
        $this->venta(['cliente_id' => $parques->id, 'cliente_nombre' => 'PARQUES MANTENIMIENTO', 'fecha' => '2026-08-01', 'total' => 9999, 'estatus' => 'pagada', 'fecha_pago' => '2026-08-20']);
        $this->venta(['cliente_id' => $parques->id, 'cliente_nombre' => 'PARQUES MANTENIMIENTO', 'fecha' => '2026-08-01', 'total' => 8888, 'estatus' => 'cancelada']);

        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $c = Livewire::test(Cobranza::class);
        $c->assertViewHas('cartera', 2000.0)
            ->assertViewHas('vencida', 1200.0)       // 1000 ACME + 200 suelto (venció 14-sep)
            ->assertViewHas('siete', 300.0)
            ->assertViewHas('catorce', 800.0)
            ->assertViewHas('pendientes', 4);
        $clientes = $c->viewData('clientes');
        $this->assertSame('ACME MEXICO', $clientes[0]['nombre']);
        $this->assertSame(1500.0, $clientes[0]['total']);
        $this->assertSame(1000.0, $clientes[0]['vencido']);
        $this->assertSame(16, $clientes[0]['mas_atrasada']);
        $this->assertSame(1000.0, $c->viewData('llamar')[0]['monto']);
        $c->assertSee('ACME MEXICO')->assertSee('CLIENTE SUELTO');
    }

    public function test_la_migracion_da_por_cobradas_las_de_anios_anteriores(): void
    {
        $vieja = $this->venta(['fecha' => '2024-05-10']);
        $saldada = $this->venta(['fecha' => '2026-03-02', 'saldo_sicar' => 0]);
        $debe = $this->venta(['fecha' => '2026-03-02', 'saldo_sicar' => 116]);
        $reciente = $this->venta(['fecha' => '2026-09-29', 'saldo_sicar' => 0]);   // después del inicio de comisiones: no

        (require database_path('migrations/2026_11_02_000006_cobradas_anteriores_a_2026.php'))->up();

        $this->assertSame('pagada', $vieja->fresh()->estatus);
        $this->assertSame('2024-05-10', $vieja->fresh()->fecha_pago->toDateString());
        $this->assertSame('pagada', $saldada->fresh()->estatus);
        $this->assertSame('pendiente', $debe->fresh()->estatus);
        $this->assertSame('pendiente', $reciente->fresh()->estatus);
        $this->assertSame(0, CalculoNomina::cobros(2026, 40)->count());   // nada entra a comisiones
    }

    public function test_aviso_de_las_que_sicar_ya_no_debe_y_marcarlas_cobradas(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $v = $this->venta(['fecha' => '2026-09-29', 'saldo_sicar' => 0]);
        $this->venta(['fecha' => '2026-09-29', 'saldo_sicar' => 50]);
        $admin = User::factory()->create(['rol' => 'admin']);

        Livewire::actingAs($admin)->test(Cobranza::class)
            ->assertSee('SICAR ya no las debe (1)')
            ->call('marcarSaldadas')->assertHasErrors('fechaSaldadas')
            ->set('fechaSaldadas', '2026-09-30')->call('marcarSaldadas')->assertHasNoErrors()
            ->assertSee('1 venta quedó cobrada')->assertDontSee('SICAR ya no las debe');
        $this->assertSame('2026-09-30', $v->fresh()->fecha_pago->toDateString());
    }

    public function test_grafica_por_cliente_abre_su_ventana_y_buscador_de_folio(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $c = Cliente::factory()->create(['nombre' => 'ACME MEXICO', 'dias_credito' => 30]);
        $v = $this->venta(['cliente_id' => $c->id, 'cliente_nombre' => 'ACME MEXICO', 'fecha' => '2026-07-01', 'folio' => '5-5072']);
        $this->venta(['cliente_id' => $c->id, 'cliente_nombre' => 'ACME MEXICO', 'fecha' => '2026-09-20', 'folio' => '5-5080']);
        $this->venta(['cliente_nombre' => 'OTRO', 'fecha' => '2026-09-20', 'folio' => '5-5081']);
        $admin = User::factory()->create(['rol' => 'admin']);

        Livewire::actingAs($admin)->test(Cobranza::class)
            ->assertSeeHtml('aria-label="ACME MEXICO: debe $232.00')
            ->call('abrirCliente', 'c'.$c->id)->assertSee('el más atrasado')->assertSee('5-5080')
            ->call('cerrarCliente')->assertSet('clienteAbierto', '')
            ->set('folio', '5-5072')->call('buscarFolio')
            ->assertNoRedirect()->assertSet('ventaAbierta', $v->id)->assertSee('Factura 5-5072')->assertSee('Editar en Ventas')
            ->call('cerrarVenta')->assertSet('ventaAbierta', null)
            ->call('abrirCliente', 'c'.$c->id)->call('verVenta', $v->id)->assertSet('clienteAbierto', 'c'.$c->id)->assertSee('Factura 5-5072');

        Livewire::actingAs($admin)->test(Cobranza::class)
            ->set('folio', '508')->call('buscarFolio')->assertSee('5-5080')->assertSee('5-5081')
            ->set('folio', 'ZZZ')->call('buscarFolio')->assertSee('No encontré');

        // El enlace abre el detalle en Ventas.
        Livewire::actingAs($admin)->withQueryParams(['ver' => $v->id, 'anio' => 'todos'])->test(Ventas::class)
            ->assertSet('detalleId', $v->id);
    }

    public function test_permiso_de_cobranza(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $this->get('/finanzas/cobranza')->assertForbidden();

        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => ['cobranza']]));
        $this->get('/finanzas/cobranza')->assertOk()->assertSee('A quién llamar hoy');
    }

}

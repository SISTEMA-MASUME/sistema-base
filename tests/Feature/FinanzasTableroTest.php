<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Tablero;
use App\Models\Cliente;
use App\Models\Gasto;
use App\Models\Nomina;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class FinanzasTableroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function venta(array $d): Venta
    {
        static $n = 0;
        $n++;

        return Venta::create($d + ['tipo' => 'factura', 'folio' => "9-{$n}", 'cliente_nombre' => 'X', 'iva' => 0, 'estatus' => 'pendiente']);
    }

    private function gasto(string $fecha, float $monto, string $categoria = 'GASOLINA', string $de = 'masume', string $tipo = 'gasto'): Gasto
    {
        return Gasto::create(['fecha' => $fecha, 'monto' => $monto, 'categoria' => $categoria, 'de' => $de, 'tipo' => $tipo]);
    }

    private function nomina(string $tipo, string $estatus, string $hasta, float $comision): void
    {
        $n = Nomina::create(['tipo' => $tipo, 'anio' => 2026, 'semana' => 1, 'desde' => $hasta, 'hasta' => $hasta, 'estatus' => $estatus]);
        $n->lineas()->create(['nombre' => 'Rosa', 'comision' => $comision, 'total' => $comision]);
    }

    private function datos(): Cliente
    {
        $beta = Cliente::factory()->create(['nombre' => 'BETA SA']);
        $this->venta(['cliente_nombre' => 'ACME', 'fecha' => '2026-01-10', 'subtotal' => 1000, 'costo' => 600, 'total' => 1160, 'estatus' => 'pagada', 'fecha_pago' => '2026-01-20']);
        $this->venta(['cliente_nombre' => 'ACME', 'fecha' => '2026-01-20', 'subtotal' => 500, 'costo' => 300, 'total' => 580]);
        $this->venta(['cliente_id' => $beta->id, 'cliente_nombre' => 'BETA SA', 'fecha' => '2026-02-05', 'subtotal' => 2000, 'costo' => 1500, 'total' => 2320, 'estatus' => 'pagada', 'fecha_pago' => '2026-02-25']);
        $this->venta(['cliente_nombre' => 'ACME', 'fecha' => '2026-01-01', 'subtotal' => 9999, 'costo' => 1, 'total' => 9999, 'estatus' => 'cancelada']);
        $this->venta(['cliente_nombre' => 'ACME', 'fecha' => '2025-01-15', 'subtotal' => 800, 'costo' => 500, 'total' => 928, 'estatus' => 'pagada', 'fecha_pago' => '2025-01-30']);

        $this->gasto('2026-01-12', 200);
        $this->gasto('2026-01-25', 100, 'NOMINA');   // domingo: cuenta en la semana del sábado 31
        $this->gasto('2026-02-10', 50);
        $this->gasto('2026-01-12', 777, 'GASOLINA', 'personal');
        $this->gasto('2026-01-12', 888, 'GASOLINA', 'masume', 'movimiento');
        $this->gasto('2025-01-12', 100);

        $this->nomina('semanal', 'pagada', '2026-01-17', 40);
        $this->nomina('semanal', 'borrador', '2026-01-24', 99);
        $this->nomina('reparto', 'pagada', '2026-01-31', 55);
        $this->nomina('semanal', 'pagada', '2026-03-07', 10);

        return $beta;
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'admin']);
    }

    public function test_permisos(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']))->get('/finanzas/tablero')->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => ['tablero']]))->get('/finanzas/tablero')->assertOk()->assertSee('Dashboard');
        $this->actingAs($this->admin())->get('/finanzas/tablero')->assertOk();
        $this->assertSame('/finanzas/tablero', route('finanzas.tablero', [], false));
    }

    public function test_metricas_del_anio(): void
    {
        $this->datos();
        $this->actingAs($this->admin());
        $r = Livewire::test(Tablero::class)->set('anio', 2026)->viewData('resumen');

        $this->assertSame(3500.0, $r['venta']);
        $this->assertSame(2400.0, $r['compra']);
        $this->assertSame(1100.0, $r['bruta']);
        $this->assertSame(350.0, $r['gastos']);   // sin personal ni movimientos
        $this->assertSame(750.0, $r['neta']);
        $this->assertSame(21.4, $r['margen_neto']);
        $this->assertSame(66.7, $r['cobradas_pct']);
        $this->assertSame(10.0, $r['gastos_venta']);
        $this->assertSame(3, $r['documentos']);   // la cancelada no cuenta
        $this->assertSame(2, $r['cobrados']);
        $this->assertSame(1, $r['pendientes']);
        $this->assertSame(580.0, $r['monto_pendiente']);
        $this->assertSame(1166.67, $r['ticket']);
        $this->assertSame(15.0, $r['dias_cobro']);
        $this->assertSame(2, $r['clientes']);
        $this->assertSame(50.0, $r['comisiones']);   // 40 + 10; ni borrador ni reparto
        $this->assertSame(116.67, $r['costo_documento']);
        $this->assertSame(214.3, $r['roi']);
    }

    public function test_filtro_de_mes(): void
    {
        $this->datos();
        $this->actingAs($this->admin());
        $c = Livewire::test(Tablero::class)->set('anio', 2026)->set('mes', '1');
        $r = $c->viewData('resumen');

        $this->assertSame(1500.0, $r['venta']);
        $this->assertSame(600.0, $r['bruta']);
        $this->assertSame(300.0, $r['gastos']);
        $this->assertSame(300.0, $r['neta']);
        $this->assertSame(2, $r['documentos']);
        $this->assertSame(10.0, $r['dias_cobro']);
        $this->assertSame(1, $r['clientes']);
        $this->assertSame(40.0, $r['comisiones']);
        $c->assertSee('Enero 2026');
    }

    public function test_top_clientes_y_concentracion(): void
    {
        $beta = $this->datos();
        $this->actingAs($this->admin());
        $top = Livewire::test(Tablero::class)->set('anio', 2026)->viewData('top');

        $this->assertCount(2, $top);
        $this->assertSame('BETA SA', $top[0]['nombre']);
        $this->assertSame(2000.0, $top[0]['venta']);
        $this->assertSame(500.0, $top[0]['utilidad']);
        $this->assertSame(25.0, $top[0]['margen']);
        $this->assertSame(57.1, $top[0]['concentracion']);
        $this->assertSame('ACME', $top[1]['nombre']);
        $this->assertSame(1500.0, $top[1]['venta']);
        $this->assertSame(2, $top[1]['documentos']);
        $this->assertSame(40.0, $top[1]['margen']);
        $this->assertSame(42.9, $top[1]['concentracion']);
    }

    public function test_desglose_mensual_con_total(): void
    {
        $this->datos();
        $this->actingAs($this->admin());
        $c = Livewire::test(Tablero::class)->set('anio', 2026);
        $filas = $c->viewData('mensual');

        $this->assertCount(12, $filas);
        $this->assertSame(['venta' => 1500.0, 'compra' => 900.0, 'utilidad' => 600.0, 'margen' => 40.0, 'gastos' => 300.0, 'neta' => 300.0], array_intersect_key($filas[0], array_flip(['venta', 'compra', 'utilidad', 'margen', 'gastos', 'neta'])));
        $this->assertSame(2000.0, $filas[1]['venta']);
        $this->assertSame(450.0, $filas[1]['neta']);
        $this->assertSame(0.0, $filas[5]['venta']);
        $this->assertNull($filas[5]['margen']);
        $total = $c->viewData('totalMensual');
        $this->assertSame(3500.0, $total['venta']);
        $this->assertSame(750.0, $total['neta']);
        $this->assertSame([800.0, 0.0], [$c->viewData('ventaAnterior')[0], $c->viewData('ventaAnterior')[1]]);
    }

    public function test_comparativo_con_el_anio_anterior(): void
    {
        $this->datos();
        $this->actingAs($this->admin());
        $c = Livewire::test(Tablero::class)->set('anio', 2026)->viewData('comparativo');

        $this->assertSame(337.5, $c['venta']['cambio']);
        $this->assertTrue($c['venta']['mejora']);
        $this->assertSame(266.7, $c['bruta']['cambio']);
        $this->assertSame(250.0, $c['gastos']['cambio']);
        $this->assertFalse($c['gastos']['mejora']);   // gastar más es empeorar
        $this->assertSame(200.0, $c['neta']['anterior']);
        $this->assertSame(275.0, $c['neta']['cambio']);

        // Enero contra enero de 2025.
        $e = Livewire::test(Tablero::class)->set('anio', 2026)->set('mes', '1')->viewData('comparativo');
        $this->assertSame(800.0, $e['venta']['anterior']);
        $this->assertSame(87.5, $e['venta']['cambio']);

        // Sin base el porcentaje no se calcula.
        $f = Livewire::test(Tablero::class)->set('anio', 2026)->set('mes', '2')->viewData('comparativo');
        $this->assertNull($f['venta']['cambio']);
        $this->assertTrue($f['venta']['mejora']);
    }

    public function test_semanas_y_categorias(): void
    {
        $this->datos();
        $this->actingAs($this->admin());
        $c = Livewire::test(Tablero::class)->set('anio', 2026)->set('mes', '1');
        $s = $c->viewData('semanas');

        $this->assertCount(5, $s);   // sábados 3, 10, 17, 24 y 31
        $this->assertSame('2026-01-10', $s[1]['sabado']->toDateString());
        $this->assertSame(1000.0, $s[1]['venta']);
        $this->assertSame(400.0, $s[1]['utilidad']);
        $this->assertSame(200.0, $s[2]['gastos']);    // gasto del lunes 12 → sábado 17
        $this->assertSame(500.0, $s[3]['venta']);
        $this->assertSame(100.0, $s[4]['gastos']);    // domingo 25 → semana del sábado 31
        $this->assertSame(0.0, $s[0]['venta']);       // la cancelada no cuenta

        session()->forget('tablero.vista');   // el Dashboard recuerda la última vista (aquí se quiere el año)
        $todo = Livewire::test(Tablero::class)->set('anio', 2026)->viewData('semanas');
        $this->assertCount(12, $todo);
        $this->assertSame('2026-10-03', $todo[11]['sabado']->toDateString());

        $cat = Livewire::test(Tablero::class)->set('anio', 2026)->viewData('categorias');
        $this->assertSame([['nombre' => 'GASOLINA', 'monto' => 250.0], ['nombre' => 'NOMINA', 'monto' => 100.0]], $cat);
    }

    public function test_categorias_top_ocho_y_otras(): void
    {
        foreach (range(1, 10) as $i) {
            $this->gasto('2026-03-01', $i * 10, "CAT{$i}");
        }
        $this->actingAs($this->admin());
        $cat = Livewire::test(Tablero::class)->set('anio', 2026)->viewData('categorias');

        $this->assertCount(9, $cat);
        $this->assertSame('CAT10', $cat[0]['nombre']);
        $this->assertSame('OTRAS', $cat[8]['nombre']);
        $this->assertSame(30.0, $cat[8]['monto']);   // CAT2 + CAT1
    }

    public function test_nomina_por_empleado_y_mes(): void
    {
        $rosa = User::factory()->create(['name' => 'Rosa Gómez']);
        $alberto = User::factory()->create(['name' => 'Alberto Pérez']);
        $semana = 30;
        $pagar = function (string $hasta, array $lineas, string $estatus = 'pagada', string $tipo = 'semanal') use (&$semana) {
            $n = Nomina::create(['tipo' => $tipo, 'anio' => 2026, 'semana' => $semana++, 'mes' => 9, 'desde' => $hasta, 'hasta' => $hasta, 'estatus' => $estatus]);
            foreach ($lineas as $l) {
                $n->lineas()->create($l);
            }
        };
        $pagar('2026-08-29', [['user_id' => $rosa->id, 'nombre' => 'Rosa Gómez', 'total' => 3000],
            ['user_id' => $alberto->id, 'nombre' => 'Alberto Pérez', 'total' => 2500, 'descuento_prestamo' => 500]]);
        $pagar('2026-09-05', [['user_id' => $rosa->id, 'nombre' => 'Rosa Gómez', 'total' => 1000],
            ['user_id' => null, 'nombre' => 'HUGO', 'total' => 2000]]);
        $pagar('2026-09-12', [['user_id' => $rosa->id, 'nombre' => 'Rosa', 'total' => 999]], 'borrador');            // sin pagar: no cuenta
        $pagar('2026-09-30', [['user_id' => $alberto->id, 'nombre' => 'Ernesto', 'total' => 5000]], 'pagada', 'reparto'); // reparto: no cuenta
        $pagar('2025-12-27', [['user_id' => $rosa->id, 'nombre' => 'Rosa', 'total' => 777]]);                         // otro año

        $r = (new \App\Support\Tablero)->nominaPorEmpleado(2026);
        $this->assertSame(['Rosa', 'Alberto', 'Hugo'], array_column($r['empleados'], 'nombre'));
        $this->assertSame(3000.0, $r['empleados'][0]['meses'][7]);
        $this->assertSame(1000.0, $r['empleados'][0]['meses'][8]);
        $this->assertSame(3000.0, $r['empleados'][1]['meses'][7]);   // 2,500 pagados + 500 de préstamo
        $this->assertSame(2000.0, $r['empleados'][2]['total']);
        $this->assertSame(6000.0, $r['total_mes'][7]);
        $this->assertSame(9000.0, $r['total']);
        $this->assertSame('#2a78d6', $r['empleados'][0]['color']);

        Livewire::actingAs($this->admin())->test(Tablero::class)
            ->assertSee('Nómina por empleado')->assertSee('$9,000.00')->assertSee('Hugo');
    }

    public function test_compra_de_vehiculo_y_reparto_no_cuentan_como_gasto_operativo(): void
    {
        $this->gasto('2026-03-10', 1000, 'GASOLINA');
        $this->gasto('2026-03-11', 170000, 'COMPRA DE VEHICULOS Y EQUIPO');
        $this->gasto('2026-03-12', 5000, 'REPARTO SOCIO');
        $t = new \App\Support\Tablero;
        $this->assertSame(1000.0, $t->mensual(2026)['filas'][2]['gastos']);
        $this->assertSame(['GASOLINA'], array_column($t->gastosPorCategoria(2026, 3), 'nombre'));
    }

    public function test_la_vista_pinta_los_svg_y_sin_datos_no_truena(): void
    {
        $this->datos();
        $this->actingAs($this->admin());
        $c = Livewire::test(Tablero::class)->set('anio', 2026);
        $c->assertSeeHtml('viewBox="0 0 720 300"')->assertSeeHtml('<title>Ene · Venta: $1,500.00</title>')
            ->assertSeeHtml('stroke-dasharray="5 4"')->assertSee('$1.5k', false)->assertSee('Gastos por categoría')
            ->assertSee('Top 10 clientes')->assertSee('Margen de utilidad neta')->assertSeeHtml('<div class="sr-only">');
        $this->assertGreaterThanOrEqual(3, substr_count($c->html(), '<svg viewBox'));

        Venta::query()->delete();
        Gasto::query()->delete();
        Livewire::test(Tablero::class)->set('anio', 2026)->assertOk()->assertSee('No hay ventas ni gastos');
    }

    public function test_corte_por_semana_contra_la_anterior_y_flechas(): void
    {
        $this->datos();
        $this->actingAs($this->admin());
        // Semana del lunes 12 al sábado 17 de enero (el domingo 11 cuenta para esta semana).
        $this->venta(['cliente_nombre' => 'DELTA', 'fecha' => '2026-01-11', 'subtotal' => 300, 'costo' => 100, 'total' => 348, 'estatus' => 'pagada', 'fecha_pago' => '2026-01-16']);
        $c = Livewire::withQueryParams(['vista' => 'semana', 'semana' => '2026-01-14'])->test(Tablero::class);

        $corte = $c->viewData('corte');
        $this->assertSame([300.0, 100.0, 200.0, 200.0, 0.0, 348.0, 40.0, 1],
            [$corte['venta'], $corte['compra'], $corte['bruta'], $corte['gastos'], $corte['neta'], $corte['cobrado'], $corte['comisiones'], $corte['documentos']]);
        $this->assertSame(1000.0, $c->viewData('corteAnterior')['venta']);   // semana del 4 al 10 de enero
        $this->assertSame('2026-01-17', $c->viewData('p')['hasta']->toDateString());
        $this->assertCount(12, $c->viewData('tendencia'));
        $this->assertSame('DELTA', $c->viewData('topSemana')[0]['nombre']);
        $c->assertSee('Semana 3 de 2026')->assertSee('▼ 70% vs la semana anterior')->assertSee('Top clientes de la semana')
            ->assertSet('mes', '1');   // lo de abajo muestra enero

        $c->call('mover', -1)->assertSet('semana', '2026-01-10');
        $this->assertSame(1000.0, $c->viewData('corte')['venta']);

        // Mes y año desde los botones; el mes recuerda la vista.
        $c->call('verMes')->assertSet('vista', 'mes')->assertSet('mes', '1');
        $this->assertSame(1800.0, $c->viewData('corte')['venta']);
        $this->assertSame(800.0, $c->viewData('corteAnterior')['venta']);   // enero 2025
        $c->call('mover', 1)->assertSet('mes', '2')->call('verAnio')->assertSet('mes', '')->assertSee('Año 2026');
        Livewire::withQueryParams([])->test(Tablero::class)->assertSet('vista', 'anio');
        Livewire::withQueryParams([])->test(Tablero::class)->call('verMes')->assertSet('mes', '10');
        Livewire::withQueryParams([])->test(Tablero::class)->assertSet('vista', 'mes')->assertSet('mes', '10');
    }
}

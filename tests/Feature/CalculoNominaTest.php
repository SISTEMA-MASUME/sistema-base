<?php

namespace Tests\Feature;

use App\Models\Gasto;
use App\Models\Nomina;
use App\Models\Prestamo;
use App\Models\User;
use App\Models\Venta;
use App\Support\CalculoNomina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculoNominaTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function cobrada(string $fechaPago, float $venta, float $costo, array $extra = []): Venta
    {
        $this->n++;

        return Venta::create($extra + ['tipo' => 'factura', 'folio' => "5-{$this->n}", 'cliente_nombre' => 'X', 'fecha' => '2026-09-01',
            'subtotal' => $venta, 'costo' => $costo, 'iva' => 0, 'total' => $venta, 'estatus' => 'pagada', 'fecha_pago' => $fechaPago]);
    }

    private function empleados(): array
    {
        return [
            User::factory()->create(['name' => 'Alberto', 'en_nomina' => true, 'sueldo_base' => 3000, 'comision_pct' => 45]),
            User::factory()->create(['name' => 'Rosa Ruiz', 'en_nomina' => true, 'sueldo_base' => 2400, 'comision_pct' => 35]),
            User::factory()->create(['name' => 'Ernesto Pérez', 'en_nomina' => true, 'sueldo_base' => 4000, 'comision_pct' => 20]),
            User::factory()->create(['name' => 'Hugo', 'en_nomina' => true, 'sueldo_base' => 2500, 'comision_pct' => 0]),
        ];
    }

    public function test_comision_de_la_semana_lunes_a_sabado_y_reparto_45_35_20(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        [$alberto, $rosa, $ernesto, $hugo] = $this->empleados();
        // Semana 40 de 2026: lunes 28-sep a sábado 3-oct (el domingo 27 cuenta aquí).
        $this->cobrada('2026-09-27', 120, 100);     // utilidad 20 → comisión 2 (el ejemplo de Daniel)
        $this->cobrada('2026-10-03', 10000, 8000);  // 2,000
        $this->cobrada('2026-10-04', 5000, 1000);   // domingo: semana 41
        $this->cobrada('2026-09-20', 5000, 1000);   // antes de que el sistema comisione (ya se pagó con el Sheets)
        $this->cobrada('2026-10-01', 5000, 1000, ['estatus' => 'cancelada']);

        $n = CalculoNomina::semana(2026, 40);
        $this->assertSame('2020.00', $n->utilidad);
        $this->assertSame('202.00', $n->bolsa);
        $l = $n->lineas->keyBy('user_id');
        $this->assertSame('90.90', $l[$alberto->id]->comision);
        $this->assertSame('70.70', $l[$rosa->id]->comision);
        $this->assertSame('40.40', $l[$ernesto->id]->comision);
        $this->assertSame('2500.00', $l[$hugo->id]->total);
        $this->assertSame('3090.90', $l[$alberto->id]->total);
    }

    public function test_registrar_cierra_la_semana_y_un_cobro_tardio_entra_en_la_siguiente(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        [$alberto] = $this->empleados();
        $this->cobrada('2026-09-29', 1000, 0);
        $n40 = CalculoNomina::semana(2026, 40);
        CalculoNomina::registrar($n40);
        $this->assertTrue($n40->fresh()->pagada());
        $this->assertSame(1, $n40->fresh()->ventas()->count());

        // Ya pagada: cambiar el costo no mueve lo pagado.
        Venta::first()->update(['costo' => 900]);
        $this->assertSame('100.00', $n40->fresh()->bolsa);

        // Un complemento que llegó tarde con fecha de la semana 40 entra en la 41.
        $this->cobrada('2026-10-02', 2000, 0);
        $this->cobrada('2026-10-06', 1000, 0);
        $this->assertSame('300.00', CalculoNomina::semana(2026, 41)->bolsa);

        // Reabrir suelta las ventas.
        CalculoNomina::reabrir($n40->fresh());
        $this->assertFalse($n40->fresh()->pagada());
        $this->assertSame(0, Venta::whereNotNull('nomina_id')->count());
    }

    public function test_comision_a_mano_y_bonos(): void
    {
        [$alberto] = $this->empleados();
        $this->cobrada('2026-09-29', 1000, 0);
        $n = CalculoNomina::semana(2026, 40);
        $l = $n->lineas()->where('user_id', $alberto->id)->first();
        $l->update(['comision' => 2300, 'comision_manual' => true, 'ajuste' => -500, 'notas' => 'guantes']);
        CalculoNomina::recalcular($n);
        $this->assertSame('4800.00', $l->fresh()->total);   // 3000 + 2300 − 500
    }

    public function test_prestamo_se_descuenta_y_se_salda(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        [$alberto] = $this->empleados();
        $p = Prestamo::create(['user_id' => $alberto->id, 'nombre' => 'Alberto', 'monto' => 700, 'fecha' => '2026-09-01', 'descuento_semanal' => 500]);
        $n = CalculoNomina::semana(2026, 40);
        $this->assertSame('500.00', $n->lineas()->where('user_id', $alberto->id)->value('descuento_prestamo'));
        CalculoNomina::registrar($n);
        $this->assertSame(200.0, $p->fresh()->saldo());

        $n41 = CalculoNomina::semana(2026, 41);
        $this->assertSame('200.00', $n41->lineas()->where('user_id', $alberto->id)->value('descuento_prestamo'));   // no pasa del saldo
        CalculoNomina::registrar($n41);
        $this->assertNotNull($p->fresh()->saldado_at);

        CalculoNomina::reabrir($n41->fresh());
        $this->assertNull($p->fresh()->saldado_at);
        $this->assertSame(200.0, $p->fresh()->saldo());
    }

    public function test_el_pago_queda_como_gasto_y_el_reparto_toma_los_gastos_del_mes(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $this->empleados();
        $this->cobrada('2026-09-29', 1000, 0);
        $n = CalculoNomina::semana(2026, 40);
        CalculoNomina::registrar($n);
        $g = Gasto::sole();
        $this->assertSame('NOMINA', $g->categoria);
        $this->assertSame('2026-10-03', $g->fecha->toDateString());
        $this->assertSame((string) number_format($n->fresh()->total(), 2, '.', ''), $g->monto);
        CalculoNomina::reabrir($n->fresh());
        $this->assertSame(0, Gasto::withTrashed()->count());

        Gasto::create(['fecha' => '2026-08-10', 'monto' => 6000, 'de' => 'masume', 'categoria' => 'gasolina']);
        Gasto::create(['fecha' => '2026-08-11', 'monto' => 999, 'de' => 'personal']);
        Gasto::create(['fecha' => '2026-08-12', 'monto' => 888, 'de' => 'masume', 'tipo' => 'movimiento']);
        Gasto::create(['fecha' => '2026-08-13', 'monto' => 777, 'de' => 'masume', 'categoria' => 'REPARTO SOCIO']);
        $this->cobrada('2026-08-05', 100000, 79000);
        $this->assertSame('6000.00', CalculoNomina::reparto(2026, 8)->gastos);
        $this->assertSame('3000.00', CalculoNomina::reparto(2026, 8)->bolsa);
    }

    public function test_reparto_mensual_al_socio(): void
    {
        [, , $ernesto] = $this->empleados();
        $this->cobrada('2026-08-05', 100000, 80000);   // utilidad 20,000
        $this->cobrada('2026-08-31', 5000, 4000);      // 1,000
        $this->cobrada('2026-09-01', 9999, 0);         // otro mes
        $r = CalculoNomina::reparto(2026, 8, 6000);
        $this->assertSame('21000.00', $r->utilidad);
        $this->assertSame('3000.00', $r->bolsa);        // 20% de (21,000 − 6,000)
        $this->assertSame($ernesto->id, $r->lineas()->value('user_id'));
        $this->assertSame('3000.00', $r->lineas()->value('total'));

        $this->assertSame('0.00', CalculoNomina::reparto(2026, 8, 50000)->bolsa);   // pérdida: mínimo 0
        $this->assertSame(1, Nomina::where('tipo', 'reparto')->count());
    }
}

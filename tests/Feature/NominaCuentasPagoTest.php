<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\MisFinanzas;
use App\Livewire\Finanzas\Nomina as PantallaNomina;
use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\Nomina;
use App\Models\SaldoCorte;
use App\Models\SaldoLinea;
use App\Models\User;
use App\Models\Venta;
use App\Support\CalculoNomina;
use App\Support\Patrimonio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Desde qué cuenta se le paga a cada empleado (BBVA, Hey Banco): gasto por cuenta y la nómina en "Lo que tengo que pagar pronto". */
class NominaCuentasPagoTest extends TestCase
{
    use RefreshDatabase;

    private Cuenta $bbva;

    private Cuenta $hey;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 12:00:00');   // jueves de la semana 40 (sábado 3-oct)
        $this->bbva = Cuenta::create(['nombre' => 'BBVA (DÉBITO)', 'tipo' => 'debito', 'de' => 'masume']);
        $this->hey = Cuenta::create(['nombre' => 'HEY BANCO (DÉBITO)', 'tipo' => 'debito', 'de' => 'masume']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: User, 2: User} */
    private function equipo(): array
    {
        return [
            User::factory()->create(['name' => 'Alberto', 'en_nomina' => true, 'sueldo_base' => 3000, 'comision_pct' => 50]),
            User::factory()->create(['name' => 'Rosa', 'en_nomina' => true, 'sueldo_base' => 2000, 'comision_pct' => 50]),
            User::factory()->create(['name' => 'Ernesto', 'en_nomina' => true, 'sueldo_base' => 4000, 'comision_pct' => 0]),
        ];
    }

    public function test_en_empleados_se_elige_la_cuenta_de_cada_quien(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        [$alberto] = $this->equipo();
        $tarjeta = Cuenta::create(['nombre' => 'BBVA PLATINUM', 'tipo' => 'credito', 'de' => 'masume']);

        $t = Livewire::actingAs($admin)->test(PantallaNomina::class, ['ver' => 'empleados'])
            ->assertSee('Cuenta de pago')->assertSee('BBVA (DÉBITO)')->assertDontSee('BBVA PLATINUM')
            ->set("emp.{$alberto->id}.cuenta_nomina_id", (string) $this->bbva->id)->call('guardarEmpleado', $alberto->id);
        $this->assertSame($this->bbva->id, $alberto->fresh()->cuenta_nomina_id);

        // Una tarjeta de crédito no sirve para pagar la nómina.
        $t->set("emp.{$alberto->id}.cuenta_nomina_id", (string) $tarjeta->id)->call('guardarEmpleado', $alberto->id)
            ->assertHasErrors("emp.{$alberto->id}.cuenta_nomina_id");
        $this->assertSame($this->bbva->id, $alberto->fresh()->cuenta_nomina_id);

        $t->set("emp.{$alberto->id}.cuenta_nomina_id", '')->call('guardarEmpleado', $alberto->id);
        $this->assertNull($alberto->fresh()->cuenta_nomina_id);
    }

    public function test_al_registrar_se_anota_un_gasto_por_cuenta_y_reabrir_los_quita(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        [$alberto, $rosa, $ernesto] = $this->equipo();
        $alberto->update(['cuenta_nomina_id' => $this->bbva->id]);
        $rosa->update(['cuenta_nomina_id' => $this->hey->id]);
        // Ernesto no tiene cuenta: sale de la general (Hey Banco Débito).
        Venta::create(['tipo' => 'factura', 'folio' => '5-1', 'cliente_nombre' => 'X', 'fecha' => '2026-09-01', 'subtotal' => 10000, 'costo' => 0,
            'iva' => 0, 'total' => 10000, 'estatus' => 'pagada', 'fecha_pago' => '2026-09-29']);   // comisión 1,000 → 500 y 500

        $t = Livewire::actingAs($admin)->test(PantallaNomina::class)
            ->assertSee('Desde BBVA (DÉBITO)')->assertSee('Sale de:')
            ->assertSeeText('HEY BANCO (DÉBITO) · Rosa, Ernesto')->assertSee('$6,500.00')->assertSee('$3,500.00');

        // Esta semana a Ernesto se le paga desde BBVA.
        $lineaEdgar = Nomina::sole()->lineas()->where('user_id', $ernesto->id)->sole();
        $t->call('guardarCuentaLinea', $lineaEdgar->id, (string) $this->bbva->id)->assertSee('se le paga esta semana desde BBVA (DÉBITO)');
        $t->call('guardarCuentaLinea', $lineaEdgar->id, '999999')->assertSet('error', fn ($e) => str_contains($e, 'débito o efectivo'));

        $t->call('registrarPago');
        $gastos = Gasto::orderBy('monto')->get();
        $this->assertSame([$this->hey->id, $this->bbva->id], $gastos->pluck('cuenta_id')->all());
        $this->assertSame(['2500.00', '7500.00'], $gastos->pluck('monto')->all());
        $this->assertSame(['PAGO NOMINA SEMANA 40 · Rosa', 'PAGO NOMINA SEMANA 40 · Alberto, Ernesto'], $gastos->pluck('descripcion')->all());
        $this->assertSame(['NOMINA'], $gastos->pluck('categoria')->unique()->values()->all());
        // Cada línea guarda de dónde se le pagó (aunque después le cambien su cuenta de siempre).
        $this->assertSame($this->bbva->id, $lineaEdgar->fresh()->cuenta_id);
        $rosa->update(['cuenta_nomina_id' => $this->bbva->id]);
        $t->call('irA', 'semana')->assertSee('Se pagó desde HEY BANCO (DÉBITO)')->assertSee('Salió de:');

        $t->call('reabrirSemana');
        $this->assertSame(0, Gasto::withTrashed()->count());
    }

    public function test_cada_semana_se_elige_de_que_cuenta_pagar_a_todos_viendo_cuanto_tenia(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        [$alberto, $rosa] = $this->equipo();
        $corte = SaldoCorte::create(['fecha' => '2026-09-30']);
        SaldoLinea::create(['saldo_corte_id' => $corte->id, 'cuenta_id' => $this->bbva->id, 'saldo' => 19862.89]);

        $t = Livewire::actingAs($admin)->test(PantallaNomina::class)
            ->assertSee('¿De qué cuenta pagas esta semana?')->assertSee('Todos desde BBVA (DÉBITO)')->assertSee('tenía $19,862.89 al 30/09');
        $t->call('pagarTodosDesde', $this->bbva->id)->assertSee('se le paga a todos desde BBVA (DÉBITO)');
        $this->assertSame([$this->bbva->id], Nomina::sole()->lineas()->pluck('cuenta_id')->unique()->values()->all());

        // A Rosa esta vez desde Hey; la semana siguiente vuelve a empezar como siempre.
        $t->call('guardarCuentaLinea', Nomina::sole()->lineas()->where('user_id', $rosa->id)->value('id'), (string) $this->hey->id);
        $t->call('registrarPago');
        $this->assertSame(['HEY BANCO (DÉBITO)' => '2000.00', 'BBVA (DÉBITO)' => '7000.00'],
            Gasto::with('cuenta')->orderBy('monto')->get()->mapWithKeys(fn ($g) => [$g->cuenta->nombre => $g->monto])->all());
        $this->assertSame([null], CalculoNomina::semana(2026, 41)->lineas()->pluck('cuenta_id')->unique()->values()->all());

        $t->call('pagarTodosDesde', 999999)->assertSet('error', fn ($e) => str_contains($e, 'ya está pagada') || str_contains($e, 'débito'));
        // Quien maneja la nómina sin ver Mis finanzas no ve los saldos de las cuentas.
        Livewire::actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => ['nomina']]))->test(PantallaNomina::class, ['semana' => 41])
            ->assertSee('Todos desde BBVA (DÉBITO)')->assertDontSee('19,862.89');
    }

    public function test_si_todos_salen_de_la_misma_cuenta_queda_un_solo_gasto_como_antes(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $this->equipo();
        CalculoNomina::registrar(CalculoNomina::semana(2026, 40));
        $g = Gasto::sole();
        $this->assertSame('PAGO NOMINA SEMANA 40', $g->descripcion);
        $this->assertSame('HEY BANCO (DÉBITO)', $g->cuenta->nombre);
        $this->assertSame('9000.00', $g->monto);
    }

    public function test_el_reparto_del_socio_sale_de_su_cuenta(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        [, , $ernesto] = $this->equipo();
        $ernesto->update(['cuenta_nomina_id' => $this->bbva->id]);
        $n = CalculoNomina::reparto(2026, 9, 0);
        $n->lineas()->first()->update(['comision' => 1000, 'comision_manual' => true, 'total' => 1000]);
        CalculoNomina::registrar($n);
        $g = Gasto::sole();
        $this->assertSame(['REPARTO SOCIO', $this->bbva->id], [$g->categoria, $g->cuenta_id]);
    }

    public function test_mis_finanzas_muestra_la_nomina_del_sabado_por_cuenta_sin_abrirla(): void
    {
        [$alberto] = $this->equipo();
        $alberto->update(['cuenta_nomina_id' => $this->bbva->id]);

        $pagos = Patrimonio::pagosProximos(conNomina: true);
        $this->assertSame(['Nómina de la semana 40'], array_column($pagos, 'nombre'));
        $this->assertSame(9000.0, $pagos[0]['monto']);
        $this->assertSame(2, $pagos[0]['dias']);
        $this->assertStringContainsString('HEY BANCO (DÉBITO) $6,000.00 · BBVA (DÉBITO) $3,000.00', $pagos[0]['detalle']);
        $this->assertSame(0, Nomina::count());   // solo vista previa: no abre la semana
        $this->assertSame([], Patrimonio::pagosProximos());

        Livewire::actingAs(User::factory()->create(['rol' => 'admin']))->test(MisFinanzas::class)->assertSee('Nómina de la semana 40');
        // Quien solo ve "Mis finanzas" (sin permiso de nómina) no ve la nómina.
        Livewire::actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => ['finanzas']]))->test(MisFinanzas::class)
            ->assertDontSee('Nómina de la semana');

        // Ya pagada, deja de salir.
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        CalculoNomina::registrar(CalculoNomina::semana(2026, 40));
        $this->assertSame([], Patrimonio::pagosProximos(conNomina: true));
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\MisFinanzas;
use App\Models\Cuenta;
use App\Models\CuentaPorPagar;
use App\Models\Proveedor;
use App\Models\SaldoCorte;
use App\Models\SaldoLinea;
use App\Models\User;
use App\Models\Venta;
use App\Support\Patrimonio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Mis finanzas: permisos, cortes de saldos, patrimonio, fechas de corte y pago, semáforo y cuentas. */
class MisFinanzasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'admin']);
    }

    private function cuenta(string $nombre, string $tipo = 'debito', array $extra = []): Cuenta
    {
        return Cuenta::create(['nombre' => $nombre, 'tipo' => $tipo, 'de' => 'personal'] + $extra);
    }

    private function corte(string $fecha, array $saldos): SaldoCorte
    {
        $corte = SaldoCorte::create(['fecha' => $fecha]);
        foreach ($saldos as $cuentaId => $saldo) {
            $datos = is_array($saldo) ? $saldo : ['saldo' => $saldo];
            SaldoLinea::create($datos + ['saldo_corte_id' => $corte->id, 'cuenta_id' => $cuentaId]);
        }

        return $corte;
    }

    private function venta(array $extra = []): Venta
    {
        static $n = 0;
        $n++;

        return Venta::create($extra + ['tipo' => 'factura', 'folio' => "7-{$n}", 'cliente_nombre' => 'X', 'fecha' => '2026-09-01', 'subtotal' => 100, 'iva' => 16, 'total' => 116, 'estatus' => 'pendiente']);
    }

    public function test_eliminar_cuenta_sin_uso_la_borra_y_con_historial_la_desactiva(): void
    {
        $libre = $this->cuenta('CUENTA VIEJA');
        $usada = $this->cuenta('NU DEBITO');
        $this->corte('2026-09-30', [$usada->id => 500]);

        Livewire::actingAs($this->admin())->test(MisFinanzas::class)->set('pestana', 'cuentas')
            ->call('editarCuenta', $libre->id)->assertSet('editandoCuenta', true)->assertSee('Eliminar cuenta')
            ->call('eliminarCuenta', $libre->id)->assertSee('eliminé la cuenta CUENTA VIEJA')
            ->call('eliminarCuenta', $usada->id)->assertSee('la desactivé');

        $this->assertNull(Cuenta::find($libre->id));
        $this->assertFalse(Cuenta::find($usada->id)->activa);
        $this->assertSame(1, SaldoLinea::count());
    }

    public function test_actualizar_una_sola_cuenta_y_totales_por_rubro(): void
    {
        Carbon::setTestNow('2026-10-01 13:00');
        $nu = $this->cuenta('NU DEBITO');
        $hey = $this->cuenta('HEY BANCO');
        $costco = $this->cuenta('COSTCO BANAMEX', 'credito');
        $this->corte('2026-09-30', [$nu->id => 1000, $hey->id => 500, $costco->id => 200]);

        $lw = Livewire::actingAs($this->admin())->test(MisFinanzas::class)
            ->assertSee('$1,500.00')                       // total de débito
            ->call('abrirUno', $costco->id)->assertSet('unoSaldo', '200')
            ->set('unoSaldo', 'abc')->call('guardarUno')->assertHasErrors('unoSaldo')
            ->set('unoSaldo', '16,350.23')->set('unoFecha', '2026-10-23')->call('guardarUno')
            ->assertHasNoErrors()->assertSee('actualicé COSTCO BANAMEX')
            ->call('abrirUno', $nu->id)->set('unoSaldo', '1200')->call('guardarUno')
            ->assertSee('$1,700.00');

        $this->assertSame(2, SaldoCorte::count());          // las dos van al mismo corte de hoy
        $hoy = SaldoCorte::orderByDesc('id')->first();
        $this->assertSame(2, $hoy->lineas()->count());
        $ultimos = Patrimonio::ultimosSaldos();
        $this->assertSame(16350.23, (float) $ultimos[$costco->id]->saldo);
        $this->assertSame(500.0, (float) $ultimos[$hey->id]->saldo);   // las demás se quedan como estaban
    }

    public function test_permisos(): void
    {
        $this->get('/finanzas/mis-finanzas')->assertRedirect();

        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $this->get('/finanzas/mis-finanzas')->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => ['gastos']]));
        $this->get('/finanzas/mis-finanzas')->assertForbidden();

        $ivonne = User::factory()->create(['rol' => 'vendedor', 'permisos' => ['finanzas']]);
        $this->actingAs($ivonne)->get('/finanzas/mis-finanzas')->assertOk()->assertSee('Actualizar saldos')->assertSee('Mis finanzas');
        $this->actingAs($this->admin())->get('/finanzas/mis-finanzas')->assertOk();
    }

    public function test_actualizar_saldos_crea_un_corte_y_se_prellena_con_el_ultimo(): void
    {
        $this->actingAs($this->admin());
        $hey = $this->cuenta('HEY BANCO');
        $tdc = $this->cuenta('BBVA PLATINUM', 'credito', ['limite' => 10000, 'dia_corte' => 9, 'dia_pago' => 29]);
        $sinUsar = $this->cuenta('CAJITA', 'apartado');
        $inactiva = $this->cuenta('VIEJA', 'debito', ['activa' => false]);
        $this->corte('2026-09-15', [$hey->id => 1500.5, $tdc->id => ['saldo' => 4000, 'pago_sin_intereses' => 900, 'fecha_limite' => '2026-10-29']]);

        $c = Livewire::test(MisFinanzas::class)->call('abrirActualizar')
            ->assertSet("saldos.{$hey->id}", '1500.5')->assertSet("saldos.{$tdc->id}", '4000')->assertSet("saldos.{$sinUsar->id}", '')
            ->assertSet("pagos.{$tdc->id}", '900')->assertSet("limites.{$tdc->id}", '2026-10-29')
            ->assertSee('Débito, efectivo e inversión')->assertSee('Pago para no generar intereses')->assertDontSee('VIEJA');
        $this->assertArrayNotHasKey($inactiva->id, $c->get('saldos'));

        $c->set("saldos.{$hey->id}", '$2,000.25')->set("saldos.{$tdc->id}", '4200')->set("pagos.{$tdc->id}", '')->set("limites.{$tdc->id}", '')
            ->call('guardarSaldos')->assertHasNoErrors()->assertSet('actualizando', false)->assertSee('guardé tus saldos');

        $this->assertSame(2, SaldoCorte::count());
        $nuevo = SaldoCorte::orderByDesc('id')->first();
        $this->assertSame('2026-10-01', $nuevo->fecha->format('Y-m-d'));
        $this->assertSame(2, $nuevo->lineas()->count(), 'la cuenta sin saldo escrito no genera línea');
        $this->assertSame('2000.25', $nuevo->lineas()->where('cuenta_id', $hey->id)->value('saldo'));
        $this->assertNull($nuevo->lineas()->where('cuenta_id', $tdc->id)->value('pago_sin_intereses'));
        $this->assertSame(2000.25, (float) Patrimonio::ultimosSaldos()[$hey->id]->saldo);
    }

    public function test_valida_numeros_y_exige_al_menos_un_saldo(): void
    {
        $this->actingAs($this->admin());
        $hey = $this->cuenta('HEY BANCO');
        Livewire::test(MisFinanzas::class)->call('abrirActualizar')
            ->call('guardarSaldos')->assertHasErrors(['general'])
            ->set("saldos.{$hey->id}", 'mucho')->call('guardarSaldos')->assertHasErrors(["saldos.{$hey->id}"]);
        $this->assertSame(0, SaldoCorte::count());
    }

    public function test_patrimonio_con_la_cartera_de_ventas(): void
    {
        $banco = $this->cuenta('BANCO');
        $efectivo = $this->cuenta('EFECTIVO', 'efectivo');
        $gbm = $this->cuenta('GBM', 'inversion');
        $cajita = $this->cuenta('CAJITA', 'apartado');
        $tdc = $this->cuenta('TDC', 'credito', ['limite' => 1000]);
        $credito = $this->cuenta('CREDITO', 'prestamo');
        $jorge = $this->cuenta('JORGE', 'por_cobrar');
        $this->corte('2026-09-01', [$banco->id => 100, $tdc->id => 999]);   // el último corte que incluye cada cuenta gana
        $this->corte('2026-09-20', [$banco->id => 1000, $efectivo->id => 200, $gbm->id => 5000, $cajita->id => 300, $tdc->id => 400, $credito->id => 2500, $jorge->id => 140]);
        $this->cuenta('VIEJA', 'debito', ['activa' => false]);

        $this->venta(['total' => 116]);
        $this->venta(['total' => 1000]);
        $this->venta(['total' => 500, 'estatus' => 'pagada']);
        $this->venta(['total' => 0]);

        $r = Patrimonio::resumen();
        $this->assertSame(6500.0, $r['disponible']);
        $this->assertSame(400.0, $r['tarjetas']);
        $this->assertSame(2500.0, $r['creditos']);
        $this->assertSame(140.0, $r['me_deben']);
        $this->assertSame(1116.0, $r['cartera']);
        $this->assertSame(6500 + 140 + 1116 - 400 - 2500.0, $r['neto']);
        $this->assertSame(11, $r['dias']);

        $h = Patrimonio::historia();
        $this->assertCount(2, $h);
        $this->assertSame(100 + 1116 - 999.0, $h[0]['neto']);   // al primer corte solo se conocía banco y tarjeta
    }

    public function test_aviso_de_actualizar_y_hace_cuantos_dias(): void
    {
        $this->actingAs($this->admin());
        $c = Livewire::test(MisFinanzas::class)->assertSee('Todavía no has capturado')->assertSee('Todavía no capturas tus saldos.');
        $banco = $this->cuenta('BANCO');
        $this->corte('2026-09-30', [$banco->id => 10]);
        Livewire::test(MisFinanzas::class)->assertSee('hace 1 día')->assertDontSee('Tus saldos tienen')->assertDontSee('Todavía no capturas tus saldos.');
        SaldoCorte::query()->update(['fecha' => '2026-09-10']);
        Livewire::test(MisFinanzas::class)->assertSee('hace 21 días')->assertSee('Tus saldos tienen 21 días.');
    }

    public function test_fechas_de_corte_y_pago(): void
    {
        $hoy = Carbon::parse('2026-10-01');
        $f = fn (int $dia, string $hoy) => Patrimonio::proximoDia($dia, Carbon::parse($hoy))->format('Y-m-d');

        $this->assertSame('2026-10-15', $f(15, '2026-10-01'));       // todavía no pasa este mes
        $this->assertSame('2026-10-01', $f(1, '2026-10-01'));        // hoy cuenta
        $this->assertSame('2026-11-01', $f(1, '2026-10-02'));        // ya pasó: el mes siguiente
        $this->assertSame('2026-02-28', $f(31, '2026-02-10'));       // febrero no tiene 31: último día
        $this->assertSame('2026-03-31', $f(31, '2026-03-01'));
        $this->assertSame('2026-11-30', $f(31, '2026-11-02'));
        $this->assertSame('2027-02-28', $f(31, '2027-02-28'));
        $this->assertSame('2027-01-09', $f(9, '2026-12-20'));        // cruza de año
        $this->assertSame('2026-02-28', $f(31, '2026-02-28'));

        $this->cuenta('TDC', 'credito', ['dia_corte' => 9, 'dia_pago' => 29, 'limite' => 1000]);
        $t = Patrimonio::tarjetas($hoy)->first();
        $this->assertSame('2026-10-09', $t['corte']->format('Y-m-d'));
        $this->assertSame('2026-10-29', $t['pago']->format('Y-m-d'));
        $this->assertSame(28, $t['dias_pago']);

        // Día de pago que ya pasó este mes: cae el mes siguiente.
        Cuenta::query()->update(['dia_pago' => 1]);
        $t = Patrimonio::tarjetas(Carbon::parse('2026-10-20'))->first();
        $this->assertSame('2026-11-01', $t['pago']->format('Y-m-d'));
        $this->assertSame(12, $t['dias_pago']);
    }

    public function test_semaforo_disponible_al_70_y_orden_por_pago(): void
    {
        $verde = $this->cuenta('VERDE', 'credito', ['limite' => 10000, 'dia_pago' => 25]);
        $amarilla = $this->cuenta('AMARILLA', 'credito', ['limite' => 10000, 'dia_pago' => 10]);
        $roja = $this->cuenta('ROJA', 'credito', ['limite' => 10000, 'dia_pago' => 5]);
        $sinLimite = $this->cuenta('SIN LIMITE', 'credito', ['dia_pago' => 3]);
        $sinDia = $this->cuenta('SIN DIA', 'credito', ['limite' => 1000]);
        $this->corte('2026-09-30', [$verde->id => 6999, $amarilla->id => 7000, $roja->id => 9000, $sinLimite->id => 500, $sinDia->id => 100]);

        $t = Patrimonio::tarjetas(Carbon::parse('2026-10-01'))->keyBy(fn ($x) => $x['cuenta']->nombre);
        $this->assertSame('verde', $t['VERDE']['semaforo']['clave']);
        $this->assertSame('Abierta', $t['VERDE']['semaforo']['texto']);
        $this->assertSame('amarillo', $t['AMARILLA']['semaforo']['clave']);
        $this->assertSame('Limitar', $t['AMARILLA']['semaforo']['texto']);
        $this->assertSame('rojo', $t['ROJA']['semaforo']['clave']);
        $this->assertSame('Saturada', $t['ROJA']['semaforo']['texto']);
        $this->assertNull($t['SIN LIMITE']['semaforo']);
        $this->assertSame(1.0, round($t['VERDE']['disponible70']));          // 7000 − 6999
        $this->assertSame(0.0, $t['AMARILLA']['disponible70']);              // 7000 − 7000
        $this->assertSame(0.0, $t['ROJA']['disponible70']);                  // nunca negativo
        $this->assertSame(600.0, $t['SIN DIA']['disponible70']);             // 1000 × 0.7 − 100
        $this->assertSame(0.9, $t['ROJA']['utilizacion']);
        $this->assertSame(['SIN LIMITE', 'ROJA', 'AMARILLA', 'VERDE', 'SIN DIA'], Patrimonio::tarjetas(Carbon::parse('2026-10-01'))->map(fn ($x) => $x['cuenta']->nombre)->all());

        $this->actingAs($this->admin());
        Livewire::test(MisFinanzas::class)->assertSee('Saturada')->assertSee('Limitar')->assertSee('Abierta')->assertSee('Disponible al 70%');
    }

    public function test_grafica_solo_con_dos_o_mas_cortes(): void
    {
        $this->actingAs($this->admin());
        $banco = $this->cuenta('BANCO');
        $this->corte('2026-09-01', [$banco->id => 100]);
        Livewire::test(MisFinanzas::class)->assertSee('Sin suficientes cortes para la gráfica')->assertDontSee('<polyline', false);
        $this->corte('2026-09-20', [$banco->id => 300]);
        Livewire::test(MisFinanzas::class)->assertSee('<polyline', false)->assertDontSee('Sin suficientes cortes para la gráfica');
    }

    public function test_grafica_muestra_el_monto_de_cada_punto_al_pasar_el_cursor(): void
    {
        $this->actingAs($this->admin());
        $banco = $this->cuenta('BANCO');
        $this->corte('2026-09-01', [$banco->id => 100]);
        $this->corte('2026-09-20', [$banco->id => 1234.5]);
        Livewire::test(MisFinanzas::class)
            ->assertSeeHtml('data-globo="1"')
            ->assertSeeHtml('@click="sel = 1"')
            ->assertSee('20/09/2026')
            ->assertSee('$1,234.50');
    }

    public function test_alta_y_edicion_de_cuentas(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => ['finanzas']]));

        Livewire::test(MisFinanzas::class)->set('pestana', 'cuentas')->call('nuevaCuenta')
            ->set('nombre', 'Costco Banamex')->set('tipo', 'credito')->set('de', 'personal')->set('diaCorte', '3')->set('diaPago', '23')->set('limite', '$71,200')->set('orden', '4')
            ->call('guardarCuenta')->assertHasNoErrors()->assertSet('editandoCuenta', false)->assertSee('COSTCO BANAMEX');
        $c = Cuenta::where('nombre', 'COSTCO BANAMEX')->first();
        $this->assertSame(['credito', 3, 23, '71200.00', 'personal', 4], [$c->tipo, $c->dia_corte, $c->dia_pago, $c->limite, $c->de, $c->orden]);

        // Nombre repetido (sin acentos ni mayúsculas) y días fuera de rango.
        Livewire::test(MisFinanzas::class)->call('nuevaCuenta')->set('nombre', 'costco  BANAMEX')->call('guardarCuenta')->assertHasErrors(['nombre']);
        Livewire::test(MisFinanzas::class)->call('nuevaCuenta')->set('nombre', 'Otra')->set('tipo', 'credito')->set('diaCorte', '32')->set('diaPago', '0')
            ->call('guardarCuenta')->assertHasErrors(['diaCorte', 'diaPago']);

        // Editar: cambiar a efectivo borra datos de tarjeta; se puede desactivar.
        Livewire::test(MisFinanzas::class)->call('editarCuenta', $c->id)->assertSet('nombre', 'COSTCO BANAMEX')->assertSet('diaPago', '23')->assertSet('limite', '71200')
            ->set('tipo', 'efectivo')->set('activa', false)->call('guardarCuenta')->assertHasNoErrors();
        $c->refresh();
        $this->assertSame(['efectivo', null, null, null, false], [$c->tipo, $c->dia_corte, $c->dia_pago, $c->limite, $c->activa]);
        $this->assertSame(1, Cuenta::count());

        Livewire::test(MisFinanzas::class)->call('nuevaCuenta')->set('nombre', 'Préstamo Jorge')->set('tipo', 'por_cobrar')->call('guardarCuenta')->assertHasNoErrors();
        $this->assertSame('por_cobrar', Cuenta::where('nombre', 'PRÉSTAMO JORGE')->value('tipo'));
    }

    public function test_sin_permiso_no_puede_usar_las_acciones(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        Livewire::test(MisFinanzas::class)->assertForbidden();
    }

    public function test_lo_que_tengo_que_pagar_pronto_y_tengo_contra_debo(): void
    {
        $this->actingAs($this->admin());
        $hey = $this->cuenta('HEY CREDITO', 'credito', ['dia_pago' => 2, 'limite' => 1000]);
        $mp = $this->cuenta('MERCADO PAGO', 'credito', ['dia_pago' => 20, 'limite' => 100]);
        $lejana = $this->cuenta('LEJANA', 'credito', ['dia_pago' => 30, 'limite' => 100]);   // 30 oct: fuera de 21 días
        $banco = $this->cuenta('BANCO');
        $this->corte('2026-09-30', [$hey->id => 300, $mp->id => ['saldo' => 99, 'pago_sin_intereses' => 60], $lejana->id => 50, $banco->id => 1000]);
        $urrea = Proveedor::factory()->create(['nombre' => 'URREA']);
        CuentaPorPagar::create(['proveedor_id' => $urrea->id, 'folio' => '3900784', 'fecha' => '2026-09-18', 'vence' => '2026-10-21', 'importe' => 17417, 'estatus' => 'pendiente']);
        CuentaPorPagar::create(['proveedor_id' => $urrea->id, 'folio' => '3901405', 'fecha' => '2026-09-18', 'vence' => '2026-12-17', 'importe' => 60806, 'estatus' => 'pendiente']);

        $pagos = Patrimonio::pagosProximos();
        $this->assertSame(['HEY CREDITO', 'MERCADO PAGO', 'URREA · factura 3900784'], array_column($pagos, 'nombre'));
        $this->assertSame([300.0, 60.0, 17417.0], array_column($pagos, 'monto'));   // Mercado Pago: lo de no generar intereses
        $this->assertSame([1, 19, 20], array_column($pagos, 'dias'));

        Livewire::test(MisFinanzas::class)
            ->assertSee('Lo que tengo que pagar pronto')->assertSee('Mañana')->assertSee('URREA · factura 3900784')
            ->assertSee('Lo que tengo y me deben')->assertSee('Proveedores $78,223.00')->assertSee('ver a quién');
        // Quien solo tiene permiso de "Mis finanzas" no ve el link a "A quién le debo" (es del admin).
        Livewire::actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => ['finanzas']]))->test(MisFinanzas::class)
            ->assertSee('Proveedores $78,223.00')->assertDontSee('ver a quién');
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Nomina as PantallaNomina;
use App\Models\Ajuste;
use App\Models\Nomina;
use App\Models\NominaLinea;
use App\Models\Prestamo;
use App\Models\PrestamoAbono;
use App\Models\User;
use App\Models\Venta;
use App\Support\CalculoNomina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Pantalla Finanzas → Nómina: permisos, semana (borrador, edición, pago), préstamos, reparto, historial y empleados. */
class FinanzasNominaTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 12:00:00');   // jueves de la semana 40 (lun 28-sep al sáb 3-oct)
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'admin', 'name' => 'Daniel Dueño']);
    }

    private function cobrada(string $fechaPago, float $venta, float $costo): Venta
    {
        $this->n++;

        return Venta::create(['tipo' => 'factura', 'folio' => "5-{$this->n}", 'cliente_nombre' => 'CLIENTE '.$this->n, 'fecha' => '2026-09-01',
            'subtotal' => $venta, 'costo' => $costo, 'iva' => 0, 'total' => $venta, 'estatus' => 'pagada', 'fecha_pago' => $fechaPago]);
    }

    /** @return array{0: User, 1: User, 2: User} */
    private function equipo(): array
    {
        return [
            User::factory()->create(['name' => 'Alberto', 'en_nomina' => true, 'sueldo_base' => 3000, 'comision_pct' => 45]),
            User::factory()->create(['name' => 'Rosa Ruiz', 'en_nomina' => true, 'sueldo_base' => 2400, 'comision_pct' => 35]),
            User::factory()->create(['name' => 'Ernesto Pérez', 'en_nomina' => true, 'sueldo_base' => 4000, 'comision_pct' => 20]),
        ];
    }

    private function linea(Nomina $n, User $u): NominaLinea
    {
        return $n->lineas()->where('user_id', $u->id)->firstOrFail();
    }

    // ------------------------------------------------------------------------------------------ permisos

    public function test_permisos_de_la_pantalla(): void
    {
        $this->get('/finanzas/nomina')->assertRedirect('/login');

        $sin = User::factory()->create(['rol' => 'vendedor']);
        $this->actingAs($sin)->get('/finanzas/nomina')->assertForbidden();

        $campo = User::factory()->create(['rol' => 'campo', 'permisos' => ['ventas', 'cobranza']]);   // otros permisos no alcanzan
        $this->actingAs($campo)->get('/finanzas/nomina')->assertForbidden();

        $this->actingAs($this->admin())->get('/finanzas/nomina')->assertOk()->assertSee('Nómina y comisiones')->assertSee('Empleados');

        $vendedor = User::factory()->create(['rol' => 'vendedor', 'permisos' => ['nomina']]);
        $this->actingAs($vendedor)->get('/finanzas/nomina')->assertOk()->assertSee('Registrar pago')->assertDontSee('Empleados');
    }

    public function test_el_menu_muestra_nomina_solo_con_permiso(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']))->get('/cotizaciones')->assertDontSee('/finanzas/nomina');
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => ['nomina']]))->get('/cotizaciones')->assertSee('/finanzas/nomina');
    }

    public function test_empleados_y_reabrir_son_solo_del_admin(): void
    {
        [$alberto] = $this->equipo();
        $vendedor = User::factory()->create(['rol' => 'vendedor', 'permisos' => ['nomina']]);
        $this->actingAs($vendedor);

        // La pestaña Empleados no se abre para quien no es admin.
        Livewire::withQueryParams(['ver' => 'empleados'])->test(PantallaNomina::class)->assertSet('ver', 'semana');
        Livewire::test(PantallaNomina::class)->call('irA', 'empleados')->assertSet('ver', 'semana');
        Livewire::test(PantallaNomina::class)->call('guardarEmpleado', $alberto->id)->assertForbidden();
        $this->assertSame('3000.00', $alberto->fresh()->sueldo_base);

        // Pagada la semana, reabrir tampoco.
        $nomina = CalculoNomina::semana(2026, 40);
        $c = Livewire::test(PantallaNomina::class)->call('registrarPago');
        $this->assertTrue($nomina->fresh()->pagada());
        $c->assertDontSee('Reabrir semana')->call('reabrirSemana')->assertForbidden();
        $this->assertTrue($nomina->fresh()->pagada());
        Livewire::test(PantallaNomina::class)->call('reabrirReparto')->assertForbidden();
    }

    public function test_si_le_quitan_el_permiso_ya_no_puede_usar_la_pantalla(): void
    {
        $vendedor = User::factory()->create(['rol' => 'vendedor', 'permisos' => ['nomina']]);
        $this->actingAs($vendedor);
        $c = Livewire::test(PantallaNomina::class);
        $vendedor->update(['permisos' => []]);
        $c->call('irA', 'prestamos')->assertForbidden();
    }

    // ------------------------------------------------------------------------------------------ semana

    public function test_complemento_hecho_tarde_con_fecha_anterior_al_arranque_entra_solo_en_la_comision(): void
    {
        $this->actingAs($this->admin());
        $this->equipo();
        $this->cobrada('2026-09-29', 10000, 8000);                 // utilidad 2,000: entra sola
        $vieja = $this->cobrada('2026-09-10', 1000, 500);           // se pagó y comisionó con el Sheets: no se toca
        $tarde = Venta::create(['tipo' => 'factura', 'folio' => '5-4801', 'cliente_nombre' => 'SERVICIOS TURISTICOS', 'fecha' => '2026-08-20',
            'subtotal' => 304.99, 'costo' => 228.74, 'iva' => 0, 'total' => 304.99, 'estatus' => 'pendiente', 'sicar_id' => 'F:4960', 'origen' => 'sicar']);

        // Hoy (1-oct) llega de SICAR el complemento con la fecha real del pago: 25-sep, antes del arranque (27-sep).
        $tarde->update(['estatus' => 'pagada', 'fecha_pago' => '2026-09-25', 'pago_registrado_at' => now()]);

        Livewire::test(PantallaNomina::class)->assertViewHas('cobrosN', 2)->call('alternarCobros')->assertSee('5-4801')->assertSee('cobro atrasado');
        $this->assertEquals(207.63, (float) Nomina::where('semana', 40)->first()->bolsa);   // 10% de 2,076.25
        $this->assertFalse(CalculoNomina::cobros(2026, 40)->whereKey($vieja->id)->exists());
    }

    public function test_la_migracion_llena_solo_los_pagos_reales_registrados_desde_el_arranque(): void
    {
        $tarde = $this->cobrada('2026-09-25', 304.99, 228.74);
        $tarde->cambios()->create(['campo' => 'estatus', 'antes' => 'pendiente', 'despues' => 'pagada', 'origen' => 'sicar', 'created_at' => '2026-10-01 09:00:00']);
        $viejaMigrada = $this->cobrada('2024-05-10', 500, 100);
        $viejaMigrada->update(['pago_referencia' => 'Sin adeudo en SICAR (años anteriores)']);
        $viejaMigrada->cambios()->create(['campo' => 'estatus', 'antes' => 'pendiente', 'despues' => 'pagada', 'origen' => 'sicar', 'created_at' => '2026-10-02 09:00:00']);
        $saldada = $this->cobrada('2026-03-02', 500, 100);
        $saldada->update(['pago_referencia' => 'Saldada en SICAR']);
        $saldada->cambios()->create(['campo' => 'estatus', 'antes' => 'pendiente', 'despues' => 'pagada', 'origen' => 'sicar', 'created_at' => '2026-10-02 09:00:00']);
        $comisionadaSheets = $this->cobrada('2026-09-10', 1000, 500);   // pagada antes, sin cambio reciente

        $m = require database_path('migrations/2026_11_10_000001_add_pago_registrado_a_ventas.php');
        $m->down();
        $m->up();

        $this->assertNotNull($tarde->fresh()->pago_registrado_at);
        $this->assertNull($viejaMigrada->fresh()->pago_registrado_at);
        $this->assertNull($saldada->fresh()->pago_registrado_at);
        $this->assertNull($comisionadaSheets->fresh()->pago_registrado_at);
        $this->assertSame([$tarde->id], CalculoNomina::cobros(2026, 40)->whereIn('id', [$tarde->id, $viejaMigrada->id, $saldada->id, $comisionadaSheets->id])->pluck('id')->all());
    }

    public function test_quitar_un_cobro_que_ya_se_comisiono_y_regresarlo(): void
    {
        $andrea = User::factory()->create(['rol' => 'vendedor', 'name' => 'Andrea', 'permisos' => ['nomina']]);
        $this->actingAs($andrea);
        $this->equipo();
        $bien = $this->cobrada('2026-09-29', 10000, 8000);     // utilidad 2,000
        $repetida = $this->cobrada('2026-09-29', 2413.60, 1717.28);   // ya se pagó su comisión la semana pasada

        $c = Livewire::test(PantallaNomina::class)->assertViewHas('cobrosN', 2)->call('alternarCobros')->assertSee('Quitar');
        $c->call('quitarCobro', $repetida->id, 'no')->assertSet('error', 'Escribe por qué se quita (mínimo 5 letras), por ejemplo "ya se comisionó la semana pasada".');
        $c->call('quitarCobro', $repetida->id, 'Ya se comisionó la semana pasada')
            ->assertViewHas('cobrosN', 1)->assertSee('Quitados de la comisión (1)')->assertSee('Quitó Andrea');
        $this->assertEquals(200, (float) Nomina::where('semana', 40)->first()->bolsa);   // 10% de 2,000: la repetida ya no cuenta
        $this->assertNotNull($repetida->fresh()->comision_excluida_at);
        // No vuelve a aparecer en semanas siguientes.
        $this->assertFalse(CalculoNomina::cobros(2026, 41)->whereKey($repetida->id)->exists());
        // En las semanas siguientes ya no sale en la lista de arriba: queda plegado en "Quitados en semanas anteriores".
        Carbon::setTestNow('2026-10-22 12:00:00');
        Livewire::test(PantallaNomina::class)->assertSet('semana', 43)
            ->assertDontSee('Quitados de la comisión (')->assertSee('Quitados en semanas anteriores (1)')->assertDontSeeHtml('<details open');
        Carbon::setTestNow('2026-10-01 12:00:00');

        $c->call('regresarCobro', $repetida->id)->assertViewHas('cobrosN', 2);
        $this->assertNull($repetida->fresh()->comision_excluida_at);
        $this->assertTrue($repetida->cambios()->where('campo', 'comision')->count() === 2);

        // Sin el permiso de nómina no se puede.
        $vendedor = User::factory()->create(['rol' => 'vendedor', 'permisos' => []]);
        $this->actingAs($vendedor);
        Livewire::test(PantallaNomina::class)->assertForbidden();
    }

    public function test_abre_la_semana_actual_con_los_cobros_y_el_reparto(): void
    {
        $this->actingAs($this->admin());
        [$alberto, $rosa, $ernesto] = $this->equipo();
        $this->cobrada('2026-09-29', 10000, 8000);   // utilidad 2,000
        $this->cobrada('2026-09-27', 120, 100);      // domingo: cuenta en esta semana
        Ajuste::guardar(CalculoNomina::DESDE, '2026-09-01');
        $this->cobrada('2026-09-20', 1000, 0);       // se cobró en una semana anterior y nunca se comisionó

        $c = Livewire::test(PantallaNomina::class)
            ->assertSet('anio', 2026)->assertSet('semana', 40)->assertSet('ver', 'semana')
            ->assertSee('Semana 40 · lun 28 sep al sáb 3 oct 2026')
            ->assertViewHas('cobrosN', 3)
            ->assertViewHas('total', fn ($t) => abs($t - (3000 + 2400 + 4000 + 302)) < 0.01);   // sueldos + bolsa de 302

        $n = Nomina::where(['tipo' => 'semanal', 'anio' => 2026, 'semana' => 40])->firstOrFail();
        $this->assertSame('3020.00', $n->utilidad);
        $this->assertSame('302.00', $n->bolsa);
        $c->assertSee('$302.00')->assertDontSee('CLIENTE 1');   // la lista de cobros está oculta

        $c->call('alternarCobros')->assertSee('CLIENTE 1')->assertSee('CLIENTE 2')->assertSee('CLIENTE 3')->assertSee('Folio');
        // El del 20-sep se cobró antes de que empezara la semana y apenas entra: "cobro atrasado" (solo ese).
        $c->assertSee('cobro atrasado');
        $this->assertSame(2, substr_count($c->html(), 'cobro atrasado</span>'));   // la tabla de escritorio y la tarjeta del celular
    }

    public function test_cambiar_de_semana_o_de_anio(): void
    {
        $this->actingAs($this->admin());
        $this->equipo();
        Livewire::test(PantallaNomina::class)
            ->set('semana', 39)->assertSee('Semana 39 · lun 21 al sáb 26 sep 2026')
            ->call('semanaActual')->assertSet('semana', 40)
            ->set('anio', 2027)->assertSet('semana', 40)->assertSet('anio', 2027);
        $this->assertSame(1, Nomina::where(['anio' => 2026, 'semana' => 39])->count());
    }

    public function test_editar_una_linea_recalcula_el_total_de_la_semana(): void
    {
        $this->actingAs($this->admin());
        [$alberto, $rosa, $ernesto] = $this->equipo();
        $this->cobrada('2026-09-29', 10000, 0);   // bolsa 1,000
        $n = CalculoNomina::semana(2026, 40);
        $l = $this->linea($n, $alberto);
        $this->assertSame('3450.00', $l->total);  // 3000 + 450

        $c = Livewire::test(PantallaNomina::class);
        $c->call('guardarLinea', $l->id, 'sueldo_base', '3,500')->assertSet('error', '');
        $this->assertSame('3500.00', $l->fresh()->sueldo_base);
        $this->assertSame('3950.00', $l->fresh()->total);

        // El % cambia la comisión (no está escrita a mano).
        $c->call('guardarLinea', $l->id, 'comision_pct', '50');
        $this->assertSame('500.00', $l->fresh()->comision);
        $this->assertFalse((bool) $l->fresh()->comision_manual);

        // Bono o descuento con nota.
        $c->call('guardarLinea', $l->id, 'ajuste', '-200')->call('guardarLinea', $l->id, 'notas', 'guantes');
        $this->assertSame('3800.00', $l->fresh()->total);   // 3500 + 500 − 200
        $this->assertSame('guantes', $l->fresh()->notas);

        // Comisión a mano: queda marcada y no se recalcula; "volver a calcular" la suelta.
        $c->call('guardarLinea', $l->id, 'comision', '1200');
        $this->assertTrue((bool) $l->fresh()->comision_manual);
        $this->assertSame('4500.00', $l->fresh()->total);
        $c->assertSee('Escrita a mano')->call('volverACalcular', $l->id);
        $this->assertFalse((bool) $l->fresh()->comision_manual);
        $this->assertSame('500.00', $l->fresh()->comision);
        $this->assertSame('3800.00', $l->fresh()->total);

        // El total de la semana de la pantalla suma todas las líneas.
        $esperado = round((float) $n->fresh()->lineas()->sum('total'), 2);
        $c->assertViewHas('total', $esperado);
    }

    public function test_valores_raros_se_rechazan_con_aviso(): void
    {
        $this->actingAs($this->admin());
        [$alberto] = $this->equipo();
        $l = $this->linea(CalculoNomina::semana(2026, 40), $alberto);
        $c = Livewire::test(PantallaNomina::class);

        $c->call('guardarLinea', $l->id, 'sueldo_base', 'mucho')->assertSet('error', 'El sueldo base tiene que ser un número.');
        $c->call('guardarLinea', $l->id, 'comision_pct', '150')->assertSee('El porcentaje tiene que estar entre 0 y 100.');
        $c->call('guardarLinea', $l->id, 'sueldo_base', '-5')->assertSet('error', 'El sueldo base tiene que estar entre 0 y 9,999,999.');
        $this->assertSame('3000.00', $l->fresh()->sueldo_base);
        $this->assertSame('45.00', $l->fresh()->comision_pct);
    }

    public function test_descuento_de_prestamo_no_pasa_del_saldo(): void
    {
        $this->actingAs($this->admin());
        [$alberto, $rosa] = $this->equipo();
        Prestamo::create(['user_id' => $alberto->id, 'nombre' => 'Alberto', 'monto' => 5000, 'fecha' => '2026-10-01', 'descuento_semanal' => 500]);
        $n = CalculoNomina::semana(2026, 40);
        $l = $this->linea($n, $alberto);
        $this->assertSame('500.00', $l->descuento_prestamo);   // el sugerido

        $c = Livewire::test(PantallaNomina::class)->assertSee('Debe $5,000.00');
        $c->call('guardarLinea', $l->id, 'descuento_prestamo', '6000')->assertSee('El descuento no puede pasar del saldo del préstamo ($5,000.00).');
        $this->assertSame('500.00', $l->fresh()->descuento_prestamo);
        $c->call('guardarLinea', $l->id, 'descuento_prestamo', '800');
        $this->assertSame('800.00', $l->fresh()->descuento_prestamo);
        $this->assertSame('2200.00', $l->fresh()->total);   // 3000 + 0 − 800 (no hay cobros)

        // Quien no debe nada no puede llevar descuento de préstamo.
        $lr = $this->linea($n, $rosa);
        $c->call('guardarLinea', $lr->id, 'descuento_prestamo', '100')->assertSee('no tiene préstamos con saldo');
        $this->assertSame('0.00', $lr->fresh()->descuento_prestamo);

        // El sugerido se puede volver a poner con un clic.
        $c->call('usarDescuentoSugerido', $l->id);
        $this->assertSame('500.00', $l->fresh()->descuento_prestamo);
    }

    public function test_registrar_pago_deja_la_semana_de_solo_lectura(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        [$alberto] = $this->equipo();
        $v = $this->cobrada('2026-09-29', 1000, 0);
        Prestamo::create(['user_id' => $alberto->id, 'nombre' => 'Alberto', 'monto' => 5000, 'fecha' => '2026-10-01', 'descuento_semanal' => 500]);

        $c = Livewire::test(PantallaNomina::class)->assertSee('Registrar pago');
        $c->call('registrarPago')->assertSee('Pago registrado');
        $n = Nomina::where(['tipo' => 'semanal', 'semana' => 40])->firstOrFail();
        $this->assertTrue($n->pagada());
        $this->assertSame($admin->id, $n->user_id);
        $this->assertSame($n->id, $v->fresh()->nomina_id);
        $this->assertSame(1, PrestamoAbono::where('nomina_id', $n->id)->count());

        // Todo queda de solo lectura: no hay campos editables ni botón de pago, y el servidor tampoco deja.
        $c->assertSee('Pagada')->assertDontSee('Registrar pago')->assertDontSee('guardarLinea')->assertSee('Reabrir semana');
        $l = $this->linea($n, $alberto);
        $c->call('guardarLinea', $l->id, 'sueldo_base', '9999')->assertSee('ya está pagada');
        $this->assertSame('3000.00', $l->fresh()->sueldo_base);
        $c->call('quitarLinea', $l->id)->assertSee('ya está pagada');
        $this->assertSame(3, $n->lineas()->count());
        $c->call('registrarPago')->assertSee('ya estaba pagada');
        $this->assertSame(1, PrestamoAbono::where('nomina_id', $n->id)->count());   // no se abonó dos veces

        // El admin la puede reabrir: se sueltan las ventas y el abono.
        $c->call('reabrirSemana')->assertSee('se reabrió');
        $this->assertFalse($n->fresh()->pagada());
        $this->assertNull($v->fresh()->nomina_id);
        $this->assertSame(0, PrestamoAbono::count());
    }

    public function test_agregar_y_quitar_personas(): void
    {
        $this->actingAs($this->admin());
        [$alberto] = $this->equipo();
        $andrea2 = User::factory()->create(['name' => 'Andrea', 'sueldo_base' => 2000]);   // no está "en nómina"
        $inactivo = User::factory()->create(['name' => 'Inactivo Pérez', 'activo' => false]);
        $n = CalculoNomina::semana(2026, 40);
        $this->assertSame(3, $n->lineas()->count());

        $c = Livewire::test(PantallaNomina::class)->assertSee('Andrea')->assertDontSee('Inactivo Pérez');
        $c->set('agregarUsuario', (string) $andrea2->id)->call('agregarPersona')->assertSee('Se agregó a Andrea');
        $this->assertSame(4, $n->lineas()->count());
        $this->assertSame('2000.00', $this->linea($n, $andrea2)->total);

        $c->set('agregarUsuario', (string) $inactivo->id)->call('agregarPersona')->assertSet('error', 'Elige a la persona que quieres agregar.');
        $this->assertSame(4, $n->lineas()->count());

        // Quitar a cualquiera de ESTA semana; no vuelve sola al recargar.
        $c->call('quitarLinea', $this->linea($n, $andrea2)->id)->assertSee('Se quitó a Andrea');
        $c->call('quitarLinea', $this->linea($n, $alberto)->id)->assertSee('Se quitó a Alberto');
        $this->assertSame(2, $n->lineas()->count());
        CalculoNomina::semana(2026, 40);
        $this->assertSame(2, $n->lineas()->count());
    }

    public function test_no_se_puede_registrar_una_nomina_sin_personas(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(PantallaNomina::class)->call('registrarPago')->assertSee('No hay nadie en la nómina');
        $this->assertSame(0, Nomina::where('estatus', 'pagada')->count());
    }

    // ------------------------------------------------------------------------------------------ préstamos

    public function test_prestamo_nuevo_abono_suelto_y_saldo(): void
    {
        $this->actingAs($this->admin());
        [$alberto] = $this->equipo();
        $fuera = User::factory()->create(['name' => 'Fuera de nómina']);

        $c = Livewire::withQueryParams(['ver' => 'prestamos'])->test(PantallaNomina::class)->assertSee('No hay préstamos todavía');

        // Solo se le presta a quien está en nómina.
        $c->call('abrirNuevoPrestamo')->assertSee('Alberto')->assertDontSee('Fuera de nómina')
            ->call('cancelarPrestamo')->call('abrirNuevoPrestamo')
            ->set('pUsuario', (string) $alberto->id)->set('pMonto', '5,000')->set('pFecha', '2026-10-01')->set('pDescuento', '500')->set('pNota', 'guantes')
            ->call('guardarPrestamo')->assertHasNoErrors()->assertSee('Préstamo de $5,000.00')->assertSee('Debe $5,000.00');
        $p = Prestamo::firstOrFail();
        $this->assertSame($alberto->id, $p->user_id);
        $this->assertSame('500.00', $p->descuento_semanal);

        // Validaciones del formulario.
        $c->call('abrirNuevoPrestamo')->call('guardarPrestamo')->assertHasErrors(['pUsuario', 'pMonto']);
        $c->set('pUsuario', (string) $fuera->id)->set('pMonto', '100')->call('guardarPrestamo')->assertHasErrors('pUsuario');
        $c->set('pUsuario', (string) $alberto->id)->set('pMonto', '100')->set('pDescuento', '300')->call('guardarPrestamo')->assertHasErrors('pDescuento');
        $this->assertSame(1, Prestamo::count());

        // Abono suelto: baja el saldo, no tiene nómina, y no puede pasar de lo que se debe.
        $c->call('abrirAbono', $p->id)->set('aMonto', '1500')->set('aFecha', '2026-10-02')->set('aNota', 'en efectivo')->call('guardarAbono')
            ->assertHasNoErrors()->assertSee('le quedan $3,500.00')->assertSee('Abono suelto');
        $a = PrestamoAbono::firstOrFail();
        $this->assertNull($a->nomina_id);
        $this->assertSame('1500.00', $a->monto);
        $this->assertSame(3500.0, $p->fresh()->saldo());

        $c->call('abrirAbono', $p->id)->set('aMonto', '9000')->call('guardarAbono')->assertHasErrors('aMonto');
        $this->assertSame(1, PrestamoAbono::count());

        // Editar el descuento semanal.
        $c->call('guardarDescuentoSemanal', $p->id, '750')->assertSee('Descuento semanal de $750.00');
        $this->assertSame('750.00', $p->fresh()->descuento_semanal);
        $c->call('guardarDescuentoSemanal', $p->id, '99999')->assertSee('no pasar del monto');
        $this->assertSame('750.00', $p->fresh()->descuento_semanal);

        // Liquidar con otro abono lo manda a "Saldados"; quitar el abono suelto lo reabre.
        $c->call('abrirAbono', $p->id)->set('aMonto', '3500')->call('guardarAbono');
        $this->assertNotNull($p->fresh()->saldado_at);
        $c->assertSee('Saldados (1)')->assertSee('Sin deudas');
        $c->call('quitarAbono', PrestamoAbono::orderByDesc('id')->value('id'));
        $this->assertNull($p->fresh()->saldado_at);
        $this->assertSame(3500.0, $p->fresh()->saldo());
    }

    public function test_los_abonos_de_nomina_se_ven_con_su_semana_y_no_se_quitan_a_mano(): void
    {
        $this->actingAs($this->admin());
        [$alberto] = $this->equipo();
        $p = Prestamo::create(['user_id' => $alberto->id, 'nombre' => 'Alberto', 'monto' => 700, 'fecha' => '2026-09-01', 'descuento_semanal' => 500]);
        CalculoNomina::registrar(CalculoNomina::semana(2026, 40));
        $abono = PrestamoAbono::firstOrFail();

        Livewire::withQueryParams(['ver' => 'prestamos'])->test(PantallaNomina::class)->assertSee('En nómina semana 40')->assertSee('Saldo $200.00')
            ->call('quitarAbono', $abono->id)->assertSee('salió de una nómina');
        $this->assertSame(1, PrestamoAbono::count());
        $this->assertSame(200.0, $p->fresh()->saldo());
    }

    // ------------------------------------------------------------------------------------------ reparto

    public function test_reparto_del_socio_con_gastos_monto_a_mano_y_pago(): void
    {
        $this->actingAs($this->admin());
        [, , $ernesto] = $this->equipo();
        $this->cobrada('2026-09-05', 100000, 80000);   // 20,000
        $this->cobrada('2026-09-30', 5000, 4000);      // 1,000
        $this->cobrada('2026-10-01', 9999, 0);         // otro mes

        // Por omisión, el mes anterior.
        $c = Livewire::withQueryParams(['ver' => 'reparto'])->test(PantallaNomina::class)->assertSet('repMes', 9)->assertSet('repAnio', 2026)
            ->assertSee('Septiembre')->assertSeeHtml('>Gastos</a>: <strong>')->assertDontSee('Usar este monto');
        $c->assertViewHas('rep', fn ($r) => $r->utilidad == 21000 && $r->bolsa == 4200)->assertViewHas('retenido', 16800.0);

        $c->call('guardarGastos', '6000');
        $c->assertViewHas('neta', 15000.0)->assertViewHas('rep', fn ($r) => (float) $r->bolsa === 3000.0 && (float) $r->gastos === 6000.0);
        $r = Nomina::where('tipo', 'reparto')->firstOrFail();
        $this->assertSame($ernesto->id, $r->lineas()->value('user_id'));
        $this->assertSame('3000.00', $r->lineas()->value('total'));
        $c->assertViewHas('retenido', 12000.0)->assertSee('Ernesto Pérez');

        // Monto final a mano (queda marcado) y vuelve a calcularse.
        $c->call('guardarMontoReparto', '2500');
        $this->assertSame('2500.00', $r->lineas()->value('total'));
        $this->assertTrue((bool) $r->lineas()->value('comision_manual'));
        $c->call('guardarGastos', '5000');   // un cambio de gastos no pisa el monto escrito a mano
        $this->assertSame('2500.00', $r->lineas()->value('total'));
        $c->assertSee('Escrito a mano')->call('volverACalcularReparto');
        $this->assertSame('3200.00', $r->lineas()->value('total'));   // 20% de (21,000 − 5,000)

        $c->call('guardarGastos', 'abc')->assertSee('Los gastos tienen que ser un número');
        $this->assertSame('5000.00', $r->fresh()->gastos);

        // Registrar: queda pagado y de solo lectura; solo el admin lo reabre.
        $c->call('registrarReparto')->assertSee('Reparto registrado');
        $this->assertTrue($r->fresh()->pagada());
        $c->assertSee('Pagado')->assertDontSee('Registrar pago')->call('guardarGastos', '0')->assertSee('ya está pagado');
        $this->assertSame('5000.00', $r->fresh()->gastos);
        $c->call('reabrirReparto')->assertSee('se reabrió');
        $this->assertFalse($r->fresh()->pagada());
    }

    public function test_cambiar_el_mes_del_reparto_no_toca_otros_meses(): void
    {
        $this->actingAs($this->admin());
        $this->equipo();
        $this->cobrada('2026-08-10', 1000, 0);
        Livewire::withQueryParams(['ver' => 'reparto'])->test(PantallaNomina::class)->call('guardarGastos', '100')
            ->set('repMes', 8)->assertViewHas('rep', fn ($r) => $r->mes === 8 && $r->utilidad == 1000 && (float) $r->gastos === 0.0 && (float) $r->bolsa === 200.0);
        $this->assertSame(2, Nomina::where('tipo', 'reparto')->count());
    }

    // ------------------------------------------------------------------------------------------ historial

    public function test_historial_con_total_por_persona_y_por_mes(): void
    {
        $this->actingAs($this->admin());
        [$alberto, $rosa, $ernesto] = $this->equipo();
        $this->cobrada('2026-09-22', 10000, 0);   // semana 39 (lun 21–sáb 26 sep): bolsa 1,000
        $this->cobrada('2026-10-01', 5000, 0);    // semana 40 (28 sep–3 oct): bolsa 500
        Prestamo::create(['user_id' => $rosa->id, 'nombre' => 'Rosa Ruiz', 'monto' => 1000, 'fecha' => '2026-09-01', 'descuento_semanal' => 100]);
        Ajuste::guardar(CalculoNomina::DESDE, '2026-09-01');

        $n39 = CalculoNomina::semana(2026, 39);
        $this->linea($n39, $alberto)->update(['ajuste' => 100, 'notas' => 'bono']);
        CalculoNomina::recalcular($n39);
        CalculoNomina::registrar($n39);
        $n40 = CalculoNomina::semana(2026, 40);
        CalculoNomina::registrar($n40);
        $reparto = CalculoNomina::reparto(2026, 9, 0);
        CalculoNomina::registrar($reparto);
        CalculoNomina::semana(2026, 41);   // borrador: no sale en el historial

        $c = Livewire::withQueryParams(['ver' => 'historial'])->test(PantallaNomina::class)->assertSet('histAnio', '2026')
            ->assertSee('Cuánto le pagué a cada quien')->assertSee('Semana 39')->assertSee('Semana 40')->assertSee('Reparto del socio · Septiembre 2026')
            ->assertDontSee('Semana 41');

        $personas = collect($c->viewData('personas'))->keyBy('nombre');
        // Alberto: 2 semanas × 3000 + 45% de (1,000 + 500) = 675 de comisiones + 100 de bono.
        $this->assertEquals(6000, $personas['Alberto']['sueldo']);
        $this->assertEquals(675, $personas['Alberto']['comisiones']);
        $this->assertEquals(100, $personas['Alberto']['bonos']);
        $this->assertEquals(6775, $personas['Alberto']['total']);
        // Rosa: descuento de préstamo de 100 por semana (resta en "bonos y descuentos").
        $this->assertEquals(-200, $personas['Rosa Ruiz']['bonos']);
        $this->assertEquals(2 * 2400 + 525 - 200, $personas['Rosa Ruiz']['total']);
        // Ernesto: sueldo + 20% + su reparto del socio aparte (20% de 15,000... la utilidad del mes de septiembre).
        $this->assertEquals(8000, $personas['Ernesto Pérez']['sueldo']);
        $this->assertEquals(300, $personas['Ernesto Pérez']['comisiones']);
        $this->assertEquals((float) $reparto->fresh()->lineas()->value('total'), $personas['Ernesto Pérez']['reparto']);
        $c->assertViewHas('totalPagado', round($personas->sum('total'), 2));

        // Por mes: la semana 40 termina en octubre y la 39 en septiembre (con el reparto de septiembre).
        $c->set('histMes', '10')->assertSee('Semana 40')->assertDontSee('Semana 39')->assertDontSee('Reparto del socio · Septiembre');
        $oct = collect($c->viewData('personas'))->keyBy('nombre');
        $this->assertEquals(3000 + 225, $oct['Alberto']['total']);
        $c->set('histMes', '')->set('histAnio', '2025')->assertSee('No hay nóminas pagadas en este periodo.');
        $c->set('histAnio', 'todos')->assertSee('Semana 39');

        // Abrir el detalle de una semana.
        $c->set('histAnio', '2026')->call('alternarNomina', $n39->id)->assertSee('bono')->assertSee('Abrir en la pestaña Semana');
    }

    // ------------------------------------------------------------------------------------------ empleados

    public function test_empleados_guarda_por_fila_y_avisa_si_los_porcentajes_no_suman_100(): void
    {
        $this->actingAs($this->admin());
        [$alberto, $rosa, $ernesto] = $this->equipo();
        $nuevo = User::factory()->create(['name' => 'Ivonne Garza']);
        $c = Livewire::withQueryParams(['ver' => 'empleados'])->test(PantallaNomina::class)->assertSet('ver', 'empleados')->assertSee('Ivonne Garza')->assertDontSee('suman');

        $c->set("emp.{$nuevo->id}.en_nomina", true)->set("emp.{$nuevo->id}.sueldo_base", '2250')->set("emp.{$nuevo->id}.comision_pct", '10')
            ->set("emp.{$nuevo->id}.fecha_ingreso", '2026-04-01')->set("emp.{$nuevo->id}.trabaja_sabados", true)
            ->call('guardarEmpleado', $nuevo->id)->assertHasNoErrors()->assertSee('Se guardaron los datos de Ivonne Garza');
        $nuevo->refresh();
        $this->assertTrue($nuevo->en_nomina);
        $this->assertTrue($nuevo->trabaja_sabados);
        $this->assertSame('2250.00', $nuevo->sueldo_base);
        $this->assertSame('2026-04-01', $nuevo->fecha_ingreso->toDateString());
        $c->assertSee('suman 110%');   // 45 + 35 + 20 + 10

        $c->set("emp.{$nuevo->id}.comision_pct", '0')->call('guardarEmpleado', $nuevo->id)->assertDontSee('suman');
        $c->set("emp.{$rosa->id}.comision_pct", '30')->assertSee('suman 95%');

        // Validaciones: no se guarda nada si el dato es raro.
        $c->set("emp.{$alberto->id}.sueldo_base", '-1')->call('guardarEmpleado', $alberto->id)->assertHasErrors("emp.{$alberto->id}.sueldo_base");
        $c->set("emp.{$alberto->id}.sueldo_base", '3000')->set("emp.{$alberto->id}.comision_pct", '120')->call('guardarEmpleado', $alberto->id)->assertHasErrors("emp.{$alberto->id}.comision_pct");
        $this->assertSame('3000.00', $alberto->fresh()->sueldo_base);
        $this->assertSame('45.00', $alberto->fresh()->comision_pct);
    }

    public function test_empleados_no_muestra_inactivos_ni_ned(): void
    {
        $this->actingAs($this->admin());
        User::factory()->create(['name' => 'Baja Reciente', 'activo' => false]);
        User::factory()->create(['name' => 'Pedro Externo', 'rol' => 'nedtracking']);
        Livewire::withQueryParams(['ver' => 'empleados'])->test(PantallaNomina::class)->assertDontSee('Baja Reciente')->assertDontSee('Pedro Externo');
    }
}

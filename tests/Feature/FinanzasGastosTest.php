<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Gastos;
use App\Livewire\Finanzas\Nomina as PantallaNomina;
use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\Nomina;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Pantalla Finanzas → Gastos: permisos, captura rápida, movimientos entre cuentas, filtros, resumen y reglas de edición. */
class FinanzasGastosTest extends TestCase
{
    use RefreshDatabase;

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
        return User::factory()->create(['rol' => 'admin']);
    }

    private function conPermiso(): User
    {
        return User::factory()->create(['rol' => 'vendedor', 'permisos' => ['gastos']]);
    }

    private function gasto(array $extra = []): Gasto
    {
        return Gasto::create($extra + [
            'fecha' => '2026-09-10', 'monto' => 100, 'de' => 'masume', 'tipo' => 'gasto', 'categoria' => 'GASOLINA',
            'descripcion' => 'Gasolina camioneta', 'origen' => 'manual',
        ]);
    }

    // ===== Acceso =====

    public function test_el_admin_entra_y_el_vendedor_solo_con_permiso(): void
    {
        $this->get(route('finanzas.gastos'))->assertRedirect();   // sin sesión

        $this->actingAs($this->admin())->get(route('finanzas.gastos'))->assertOk()->assertSee('Capturar un gasto');
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']))->get(route('finanzas.gastos'))->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => []]))->get(route('finanzas.gastos'))->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => 'campo', 'permisos' => ['ventas']]))->get(route('finanzas.gastos'))->assertForbidden();
        $this->actingAs($this->conPermiso())->get(route('finanzas.gastos'))->assertOk();
    }

    public function test_el_menu_muestra_gastos_solo_a_quien_tiene_permiso(): void
    {
        $this->actingAs($this->conPermiso())->get(route('finanzas.gastos'))->assertSee(url('/finanzas/gastos').'"', false);
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => ['ventas']]))->get(route('finanzas.ventas'))
            ->assertDontSee(url('/finanzas/gastos').'"', false);
    }

    public function test_el_componente_tambien_valida_el_permiso_en_el_servidor(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        Livewire::test(Gastos::class)->assertForbidden();

        // Si le quitan el permiso con la pantalla abierta, la siguiente acción ya no pasa.
        $usuario = $this->conPermiso();
        $this->actingAs($usuario);
        $pantalla = Livewire::test(Gastos::class)->assertOk()->set('form.monto', '50');
        $usuario->update(['permisos' => []]);
        $pantalla->call('guardar')->assertForbidden();
        $this->assertSame(0, Gasto::count());
    }

    // ===== Captura =====

    public function test_captura_un_gasto_de_masume_y_deja_fecha_y_cuenta(): void
    {
        $usuario = $this->conPermiso();
        $hey = Cuenta::obtener('Hey Banco (Débito)');
        $this->actingAs($usuario);

        Livewire::test(Gastos::class)
            ->assertSet('form.fecha', '2026-10-01')->assertSet('form.de', 'masume')
            ->set('form.monto', '1,250.50')->set('form.categoria', 'GASOLINA')->set('form.descripcion', '  Gasolina   camioneta ')
            ->set('form.cuenta_id', (string) $hey->id)
            ->call('guardar')->assertHasNoErrors()
            ->assertSee('Listo: se guardó el gasto de $1,250.50')
            ->assertSet('form.monto', '')->assertSet('form.descripcion', '')->assertSet('form.categoria', '')
            ->assertSet('form.fecha', '2026-10-01')->assertSet('form.cuenta_id', (string) $hey->id);

        $g = Gasto::firstOrFail();
        $this->assertSame('1250.50', $g->monto);
        $this->assertSame('masume', $g->de);
        $this->assertSame('gasto', $g->tipo);
        $this->assertSame('GASOLINA', $g->categoria);
        $this->assertSame('Gasolina camioneta', $g->descripcion);
        $this->assertSame($hey->id, $g->cuenta_id);
        $this->assertSame('manual', $g->origen);
        $this->assertSame($usuario->id, $g->user_id);
    }

    public function test_captura_un_gasto_personal_con_cuenta_nueva(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Gastos::class)
            ->set('form.fecha', '2026-09-28')->set('form.monto', '320')->set('form.de', 'personal')
            ->set('form.categoria', 'PERSONAL Y EVENTOS')
            ->set('form.cuenta_id', Gastos::OTRA)->set('form.cuenta_nueva', 'bbva empresarial')
            ->call('guardar')->assertHasNoErrors();

        $g = Gasto::firstOrFail();
        $this->assertSame('personal', $g->de);
        $this->assertSame('PERSONAL Y EVENTOS', $g->categoria);
        $this->assertSame('2026-09-28', $g->fecha->toDateString());
        $this->assertSame('BBVA EMPRESARIAL', Cuenta::findOrFail($g->cuenta_id)->nombre);

        // La cuenta se reutiliza sin duplicarse (sin acentos ni mayúsculas).
        Livewire::test(Gastos::class)
            ->set('form.monto', '10')->set('form.categoria', 'PERSONAL Y EVENTOS')
            ->set('form.cuenta_id', Gastos::OTRA)->set('form.cuenta_nueva', 'BBVA Empresarial')->call('guardar')->assertHasNoErrors();
        $this->assertSame(1, Cuenta::count());
    }

    public function test_valida_monto_categoria_y_fecha(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Gastos::class)
            ->call('guardar')->assertHasErrors(['form.monto', 'form.categoria'])
            ->set('form.monto', 'abc')->set('form.categoria', 'GASOLINA')->call('guardar')->assertHasErrors('form.monto')->assertSee('debe ser un número')
            ->set('form.monto', '0')->call('guardar')->assertHasErrors('form.monto')->assertSee('mayor a cero')
            ->set('form.monto', '-5')->call('guardar')->assertHasErrors('form.monto')
            ->set('form.monto', '10')->set('form.fecha', '2062-01-01')->call('guardar')->assertHasErrors('form.fecha')
            ->set('form.fecha', '2026-10-01')->set('form.de', 'otro')->call('guardar')->assertHasErrors('form.de')
            ->set('form.de', 'masume')->set('form.categoria', 'INVENTADA')->call('guardar')->assertHasErrors('form.categoria')
            ->set('form.categoria', 'GASOLINA')->set('form.cuenta_id', Gastos::OTRA)->call('guardar')->assertHasErrors('form.cuenta_nueva');
        $this->assertSame(0, Gasto::count());
    }

    public function test_un_movimiento_entre_cuentas_pide_destino_y_no_suma_en_los_totales(): void
    {
        $hey = Cuenta::obtener('Hey Banco (Débito)');
        $inv = Cuenta::obtener('VOLARIS INVEX');
        $this->gasto(['monto' => 1000]);
        $this->actingAs($this->admin());

        $c = Livewire::test(Gastos::class)
            ->set('form.tipo', 'movimiento')->set('form.monto', '20000')->set('form.categoria', 'PAGO PRESTAMO')
            ->assertSee('Llega a la cuenta')
            ->call('guardar')->assertHasErrors(['form.cuenta_id', 'form.destino_id'])
            ->set('form.cuenta_id', (string) $hey->id)->set('form.destino_id', (string) $hey->id)
            ->call('guardar')->assertHasErrors('form.destino_id')->assertSee('distinta')
            ->set('form.destino_id', (string) $inv->id)
            ->call('guardar')->assertHasNoErrors()->assertSee('Listo: se guardó el movimiento entre cuentas de $20,000.00');

        $m = Gasto::where('tipo', 'movimiento')->firstOrFail();
        $this->assertSame($inv->id, $m->cuenta_destino_id);
        $this->assertSame($hey->id, $m->cuenta_id);

        $c->assertViewHas('totales', fn ($t) => $t['total'] == 1000.0 && $t['masume'] == 1000.0 && $t['n'] === 1
            && $t['mov_total'] == 20000.0 && $t['mov_n'] === 1)
            ->assertViewHas('resumen', fn ($r) => count($r) === 1 && $r[0]['categoria'] === 'GASOLINA')
            ->assertSee('Movimiento');

        // Un gasto normal no guarda cuenta destino aunque venga escrita.
        $c->set('form.tipo', 'gasto')->set('form.monto', '5')->set('form.categoria', 'GASOLINA')->set('form.destino_id', (string) $inv->id)->call('guardar');
        $this->assertNull(Gasto::where('tipo', 'gasto')->orderByDesc('id')->first()->cuenta_destino_id);
    }

    // ===== Lista, filtros y resumen =====

    public function test_filtros_por_anio_mes_semana_de_categoria_cuenta_tipo_y_texto(): void
    {
        $hey = Cuenta::obtener('Hey Banco (Débito)');
        $bbva = Cuenta::obtener('BBVA PLATINUM');
        $this->gasto(['fecha' => '2026-09-10', 'monto' => 100, 'descripcion' => 'Gasolina camioneta', 'cuenta_id' => $hey->id]);
        $this->gasto(['fecha' => '2026-09-28', 'monto' => 200, 'categoria' => 'ALIMENTOS', 'descripcion' => 'Comida del equipo', 'cuenta_id' => $bbva->id]);
        $this->gasto(['fecha' => '2026-10-03', 'monto' => 300, 'de' => 'personal', 'categoria' => 'ALIMENTOS', 'descripcion' => 'Cena familiar']);
        $this->gasto(['fecha' => '2026-10-04', 'monto' => 400, 'descripcion' => 'Domingo: va a la semana 41']);
        $this->gasto(['fecha' => '2025-12-31', 'monto' => 500, 'categoria' => 'IMPUESTOS', 'descripcion' => 'Del año pasado']);
        $this->gasto(['fecha' => '2026-09-15', 'monto' => 600, 'tipo' => 'movimiento', 'categoria' => 'PAGO TARJETAS', 'descripcion' => 'Pago a la tarjeta']);
        $this->gasto(['fecha' => '2026-09-16', 'monto' => 700, 'categoria' => null, 'descripcion' => 'Sin categoría']);
        $this->actingAs($this->admin());

        $total = fn ($c) => $c->viewData('totales')['total'];
        $c = Livewire::test(Gastos::class)->assertSet('anio', '2026');
        $this->assertEquals(100 + 200 + 300 + 400 + 700, $total($c));   // el movimiento y el 2025 no entran

        $c->set('anio', '2025');
        $this->assertEquals(500, $total($c));
        $c->set('anio', 'todos');
        $this->assertEquals(2200, $total($c));
        $c->set('anio', '2026')->set('mes', '9');
        $this->assertEquals(100 + 200 + 700, $total($c));
        $c->set('mes', '10');
        $this->assertEquals(700, $total($c));

        // Semana 40: del domingo 27-sep al sábado 3-oct (lo del domingo 4-oct cuenta en la 41).
        $c->set('mes', '')->set('semana', '40')->assertSee('Semana 40');
        $this->assertEquals(200 + 300, $total($c));
        $c->set('semana', '41');
        $this->assertEquals(400, $total($c));
        $c->set('semana', '');

        $c->set('de', 'personal');
        $this->assertEquals(300, $total($c));
        $c->set('de', 'masume');
        $this->assertEquals(1400, $total($c));
        $c->set('de', '')->set('categoria', 'ALIMENTOS');
        $this->assertEquals(500, $total($c));
        $c->set('categoria', Gastos::SIN_CATEGORIA);
        $this->assertEquals(700, $total($c));
        $c->set('categoria', '')->set('cuenta', (string) $bbva->id);
        $this->assertEquals(200, $total($c));
        $c->set('cuenta', '')->set('tipo', 'movimiento');
        $this->assertEquals(0, $total($c));
        $this->assertEquals(600, $c->viewData('totales')['mov_total']);
        $c->set('tipo', 'gasto');
        $this->assertEquals(1700, $total($c));
        $c->set('tipo', '')->set('buscar', 'comida equipo');
        $this->assertEquals(200, $total($c));
        $c->set('buscar', 'camioneta');
        $c->assertSee('Gasolina camioneta')->assertDontSee('Comida del equipo');

        $c->call('limpiar')->assertSet('buscar', '')->assertSet('anio', '2026');
        $this->assertEquals(1700, $total($c));
    }

    public function test_resumen_por_categoria_de_mayor_a_menor_con_porcentaje(): void
    {
        $this->gasto(['monto' => 250, 'categoria' => 'ALIMENTOS']);
        $this->gasto(['monto' => 750, 'categoria' => 'GASOLINA']);
        $this->gasto(['monto' => 250, 'categoria' => 'gasolina ']);   // se junta con GASOLINA (el modelo la normaliza)
        $this->gasto(['monto' => 500, 'categoria' => null]);
        $this->gasto(['monto' => 9999, 'categoria' => 'PAGO TARJETAS', 'tipo' => 'movimiento']);
        $this->actingAs($this->admin());

        $c = Livewire::test(Gastos::class)->assertSee('Por categoría');
        $c->assertViewHas('resumen', fn ($r) => array_column($r, 'categoria') === ['GASOLINA', 'SIN CATEGORÍA', 'ALIMENTOS']
            && array_column($r, 'total') === [1000.0, 500.0, 250.0]
            && array_column($r, 'pct') === [57.1, 28.6, 14.3]
            && array_column($r, 'n') === [2, 1, 1]);
        $c->assertSee('57.1%');
    }

    public function test_pagina_de_a_50_y_la_lista_trae_los_datos_de_cada_gasto(): void
    {
        $hey = Cuenta::obtener('Hey Banco (Débito)');
        for ($i = 1; $i <= 51; $i++) {
            $this->gasto(['monto' => $i, 'descripcion' => "Gasto número {$i}", 'cuenta_id' => $hey->id]);
        }
        $this->actingAs($this->admin());

        Livewire::test(Gastos::class)
            ->assertSee('Página 1 de 2')->assertSee('51 registros')
            ->assertSee('HEY BANCO (DÉBITO)')->assertSee('10/09/2026')->assertSee('Gasolina camioneta', false)->assertDontSee('Gasto número 1<', false)
            ->call('nextPage')->assertSee('Página 2 de 2');
    }

    // ===== Editar y borrar =====

    public function test_quien_captura_edita_y_borra_el_mismo_dia_pero_no_despues_ni_lo_de_otros(): void
    {
        $yo = $this->conPermiso();
        $otro = $this->conPermiso();
        $this->actingAs($yo);

        $mio = $this->gasto(['user_id' => $yo->id, 'monto' => 50]);
        $deOtro = $this->gasto(['user_id' => $otro->id]);
        $viejo = $this->gasto(['user_id' => $yo->id]);
        $viejo->forceFill(['created_at' => now()->subDay()])->saveQuietly();
        $importado = $this->gasto(['user_id' => $yo->id, 'origen' => 'sheets']);

        $c = Livewire::test(Gastos::class)->set('anio', 'todos');
        $c->assertSeeHtml('wire:click="editar('.$mio->id.')"')->assertDontSeeHtml('wire:click="editar('.$deOtro->id.')"')
            ->assertDontSeeHtml('wire:click="editar('.$viejo->id.')"')->assertDontSeeHtml('wire:click="editar('.$importado->id.')"');

        foreach ([$deOtro, $viejo, $importado] as $g) {
            Livewire::test(Gastos::class)->call('editar', $g->id)->assertForbidden();
            Livewire::test(Gastos::class)->call('borrar', $g->id)->assertForbidden();
        }
        $this->assertSame(4, Gasto::count());

        // Editar el propio: se carga en el formulario de arriba y se guarda como cambio (no crea otro).
        $c->call('editar', $mio->id)->assertSet('editandoId', $mio->id)->assertSet('form.monto', '50')->assertSee('Editar el gasto')
            ->set('form.monto', '75.5')->call('guardar')->assertHasNoErrors()->assertSee('Se guardaron los cambios')
            ->assertSet('editandoId', null)->assertSet('form.monto', '');
        $this->assertSame('75.50', $mio->fresh()->monto);
        $this->assertSame(4, Gasto::count());

        // Si ya no es del día, un formulario que quedó abierto tampoco guarda.
        $c->call('editar', $mio->id);
        $mio->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
        $c->set('form.monto', '1')->call('guardar')->assertForbidden();
        $this->assertSame('75.50', $mio->fresh()->monto);

        $nuevo = $this->gasto(['user_id' => $yo->id]);
        Livewire::test(Gastos::class)->call('borrar', $nuevo->id)->assertSee('Se borró el gasto');
        $this->assertSoftDeleted($nuevo);
    }

    public function test_el_admin_edita_y_borra_cualquiera_menos_lo_de_nomina(): void
    {
        $admin = $this->admin();
        $otro = $this->conPermiso();
        $importado = $this->gasto(['user_id' => $otro->id, 'origen' => 'sheets', 'monto' => 10]);
        $nomina = Nomina::create(['tipo' => 'semanal', 'anio' => 2026, 'semana' => 39, 'desde' => '2026-09-21', 'hasta' => '2026-09-26', 'estatus' => 'pagada', 'origen' => 'sistema']);
        $deNomina = $this->gasto(['origen' => 'nomina', 'nomina_id' => $nomina->id, 'categoria' => 'NOMINA', 'descripcion' => 'Nómina semana 39', 'monto' => 9000]);
        $this->actingAs($admin);

        $c = Livewire::test(Gastos::class)->assertSeeHtml('wire:click="editar('.$importado->id.')"')
            ->assertDontSeeHtml('wire:click="editar('.$deNomina->id.')"')->assertDontSeeHtml('wire:click="borrar('.$deNomina->id.')"')
            ->assertSee('Nómina');

        $c->call('editar', $importado->id)->set('form.monto', '12')->call('guardar')->assertHasNoErrors();
        $this->assertSame('12.00', $importado->fresh()->monto);
        $this->assertSame('sheets', $importado->fresh()->origen);

        // Lo de nómina no se toca desde aquí: se mueve reabriendo la nómina.
        $c->call('editar', $deNomina->id)->assertSee('reabre la nómina')->assertSet('editandoId', null);
        $c->call('borrar', $deNomina->id)->assertSee('reabre la nómina');
        $this->assertNotSoftDeleted($deNomina);
        $this->assertSame('9000.00', $deNomina->fresh()->monto);

        $c->call('borrar', $importado->id)->assertSee('Se borró');
        $this->assertSoftDeleted($importado);
        // Lo borrado ya no cuenta en el total.
        $c->assertViewHas('totales', fn ($t) => $t['total'] == 9000.0);
    }

    public function test_la_insignia_de_nomina_enlaza_al_historial(): void
    {
        $this->gasto(['origen' => 'nomina', 'categoria' => 'NOMINA', 'descripcion' => 'Nómina semana 39']);
        $this->actingAs($this->admin());

        Livewire::test(Gastos::class)->assertSeeHtml('href="'.route('finanzas.nomina', ['ver' => 'historial']).'"');
    }

    // ===== Reparto del socio (pestaña de Nómina) =====

    public function test_el_reparto_muestra_los_gastos_del_modulo_y_se_pueden_usar(): void
    {
        $this->actingAs($this->admin());
        Livewire::withQueryParams(['ver' => 'reparto'])->test(PantallaNomina::class)->call('guardarGastos', '0');
        $this->gasto(['fecha' => '2026-09-05', 'monto' => 2000]);
        $this->gasto(['fecha' => '2026-09-30', 'monto' => 500]);
        $this->gasto(['fecha' => '2026-09-06', 'monto' => 700, 'de' => 'personal']);                          // personal: no resta
        $this->gasto(['fecha' => '2026-09-07', 'monto' => 800, 'tipo' => 'movimiento']);                      // movimiento: no resta
        $this->gasto(['fecha' => '2026-09-08', 'monto' => 900, 'categoria' => 'REPARTO SOCIO']);              // reparto: no resta
        $this->gasto(['fecha' => '2026-10-01', 'monto' => 5000]);                                             // otro mes

        $c = Livewire::withQueryParams(['ver' => 'reparto'])->test(PantallaNomina::class)
            ->assertSeeHtml('>Gastos</a>: <strong>')->assertSee('$2,500.00')->assertSee('Usar este monto');
        $c->call('usarGastosDelModulo');
        $this->assertSame('2500.00', Nomina::where('tipo', 'reparto')->firstOrFail()->gastos);
        $c->assertDontSee('Usar este monto');
    }
}

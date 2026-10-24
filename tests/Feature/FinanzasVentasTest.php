<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Ventas;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaCambio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class FinanzasVentasTest extends TestCase
{
    use RefreshDatabase;

    private int $consecutivo = 0;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'admin']);
    }

    /** Vendedor al que el admin le palomeó "Ventas" (como Andrea). */
    private function conPermiso(): User
    {
        return User::factory()->create(['rol' => 'vendedor', 'permisos' => ['ventas']]);
    }

    /** Folios únicos y fáciles de buscar en el HTML ("VTA-0001"). */
    private function venta(array $extra = []): Venta
    {
        $this->consecutivo++;

        return Venta::create($extra + [
            'tipo' => 'factura', 'folio' => sprintf('VTA-%04d', $this->consecutivo), 'cliente_nombre' => 'ACME',
            'fecha' => now()->year.'-03-10', 'costo' => 100, 'subtotal' => 150, 'iva' => 24, 'total' => 174,
            'estatus' => 'pendiente', 'origen' => 'manual',
        ]);
    }

    private function cambio(Venta $venta, string $campo): ?VentaCambio
    {
        return VentaCambio::where('venta_id', $venta->id)->where('campo', $campo)->latest('id')->first();
    }

    // ===== Acceso =====

    public function test_el_admin_entra_y_el_vendedor_solo_con_permiso(): void
    {
        $this->get(route('finanzas.ventas'))->assertRedirect();   // sin sesión

        $this->actingAs($this->admin())->get(route('finanzas.ventas'))->assertOk()->assertSee('Ventas');

        $sinPermiso = User::factory()->create(['rol' => 'vendedor']);
        $this->actingAs($sinPermiso)->get(route('finanzas.ventas'))->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => []]))->get(route('finanzas.ventas'))->assertForbidden();

        $this->actingAs($this->conPermiso())->get(route('finanzas.ventas'))->assertOk();
    }

    public function test_el_componente_tambien_valida_el_permiso_en_el_servidor(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        Livewire::test(Ventas::class)->assertForbidden();

        // Si le quitan el permiso con la pantalla ya abierta, la siguiente acción ya no pasa.
        $usuario = $this->conPermiso();
        $pagada = $this->venta(['estatus' => 'pagada', 'fecha_pago' => '2026-09-01']);
        $this->actingAs($usuario);
        $pantalla = Livewire::test(Ventas::class)->assertOk()->set('seleccion', [$pagada->id]);
        $usuario->update(['permisos' => []]);
        $pantalla->call('regresarPendiente')->assertForbidden();
        $this->assertSame('pagada', $pagada->fresh()->estatus);
    }

    public function test_botones_solo_del_admin_se_ocultan_al_vendedor(): void
    {
        $venta = $this->venta();

        $this->actingAs($this->conPermiso());
        Livewire::test(Ventas::class)
            ->assertSee($venta->folio)
            ->assertDontSee('+ Nueva venta')
            ->assertDontSee('Importar del Sheets')
            ->assertDontSee('Editar la venta '.$venta->folio);

        $this->actingAs($this->admin());
        $admin = Livewire::test(Ventas::class)->assertSee('+ Nueva venta')->assertSee('Editar la venta '.$venta->folio);
        if (Route::has('finanzas.importar')) {   // la pantalla de importar la hace otra tarea
            $admin->assertSee('Importar del Sheets');
        }
    }

    // ===== Filtros =====

    public function test_filtra_por_anio_y_por_mes_de_la_factura(): void
    {
        $this->actingAs($this->admin());
        $actual = now()->year;
        $este = $this->venta(['fecha' => "{$actual}-03-10"]);
        $otroMes = $this->venta(['fecha' => "{$actual}-07-02"]);
        $anterior = $this->venta(['fecha' => ($actual - 1).'-12-31']);

        Livewire::test(Ventas::class)
            ->assertSet('anio', (string) $actual)                       // por defecto, el año actual
            ->assertSee($este->folio)->assertSee($otroMes->folio)->assertDontSee($anterior->folio)
            ->set('mes', '3')->assertSee($este->folio)->assertDontSee($otroMes->folio)
            ->set('mes', '')->set('anio', (string) ($actual - 1))
            ->assertSee($anterior->folio)->assertDontSee($este->folio)
            ->assertViewHas('anios', fn (array $anios) => in_array($actual, $anios, true) && in_array($actual - 1, $anios, true));
    }

    public function test_todos_los_anios_y_el_enlace_que_manda_cobranza(): void
    {
        $this->actingAs($this->admin());
        $actual = now()->year;
        $nueva = $this->venta(['fecha' => "{$actual}-02-01", 'cliente_nombre' => 'ACME']);
        $vieja = $this->venta(['fecha' => ($actual - 2).'-02-01', 'cliente_nombre' => 'ACME']);
        $pagada = $this->venta(['fecha' => ($actual - 2).'-03-01', 'cliente_nombre' => 'ACME', 'estatus' => 'pagada', 'fecha_pago' => ($actual - 2).'-04-01']);

        // Así enlaza Cobranza ("Ver sus facturas"): ?buscar=ACME&estatus=pendiente&anio= → pendientes de cualquier año.
        Livewire::withQueryParams(['buscar' => 'ACME', 'estatus' => 'pendiente', 'anio' => ''])->test(Ventas::class)
            ->assertSet('anio', 'todos')->assertSet('estatus', 'pendiente')
            ->assertSee($nueva->folio)->assertSee($vieja->folio)->assertDontSee($pagada->folio);

        // Sin ?anio= se abre el año actual.
        Livewire::withQueryParams(['buscar' => 'ACME'])->test(Ventas::class)
            ->assertSet('anio', (string) $actual)->assertSee($nueva->folio)->assertDontSee($vieja->folio);
        Livewire::withQueryParams(['anio' => (string) ($actual - 2)])->test(Ventas::class)
            ->assertSet('anio', (string) ($actual - 2))->assertSee($vieja->folio)->assertDontSee($nueva->folio);

        // Elegir "todos" quita mes y semana (son de un año) y los totales son de todo el historial.
        Livewire::test(Ventas::class)->set('mes', '2')->set('semana', '10')->set('anio', 'todos')
            ->assertSet('mes', '')->assertSet('semana', '')
            ->assertSee($nueva->folio)->assertSee($vieja->folio)->assertSee($pagada->folio)
            ->assertViewHas('todosLosAnios', true)->assertViewHas('totales', fn (array $t) => $t['n'] === 3)
            ->set('semana', '10')->assertDontSee('Utilidad cobrada')          // sin año no hay semana
            ->set('anio', '')->assertSet('anio', 'todos')
            ->call('limpiar')->assertSet('anio', (string) $actual)->assertSee($nueva->folio)->assertDontSee($vieja->folio);
    }

    public function test_el_ultimo_dia_del_mes_y_del_anio_si_cuenta(): void
    {
        $this->actingAs($this->admin());
        $ultimoDia = $this->venta(['fecha' => '2026-09-30']);
        $primeroDeOctubre = $this->venta(['fecha' => '2026-10-01']);
        $findeAnio = $this->venta(['fecha' => '2026-12-31']);

        Livewire::test(Ventas::class)->set('anio', '2026')->set('mes', '9')
            ->assertSee($ultimoDia->folio)->assertDontSee($primeroDeOctubre->folio)
            ->set('mes', '')->assertSee($findeAnio->folio);
    }

    public function test_filtra_por_estatus_portal_tipo_y_busca_por_cliente_folio_o_concepto(): void
    {
        $this->actingAs($this->admin());
        $pendiente = $this->venta(['cliente_nombre' => 'DELTA METAL MEXICO', 'concepto' => 'RTV SILICONE AZUL', 'estatus_portal' => 'por_cargar']);
        $pagada = $this->venta(['cliente_nombre' => 'ACME', 'estatus' => 'pagada', 'fecha_pago' => now()->year.'-04-01', 'estatus_portal' => 'cargada']);
        $nota = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-298', 'cliente_nombre' => 'PARQUES', 'concepto' => 'Cople rapido']);
        $cancelada = $this->venta(['cliente_nombre' => 'PROVEX', 'estatus' => 'cancelada']);

        Livewire::test(Ventas::class)
            ->assertSee($pendiente->folio)->assertSee($pagada->folio)->assertSee('NOTA-298')->assertSee($cancelada->folio)
            ->set('estatus', 'pagada')->assertSee($pagada->folio)->assertDontSee($pendiente->folio)->assertDontSee($cancelada->folio)
            ->set('estatus', 'cancelada')->assertSee($cancelada->folio)->assertDontSee($pagada->folio)
            ->set('estatus', '')
            ->set('portal', 'por_cargar')->assertSee($pendiente->folio)->assertDontSee($pagada->folio)
            ->set('portal', 'sin')->assertSee('NOTA-298')->assertSee($cancelada->folio)->assertDontSee($pendiente->folio)->assertDontSee($pagada->folio)
            ->set('portal', '')
            ->set('tipo', 'nota')->assertSee('NOTA-298')->assertDontSee($pendiente->folio)
            ->set('tipo', '')
            ->set('buscar', 'delta')->assertSee($pendiente->folio)->assertDontSee($pagada->folio)
            ->set('buscar', 'metal mexico')->assertSee($pendiente->folio)
            ->set('buscar', 'silicone azul')->assertSee($pendiente->folio)->assertDontSee('NOTA-298')   // por concepto
            ->set('buscar', 'nota 298')->assertSee('NOTA-298')->assertDontSee($pendiente->folio)         // por folio
            ->set('buscar', $pagada->folio)->assertSee($pagada->folio)->assertDontSee($pendiente->folio);
    }

    public function test_un_filtro_con_valor_raro_no_truena(): void
    {
        $this->actingAs($this->admin());
        $venta = $this->venta();

        Livewire::test(Ventas::class)
            ->set('estatus', 'inventado')->set('portal', 'xx')->set('tipo', 'otro')->set('mes', '99')->set('semana', '77')
            ->set('buscar', "100% _raro_ '--")
            ->assertOk();
        Livewire::test(Ventas::class)->set('anio', 'abc')->assertSee($venta->folio);
    }

    public function test_limpiar_filtros_y_la_seleccion_no_pasa_a_otra_vista(): void
    {
        $this->actingAs($this->admin());
        $venta = $this->venta();

        Livewire::test(Ventas::class)
            ->set('seleccion', [$venta->id])->set('estatus', 'pagada')->assertSet('seleccion', [])
            ->set('buscar', 'x')->set('mes', '5')->set('portal', 'sin')->set('tipo', 'nota')->set('anio', '2020')
            ->call('limpiar')
            ->assertSet('buscar', '')->assertSet('mes', '')->assertSet('estatus', '')->assertSet('portal', '')
            ->assertSet('tipo', '')->assertSet('semana', '')->assertSet('anio', (string) now()->year);
    }

    // ===== Totales =====

    public function test_los_totales_excluyen_las_canceladas_y_cuentan_todo_el_filtro(): void
    {
        $this->actingAs($this->admin());
        $this->venta(['costo' => 100, 'subtotal' => 150, 'total' => 174]);
        $this->venta(['costo' => 200, 'subtotal' => 300, 'total' => 348, 'estatus' => 'pagada', 'fecha_pago' => now()->year.'-04-01']);
        $this->venta(['costo' => 999, 'subtotal' => 5000, 'total' => 5800, 'estatus' => 'cancelada']);

        Livewire::test(Ventas::class)
            ->assertViewHas('totales', function (array $t) {
                $this->assertSame(2, $t['n']);
                $this->assertEquals(450.0, $t['venta']);
                $this->assertEquals(300.0, $t['costo']);
                $this->assertEquals(150.0, $t['utilidad']);
                $this->assertEqualsWithDelta(0.5, $t['margen'], 0.0001);
                $this->assertEquals(522.0, $t['total']);
                $this->assertSame(1, $t['canceladas']);

                return true;
            })
            ->assertSee('$450.00')->assertSee('$150.00')->assertSee('$522.00')->assertSee('50.0%')
            ->assertSee('+ 1 cancelada fuera de los totales');
    }

    public function test_los_totales_son_de_todo_el_filtro_no_solo_de_la_pagina(): void
    {
        $this->actingAs($this->admin());
        for ($i = 0; $i < 55; $i++) {
            $this->venta(['costo' => 10, 'subtotal' => 20, 'total' => 23.20]);
        }

        Livewire::test(Ventas::class)
            ->assertViewHas('ventas', fn ($p) => $p->count() === 50 && $p->total() === 55)
            ->assertViewHas('totales', fn (array $t) => $t['n'] === 55 && $t['venta'] == 1100.0 && $t['costo'] == 550.0 && $t['utilidad'] == 550.0)
            ->call('nextPage')
            ->assertViewHas('ventas', fn ($p) => $p->count() === 5)
            ->assertViewHas('totales', fn (array $t) => $t['n'] === 55);
    }

    public function test_sin_costo_el_margen_se_muestra_con_guion(): void
    {
        $this->actingAs($this->admin());
        $this->venta(['costo' => 0, 'subtotal' => 500, 'total' => 580]);

        Livewire::test(Ventas::class)
            ->assertViewHas('totales', fn (array $t) => $t['margen'] === null && $t['utilidad'] == 500.0)
            ->assertSee('Sin costo capturado');
    }

    // ===== Semana de cobro =====

    public function test_el_filtro_por_semana_usa_lunes_a_sabado_y_el_domingo_cuenta_para_la_siguiente(): void
    {
        $this->actingAs($this->admin());
        $domingo20 = $this->venta(['estatus' => 'pagada', 'fecha_pago' => '2026-09-20', 'subtotal' => 1000, 'costo' => 700, 'fecha' => '2026-08-01']);
        $sabado26 = $this->venta(['estatus' => 'pagada', 'fecha_pago' => '2026-09-26', 'subtotal' => 500, 'costo' => 400, 'fecha' => '2025-12-01']);
        $domingo27 = $this->venta(['estatus' => 'pagada', 'fecha_pago' => '2026-09-27', 'subtotal' => 2000, 'costo' => 1000]);
        $sabado19 = $this->venta(['estatus' => 'pagada', 'fecha_pago' => '2026-09-19', 'subtotal' => 800, 'costo' => 800]);
        $pendiente = $this->venta(['estatus' => 'pendiente', 'fecha' => '2026-09-21']);
        $cancelada = $this->venta(['estatus' => 'cancelada', 'fecha_pago' => '2026-09-22']);

        Livewire::test(Ventas::class)->set('anio', '2026')->set('semana', '39')
            ->assertSee('Semana 39 · lun 21 al sáb 26 sep 2026')
            ->assertSee($domingo20->folio)->assertSee($sabado26->folio)
            ->assertDontSee($domingo27->folio)->assertDontSee($sabado19->folio)->assertDontSee($pendiente->folio)->assertDontSee($cancelada->folio)
            ->assertSee('Utilidad cobrada: $400.00 · Comisión 10%: $40.00')
            ->assertViewHas('totales', fn (array $t) => $t['n'] === 2 && $t['utilidad'] == 400.0)
            ->set('semana', '40')
            ->assertSee('Semana 40 · lun 28 sep al sáb 3 oct 2026')
            ->assertSee($domingo27->folio)->assertDontSee($domingo20->folio)->assertDontSee($sabado26->folio)
            ->assertSee('Utilidad cobrada: $1,000.00 · Comisión 10%: $100.00')
            ->set('semana', '')
            ->assertSee($pendiente->folio);   // sin semana vuelve la lista normal del año
    }

    public function test_la_semana_ignora_mes_y_estatus_pero_respeta_buscar_portal_y_tipo(): void
    {
        $this->actingAs($this->admin());
        $delta = $this->venta(['estatus' => 'pagada', 'fecha_pago' => '2026-09-23', 'cliente_nombre' => 'DELTA', 'estatus_portal' => 'cargada']);
        $acme = $this->venta(['estatus' => 'pagada', 'fecha_pago' => '2026-09-24', 'cliente_nombre' => 'ACME', 'tipo' => 'nota', 'folio' => 'NOTA-5']);

        Livewire::test(Ventas::class)->set('anio', '2026')->set('semana', '39')
            ->set('mes', '1')->set('estatus', 'pendiente')                  // estorban: se ignoran
            ->assertSee($delta->folio)->assertSee('NOTA-5')
            ->set('buscar', 'acme')->assertSee('NOTA-5')->assertDontSee($delta->folio)
            ->set('buscar', '')->set('portal', 'cargada')->assertSee($delta->folio)->assertDontSee('NOTA-5')
            ->set('portal', '')->set('tipo', 'nota')->assertSee('NOTA-5')->assertDontSee($delta->folio);
    }

    public function test_la_lista_de_semanas_sigue_al_anio_y_una_semana_inexistente_se_ignora(): void
    {
        $this->actingAs($this->admin());
        $vieja = $this->venta(['estatus' => 'pagada', 'fecha_pago' => '2022-12-31', 'fecha' => '2022-12-01']);   // sábado: semana 53 de 2022

        // 2022 tuvo 53 semanas (la última terminó el sábado 31 dic); 2026 solo tiene 52.
        Livewire::test(Ventas::class)->set('anio', '2022')
            ->assertViewHas('semanas', fn (array $s) => count($s) === 53 && $s[53] === 'Semana 53 · lun 26 al sáb 31 dic 2022')
            ->set('semana', '53')->assertSee($vieja->folio)->assertSee('Semana 53 · lun 26 al sáb 31 dic 2022')
            ->set('anio', '2026')
            ->assertSet('semana', '')                                       // 2026 no tiene semana 53
            ->assertViewHas('semanas', fn (array $s) => count($s) === 52)
            ->assertDontSee('Utilidad cobrada');

        // Un número que no existe no rompe nada: es como no haber elegido semana.
        Livewire::test(Ventas::class)->set('anio', '2026')->set('semana', '53')->assertOk()->assertDontSee('Utilidad cobrada');
    }

    // ===== Acciones en lote =====

    public function test_marcar_pagadas_en_lote_pone_fecha_cuenta_y_deja_bitacora(): void
    {
        $usuario = $this->conPermiso();
        $this->actingAs($usuario);
        $cuenta = Cuenta::obtener('BBVA');
        $v1 = $this->venta();
        $v2 = $this->venta();
        $ya = $this->venta(['estatus' => 'pagada', 'fecha_pago' => '2026-08-01']);
        $cancelada = $this->venta(['estatus' => 'cancelada']);

        Livewire::test(Ventas::class)
            ->set('seleccion', [$v1->id, $v2->id, $ya->id, $cancelada->id])
            ->call('abrirLote', 'pagar')->assertSet('panelLote', 'pagar')
            ->set('fechaPago', '2026-09-22')->set('cuentaId', $cuenta->id)
            ->call('marcarPagadas')
            ->assertHasNoErrors()
            ->assertSet('panelLote', '')->assertSet('seleccion', [])
            ->assertSee('2 ventas quedaron pagadas el 22/09/2026 en BBVA.')
            ->assertSee('Se dejaron igual 2 que ya estaban pagadas o canceladas.');

        foreach ([$v1, $v2] as $v) {
            $v->refresh();
            $this->assertSame('pagada', $v->estatus);
            $this->assertSame('2026-09-22', $v->fecha_pago->toDateString());
            $this->assertSame($cuenta->id, $v->cuenta_id);

            $estatus = $this->cambio($v, 'estatus');
            $this->assertSame(['pendiente', 'pagada', $usuario->id], [$estatus->antes, $estatus->despues, $estatus->user_id]);
            $this->assertSame('2026-09-22', $this->cambio($v, 'fecha_pago')->despues);
            $this->assertSame('BBVA', $this->cambio($v, 'cuenta')->despues);
        }
        // Lo que ya estaba pagado o cancelado no se toca ni se anota.
        $this->assertSame('2026-08-01', $ya->fresh()->fecha_pago->toDateString());
        $this->assertSame('cancelada', $cancelada->fresh()->estatus);
        $this->assertSame(0, VentaCambio::whereIn('venta_id', [$ya->id, $cancelada->id])->count());
    }

    public function test_marcar_pagadas_con_una_cuenta_nueva_escrita_y_sin_cuenta(): void
    {
        $this->actingAs($this->conPermiso());
        $v1 = $this->venta();
        $v2 = $this->venta();

        Livewire::test(Ventas::class)
            ->set('seleccion', [$v1->id])->call('abrirLote', 'pagar')
            ->set('fechaPago', '2026-09-22')->set('cuentaNueva', 'hey banco')
            ->call('marcarPagadas')->assertHasNoErrors()
            ->assertSee('1 venta quedó pagada el 22/09/2026 en HEY BANCO.');
        $cuenta = Cuenta::where('nombre_llave', 'HEYBANCO')->firstOrFail();
        $this->assertSame($cuenta->id, $v1->fresh()->cuenta_id);

        // Escribir otra vez el mismo nombre (con otras mayúsculas) reutiliza la cuenta.
        $v3 = $this->venta();
        Livewire::test(Ventas::class)
            ->set('seleccion', [$v3->id])->call('abrirLote', 'pagar')->set('cuentaNueva', 'Hey Banco')->call('marcarPagadas');
        $this->assertSame(1, Cuenta::count());
        $this->assertSame($cuenta->id, $v3->fresh()->cuenta_id);

        // La cuenta es opcional.
        Livewire::test(Ventas::class)
            ->set('seleccion', [$v2->id])->call('abrirLote', 'pagar')->set('fechaPago', '2026-09-23')->call('marcarPagadas')->assertHasNoErrors();
        $this->assertNull($v2->fresh()->cuenta_id);
        $this->assertSame('pagada', $v2->fresh()->estatus);
        $this->assertNull($this->cambio($v2, 'cuenta'));
    }

    public function test_marcar_pagadas_valida_la_fecha_y_no_cambia_nada_si_falla(): void
    {
        $this->actingAs($this->conPermiso());
        $venta = $this->venta();

        Livewire::test(Ventas::class)
            ->set('seleccion', [$venta->id])->call('abrirLote', 'pagar')
            ->set('fechaPago', '')->call('marcarPagadas')->assertHasErrors(['fechaPago'])->assertSet('panelLote', 'pagar')
            ->set('fechaPago', 'ayer')->call('marcarPagadas')->assertHasErrors(['fechaPago'])
            ->set('fechaPago', '2026-09-22')->set('cuentaId', 99999)->call('marcarPagadas')->assertHasErrors(['cuentaId']);

        $this->assertSame('pendiente', $venta->fresh()->estatus);
        $this->assertSame(0, VentaCambio::count());
    }

    public function test_el_lote_sin_nada_seleccionado_o_con_ids_falsos_no_hace_nada(): void
    {
        $this->actingAs($this->conPermiso());
        $venta = $this->venta();

        Livewire::test(Ventas::class)
            ->call('abrirLote', 'pagar')->assertSet('panelLote', '')->assertSee('Marca primero las ventas')
            ->set('seleccion', [987654])->call('abrirLote', 'pagar')->call('marcarPagadas')->assertSee('No se cambió ninguna')
            ->call('regresarPendiente')->assertSee('Marca primero las ventas');

        $this->assertSame('pendiente', $venta->fresh()->estatus);
        $this->assertSame(0, VentaCambio::count());
    }

    public function test_regresar_a_pendiente_quita_fecha_y_cuenta_con_bitacora(): void
    {
        $usuario = $this->conPermiso();
        $this->actingAs($usuario);
        $cuenta = Cuenta::obtener('BBVA');
        $pagada = $this->venta(['estatus' => 'pagada', 'fecha_pago' => '2026-09-22', 'cuenta_id' => $cuenta->id]);
        $pendiente = $this->venta();

        Livewire::test(Ventas::class)
            ->set('seleccion', [$pagada->id, $pendiente->id])
            ->call('regresarPendiente')
            ->assertSee('1 venta regresó a pendiente.')->assertSet('seleccion', []);

        $pagada->refresh();
        $this->assertSame('pendiente', $pagada->estatus);
        $this->assertNull($pagada->fecha_pago);
        $this->assertNull($pagada->cuenta_id);
        $estatus = $this->cambio($pagada, 'estatus');
        $this->assertSame(['pagada', 'pendiente', $usuario->id], [$estatus->antes, $estatus->despues, $estatus->user_id]);
        $this->assertSame('2026-09-22', $this->cambio($pagada, 'fecha_pago')->antes);
        $this->assertNull($this->cambio($pagada, 'fecha_pago')->despues);
        $this->assertSame('BBVA', $this->cambio($pagada, 'cuenta')->antes);
        $this->assertSame(0, VentaCambio::where('venta_id', $pendiente->id)->count());
    }

    public function test_solo_el_admin_revive_una_cancelada_al_regresar_a_pendiente(): void
    {
        $cancelada = $this->venta(['estatus' => 'cancelada']);

        $this->actingAs($this->conPermiso());
        Livewire::test(Ventas::class)->set('seleccion', [$cancelada->id])->call('regresarPendiente')->assertSee('No se cambió ninguna');
        $this->assertSame('cancelada', $cancelada->fresh()->estatus);

        $this->actingAs($this->admin());
        Livewire::test(Ventas::class)->set('seleccion', [$cancelada->id])->call('regresarPendiente')->assertSee('1 venta regresó a pendiente.');
        $this->assertSame('pendiente', $cancelada->fresh()->estatus);
        $this->assertSame('cancelada', $this->cambio($cancelada, 'estatus')->antes);
    }

    public function test_estatus_del_portal_de_una_sola_venta_con_su_lista(): void
    {
        $this->actingAs($this->conPermiso());
        $a = $this->venta();
        $b = $this->venta(['estatus_portal' => 'cargada']);

        Livewire::test(Ventas::class)
            ->assertSeeHtml('aria-label="Estatus del portal de '.$a->folio.'"')
            ->call('cambiarPortal', $a->id, 'programada')->assertSee('Programada para pago')
            ->call('cambiarPortal', $b->id, '')
            ->call('cambiarPortal', $b->id, 'inventado');

        $this->assertSame('programada', $a->fresh()->estatus_portal);
        $this->assertNull($b->fresh()->estatus_portal);
        $this->assertTrue($a->cambios()->where('campo', 'estatus_portal')->exists());
    }

    public function test_estatus_del_portal_en_lote_y_quitarlo(): void
    {
        $usuario = $this->conPermiso();
        $this->actingAs($usuario);
        $a = $this->venta();
        $b = $this->venta(['estatus_portal' => 'cargada']);
        $c = $this->venta(['estatus_portal' => 'cargada', 'estatus' => 'pagada', 'fecha_pago' => '2026-09-01']);

        Livewire::test(Ventas::class)
            ->set('seleccion', [$a->id, $b->id, $c->id])
            ->call('abrirLote', 'portal')->assertSet('panelLote', 'portal')
            ->call('aplicarPortal')->assertHasErrors(['portalLote'])           // hay que elegir uno
            ->set('portalLote', 'inventado')->call('aplicarPortal')->assertHasErrors(['portalLote'])
            ->set('portalLote', 'cargada')->call('aplicarPortal')->assertHasNoErrors()
            ->assertSee('1 venta quedó con el estatus de portal "Cargada".')
            ->assertSee('Se dejaron igual 2 que ya tenían ese estatus.')
            ->assertSet('seleccion', [])->assertSet('panelLote', '');

        $this->assertSame('cargada', $a->fresh()->estatus_portal);
        $cambio = $this->cambio($a, 'estatus_portal');
        $this->assertSame([null, 'cargada', $usuario->id], [$cambio->antes, $cambio->despues, $cambio->user_id]);
        $this->assertSame(0, VentaCambio::where('venta_id', $b->id)->count());

        Livewire::test(Ventas::class)
            ->set('seleccion', [$a->id, $b->id])->call('abrirLote', 'portal')->set('portalLote', 'sin')->call('aplicarPortal')
            ->assertSee('2 ventas quedaron sin estatus de portal.');
        $this->assertNull($a->fresh()->estatus_portal);
        $this->assertNull($b->fresh()->estatus_portal);
        $this->assertSame('cargada', $this->cambio($b, 'estatus_portal')->antes);
        $this->assertNull($this->cambio($b, 'estatus_portal')->despues);
        $this->assertSame('cargada', $c->fresh()->estatus_portal);   // la que no se seleccionó no cambia
    }

    public function test_seleccionar_las_de_esta_pagina_agrega_y_quita(): void
    {
        $this->actingAs($this->admin());
        for ($i = 0; $i < 52; $i++) {
            $this->venta();
        }
        $primeras = Venta::orderByDesc('fecha')->orderByDesc('id')->limit(50)->pluck('id')->all();

        $c = Livewire::test(Ventas::class)->call('alternarPagina');
        $this->assertEqualsCanonicalizing($primeras, array_map('intval', $c->get('seleccion')));
        $c->call('alternarPagina')->assertSet('seleccion', []);

        // En la segunda página solo agrega esas dos y conserva lo de la primera.
        $c = Livewire::test(Ventas::class)->call('alternarPagina')->call('nextPage')->call('alternarPagina');
        $this->assertCount(52, array_unique(array_map('intval', $c->get('seleccion'))));
    }

    // ===== Editar y capturar (solo admin) =====

    public function test_el_vendedor_con_permiso_no_puede_editar_costo_ni_capturar_ni_cancelar(): void
    {
        $venta = $this->venta(['costo' => 100]);
        $this->actingAs($this->conPermiso());

        Livewire::test(Ventas::class)
            ->call('editar', $venta->id)->assertForbidden();
        Livewire::test(Ventas::class)
            ->set('editandoId', $venta->id)->set('mostrarForm', true)
            ->set('form.costo', '1')->call('guardar')->assertForbidden();
        Livewire::test(Ventas::class)->call('nueva')->assertForbidden();
        Livewire::test(Ventas::class)->set('editandoId', $venta->id)->call('cancelarVenta')->assertForbidden();
        Livewire::test(Ventas::class)->call('recalcularMontos')->assertForbidden();

        $venta->refresh();
        $this->assertSame('100.00', $venta->costo);
        $this->assertSame('pendiente', $venta->estatus);
        $this->assertSame(0, VentaCambio::count());
        $this->assertSame(1, Venta::count());
    }

    public function test_el_admin_edita_el_costo_y_queda_en_la_bitacora_con_su_nombre(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $venta = $this->venta(['costo' => 100, 'subtotal' => 150, 'iva' => 24, 'total' => 174]);

        Livewire::test(Ventas::class)
            ->call('editar', $venta->id)
            ->assertSet('editandoId', $venta->id)->assertSet('mostrarForm', true)
            ->assertSet('form.costo', '100.00')->assertSet('form.folio', $venta->folio)
            ->assertSee('Editar venta '.$venta->folio)->assertSee('Todavía no hay cambios anotados.')
            ->set('form.costo', '123.45')
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertSet('mostrarForm', false)->assertSet('editandoId', null)
            ->assertSee('Se guardaron los cambios de la venta '.$venta->folio);

        $this->assertSame('123.45', $venta->fresh()->costo);
        $cambio = $this->cambio($venta, 'costo');
        $this->assertSame(['100.00', '123.45', $admin->id, 'usuario'], [$cambio->antes, $cambio->despues, $cambio->user_id, $cambio->origen]);
        $this->assertSame(1, VentaCambio::count());   // solo lo que cambió: nada de ruido por los demás campos

        // Al abrirla otra vez, el historial sale debajo del formulario con quién y qué cambió.
        Livewire::test(Ventas::class)->call('editar', $venta->id)
            ->assertSee('Historial de cambios')->assertSee($admin->name)->assertSee('Costo:')->assertSee('$100.00')->assertSee('$123.45');
    }

    public function test_el_historial_dice_sicar_cuando_el_cambio_fue_de_la_pc(): void
    {
        $this->actingAs($this->admin());
        $venta = $this->venta();
        $venta->anotar('subtotal', '100.00', '150.00', 'sicar');

        Livewire::test(Ventas::class)->call('editar', $venta->id)->assertSee('SICAR')->assertSee('Venta (sin IVA):');
    }

    public function test_guardar_valida_numeros_folio_y_fecha(): void
    {
        $this->actingAs($this->admin());
        $venta = $this->venta();

        Livewire::test(Ventas::class)->call('editar', $venta->id)
            ->set('form.costo', '-5')->set('form.subtotal', 'mucho')->set('form.iva', '-1')->set('form.retencion', '-0.5')->set('form.total', '-9')
            ->set('form.folio', '')->set('form.fecha', '')->set('form.cliente_nombre', '')
            ->call('guardar')
            ->assertHasErrors(['form.costo', 'form.subtotal', 'form.iva', 'form.retencion', 'form.total', 'form.folio', 'form.fecha', 'form.cliente_nombre'])
            ->assertSee('Escribe el folio.')->assertSee('El costo no puede ser negativo.')->assertSee('La venta debe ser un número');

        Livewire::test(Ventas::class)->call('editar', $venta->id)
            ->set('form.costo', '1000000000000')->set('form.tipo', 'otro')->set('form.estatus_portal', 'raro')->set('form.fecha', '10/03/2026')
            ->call('guardar')
            ->assertHasErrors(['form.costo', 'form.tipo', 'form.estatus_portal', 'form.fecha']);

        $this->assertSame('100.00', $venta->fresh()->costo);
        $this->assertSame(0, VentaCambio::count());
    }

    public function test_folio_repetido_marca_error_y_el_mismo_folio_de_la_venta_si_se_puede(): void
    {
        $this->actingAs($this->admin());
        $a = $this->venta(['folio' => '5-3317']);
        $b = $this->venta(['folio' => '5-3318']);
        $nota = $this->venta(['tipo' => 'nota', 'folio' => '5-3317']);   // otro tipo = otra llave

        Livewire::test(Ventas::class)->call('editar', $b->id)
            ->set('form.folio', '5 - 3317')->call('guardar')
            ->assertHasErrors(['form.folio'])->assertSee('Ya existe otra venta con ese folio')->assertSet('mostrarForm', true);
        $this->assertSame('5-3318', $b->fresh()->folio);

        // Su propio folio, o cambiarlo a uno libre, sí.
        Livewire::test(Ventas::class)->call('editar', $a->id)->set('form.notas', 'revisar con Daniel')->call('guardar')->assertHasNoErrors();
        Livewire::test(Ventas::class)->call('editar', $b->id)->set('form.folio', '5-3999')->call('guardar')->assertHasNoErrors();
        $this->assertSame('5-3999', $b->fresh()->folio);
        $this->assertSame(Venta::llave('factura', '5-3999'), $b->fresh()->folio_llave);
        $this->assertSame('5-3999', $this->cambio($b, 'folio')->despues);
        $this->assertSame('5-3318', $this->cambio($b, 'folio')->antes);

        // Una venta borrada también ocupa su folio (el índice único no distingue).
        $borrada = $this->venta(['folio' => '5-7000']);
        $borrada->delete();
        Livewire::test(Ventas::class)->call('nueva')->set('form.folio', '5-7000')->set('form.cliente_nombre', 'X')->call('guardar')
            ->assertHasErrors(['form.folio']);
        $this->assertNotNull($nota);
    }

    public function test_si_cambia_la_venta_y_no_se_tocaron_iva_ni_total_se_recalculan(): void
    {
        $this->actingAs($this->admin());
        $venta = $this->venta(['subtotal' => 150, 'iva' => 24, 'total' => 174, 'retencion' => 0]);

        Livewire::test(Ventas::class)->call('editar', $venta->id)
            ->set('form.subtotal', '1000')->call('guardar')->assertHasNoErrors();
        $venta->refresh();
        $this->assertSame(['1000.00', '160.00', '1160.00'], [$venta->subtotal, $venta->iva, $venta->total]);
        $this->assertSame('160.00', $this->cambio($venta, 'iva')->despues);
        $this->assertSame('1160.00', $this->cambio($venta, 'total')->despues);

        // Con retención: total = venta + IVA − retención.
        Livewire::test(Ventas::class)->call('editar', $venta->id)
            ->set('form.subtotal', '2000')->set('form.retencion', '20')->call('guardar');
        $venta->refresh();
        $this->assertSame(['2000.00', '320.00', '20.00', '2300.00'], [$venta->subtotal, $venta->iva, $venta->retencion, $venta->total]);

        // Si el admin también escribió el IVA o el total, se respeta lo que escribió.
        Livewire::test(Ventas::class)->call('editar', $venta->id)
            ->set('form.subtotal', '3000')->set('form.iva', '0')->call('guardar');
        $venta->refresh();
        $this->assertSame(['3000.00', '0.00', '2300.00'], [$venta->subtotal, $venta->iva, $venta->total]);

        Livewire::test(Ventas::class)->call('editar', $venta->id)
            ->set('form.subtotal', '4000')->set('form.total', '4500')->call('guardar');
        $venta->refresh();
        $this->assertSame(['4000.00', '0.00', '4500.00'], [$venta->subtotal, $venta->iva, $venta->total]);
    }

    public function test_el_boton_recalcular_pone_iva_y_total_en_el_formulario(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Ventas::class)->call('nueva')
            ->set('form.subtotal', '1000')->set('form.retencion', '10')->call('recalcularMontos')
            ->assertSet('form.iva', '160.00')->assertSet('form.total', '1150.00');
    }

    public function test_cambiar_el_cliente_lo_liga_por_nombre_y_da_sus_dias_de_credito(): void
    {
        $this->actingAs($this->admin());
        $acme = Cliente::factory()->create(['nombre' => 'ACME', 'dias_credito' => 45]);
        $venta = $this->venta(['cliente_nombre' => 'ACME MEXICO']);
        $this->assertNull($venta->cliente_id);
        $this->assertSame(30, $venta->diasCredito());

        Livewire::test(Ventas::class)->call('editar', $venta->id)->set('form.cliente_nombre', 'acme')->call('guardar')->assertHasNoErrors();

        $venta->refresh();
        $this->assertSame('ACME', $venta->cliente_nombre);
        $this->assertSame($acme->id, $venta->cliente_id);
        $this->assertSame(45, $venta->diasCredito());
        $this->assertSame('ACME MEXICO', $this->cambio($venta, 'cliente_nombre')->antes);
    }

    public function test_lo_que_no_se_toco_no_se_normaliza_ni_se_anota(): void
    {
        $this->actingAs($this->admin());
        $venta = $this->venta(['folio' => '5-1a', 'cliente_nombre' => 'Delta Metal']);

        Livewire::test(Ventas::class)->call('editar', $venta->id)->set('form.notas', 'solo una nota')->call('guardar')->assertHasNoErrors();

        $venta->refresh();
        $this->assertSame('5-1a', $venta->folio);
        $this->assertSame('Delta Metal', $venta->cliente_nombre);
        $this->assertSame(['notas'], VentaCambio::pluck('campo')->all());
    }

    public function test_el_admin_captura_una_venta_nueva_a_mano(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $cliente = Cliente::factory()->create(['nombre' => 'PARQUES', 'dias_credito' => 45]);

        Livewire::test(Ventas::class)
            ->call('nueva')->assertSet('mostrarForm', true)->assertSet('editandoId', null)->assertSee('Nueva venta')
            ->set('form.folio', ' nota-298 ')->set('form.tipo', 'nota')->set('form.fecha', now()->year.'-05-20')
            ->set('form.cliente_nombre', 'parques')->set('form.concepto', 'Cople rápido')
            ->set('form.costo', '700')->set('form.subtotal', '1000')
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertSet('mostrarForm', false)->assertSee('Se agregó la venta NOTA-298.')->assertSee('NOTA-298');

        $venta = Venta::where('folio', 'NOTA-298')->firstOrFail();
        $this->assertSame(['nota', 'manual', 'pendiente', 'PARQUES', $cliente->id], [$venta->tipo, $venta->origen, $venta->estatus, $venta->cliente_nombre, $venta->cliente_id]);
        $this->assertSame(['700.00', '1000.00', '160.00', '1160.00', '0.00'], [$venta->costo, $venta->subtotal, $venta->iva, $venta->total, $venta->retencion]);
        $this->assertSame(Venta::llave('nota', 'NOTA-298'), $venta->folio_llave);
        $this->assertNull($venta->costo_sicar);
        $alta = $this->cambio($venta, 'alta');
        $this->assertSame([$admin->id, 'Captura manual'], [$alta->user_id, $alta->despues]);

        // Con IVA y total escritos a mano se respetan.
        Livewire::test(Ventas::class)->call('nueva')
            ->set('form.folio', '5-9001')->set('form.cliente_nombre', 'Cliente sin ficha')->set('form.subtotal', '1000')->set('form.iva', '0')->set('form.total', '1000')
            ->call('guardar')->assertHasNoErrors();
        $sinIva = Venta::where('folio', '5-9001')->firstOrFail();
        $this->assertSame(['0.00', '1000.00', null], [$sinIva->iva, $sinIva->total, $sinIva->cliente_id]);
    }

    public function test_la_venta_nueva_pide_folio_y_cliente(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Ventas::class)->call('nueva')
            ->call('guardar')->assertHasErrors(['form.folio', 'form.cliente_nombre'])
            ->set('form.folio', '   ')->set('form.cliente_nombre', ' . ')->call('guardar')->assertHasErrors();
        $this->assertSame(0, Venta::count());
    }

    public function test_cancelar_la_venta_es_solo_del_admin_y_deja_de_contar(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $venta = $this->venta(['costo' => 100, 'subtotal' => 150, 'total' => 174]);
        $otra = $this->venta(['costo' => 10, 'subtotal' => 20, 'total' => 23.2]);

        Livewire::test(Ventas::class)
            ->assertViewHas('totales', fn (array $t) => $t['n'] === 2)
            ->call('editar', $venta->id)->assertSee('Cancelar venta')
            ->call('cancelarVenta')
            ->assertSet('mostrarForm', false)->assertSee('quedó cancelada')
            ->assertViewHas('totales', fn (array $t) => $t['n'] === 1 && $t['canceladas'] === 1 && $t['venta'] == 20.0);

        $this->assertSame('cancelada', $venta->fresh()->estatus);
        $cambio = $this->cambio($venta, 'estatus');
        $this->assertSame(['pendiente', 'cancelada', $admin->id], [$cambio->antes, $cambio->despues, $cambio->user_id]);
        $this->assertSame('pendiente', $otra->fresh()->estatus);

        // Ya cancelada, el botón no aparece.
        Livewire::test(Ventas::class)->call('editar', $venta->id)->assertDontSee('Cancelar venta');
    }

    public function test_las_fechas_mal_escritas_no_se_aceptan(): void
    {
        $this->actingAs($this->admin());
        $venta = $this->venta();

        Livewire::test(Ventas::class)
            ->set('seleccion', [$venta->id])->call('abrirLote', 'pagar')
            ->set('fechaPago', '2062-09-22')->call('marcarPagadas')->assertHasErrors(['fechaPago'])
            ->set('fechaPago', '0026-09-22')->call('marcarPagadas')->assertHasErrors(['fechaPago'])->assertSee('parece mal escrita');
        $this->assertSame('pendiente', $venta->fresh()->estatus);

        Livewire::test(Ventas::class)->call('nueva')
            ->set('form.folio', 'X-1')->set('form.cliente_nombre', 'A')->set('form.fecha', '2062-01-01')->call('guardar')->assertHasErrors(['form.fecha']);
        $this->assertSame(1, Venta::count());

        // Mañana sí se puede (hay un mes de margen).
        Livewire::test(Ventas::class)->call('nueva')
            ->set('form.folio', 'X-2')->set('form.cliente_nombre', 'A')->set('form.fecha', now()->addDay()->toDateString())->call('guardar')->assertHasNoErrors();
    }

    public function test_avisa_si_con_los_filtros_de_arriba_la_venta_guardada_no_se_ve(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Ventas::class)->call('nueva')
            ->set('form.folio', 'VIEJA-1')->set('form.cliente_nombre', 'A')->set('form.fecha', (now()->year - 1).'-05-01')->call('guardar')
            ->assertSee('Se agregó la venta VIEJA-1.')->assertSee('no aparece en la lista')
            ->call('nueva')->set('form.folio', 'DEESTEANIO-1')->set('form.cliente_nombre', 'A')->set('form.fecha', now()->year.'-01-02')->call('guardar')
            ->assertSee('Se agregó la venta DEESTEANIO-1.')->assertDontSee('no aparece en la lista');
    }

    public function test_quien_no_es_admin_no_ve_el_formulario_ni_el_historial_aunque_cambie_las_propiedades(): void
    {
        $venta = $this->venta();
        $venta->anotar('costo', '10.00', '100.00', 'sicar');
        $this->actingAs($this->conPermiso());

        Livewire::test(Ventas::class)
            ->set('editandoId', $venta->id)->set('mostrarForm', true)
            ->assertDontSeeHtml('id="v-costo"')->assertDontSee('Historial de cambios')->assertDontSee('Cancelar venta');
    }

    // ===== Pantalla =====

    public function test_se_ve_la_fecha_de_pago_y_la_cuenta_y_lo_pendiente_vencido(): void
    {
        $this->actingAs($this->admin());
        $cuenta = Cuenta::obtener('Hey Banco');
        $pagada = $this->venta(['estatus' => 'pagada', 'fecha_pago' => now()->year.'-04-15', 'cuenta_id' => $cuenta->id, 'estatus_portal' => 'evidencia']);
        $vencida = $this->venta(['fecha' => now()->subDays(90)->toDateString()]);

        Livewire::test(Ventas::class)->set('anio', (string) now()->year)
            ->assertSee('15/04/'.now()->year)->assertSee('HEY BANCO')
            ->assertSee('Evidencia enviada')
            ->assertSee('Vencida '.$vencida->vence()->format('d/m/Y'));
    }

    public function test_la_tarjeta_del_celular_trae_lo_mismo_que_la_tabla(): void
    {
        $this->actingAs($this->admin());
        $venta = $this->venta(['concepto' => 'RTV SILICONE', 'costo' => 80, 'subtotal' => 100, 'total' => 116]);

        Livewire::test(Ventas::class)
            ->assertSeeHtml('wire:key="fila-'.$venta->id.'"')->assertSeeHtml('wire:key="tarjeta-'.$venta->id.'"')
            ->assertSee('RTV SILICONE')->assertSee('$80.00')->assertSee('$100.00')->assertSee('$20.00')->assertSee('25.0%')->assertSee('$116.00');
    }
}

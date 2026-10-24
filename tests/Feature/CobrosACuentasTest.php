<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\MisFinanzas;
use App\Models\ComplementoPago;
use App\Models\Cuenta;
use App\Models\SaldoCorte;
use App\Models\SaldoLinea;
use App\Models\User;
use App\Support\ComplementosSicar;
use App\Support\CuentaPorReferencia;
use App\Support\Patrimonio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** La "Referencia" del complemento en SICAR ("HEY", "BBVA") suma el cobro al saldo de esa cuenta en Mis finanzas. */
class CobrosACuentasTest extends TestCase
{
    use RefreshDatabase;

    private Cuenta $bbva;

    private Cuenta $hey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));
        $this->bbva = Cuenta::create(['nombre' => 'BBVA (DÉBITO)', 'tipo' => 'debito', 'de' => 'masume']);
        $this->hey = Cuenta::create(['nombre' => 'HEY BANCO (DÉBITO)', 'tipo' => 'debito', 'de' => 'masume']);
        Cuenta::create(['nombre' => 'BBVA PLATINUM', 'tipo' => 'credito', 'de' => 'masume']);
        Cuenta::create(['nombre' => 'HEY BANCO (CRÉDITO)', 'tipo' => 'credito', 'de' => 'masume']);
    }

    private function rep(int $n, string $fecha, float $importe, ?string $referencia): ComplementoPago
    {
        return ComplementosSicar::guardar(ComplementosSicar::normalizar([
            'sicar_id' => 'REP:'.$n, 'folio' => '1-'.$n, 'rfc' => 'CPR120531CU2', 'cliente' => 'CLIENTE PRUEBA',
            'fecha_pago' => $fecha, 'importe' => $importe, 'moneda' => 'MXN', 'referencia' => $referencia,
            'documentos' => [['sicar_id' => 'F:'.$n, 'folio' => '5-'.$n, 'importe_pagado' => $importe,
                'saldo_anterior' => $importe, 'saldo_insoluto' => 0, 'moneda' => 'MXN']],
        ]));
    }

    private function corte(string $fecha, array $saldos): void
    {
        $corte = SaldoCorte::create(['fecha' => $fecha]);
        foreach ($saldos as $id => $saldo) {
            SaldoLinea::create(['saldo_corte_id' => $corte->id, 'cuenta_id' => $id, 'saldo' => $saldo]);
        }
    }

    public function test_la_referencia_encuentra_la_cuenta_de_debito(): void
    {
        $this->assertSame($this->hey->id, CuentaPorReferencia::buscar('HEY'));
        $this->assertSame($this->hey->id, CuentaPorReferencia::buscar('hey banco'));
        $this->assertSame($this->bbva->id, CuentaPorReferencia::buscar('Transferencia BBVA 4521'));
        $this->assertNull(CuentaPorReferencia::buscar('BANORTE'));
        $this->assertNull(CuentaPorReferencia::buscar('BBVA o HEY'));  // dos cuentas: no se adivina
        $this->assertNull(CuentaPorReferencia::buscar(''));
        $this->assertNull(CuentaPorReferencia::buscar(null));
    }

    public function test_el_complemento_guarda_su_referencia_y_cuenta_sin_mandarlo_a_revision(): void
    {
        $rep = $this->rep(1, '2026-10-22', 36101.43, null);
        $huella = $rep->huella;
        $rep = $this->rep(1, '2026-10-22', 36101.43, 'HEY');
        $this->assertSame('HEY', $rep->referencia);
        $this->assertSame($this->hey->id, $rep->cuenta_id);
        $this->assertSame($huella, $rep->huella);
        $this->assertFalse($rep->requiere_revision);
        // Si después llega sin referencia (p. ej. cargando el XML), se conserva la que ya tenía.
        $this->assertSame($this->hey->id, $this->rep(1, '2026-10-22', 36101.43, null)->cuenta_id);
    }

    public function test_por_la_api_del_puente(): void
    {
        config(['services.sicar.token' => 'prueba-puente']);
        $this->withToken('prueba-puente')->postJson('/api/sicar/ventas', ['documentos' => [], 'complementos' => [[
            'sicar_id' => 'REP:7', 'folio' => '1-7', 'rfc' => 'CPR120531CU2', 'cliente' => 'CLIENTE PRUEBA',
            'fecha_pago' => '2026-10-22', 'importe' => 500, 'moneda' => 'MXN', 'referencia' => 'BBVA',
            'documentos' => [['sicar_id' => 'F:7', 'folio' => '5-7', 'importe_pagado' => 500, 'saldo_anterior' => 500, 'saldo_insoluto' => 0, 'moneda' => 'MXN']],
        ]]])->assertOk();
        $this->assertSame($this->bbva->id, ComplementoPago::where('folio', '1-7')->value('cuenta_id'));
    }

    public function test_solo_suma_lo_cobrado_despues_del_ultimo_saldo(): void
    {
        $this->corte('2026-10-20', [$this->bbva->id => 1000, $this->hey->id => 2000]);
        $this->rep(1, '2026-10-19', 50, 'BBVA');        // antes del saldo: ya venía incluido
        $this->rep(2, '2026-10-21', 300, 'BBVA');       // después: se suma
        $this->rep(3, '2026-10-22', 700, 'HEY');        // después: se suma
        $this->rep(4, '2026-10-22', 999, 'BANORTE');    // sin cuenta: no se suma a nada
        $cobros = Patrimonio::cobrosDespuesDelCorte();
        $this->assertSame(300.0, $cobros[$this->bbva->id]['total']);
        $this->assertSame(700.0, $cobros[$this->hey->id]['total']);
        $this->assertSame(1000.0 + 300 + 2000 + 700, Patrimonio::resumen()['disponible']);

        // Al capturar el saldo nuevo ya trae esos cobros: dejan de sumarse aparte.
        $this->travel(1)->minutes();
        $this->corte('2026-10-23', [$this->bbva->id => 1300, $this->hey->id => 2700]);
        $this->assertTrue(Patrimonio::cobrosDespuesDelCorte()->isEmpty());
        $this->assertSame(4000.0, Patrimonio::resumen()['disponible']);
    }

    public function test_mismo_dia_que_el_saldo_cuenta_solo_si_llego_despues(): void
    {
        $this->rep(1, '2026-10-23', 100, 'BBVA');       // llegó antes de capturar el saldo de hoy
        $this->travel(5)->minutes();
        $this->corte('2026-10-23', [$this->bbva->id => 1000]);
        $this->travel(5)->minutes();
        $this->rep(2, '2026-10-23', 250, 'BBVA');       // llegó después
        $this->assertSame(250.0, Patrimonio::cobrosDespuesDelCorte()[$this->bbva->id]['total']);
    }

    public function test_desglose_de_lo_disponible_debito_primero_e_inversion_al_final(): void
    {
        $gbm = Cuenta::create(['nombre' => 'GBM S&P500', 'tipo' => 'inversion', 'de' => 'personal']);
        $efectivo = Cuenta::create(['nombre' => 'EFECTIVO', 'tipo' => 'efectivo', 'de' => 'masume']);
        $this->corte('2026-10-20', [$this->bbva->id => 8662.07, $this->hey->id => 87650.63, $gbm->id => 80801.67, $efectivo->id => 0]);
        $this->rep(1, '2026-10-22', 1000, 'BBVA');
        $d = Patrimonio::desgloseDisponible();
        $this->assertSame(['En débito', 'En inversión'], array_column($d, 'titulo'));   // efectivo en cero no sale
        $this->assertSame(['HEY BANCO (DÉBITO)', 'BBVA (DÉBITO)'], array_column($d[0]['cuentas'], 'nombre'));
        $this->assertSame(9662.07, $d[0]['cuentas'][1]['saldo']);                        // con el cobro posterior
        $this->assertSame(97312.70, $d[0]['total']);
        $this->assertSame(Patrimonio::resumen()['disponible'], round($d[0]['total'] + $d[1]['total'], 2));

        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        Livewire::test(MisFinanzas::class)->assertSeeHtml('data-desglose-disponible')
            ->assertSeeInOrder(['En débito', '$97,312.70', 'HEY BANCO (DÉBITO)', '$87,650.63', 'En inversión', '$80,801.67', 'GBM S&amp;P500'], false);
    }

    public function test_mis_finanzas_lo_muestra_y_lo_trae_al_actualizar(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $this->corte('2026-10-20', [$this->hey->id => 2000]);
        $this->rep(9, '2026-10-22', 36101.43, 'HEY');
        Livewire::test(MisFinanzas::class)
            ->assertSee('+ $36,101.43 de cobros')
            ->assertSee('1-9 CLIENTE PRUEBA')
            ->assertSee('$38,101.43')
            ->call('abrirActualizar')
            ->assertSet('saldos.'.$this->hey->id, '38101.43');
    }
}

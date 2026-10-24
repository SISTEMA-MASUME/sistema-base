<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class LogoCuentaTest extends TestCase
{
    private function logo(string $nombre, ?string $tipo = null): string
    {
        return Blade::render('<x-logo-cuenta :nombre="$n" :tipo="$t" />', ['n' => $nombre, 't' => $tipo]);
    }

    public function test_cada_banco_lleva_su_logo_por_el_nombre_de_la_cuenta(): void
    {
        $this->assertStringContainsString('/logos/cuentas/amex-color.svg', $this->logo('AMEX PLATINUM'));
        $this->assertStringContainsString('/logos/cuentas/revolut.svg', $this->logo('REVOLOUT PERSONAL DANIEL RENDIMIENTOS'));
        $this->assertStringContainsString('/logos/cuentas/nu.svg', $this->logo('NU DEBITO'));
        $this->assertStringContainsString('/logos/cuentas/mercadopago.svg', $this->logo('MERCADO PAGO CREDITO'));
        $this->assertStringContainsString('>BBVA<', $this->logo('BBVA (DÉBITO)'));
        $this->assertStringContainsString('>COSTCO<', $this->logo('COSTCO BANAMEX'));
        $this->assertStringContainsString('>volaris<', $this->logo('VOLARIS INVEX'));
    }

    public function test_banamex_no_se_confunde_con_amex(): void
    {
        $html = $this->logo('BANAMEX', 'debito');
        $this->assertStringContainsString('/logos/cuentas/banamex.svg', $html);
        $this->assertStringNotContainsString('amex-color', $html);
    }

    public function test_credito_debito_y_prestamo_se_distinguen_con_una_franja(): void
    {
        $this->assertStringContainsString('>CRÉDITO<', $this->logo('BBVA PLATINUM', 'credito'));
        $this->assertStringContainsString('>DÉBITO<', $this->logo('BBVA (DÉBITO)', 'debito'));
        $this->assertStringContainsString('>PRÉSTAMO<', $this->logo('CREDITO BBVA', 'prestamo'));
        $this->assertStringNotContainsString('CRÉDITO', $this->logo('EFECTIVO', 'efectivo'));
    }

    public function test_sin_marca_conocida_usa_dibujo_o_iniciales(): void
    {
        $this->assertStringContainsString('<svg', $this->logo('EFECTIVO', 'efectivo'));
        $this->assertStringContainsString('<svg', $this->logo('PRÉSTAMO JORGE RÍOS', 'por_cobrar'));
        $this->assertStringContainsString('>CS<', $this->logo('CAJA SANTANDER', 'debito'));
    }
}

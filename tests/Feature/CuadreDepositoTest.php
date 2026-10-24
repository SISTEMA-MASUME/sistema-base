<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Ventas;
use App\Models\User;
use App\Models\Venta;
use App\Support\CuadreDeposito;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** "¿Qué facturas suman un depósito?" en Finanzas → Ventas: combinaciones exactas o las más cercanas, sin IA. */
class CuadreDepositoTest extends TestCase
{
    use RefreshDatabase;

    public function test_encuentra_la_combinacion_exacta_aunque_no_esten_en_orden(): void
    {
        // 5 facturas; el depósito paga la 1ª, 3ª y 5ª (se saltó la 2ª y la 4ª).
        $r = CuadreDeposito::buscar([1 => 1250000, 2 => 830050, 3 => 2097717, 4 => 410000, 5 => 1000000], 4347717);
        $this->assertContains([1, 3, 5], $r['exactas']);
        $this->assertTrue($r['completa']);
        // Aunque haya exacta, también da las más cercanas (por centavos o por una factura chica).
        $r = CuadreDeposito::buscar([1 => 10000, 2 => 25000, 3 => 40000, 4 => 9995], 50000);
        $this->assertSame([[1, 3]], $r['exactas']);
        $this->assertSame(['ids' => [3, 4], 'suma' => 49995], $r['abajo']);   // faltan 5 centavos
    }

    public function test_sin_exacta_da_la_mas_cercana_por_abajo_y_por_arriba(): void
    {
        $r = CuadreDeposito::buscar([1 => 10000, 2 => 25000, 3 => 40100], 50000);
        $this->assertSame([], $r['exactas']);
        $this->assertSame(['ids' => [3], 'suma' => 40100], $r['abajo']);   // 401.00 está más cerca que 350.00
        $this->assertSame(['ids' => [1, 3], 'suma' => 50100], $r['arriba']);
    }

    public function test_pantalla_ventas_calcula_y_palomea_las_facturas_del_cliente(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $f = fn (string $folio, float $total, string $cliente = 'PLAZA NORTE', string $estatus = 'pendiente', ?float $saldo = null) => Venta::create([
            'tipo' => 'factura', 'folio' => $folio, 'cliente_nombre' => $cliente, 'fecha' => now()->year.'-09-0'.(strlen($folio) % 9 + 1),
            'costo' => 1, 'subtotal' => $total, 'iva' => 0, 'total' => $total, 'estatus' => $estatus, 'origen' => 'sicar', 'saldo_sicar' => $saldo,
        ]);
        $a = $f('5-5001', 12500.00);
        $f('5-5002', 8300.50);
        $c = $f('5-5003', 20977.17);
        $f('5-5004', 4100.00);
        $e = $f('5-5005', 15000.00, saldo: 10000.00);   // parcialmente pagada: se usa el saldo
        $f('5-6000', 10000.00, 'OTRO CLIENTE');            // otro cliente: no entra
        $f('5-5006', 5000.00, estatus: 'pagada');         // pagada: solo si se pide

        $l = Livewire::test(Ventas::class)
            ->set('montoDeposito', '43,477.17')->call('calcularDeposito')
            ->assertSet('error', 'Primero escribe el cliente en el buscador (por ejemplo PLAZA NORTE) para que solo se combinen sus facturas.')
            ->set('buscar', 'PLAZA NORTE')->call('calcularDeposito')
            ->assertSee('Opción 1: suma exacto $43,477.17')->assertSee('Las más cercanas')->assertSee('saldo $10,000.00 de $15,000.00');
        $this->assertSame(5, $l->get('cuadre')['candidatas']);
        $this->assertContains([$a->id, $c->id, $e->id], $l->get('cuadre')['exactas']);

        $i = array_search([$a->id, $c->id, $e->id], $l->get('cuadre')['exactas'], true);
        $l->call('seleccionarCuadre', 'exacta', $i)->assertSet('seleccion', [$a->id, $c->id, $e->id]);

        // Sin exacta: las más cercanas.
        $l->set('montoDeposito', '12600')->call('calcularDeposito')->assertSee('Ninguna da exacto')
            ->assertSee('sobran $')->assertSee('faltan $');
    }
}

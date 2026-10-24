<?php

namespace Tests\Feature;

use App\Models\Pedido;
use App\Models\User;
use App\Models\Venta;
use App\Models\VentaVinculo;
use App\Support\Trazabilidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TrazabilidadTest extends TestCase
{
    use RefreshDatabase;

    private function venta(array $d): Venta
    {
        return Venta::create($d + ['cliente_nombre' => 'PROVEX', 'fecha' => '2026-09-01', 'subtotal' => 100, 'iva' => 16, 'total' => 116, 'estatus' => 'pendiente']);
    }

    public function test_varias_notas_cobradas_se_facturan_y_la_factura_hereda_el_cobro_mas_reciente(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $n1 = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-1', 'estatus' => 'pagada', 'fecha_pago' => '2026-09-02']);
        $n2 = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-2', 'estatus' => 'pagada', 'fecha_pago' => '2026-09-05']);
        $f = $this->venta(['tipo' => 'factura', 'folio' => '5-100', 'subtotal' => 200, 'total' => 232]);

        $this->assertSame(2, Trazabilidad::ligar(collect([$n1, $n2]), $f));
        $this->assertSame('cancelada', $n1->fresh()->estatus);
        $this->assertSame('5-100', $n1->fresh()->factura_sustituta);
        $f->refresh();
        $this->assertSame('pagada', $f->estatus);
        $this->assertSame('2026-09-05', $f->fecha_pago->toDateString());
        $this->assertSame(2, $f->reemplaza()->count());
        $this->assertSame(0, Trazabilidad::ligar(collect([$n1]), $f));   // no duplica
        $otra = $this->venta(['tipo' => 'factura', 'folio' => '5-101']);
        $this->assertSame(0, Trazabilidad::ligar(collect([$n1]), $otra)); // ya está ligada a otra: no se encima
        $this->assertSame('5-100', $n1->fresh()->factura_sustituta);

        // Deshacer una: la nota regresa a pagada y la factura suelta el cobro heredado
        // (la otra nota sola sí estaba cobrada, así que lo vuelve a tomar con su fecha).
        Trazabilidad::desligar(VentaVinculo::where('origen_id', $n2->id)->sole());
        $this->assertSame('pagada', $n2->fresh()->estatus);
        $this->assertNull($n2->fresh()->factura_sustituta);
        $this->assertSame('2026-09-02', $f->fresh()->fecha_pago->toDateString());
        Trazabilidad::desligar(VentaVinculo::where('origen_id', $n1->id)->sole());
        $this->assertSame('pendiente', $f->fresh()->estatus);
    }

    public function test_si_una_nota_no_estaba_cobrada_la_factura_queda_pendiente(): void
    {
        $n1 = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-1', 'estatus' => 'pagada', 'fecha_pago' => '2026-09-02']);
        $n2 = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-2']);
        $f = $this->venta(['tipo' => 'factura', 'folio' => '5-100']);
        Trazabilidad::ligar(collect([$n1, $n2]), $f);
        $this->assertSame('pendiente', $f->fresh()->estatus);
        $this->assertSame('pendiente', VentaVinculo::where('origen_id', $n2->id)->value('estatus_anterior'));
    }

    public function test_no_se_liga_a_una_nota_ni_a_una_cancelada(): void
    {
        $n = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-1']);
        $this->expectException(InvalidArgumentException::class);
        Trazabilidad::ligar(collect([$n]), $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-2']));
    }

    public function test_factura_cancelada_y_rehecha(): void
    {
        $vieja = $this->venta(['tipo' => 'factura', 'folio' => '5-1']);
        $nueva = $this->venta(['tipo' => 'factura', 'folio' => '5-2']);
        Trazabilidad::ligar(collect([$vieja]), $nueva);
        $this->assertSame('sustituida_por', VentaVinculo::sole()->tipo);
        $this->assertSame('cancelada', $vieja->fresh()->estatus);
    }

    public function test_pedido_con_varias_facturas(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $pedido = Pedido::factory()->create();
        $f1 = $this->venta(['tipo' => 'factura', 'folio' => '5-1']);
        $f2 = $this->venta(['tipo' => 'factura', 'folio' => '5-2']);
        Trazabilidad::ligarPedido($pedido, $f1);
        Trazabilidad::ligarPedido($pedido, $f2);
        Trazabilidad::ligarPedido($pedido, $f2);
        $this->assertSame(2, $pedido->ventas()->count());
        $this->assertSame($pedido->id, $f1->pedidos()->value('pedidos.id'));
        Trazabilidad::desligarPedido($pedido, $f1);
        $this->assertSame(1, $pedido->ventas()->count());
    }

    public function test_sicar_completa_la_liga_cuando_llega_la_factura(): void
    {
        config(['services.sicar.token' => 'secreto']);
        $nota = $this->venta(['tipo' => 'nota', 'folio' => 'NOTA-7', 'origen' => 'sheets']);
        $doc = fn (array $x) => array_merge(['sicar_id' => 'F:1', 'tipo' => 'factura', 'folio' => '5-700', 'fecha' => '2026-09-10',
            'cliente' => 'PROVEX', 'subtotal' => 100, 'iva' => 16, 'total' => 116], $x);
        $this->withToken('secreto')->postJson('/api/sicar/ventas', ['documentos' => [
            $doc(['sicar_id' => 'N:7', 'tipo' => 'nota', 'folio' => 'NOTA-7', 'facturada_en' => '5-700']),
            $doc([]),
        ]])->assertOk();
        $v = VentaVinculo::sole();
        $this->assertSame($nota->id, $v->origen_id);
        $this->assertSame('sicar', $v->fuente);
        $this->assertSame('facturada_en', $v->tipo);
    }
}

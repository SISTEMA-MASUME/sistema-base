<?php

namespace Tests\Feature;

use App\Livewire\Articulos\Programas;
use App\Models\ListaPrecio;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\ProgramaAjuste;
use App\Models\ProgramaComercial;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\ProgramasComerciales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Metas con proveedores: programa comercial M1 de Urrea (descuento en factura por lo comprado el trimestre anterior). */
class ProgramasComercialesTest extends TestCase
{
    use RefreshDatabase;

    private Proveedor $urrea;

    private User $daniel;

    private int $folio = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 12:00:00');   // T4 (oct–dic)
        $this->daniel = User::factory()->create(['rol' => 'admin']);
        $this->actingAs($this->daniel);
        $this->urrea = Proveedor::factory()->create(['nombre' => 'URREA']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function oc(float $totalSinIva, string $fecha, string $estado = 'enviada', ?Proveedor $proveedor = null): void
    {
        $o = OrdenProveedor::create(['folio' => ++$this->folio, 'pedido_id' => Pedido::factory()->create()->id, 'proveedor_id' => ($proveedor ?? $this->urrea)->id,
            'user_id' => $this->daniel->id, 'metodo_pago' => 'TRANSFERENCIA', 'estado' => $estado, 'total_costo' => $totalSinIva]);
        $o->forceFill(['created_at' => Carbon::parse($fecha)])->save();
    }

    public function test_lo_del_trimestre_pasado_da_el_descuento_de_hoy_y_lo_de_este_el_del_siguiente(): void
    {
        $p = ProgramasComerciales::usarUrreaM1($this->urrea->id);
        // T3: $68,000 (como el ejemplo del PDF) → este trimestre 20 + 5 y bono 2%.
        $this->oc(40000, '2026-07-20');
        $this->oc(28000, '2026-09-30');
        $this->oc(99999, '2026-08-01', 'cancelada');                         // cancelada no cuenta
        $this->oc(50000, '2026-08-01', 'enviada', Proveedor::factory()->create(['nombre' => 'TRUPER']));   // otro proveedor no cuenta
        // T4: $100,000 en OC + $12,000 comprados por fuera − $2,000 de nota de crédito = $110,000.
        $this->oc(100000, '2026-10-10');
        ProgramaAjuste::create(['programa_comercial_id' => $p->id, 'fecha' => '2026-11-02', 'monto' => 12000, 'nota' => 'Factura sin OC']);
        ProgramaAjuste::create(['programa_comercial_id' => $p->id, 'fecha' => '2026-11-03', 'monto' => -2000, 'nota' => 'Nota de crédito']);

        $r = ProgramasComerciales::resumen($p);
        $this->assertSame([4, 68000.0, '20 + 5', 2.0], [$r['trimestre'], $r['anterior'], $r['descuentos_hoy'], (float) $r['bono_hoy']]);
        $this->assertSame([110000.0, 5.0, 5.0], [$r['actual'], (float) $r['nivel_siguiente']['factura'], (float) $r['bono_siguiente']]);
        $this->assertSame([10.0, 20000.0], [(float) $r['siguiente_factura']['factura'], $r['siguiente_factura']['falta']]);   // faltan $20,000 para el 10%
        $this->assertSame(46, $r['dias_restantes']);
        $this->assertSame(['desde' => 300000, 'factura' => 10, 'bono' => 5], ProgramasComerciales::nivel($p, 500000));
        $this->assertNull(ProgramasComerciales::siguienteFactura($p, 500000));
    }

    public function test_pantalla_propone_urrea_avisa_cuanto_falta_y_actualiza_la_lista(): void
    {
        $this->oc(68000, '2026-09-10');
        $this->oc(110000, '2026-10-10');
        ListaPrecio::create(['proveedor_id' => $this->urrea->id, 'nombre' => 'URREA octubre 2026', 'columna_precio' => 'PRECIO DE LISTA', 'descuentos' => '20', 'descuento_pct' => 20]);

        $t = Livewire::test(Programas::class)->assertSet('proveedorId', $this->urrea->id)->assertSee('Empezar con el programa de Urrea')
            ->call('usarUrreaM1')->assertSee('URREA ya tiene el programa M1 de Urrea')
            ->assertSee('20 + 5% en factura')->assertSee('Te faltan $20,000.00 para el 10% en factura del próximo trimestre')
            ->assertSee('Actualizar costos a 20 + 5%');
        $t->call('actualizarLista', ProgramaComercial::sole()->id)->assertSee('ya tiene tu costo con 20 + 5%')->assertDontSee('Actualizar costos a');
        $this->assertSame('20 + 5', ListaPrecio::sole()->descuentos);

        // Ajuste a mano: se valida y suma.
        $id = ProgramaComercial::sole()->id;
        $t->set("ajuste.$id.monto", 'mucho')->call('agregarAjuste', $id)->assertHasErrors("ajuste.$id.monto")
            ->set("ajuste.$id.tipo", 'compra')->set("ajuste.$id.monto", '$20,000')->set("ajuste.$id.nota", 'Pedido por teléfono')->call('agregarAjuste', $id)->assertHasNoErrors()
            ->assertSee('Ya estás en el nivel más alto de descuento en factura');

        // Total del portal: no puede ser negativo; si se anota, manda sobre las OC de antes de su fecha.
        $t->set("ajuste.$id.tipo", 'total')->set("ajuste.$id.monto", '-5')->call('agregarAjuste', $id)->assertHasErrors("ajuste.$id.monto")
            ->set("ajuste.$id.monto", '15,495')->call('agregarAjuste', $id)->assertHasNoErrors()->assertSee('según el portal');
        $this->assertSame(15495.0, ProgramasComerciales::resumen(ProgramaComercial::sole())['actual']);

        // Bono de volumen en factura: se suma a los descuentos de la lista.
        $t->call('alternarBonoEnFactura', $id);
        $this->assertTrue(ProgramaComercial::sole()->bono_en_factura);
        $this->assertSame('20 + 5 + 2', ProgramasComerciales::resumen(ProgramaComercial::sole())['descuentos_hoy']);

        // El bono que trae la factura real manda (facturas de Urrea de oct-2026: "-20,-10,-4"); solo este trimestre.
        $t->set("bonoFactura.$id", 'mucho')->call('guardarBonoFactura', $id)->assertHasErrors("bonoFactura.$id")
            ->set("bonoFactura.$id", '4%')->call('guardarBonoFactura', $id)->assertHasNoErrors()->assertSee('(el de tu factura) 4%');
        $this->assertSame('20 + 5 + 4', ProgramasComerciales::resumen(ProgramaComercial::sole())['descuentos_hoy']);
        $this->assertFalse(ProgramasComerciales::resumen(ProgramaComercial::sole(), Carbon::parse('2027-01-10'))['bono_real']);   // otro trimestre: vuelve al cálculo
        $t->set("bonoFactura.$id", '')->call('guardarBonoFactura', $id);
        $this->assertNull(ProgramaComercial::sole()->bono_factura);

        // Solo Daniel.
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']))->get(route('programas'))->assertForbidden();
        $this->actingAs($this->daniel)->get(route('programas'))->assertOk();
    }

    public function test_el_total_del_portal_manda_y_se_le_suma_lo_comprado_despues(): void
    {
        $p = ProgramasComerciales::usarUrreaM1($this->urrea->id);
        // T3: en el sistema casi no hay OC ($5,000), pero Urreanet dice $131,000 → este trimestre 20 + 10.
        $this->oc(5000, '2026-08-10');
        ProgramaAjuste::create(['programa_comercial_id' => $p->id, 'tipo' => 'total', 'fecha' => '2026-09-30', 'monto' => 131000]);
        // T4: el portal dice $15,495 al 15-oct; luego una OC de $4,505 y una nota de crédito de −$1,000. La OC de antes no se suma doble.
        $this->oc(3000, '2026-10-05');
        ProgramaAjuste::create(['programa_comercial_id' => $p->id, 'tipo' => 'total', 'fecha' => '2026-10-15', 'monto' => 15495]);
        $this->oc(4505, '2026-10-20');
        ProgramaAjuste::create(['programa_comercial_id' => $p->id, 'fecha' => '2026-10-25', 'monto' => -1000, 'nota' => 'Nota de crédito']);
        ProgramaAjuste::create(['programa_comercial_id' => $p->id, 'fecha' => '2026-10-10', 'monto' => 999, 'nota' => 'Ya va en el total del portal']);

        $r = ProgramasComerciales::resumen($p);
        $this->assertSame([131000.0, '20 + 10', 19000.0], [$r['anterior'], $r['descuentos_hoy'], $r['actual']]);
        $this->assertSame(15495.0, (float) $r['oficial_actual']->monto);
        $this->assertSame([31000.0, 111000.0], [$r['siguiente_factura']['falta'], $r['mantener']['falta']]);   // 5% y seguir con el 10%
        Livewire::test(Programas::class)->assertSee('Para seguir con el 10% en factura el próximo trimestre te faltan $111,000.00')
            ->assertSee('20 + 10% en factura')->assertSee('según el portal');

        // Un total más nuevo reemplaza al anterior.
        ProgramaAjuste::create(['programa_comercial_id' => $p->id, 'tipo' => 'total', 'fecha' => '2026-11-10', 'monto' => 60000]);
        $this->assertSame(60000.0, ProgramasComerciales::resumen($p)['actual']);
    }
}

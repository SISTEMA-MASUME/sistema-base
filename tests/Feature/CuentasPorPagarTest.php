<?php

namespace Tests\Feature;

use App\Enums\EstadoOrdenProveedor;
use App\Livewire\Finanzas\PorPagar;
use App\Livewire\OrdenesProveedor\Generar;
use App\Livewire\OrdenesProveedor\Ver as VerOrden;
use App\Livewire\Proveedores\Ver;
use App\Models\Cuenta;
use App\Models\CuentaPorPagar;
use App\Models\OrdenProveedor;
use App\Models\PagoProveedor;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\CostoOrden;
use App\Support\Patrimonio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** "A quién le debo": compras a crédito desde la OC o a mano, pagos con comprobante y su efecto en Mis finanzas. */
class CuentasPorPagarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['rol' => 'admin']);
        $this->actingAs($this->admin);
    }

    private function partida(Pedido $pedido, Proveedor $prov, float $costo = 100, float $cantidad = 2): PedidoPartida
    {
        return PedidoPartida::create(['pedido_id' => $pedido->id, 'orden' => PedidoPartida::where('pedido_id', $pedido->id)->count() + 1,
            'descripcion' => 'PIEZA '.uniqid(), 'unidad' => 'PZA', 'cantidad' => $cantidad, 'proveedor_id' => $prov->id, 'costo' => $costo, 'precio' => 200]);
    }

    public function test_la_oc_a_credito_se_anota_sola_y_obliga_a_elegir_como_se_paga(): void
    {
        $pedido = Pedido::factory()->create();
        $urrea = Proveedor::factory()->create(['nombre' => 'URREA', 'vende_a_credito' => true]);
        $disnor = Proveedor::factory()->create(['nombre' => 'DISNOR']);
        $this->partida($pedido, $urrea, 1000, 2);
        $this->partida($pedido, $disnor, 50, 1);

        $t = Livewire::test(Generar::class, ['pedido' => $pedido])
            ->assertSet('config.p'.$urrea->id.'.metodo_pago', EstadoOrdenProveedor::CREDITO)   // ya viene marcado
            ->assertSet('config.p'.$disnor->id.'.metodo_pago', '')
            ->assertSee('A quién le debo')
            ->call('generar')->assertHasErrors(['grupo.p'.$disnor->id]);
        $this->assertSame(0, OrdenProveedor::count());

        $t->set('config.p'.$disnor->id.'.metodo_pago', 'TARJETA')->call('generar')->assertHasNoErrors();
        $ocUrrea = OrdenProveedor::where('proveedor_id', $urrea->id)->firstOrFail();
        $deuda = CuentaPorPagar::sole();
        $this->assertSame([$urrea->id, $ocUrrea->id, 'pendiente', 2320.0, $ocUrrea->folioTexto()],
            [$deuda->proveedor_id, $deuda->orden_proveedor_id, $deuda->estatus, (float) $deuda->importe, $deuda->folio]);

        // Descuento del proveedor: la deuda baja con la OC. Cancelar la OC cancela la deuda.
        CostoOrden::corregirTotal($ocUrrea, 2000);
        $this->assertEqualsWithDelta(2000, (float) $deuda->fresh()->importe, 0.01);
        Livewire::test(VerOrden::class, ['orden' => $ocUrrea])->assertSee('Se le debe $2,000.00')->call('cancelar');
        $this->assertSame('cancelada', $deuda->fresh()->estatus);
    }

    public function test_pagar_con_comprobante_quita_la_deuda_y_se_puede_anular(): void
    {
        $milwaukee = Proveedor::factory()->create(['nombre' => 'MILWAUKEE']);
        $cuenta = Cuenta::create(['nombre' => 'HEY BANCO', 'nombre_llave' => 'HEY BANCO', 'tipo' => 'debito', 'de' => 'masume', 'activa' => true]);

        $c = Livewire::test(PorPagar::class)
            ->call('abrirAgregar')
            ->set('aProveedor', 'milwaukee')->set('aFolio', '8803')->set('aFecha', '2026-09-01')->set('aImporte', '907.44')
            ->call('agregar')->assertHasNoErrors()
            ->call('abrirAgregar')
            ->set('aProveedor', 'MILWAUKEE')->set('aFolio', '8921')->set('aFecha', '2026-09-09')->set('aImporte', '$4,333.08')
            ->call('agregar')->assertHasNoErrors()
            ->call('abrirAgregar')->set('aProveedor', 'MILWAUKEE')->set('aFolio', '8803')->set('aFecha', '2026-09-01')->set('aImporte', '1')
            ->call('agregar')->assertHasErrors('aFolio')   // no se duplica la misma factura
            ->set('agregando', false)
            ->assertSee('$5,240.52');
        $this->assertEqualsWithDelta(5240.52, Patrimonio::resumen()['proveedores'], 0.001);

        [$a, $b] = CuentaPorPagar::orderBy('folio')->get()->all();
        $c->set('marcadas', [(string) $a->id, (string) $b->id])->call('pagarMarcadas', $milwaukee->id)
            ->assertSee('Registrar pago a MILWAUKEE')
            ->set('pCuenta', (string) $cuenta->id)->set('pReferencia', 'SPEI 123')
            ->set('pComprobante', UploadedFile::fake()->create('transferencia.pdf', 50, 'application/pdf'))
            ->call('guardarPago')->assertHasNoErrors()
            ->assertSee('se registró el pago de $5,240.52 a MILWAUKEE')->assertSee('No le debes nada a ningún proveedor');

        $pago = PagoProveedor::sole();
        $this->assertSame(['pagada', 'pagada'], [$a->fresh()->estatus, $b->fresh()->estatus]);
        $this->assertSame(['8803', '8921'], array_column($pago->detalle, 'folio'));
        $this->assertSame($cuenta->id, $pago->cuenta_id);
        Storage::disk('local')->assertExists($pago->comprobante_path);
        $this->assertSame(0.0, Patrimonio::resumen()['proveedores']);

        $this->get(route('finanzas.por-pagar.comprobante', $pago))->assertOk();
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']))->get(route('finanzas.por-pagar.comprobante', $pago))->assertForbidden();
        $this->get(route('finanzas.por-pagar'))->assertForbidden();
        $this->actingAs($this->admin);

        Livewire::test(PorPagar::class)->set('pestana', 'pagadas')->assertSee('Ver comprobante')->assertSee('8803')
            ->call('anularPago', $pago->id)->assertSee('regresaron a "Por pagar"');
        $this->assertNotNull($pago->fresh()->anulado_at);
        $this->assertSame(['pendiente', 'pendiente'], [$a->fresh()->estatus, $b->fresh()->estatus]);
    }

    public function test_quitar_una_anotada_por_error_y_no_se_puede_pagar_lo_de_dos_proveedores_juntos(): void
    {
        $x = Proveedor::factory()->create(['nombre' => 'ULINE']);
        $y = Proveedor::factory()->create(['nombre' => 'URREA']);
        $dx = CuentaPorPagar::create(['proveedor_id' => $x->id, 'folio' => '1', 'fecha' => '2026-10-01', 'importe' => 100, 'estatus' => 'pendiente']);
        $dy = CuentaPorPagar::create(['proveedor_id' => $y->id, 'folio' => '2', 'fecha' => '2026-10-01', 'importe' => 200, 'estatus' => 'pendiente']);

        // Marcadas de dos proveedores: "Pagar marcadas" de ULINE solo toma la de ULINE.
        Livewire::test(PorPagar::class)->set('marcadas', [(string) $dx->id, (string) $dy->id])->call('pagarMarcadas', $x->id)
            ->assertSet('marcadas', [(string) $dx->id])->call('guardarPago')->assertHasNoErrors();
        $this->assertSame(['pagada', 'pendiente'], [$dx->fresh()->estatus, $dy->fresh()->estatus]);
        $this->assertNull(PagoProveedor::sole()->comprobante_path);

        // Corregir el importe (p. ej. con centavos) de una anotada a mano.
        Livewire::test(PorPagar::class)->call('editar', $dy->id)->assertSet('aImporte', '200.00')
            ->set('aImporte', '199.50')->set('aVence', '2026-11-01')->call('agregar')->assertHasNoErrors()->assertSee('Deuda corregida');
        $this->assertSame(['199.50', '2026-11-01'], [$dy->fresh()->importe, $dy->fresh()->vence->format('Y-m-d')]);

        Livewire::test(PorPagar::class)->call('quitar', $dy->id)->assertSee('Se quitó la deuda 2');
        $this->assertSame('cancelada', $dy->fresh()->estatus);
    }

    public function test_en_la_ficha_del_proveedor_se_marca_que_vende_a_credito(): void
    {
        $p = Proveedor::factory()->create(['nombre' => 'NUEVO MUNDO']);
        Livewire::test(Ver::class, ['proveedor' => $p])
            ->assertSee('Nos vende a crédito')->call('alternarCredito')->assertSee('queda como proveedor que nos vende a crédito');
        $this->assertTrue($p->fresh()->vende_a_credito);
    }


    public function test_subir_o_cambiar_el_comprobante_de_un_pago_ya_registrado(): void
    {
        $prov = Proveedor::factory()->create(['nombre' => 'MILWAUKEE']);
        $d = CuentaPorPagar::create(['proveedor_id' => $prov->id, 'folio' => '8803', 'fecha' => '2026-09-01', 'importe' => 907.44, 'estatus' => 'pendiente']);
        $c = Livewire::test(PorPagar::class)->call('pagarUna', $d->id)->call('guardarPago')->assertSee('quedó sin comprobante');
        $pago = PagoProveedor::sole();
        $this->assertNull($pago->comprobante_path);

        $c->set('pestana', 'pagadas')->assertSee('Subir comprobante')
            ->set('comprobanteTarde', UploadedFile::fake()->create('notas.txt', 5, 'text/plain'))->call('guardarComprobante', $pago->id)
            ->assertSee('El comprobante debe ser PDF o imagen');
        $this->assertNull($pago->fresh()->comprobante_path);

        $c->set('comprobanteTarde', UploadedFile::fake()->create('spei.pdf', 30, 'application/pdf'))->call('guardarComprobante', $pago->id)
            ->assertSee('se agregó el comprobante del pago a MILWAUKEE')->assertSee('Ver comprobante')->assertSee('Cambiar comprobante');
        $primero = $pago->fresh()->comprobante_path;
        Storage::disk('local')->assertExists($primero);

        $c->set('comprobanteTarde', UploadedFile::fake()->image('captura.png'))->call('guardarComprobante', $pago->id)->assertSee('Se cambió el comprobante');
        Storage::disk('local')->assertMissing($primero);
        Storage::disk('local')->assertExists($pago->fresh()->comprobante_path);
        $this->assertSame('captura.png', $pago->fresh()->comprobante_nombre);
    }
}

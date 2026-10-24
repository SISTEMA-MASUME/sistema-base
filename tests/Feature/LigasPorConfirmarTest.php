<?php

namespace Tests\Feature;

use App\Livewire\Clientes\Ver as FichaCliente;
use App\Livewire\Finanzas\LigasPorConfirmar as Pantalla;
use App\Livewire\Finanzas\Ventas;
use App\Livewire\Pedidos\Expediente;
use App\Livewire\Pedidos\Ver;
use App\Models\Cliente;
use App\Models\ClienteRazonSocial;
use App\Models\LigaRechazada;
use App\Models\Pedido;
use App\Models\User;
use App\Models\Venta;
use App\Support\Trazabilidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Candado de razón social: la factura solo se liga sola si sale al mismo cliente o a una razón social registrada de él. */
class LigasPorConfirmarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sicar.token' => 'secreto']);
    }

    private function doc(array $extra = []): array
    {
        return array_merge([
            'sicar_id' => 'F:100', 'tipo' => 'factura', 'folio' => '5-5100', 'fecha' => '2026-09-29',
            'cliente' => 'HOTELES XY', 'rfc' => 'HAM970101AB1', 'concepto' => 'RTV SILICONE',
            'subtotal' => 1000, 'iva' => 160, 'retencion' => 0, 'total' => 1160, 'costo' => 700,
            'metodo_pago' => 'PPD', 'uuid' => 'abc-123', 'cancelada' => false, 'saldo' => 1160, 'pagada_en' => null,
            'comentario' => 'OC 4500777123',
        ], $extra);
    }

    private function enviar(array $docs)
    {
        return $this->withToken('secreto')->postJson('/api/sicar/ventas', ['documentos' => $docs]);
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'admin']);
    }

    private function hotel(string $nombre = 'HOTEL AURORA EJECUTIVO'): Cliente
    {
        return Cliente::factory()->create(['nombre' => $nombre]);
    }

    public function test_mismo_cliente_liga_directo(): void
    {
        $cliente = $this->hotel('HOTELES XY');
        $p = Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '4500777123']);

        $this->enviar([$this->doc()])->assertJson(['ligadas_a_pedido' => 1]);
        $this->assertSame(1, $p->ventas()->count());
        $this->assertSame(0, $p->ventasPorConfirmar()->count());
    }

    public function test_otra_razon_social_es_propuesta_y_no_cuenta_como_ligada(): void
    {
        $p = Pedido::factory()->create(['cliente_id' => $this->hotel()->id, 'numero_oc_cliente' => '4500777123']);

        $this->enviar([$this->doc()])->assertJson(['ligadas_a_pedido' => 0]);
        $v = Venta::firstOrFail();
        $this->assertFalse($p->ventas()->exists());
        $this->assertFalse($v->pedidos()->exists());
        $this->assertTrue($p->ventasPorConfirmar()->whereKey($v->id)->exists());
        $this->assertFalse($p->actividades()->exists());

        // Reenviar no duplica la propuesta.
        $this->enviar([$this->doc(['costo' => 710])]);
        $this->assertSame(1, DB::table('pedido_venta')->count());

        // Ni la tarjeta ni Ventas la cuentan como ligada, pero avisan que hay una por confirmar.
        $this->actingAs($this->admin());
        Livewire::test(Expediente::class, ['pedidoId' => $p->id])
            ->assertSee('Por confirmar')->assertSee('¿HOTELES XY factura por HOTEL AURORA EJECUTIVO?')
            ->assertSee('Sin facturas ni notas');
        Livewire::test(Ver::class, ['pedido' => $p])->assertSee('1 por confirmar');
        Livewire::test(Ventas::class)->set('anio', 'todos')->assertSee('Hay 1 factura por confirmar')->assertDontSee('P-'.$p->folio);
    }

    public function test_si_registra_la_razon_social_confirma_las_del_mismo_par_y_las_siguientes_ligan_directo(): void
    {
        $cliente = $this->hotel();
        $p1 = Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '4500777123']);
        $p2 = Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '4500888456']);
        $this->enviar([
            $this->doc(),
            $this->doc(['sicar_id' => 'F:2', 'folio' => '5-5101', 'uuid' => 'u2', 'comentario' => 'OC 4500888456']),
        ]);
        $this->assertSame(2, DB::table('pedido_venta')->where('por_confirmar', true)->count());
        $v1 = Venta::where('folio', '5-5100')->first();

        $admin = $this->admin();
        $this->actingAs($admin);
        Livewire::test(Pantalla::class)->assertSee('¿HOTELES XY factura por HOTEL AURORA EJECUTIVO?')->assertSee('5-5101')
            ->call('si', $p1->id, $v1->id)->assertSee('2 facturas quedaron ligadas');

        $this->assertSame(0, DB::table('pedido_venta')->where('por_confirmar', true)->count());
        $this->assertSame(1, $p1->ventas()->count());
        $this->assertSame(1, $p2->ventas()->count());
        $razon = ClienteRazonSocial::where('cliente_id', $cliente->id)->sole();
        $this->assertSame('HOTELES XY', $razon->nombre);
        $this->assertSame('HAM970101AB1', $razon->rfc);
        $this->assertSame($admin->id, $razon->confirmada_por);
        $this->assertTrue($p1->actividades()->where('descripcion', 'like', '%se confirmó que HOTELES XY factura por HOTEL AURORA%')->exists());

        // La siguiente factura de esa razón social se liga directo.
        $p3 = Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '4500999111']);
        $this->enviar([$this->doc(['sicar_id' => 'F:3', 'folio' => '5-5102', 'uuid' => 'u3', 'comentario' => 'OC 4500999111'])])
            ->assertJson(['ligadas_a_pedido' => 1]);
        $this->assertSame(1, $p3->ventas()->count());
    }

    public function test_no_la_quita_y_no_vuelve_a_proponer(): void
    {
        $cliente = $this->hotel();
        $p = Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '4500777123']);
        $p2 = Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '4500888456']);
        $this->enviar([$this->doc(), $this->doc(['sicar_id' => 'F:2', 'folio' => '5-5101', 'uuid' => 'u2', 'comentario' => 'OC 4500888456'])]);
        $v = Venta::where('folio', '5-5100')->first();

        $this->actingAs($this->admin());
        Livewire::test(Expediente::class, ['pedidoId' => $p->id])->call('rechazarLiga', $v->id);

        $this->assertSame(0, DB::table('pedido_venta')->count());   // también quitó la del otro pedido (mismo par)
        $this->assertTrue(LigaRechazada::where('cliente_id', $cliente->id)->where('nombre_llave', 'HOTELESXY')->exists());
        $this->assertFalse(ClienteRazonSocial::exists());

        $this->enviar([$this->doc(['sicar_id' => 'F:3', 'folio' => '5-5102', 'uuid' => 'u3'])])->assertJson(['ligadas_a_pedido' => 0]);
        $this->assertSame(0, DB::table('pedido_venta')->count());
        $this->assertFalse($p2->ventasPorConfirmar()->exists());
    }

    public function test_una_razon_social_puede_ser_de_varios_clientes(): void
    {
        $a = $this->hotel('HOTEL AURORA EJECUTIVO');
        $b = $this->hotel('HOTEL AURORA CENTRO');
        $pa = Pedido::factory()->create(['cliente_id' => $a->id, 'numero_oc_cliente' => '4500777123']);
        $pb = Pedido::factory()->create(['cliente_id' => $b->id, 'numero_oc_cliente' => '4500888456']);
        $this->enviar([$this->doc(), $this->doc(['sicar_id' => 'F:2', 'folio' => '5-5101', 'uuid' => 'u2', 'comentario' => 'OC 4500888456'])]);
        $this->assertSame(2, DB::table('pedido_venta')->where('por_confirmar', true)->count());

        $this->actingAs($this->admin());
        Livewire::test(Pantalla::class)->assertSee('HOTEL AURORA CENTRO')->assertSee('HOTEL AURORA EJECUTIVO');   // dos preguntas, una por cliente
        $v = Venta::where('folio', '5-5100')->first();
        Livewire::test(Pantalla::class)->call('si', $pa->id, $v->id);

        $this->assertSame(1, $pa->ventas()->count());
        $this->assertSame(0, $pb->ventas()->count());   // el otro hotel sigue por confirmar
        $this->assertSame(1, $pb->ventasPorConfirmar()->count());
        $vb = Venta::where('folio', '5-5101')->first();
        Livewire::test(Pantalla::class)->call('si', $pb->id, $vb->id);
        $this->assertSame(2, ClienteRazonSocial::where('nombre_llave', 'HOTELESXY')->count());
        $this->assertSame(1, $pb->ventas()->count());
    }

    public function test_parks_una_razon_social_varias_plazas_la_oc_decide_y_si_se_repite_pregunta(): void
    {
        $merida = $this->hotel('LUMEX MERIDA');
        $tuxtla = $this->hotel('LUMEX TUXTLA');
        foreach ([$merida, $tuxtla] as $c) {
            ClienteRazonSocial::create(['cliente_id' => $c->id, 'nombre' => 'PARQUES MANTENIMIENTO', 'nombre_llave' => 'PARKSMANTENIMIENTO']);
        }
        $pm = Pedido::factory()->create(['cliente_id' => $merida->id, 'numero_oc_cliente' => '7001']);
        $pt = Pedido::factory()->create(['cliente_id' => $tuxtla->id, 'numero_oc_cliente' => '7002']);
        $rep1 = Pedido::factory()->create(['cliente_id' => $merida->id, 'numero_oc_cliente' => '9999']);
        $rep2 = Pedido::factory()->create(['cliente_id' => $tuxtla->id, 'numero_oc_cliente' => '9999']);

        $parques = ['cliente' => 'PARQUES MANTENIMIENTO', 'rfc' => null];
        $this->enviar([
            $this->doc($parques + ['sicar_id' => 'F:1', 'folio' => '5-1', 'uuid' => 'u1', 'comentario' => 'OC 7002']),
            $this->doc($parques + ['sicar_id' => 'F:2', 'folio' => '5-2', 'uuid' => 'u2', 'comentario' => 'OC 9999']),
        ]);
        // La OC decide la plaza.
        $this->assertTrue($pt->ventas()->where('folio', '5-1')->exists());
        $this->assertFalse($pm->ventas()->exists());
        // La misma OC en dos plazas: no adivina, pregunta.
        $this->assertFalse($rep1->ventas()->exists());
        $this->assertFalse($rep2->ventas()->exists());
        $this->assertSame(2, $rep1->ventasPorConfirmar()->count() + $rep2->ventasPorConfirmar()->count());
    }

    public function test_oc_corta_o_repetida_con_otra_razon_social_ni_se_propone(): void
    {
        $cliente = $this->hotel();
        $corta = Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '778899']);
        $this->enviar([$this->doc(['comentario' => 'OC 778899'])])->assertJson(['ligadas_a_pedido' => 0]);
        $this->assertSame(0, DB::table('pedido_venta')->count());

        Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '4500111222']);
        Pedido::factory()->create(['numero_oc_cliente' => '4500111222']);
        $this->enviar([$this->doc(['sicar_id' => 'F:2', 'folio' => '5-2', 'uuid' => 'u2', 'comentario' => 'OC 4500111222'])]);
        $this->assertSame(0, DB::table('pedido_venta')->count());
        $this->assertFalse($corta->ventasPorConfirmar()->exists());
    }

    public function test_la_razon_social_se_agrega_a_mano_en_la_ficha_y_confirma_lo_pendiente(): void
    {
        $cliente = $this->hotel();
        $p = Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '4500777123']);
        $this->enviar([$this->doc()]);
        $this->assertSame(1, $p->ventasPorConfirmar()->count());

        $this->actingAs($this->admin());
        Livewire::test(FichaCliente::class, ['cliente' => $cliente])
            ->assertSee('Razones sociales con las que factura')
            ->set('razonNombre', 'hoteles xy')->call('agregarRazon')
            ->assertSee('HOTELES XY')->assertSee('Se ligaron 1 factura');
        $this->assertSame(1, $p->ventas()->count());

        $id = $cliente->razonesSociales()->first()->id;
        Livewire::test(FichaCliente::class, ['cliente' => $cliente])->call('quitarRazon', $id);
        $this->assertSame(0, $cliente->razonesSociales()->count());
    }

    public function test_sin_permiso_de_ventas_no_confirma(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => []]));
        Livewire::test(Pantalla::class)->assertForbidden();
    }

    public function test_ligar_a_mano_una_propuesta_la_confirma_y_quitarla_la_borra(): void
    {
        $p = Pedido::factory()->create(['cliente_id' => $this->hotel()->id, 'numero_oc_cliente' => '4500777123']);
        $this->enviar([$this->doc()]);
        $v = Venta::firstOrFail();

        $this->actingAs($this->admin());
        $this->assertTrue(Trazabilidad::ligarPedido($p, $v));
        $this->assertSame(1, $p->ventas()->count());
        $this->assertSame(0, $p->ventasPorConfirmar()->count());
        $this->assertSame(1, DB::table('pedido_venta')->count());
    }

    public function test_la_migracion_pasa_a_por_confirmar_las_ligas_automaticas_de_otra_razon_social(): void
    {
        $hotel = $this->hotel();
        $propio = Pedido::factory()->create(['cliente_id' => $hotel->id, 'numero_oc_cliente' => '4500777123']);
        $otroPedido = Pedido::factory()->create(['cliente_id' => $hotel->id, 'numero_oc_cliente' => '4500888456']);
        $nuevo = fn (array $d) => Venta::create($d + ['tipo' => 'factura', 'fecha' => '2026-10-01', 'subtotal' => 100, 'iva' => 16, 'total' => 116]);
        $ajena = $nuevo(['folio' => '5-1', 'cliente_nombre' => 'HOTELES XY', 'comentario_sicar' => 'OC 4500777123']);
        $manual = $nuevo(['folio' => '5-2', 'cliente_nombre' => 'HOTELES XY', 'comentario_sicar' => 'OC 4500888456']);
        $mismo = $nuevo(['folio' => '5-3', 'cliente_nombre' => $hotel->nombre, 'cliente_id' => $hotel->id, 'comentario_sicar' => 'OC 4500777123']);
        $propio->ventas()->attach($ajena->id, ['user_id' => null, 'created_at' => now()]);
        $otroPedido->ventas()->attach($manual->id, ['user_id' => $this->admin()->id, 'created_at' => now()]);
        $propio->ventas()->attach($mismo->id, ['user_id' => null, 'created_at' => now()]);

        (require database_path('migrations/2026_11_03_000002_pasar_ligas_de_otra_razon_social_a_por_confirmar.php'))->up();

        $this->assertSame([$mismo->id], $propio->ventas()->pluck('ventas.id')->all());
        $this->assertSame([$ajena->id], $propio->ventasPorConfirmar()->pluck('ventas.id')->all());
        $this->assertSame([$manual->id], $otroPedido->ventas()->pluck('ventas.id')->all());   // a mano: no se toca
    }
}

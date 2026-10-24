<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Ventas;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\User;
use App\Models\Venta;
use App\Support\Trazabilidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SicarVentasTest extends TestCase
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
            'cliente' => 'Delta Metal Mexico', 'rfc' => 'DMM970101AB1', 'concepto' => 'RTV SILICONE',
            'subtotal' => 1000, 'iva' => 160, 'retencion' => 0, 'total' => 1160, 'costo' => 700,
            'metodo_pago' => 'PPD', 'uuid' => 'abc-123', 'cancelada' => false, 'saldo' => 1160, 'pagada_en' => null,
        ], $extra);
    }

    private function enviar(array $docs)
    {
        return $this->withToken('secreto')->postJson('/api/sicar/ventas', ['documentos' => $docs]);
    }

    public function test_sin_token_no_entra(): void
    {
        $this->postJson('/api/sicar/ventas', ['documentos' => [$this->doc()]])->assertStatus(401);
        $this->withToken('otro')->postJson('/api/sicar/ventas', ['documentos' => []])->assertStatus(401);
    }

    public function test_crea_factura_y_cliente_por_rfc_y_no_duplica(): void
    {
        $this->enviar([$this->doc()])->assertOk()->assertJson(['ok' => true, 'creadas' => 1]);
        $v = Venta::firstOrFail();
        $this->assertSame('sicar', $v->origen);
        $this->assertSame('pendiente', $v->estatus);
        $this->assertSame('700.00', $v->costo);
        $this->assertSame('DMM970101AB1', $v->cliente->rfc);

        $this->enviar([$this->doc()])->assertJson(['creadas' => 0, 'sin_cambios' => 1]);
        $this->assertSame(1, Venta::count());
        $this->assertSame(1, Cliente::count());
    }

    public function test_se_liga_con_la_importada_del_sheets_y_respeta_el_costo_ajustado(): void
    {
        $cliente = Cliente::factory()->create(['nombre' => 'DELTA METAL MEXICO', 'dias_credito' => 45]);
        $sheets = Venta::create(['tipo' => 'factura', 'folio' => '5-5100', 'cliente_nombre' => 'DELTA METAL MEXICO', 'cliente_id' => $cliente->id,
            'fecha' => '2026-09-29', 'subtotal' => 1000, 'iva' => 160, 'total' => 1160, 'costo' => 650, 'origen' => 'sheets']);

        $this->enviar([$this->doc(['costo' => 700])])->assertJson(['creadas' => 0, 'actualizadas' => 1]);
        $sheets->refresh();
        $this->assertSame('F:100', $sheets->sicar_id);
        $this->assertSame('650.00', $sheets->costo);          // el de Daniel se queda
        $this->assertSame('700.00', $sheets->costo_sicar);
        $this->assertSame($cliente->id, $sheets->cliente_id);  // por nombre, y se le guarda el RFC
        $this->assertSame('DMM970101AB1', $cliente->fresh()->rfc);
        $this->assertSame(1, Cliente::count());
    }

    public function test_costo_de_sicar_se_actualiza_si_nadie_lo_cambio(): void
    {
        $this->enviar([$this->doc(['costo' => 700])]);
        $this->enviar([$this->doc(['costo' => 720])]);
        $this->assertSame('720.00', Venta::first()->costo);

        Venta::first()->update(['costo' => 600]);   // ajuste a mano
        $this->enviar([$this->doc(['costo' => 750])]);
        $this->assertSame('600.00', Venta::first()->costo);
        $this->assertSame('750.00', Venta::first()->costo_sicar);
    }

    public function test_complemento_de_pago_la_marca_pagada_y_no_regresa_a_pendiente(): void
    {
        $this->enviar([$this->doc()]);
        $this->enviar([$this->doc(['saldo' => 0, 'pagada_en' => '2026-10-02', 'pago_referencia' => 'REP 5-200'])])
            ->assertJson(['pagadas' => 1]);
        $v = Venta::first();
        $this->assertSame('pagada', $v->estatus);
        $this->assertSame('2026-10-02', $v->fecha_pago->toDateString());
        $this->assertSame('REP 5-200', $v->pago_referencia);
        $this->assertTrue($v->cambios()->where('origen', 'sicar')->where('campo', 'estatus')->exists());

        // Si SICAR luego no la trae pagada, no se des-paga.
        $this->enviar([$this->doc(['pagada_en' => null])]);
        $this->assertSame('pagada', Venta::first()->estatus);
    }

    public function test_pue_nueva_llega_pagada_y_cancelada_se_cancela(): void
    {
        $this->enviar([
            $this->doc(['sicar_id' => 'F:1', 'folio' => '5-1', 'metodo_pago' => 'PUE', 'saldo' => 0, 'pagada_en' => '2026-09-29', 'pago_referencia' => 'PUE']),
            $this->doc(['sicar_id' => 'N:9', 'tipo' => 'nota', 'folio' => 'NOTA-9']),
        ])->assertJson(['creadas' => 2, 'pagadas' => 1]);
        $this->assertSame('pagada', Venta::where('folio', '5-1')->value('estatus'));

        $this->enviar([$this->doc(['sicar_id' => 'N:9', 'tipo' => 'nota', 'folio' => 'NOTA-9', 'cancelada' => true])])
            ->assertJson(['canceladas' => 1]);
        $this->assertSame('cancelada', Venta::where('folio', 'NOTA-9')->value('estatus'));
    }

    public function test_documentos_con_error_no_frenan_a_los_demas_y_hay_tope(): void
    {
        $r = $this->enviar([$this->doc(['sicar_id' => 'F:2', 'folio' => '']), $this->doc(['sicar_id' => 'F:3', 'folio' => '5-3', 'fecha' => 'ayer']), $this->doc()]);
        $r->assertOk()->assertJson(['creadas' => 1]);
        $this->assertCount(2, $r->json('errores'));

        $this->withToken('secreto')->postJson('/api/sicar/ventas', ['documentos' => array_fill(0, 501, $this->doc())])->assertStatus(422);
        $this->withToken('secreto')->postJson('/api/sicar/ventas', ['otra' => 1])->assertStatus(422);
    }

    public function test_nota_facturada_cancela_la_del_sheets_y_la_factura_hereda_el_cobro(): void
    {
        // En el Sheets la nota ya estaba cobrada (semana 39); SICAR luego la facturó como PUE en otra fecha.
        Venta::create(['tipo' => 'nota', 'folio' => 'NOTA-420', 'cliente_nombre' => 'PROVEX', 'fecha' => '2026-09-21',
            'subtotal' => 500, 'iva' => 80, 'total' => 580, 'costo' => 400, 'estatus' => 'pagada', 'fecha_pago' => '2026-09-22', 'origen' => 'sheets']);

        $this->enviar([
            $this->doc(['sicar_id' => 'N:420', 'tipo' => 'nota', 'folio' => 'NOTA 420', 'facturada_en' => '5-5200']),
            $this->doc(['sicar_id' => 'F:5200', 'folio' => '5-5200', 'cliente' => 'PROVEX', 'metodo_pago' => 'PUE', 'pagada_en' => '2026-10-05', 'saldo' => 0]),
            // Una nota facturada que nunca estuvo en el sistema no se da de alta.
            $this->doc(['sicar_id' => 'N:421', 'tipo' => 'nota', 'folio' => 'NOTA-421', 'facturada_en' => '5-5201']),
        ])->assertOk()->assertJson(['canceladas' => 1, 'creadas' => 1]);

        $nota = Venta::where('tipo', 'nota')->sole();
        $this->assertSame('cancelada', $nota->estatus);
        $this->assertSame('5-5200', $nota->factura_sustituta);
        $factura = Venta::where('folio', '5-5200')->sole();
        $this->assertSame('pagada', $factura->estatus);
        $this->assertSame('2026-09-22', $factura->fecha_pago->toDateString());   // la del cobro de la nota
        $this->assertSame('Cobrada como NOTA-420', $factura->pago_referencia);
    }

    public function test_si_la_factura_llega_antes_que_el_aviso_de_la_nota_tambien_hereda(): void
    {
        Venta::create(['tipo' => 'nota', 'folio' => 'NOTA-50', 'cliente_nombre' => 'PROVEX', 'fecha' => '2026-09-21',
            'subtotal' => 500, 'iva' => 80, 'total' => 580, 'estatus' => 'pagada', 'fecha_pago' => '2026-09-22', 'origen' => 'sheets']);
        $this->enviar([$this->doc(['sicar_id' => 'F:60', 'folio' => '5-60', 'metodo_pago' => 'PUE', 'pagada_en' => '2026-10-05'])]);
        $this->assertSame('2026-10-05', Venta::where('folio', '5-60')->sole()->fecha_pago->toDateString());

        $this->enviar([$this->doc(['sicar_id' => 'N:50', 'tipo' => 'nota', 'folio' => 'NOTA-50', 'facturada_en' => '5-60'])]);
        $this->assertSame('2026-09-22', Venta::where('folio', '5-60')->sole()->fecha_pago->toDateString());
        $this->assertSame('cancelada', Venta::where('folio', 'NOTA-50')->sole()->estatus);
    }

    public function test_cliente_unido_en_duplicados_usa_el_principal(): void
    {
        $principal = Cliente::factory()->create(['nombre' => 'HOTEL PRINCIPAL']);
        $unido = Cliente::factory()->create(['nombre' => 'INMOBILIARIA DE EJEMPLO']);
        $unido->forceFill(['unido_a_id' => $principal->id])->save();
        $unido->delete();
        $borrado = Cliente::factory()->create(['nombre' => 'CLIENTE BORRADO SA']);
        $borrado->delete();

        $this->enviar([
            $this->doc(['sicar_id' => 'F:3488', 'folio' => '5-3343', 'cliente' => 'INMOBILIARIA DE EJEMPLO', 'rfc' => 'IDE010101AB1']),
            $this->doc(['sicar_id' => 'F:9', 'folio' => '5-9', 'cliente' => 'Cliente borrado SA', 'rfc' => null]),
        ])->assertOk()->assertJson(['creadas' => 2, 'errores' => []]);

        $this->assertSame($principal->id, Venta::where('folio', '5-3343')->value('cliente_id'));
        $this->assertSame('IDE010101AB1', $principal->fresh()->rfc);
        $this->assertSame($borrado->id, Venta::where('folio', '5-9')->value('cliente_id'));
        $this->assertFalse($borrado->fresh()->trashed());   // SICAR le sigue facturando: se revive
    }

    public function test_guarda_el_saldo_de_sicar_y_el_filtro_revisar(): void
    {
        $this->enviar([$this->doc(['saldo' => 1160])]);
        $v = Venta::first();
        $v->update(['estatus' => 'pagada', 'fecha_pago' => '2026-10-01']);   // a mano
        $this->enviar([$this->doc(['saldo' => 500])]);
        $this->assertSame('500.00', $v->fresh()->saldo_sicar);
        $this->assertSame('pagada', $v->fresh()->estatus);

        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        Livewire::test(Ventas::class)->set('anio', 'todos')
            ->set('tipo', Ventas::REVISAR_SICAR)->assertSee('5-5100')
            ->set('tipo', Ventas::DE_SICAR)->assertSee('5-5100');
    }

    public function test_ocs_en_texto(): void
    {
        $this->assertContains('4500123456', Trazabilidad::ocsEnTexto('OC: 4500123456 / entregar en almacén'));
        $this->assertContains('PO4500123', Trazabilidad::ocsEnTexto('Su orden PO-4500-123'));
        $this->assertContains('4500123', Trazabilidad::ocsEnTexto('Su orden PO-4500-123'));
        $this->assertNotContains('45', Trazabilidad::ocsEnTexto('OC 45 urgente'));
        $this->assertSame([], Trazabilidad::ocsEnTexto('entregar urgente en almacén'));
    }

    public function test_el_comentario_con_la_oc_liga_la_factura_con_su_pedido(): void
    {
        $cliente = Cliente::factory()->create(['nombre' => 'DELTA METAL MEXICO', 'rfc' => 'DMM970101AB1']);
        $pedido = Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => 'OC 4500-98765']);
        $otro = Pedido::factory()->create(['numero_oc_cliente' => '4500098765']);   // misma OC pero otro cliente: no
        $chico = Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => '45']);  // muy corta: no

        $this->enviar([$this->doc(['comentario' => 'OC 4500-98765 45 entregar en recibo'])])
            ->assertOk()->assertJson(['creadas' => 1, 'ligadas_a_pedido' => 1]);
        $v = Venta::firstOrFail();
        $this->assertSame('OC 4500-98765 45 entregar en recibo', $v->comentario_sicar);
        $this->assertTrue($pedido->ventas()->whereKey($v->id)->exists());
        $this->assertFalse($otro->ventas()->exists());
        $this->assertFalse($chico->ventas()->exists());
        $this->assertTrue($pedido->actividades()->where('descripcion', 'like', '%ligada sola%')->exists());

        // Reenviar no duplica la liga.
        $this->enviar([$this->doc(['comentario' => 'OC 4500-98765 45 entregar en recibo', 'costo' => 710])])->assertJson(['ligadas_a_pedido' => 0]);
        $this->assertSame(1, $pedido->ventas()->count());
    }

    public function test_si_la_oc_se_captura_despues_en_el_pedido_tambien_se_liga(): void
    {
        $cliente = Cliente::factory()->create(['nombre' => 'DELTA METAL MEXICO', 'rfc' => 'DMM970101AB1']);
        $pedido = Pedido::factory()->create(['cliente_id' => $cliente->id, 'numero_oc_cliente' => null]);
        $this->enviar([$this->doc(['comentario' => 'PO#77881'])]);
        $this->assertFalse($pedido->ventas()->exists());

        $pedido->update(['numero_oc_cliente' => '77881']);
        $this->assertTrue($pedido->fresh()->ventas()->exists());
    }

    public function test_oc_con_texto_largo_y_factura_a_otra_razon_social(): void
    {
        $kappa = Cliente::factory()->create(['nombre' => 'GRUPO KAPPA LA BARCA']);
        $hotel = Cliente::factory()->create(['nombre' => 'HOTEL AURORA EJECUTIVO']);
        $p1 = Pedido::factory()->create(['cliente_id' => $kappa->id, 'numero_oc_cliente' => 'OEAD39802/00 LA BARCA']);
        $p2 = Pedido::factory()->create(['cliente_id' => $hotel->id, 'numero_oc_cliente' => '4500777123']);
        $corta = Pedido::factory()->create(['cliente_id' => $hotel->id, 'numero_oc_cliente' => '778899']);

        $this->enviar([
            // Misma razón social con la OC escrita más corta.
            $this->doc(['sicar_id' => 'F:1', 'folio' => '5-1', 'cliente' => 'GRUPO KAPPA LA BARCA', 'rfc' => null, 'comentario' => 'OC OEAD39802/00']),
            // Otra razón social (HOTELES XY factura por el hotel): OC larga y única → solo una PROPUESTA por confirmar; OC corta → nada.
            $this->doc(['sicar_id' => 'F:2', 'folio' => '5-2', 'cliente' => 'HOTELES XY', 'rfc' => null, 'comentario' => 'PO 4500777123 / 778899']),
        ])->assertOk()->assertJson(['ligadas_a_pedido' => 1]);

        $this->assertTrue($p1->ventas()->where('folio', '5-1')->exists());
        $this->assertFalse($p2->ventas()->exists());   // todavía no cuenta como ligada
        $this->assertTrue($p2->ventasPorConfirmar()->where('folio', '5-2')->exists());
        $this->assertFalse($corta->ventas()->exists());
        $this->assertFalse($corta->ventasPorConfirmar()->exists());
    }

    public function test_oc_larga_repetida_en_dos_clientes_no_se_liga_a_otra_razon_social(): void
    {
        $a = Pedido::factory()->create(['numero_oc_cliente' => '4500111222']);
        $b = Pedido::factory()->create(['numero_oc_cliente' => '4500111222']);
        $this->enviar([$this->doc(['cliente' => 'OTRA RAZON SOCIAL', 'rfc' => null, 'comentario' => 'OC 4500111222'])])
            ->assertJson(['ligadas_a_pedido' => 0]);
        $this->assertFalse($a->ventas()->exists());
        $this->assertFalse($b->ventas()->exists());
    }

    public function test_el_texto_comun_de_las_oc_de_kappa_no_liga_pedidos_ajenos(): void
    {
        $kappa = Cliente::factory()->create(['nombre' => 'GRUPO KAPPA MOLINOS']);
        $p71 = Pedido::factory()->create(['cliente_id' => $kappa->id, 'numero_oc_cliente' => 'GE39934/00 ZONA INDUSTRIAL']);
        $p15 = Pedido::factory()->create(['cliente_id' => $kappa->id, 'numero_oc_cliente' => 'GEPR39866/00 ZONA INDUSTRIAL']);

        $this->enviar([$this->doc(['cliente' => 'GRUPO KAPPA MOLINOS', 'rfc' => null, 'comentario' => 'OC GEPR39866/00 ZONA INDUSTRIAL'])])
            ->assertJson(['ligadas_a_pedido' => 1]);
        $this->assertTrue($p15->ventas()->exists());
        $this->assertFalse($p71->ventas()->exists());
    }

    public function test_la_limpieza_quita_ligas_automaticas_equivocadas_y_respeta_las_manuales(): void
    {
        $kappa = Cliente::factory()->create(['nombre' => 'GRUPO KAPPA MOLINOS']);
        $p71 = Pedido::factory()->create(['cliente_id' => $kappa->id, 'numero_oc_cliente' => 'GE39934/00 ZONA INDUSTRIAL']);
        $p15 = Pedido::factory()->create(['cliente_id' => $kappa->id, 'numero_oc_cliente' => 'GEPR39866/00 ZONA INDUSTRIAL']);
        $v = Venta::create(['tipo' => 'factura', 'folio' => '5-5072', 'cliente_nombre' => 'GRUPO KAPPA MOLINOS', 'cliente_id' => $kappa->id,
            'fecha' => '2026-10-01', 'subtotal' => 100, 'iva' => 16, 'total' => 116, 'comentario_sicar' => 'OC GEPR39866/00 ZONA INDUSTRIAL']);
        $manual = Venta::create(['tipo' => 'factura', 'folio' => '5-9999', 'cliente_nombre' => 'X', 'fecha' => '2026-10-01', 'subtotal' => 1, 'iva' => 0, 'total' => 1]);
        $p71->ventas()->attach($v->id, ['user_id' => null, 'created_at' => now()]);      // la equivocada
        $p15->ventas()->attach($v->id, ['user_id' => null, 'created_at' => now()]);      // la buena
        $p71->ventas()->attach($manual->id, ['user_id' => User::factory()->create()->id, 'created_at' => now()]);   // a mano

        (require database_path('migrations/2026_11_02_000005_quitar_ligas_por_oc_equivocadas.php'))->up();

        $this->assertFalse($p71->ventas()->whereKey($v->id)->exists());
        $this->assertTrue($p15->ventas()->whereKey($v->id)->exists());
        $this->assertTrue($p71->ventas()->whereKey($manual->id)->exists());
    }

    public function test_saldo_cero_sin_fecha_de_pago(): void
    {
        // Vieja (antes del inicio de comisiones): se da por cobrada con la fecha del documento.
        $this->enviar([$this->doc(['sicar_id' => 'F:1', 'folio' => '5-1', 'fecha' => '2026-05-04', 'saldo' => 0])])->assertJson(['pagadas' => 1]);
        $this->assertSame('2026-05-04', Venta::where('folio', '5-1')->value('fecha_pago') ? Venta::where('folio', '5-1')->first()->fecha_pago->toDateString() : null);
        // Reciente: queda pendiente para que Cobranza pida la fecha.
        $this->enviar([$this->doc(['sicar_id' => 'F:2', 'folio' => '5-2', 'fecha' => '2026-09-29', 'saldo' => 0])])->assertJson(['pagadas' => 0]);
        $this->assertSame('pendiente', Venta::where('folio', '5-2')->value('estatus'));
    }

    public function test_estado(): void
    {
        $this->enviar([$this->doc()]);
        $this->withToken('secreto')->getJson('/api/sicar/ventas/estado')->assertOk()
            ->assertJson(['ok' => true, 'ultima_fecha_sicar' => '2026-09-29', 'pendientes' => 1]);
    }
}

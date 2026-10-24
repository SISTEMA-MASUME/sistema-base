<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Depositos;
use App\Livewire\Finanzas\Ventas;
use App\Models\Cliente;
use App\Models\ComplementoPago;
use App\Models\Cuenta;
use App\Models\DepositoCobro;
use App\Models\DepositoEvento;
use App\Models\User;
use App\Models\Venta;
use App\Support\CalculoNomina;
use App\Support\CobrosProvisionales;
use App\Support\ComplementosSicar;
use App\Support\Trazabilidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CobrosProvisionalesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Cliente $cliente;

    private Cuenta $cuenta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['rol' => 'admin', 'en_nomina' => true, 'comision_pct' => 100]);
        $this->cliente = Cliente::factory()->create(['nombre' => 'CLIENTE PRUEBA', 'rfc' => 'CPR120531CU2']);
        $this->cuenta = Cuenta::obtener('BBVA MASUME');
        $this->actingAs($this->admin);
        config(['services.sicar.token' => 'prueba-puente']);
    }

    private function factura(int $n, array $extra = []): Venta
    {
        return Venta::create($extra + ['tipo' => 'factura', 'folio' => '5-'.$n, 'fecha' => '2026-09-30',
            'cliente_id' => $this->cliente->id, 'cliente_nombre' => $this->cliente->nombre,
            'total' => 1000, 'subtotal' => 900, 'iva' => 100, 'costo' => 700,
            'estatus' => 'pendiente', 'uuid' => sprintf('00000000-0000-4000-8000-%012d', $n), 'sicar_id' => 'F:'.$n]);
    }

    private function datos(array $ventas, array $extra = []): array
    {
        return $extra + ['clave' => (string) Str::uuid(), 'cliente_id' => $this->cliente->id,
            'cuenta_id' => $this->cuenta->id, 'fecha' => '2026-10-02',
            'importe' => collect($ventas)->sum('total'), 'facturas' => collect($ventas)->pluck('id')->all()];
    }

    private function rep(array $ventas, array $extra = []): array
    {
        return $extra + ['sicar_id' => 'REP:100', 'uuid' => '10000000-0000-4000-8000-000000000100',
            'folio' => '1-100', 'rfc' => $this->cliente->rfc, 'cliente' => $this->cliente->nombre,
            'fecha_pago' => '2026-10-02', 'importe' => collect($ventas)->sum('total'), 'moneda' => 'MXN', 'cancelado' => false,
            'documentos' => collect($ventas)->map(fn ($v) => ['sicar_id' => $v->sicar_id, 'uuid' => $v->uuid, 'folio' => $v->folio,
                'importe_pagado' => $v->total, 'saldo_anterior' => $v->total, 'saldo_insoluto' => 0, 'moneda' => 'MXN'])->all()];
    }

    private function guardarRep(array $datos): ComplementoPago
    {
        return ComplementosSicar::guardar(ComplementosSicar::normalizar($datos));
    }

    private function enviarFacturas(array $ventas, array $reps = []): void
    {
        $docs = collect($ventas)->map(fn ($v) => ['sicar_id' => $v->sicar_id, 'tipo' => 'factura', 'folio' => $v->folio,
            'fecha' => '2026-09-30', 'cliente' => $this->cliente->nombre, 'rfc' => $this->cliente->rfc,
            'total' => $v->total, 'subtotal' => $v->subtotal, 'iva' => $v->iva, 'costo' => $v->costo,
            'uuid' => $v->uuid, 'metodo_pago' => 'PPD', 'saldo' => 0, 'pagada_en' => '2026-10-02',
            'pago_referencia' => 'REP 1-100'])->all();
        $this->withToken('prueba-puente')->postJson('/api/sicar/ventas', ['documentos' => $docs, 'complementos' => $reps])->assertOk();
    }

    public function test_acierto_concilia_sin_repetir_cobro_ni_comision(): void
    {
        $vs = [$this->factura(1), $this->factura(2)];
        $datos = $this->datos($vs);
        $dep = CobrosProvisionales::crear($datos, $this->admin);
        $this->assertSame($dep->id, CobrosProvisionales::crear($datos, $this->admin)->id);
        $n = CalculoNomina::semana(2026, 40);
        CalculoNomina::registrar($n);
        $antes = $n->fresh()->only(['bolsa', 'utilidad', 'estatus']);
        $rep = $this->guardarRep($this->rep($vs));
        CobrosProvisionales::confirmar($dep->id, $rep->id, 'El cliente confirmó estas facturas.', $this->admin);
        CobrosProvisionales::confirmar($dep->id, $rep->id, 'Repetición del mismo movimiento.', $this->admin);
        $this->enviarFacturas($vs, [$this->rep($vs)]);
        $this->assertSame('conciliado', $dep->fresh()->estatus);
        $this->assertSame(1, ComplementoPago::count());
        $this->assertSame(1, DepositoEvento::where('accion', 'conciliacion')->count());
        $this->assertSame($antes, $n->fresh()->only(['bolsa', 'utilidad', 'estatus']));
        $this->assertSame(0, CalculoNomina::cobros(2026, 41)->count());
        $this->assertSame(2000.0, (float) Venta::where('estatus', 'pagada')->sum('total'));
    }

    public function test_corrige_4_5_por_7_8_y_no_comisiona_4_5_cuando_se_cobren_despues(): void
    {
        $vs = collect(range(1, 8))->mapWithKeys(fn ($n) => [$n => $this->factura($n)]);
        $dep = CobrosProvisionales::crear($this->datos($vs->only([1, 2, 3, 4, 5])->all()), $this->admin);
        $n = CalculoNomina::semana(2026, 40);
        CalculoNomina::registrar($n);
        $bolsaAntes = $n->fresh()->bolsa;
        $confirmadas = $vs->only([1, 2, 3, 7, 8])->values()->all();
        $this->enviarFacturas($confirmadas, [$this->rep($confirmadas)]);
        $this->assertSame('pendiente', $vs[7]->fresh()->estatus, 'El puente no suma un segundo conjunto antes de conciliar.');
        $this->assertSame(5000.0, (float) Venta::where('estatus', 'pagada')->sum('total'));
        $rep = ComplementoPago::firstOrFail();
        $c = CobrosProvisionales::comparar($dep, $rep);
        $this->assertSame(['5-4', '5-5'], $c['quitar']->pluck('folio')->all());
        $this->assertSame(['5-7', '5-8'], $c['agregar']->pluck('folio')->all());
        CobrosProvisionales::confirmar($dep->id, $rep->id, 'El cliente confirmó 1,2,3,7,8.', $this->admin);
        foreach ([4, 5] as $i) {
            $this->assertSame('pendiente', $vs[$i]->fresh()->estatus);
            $this->assertSame($n->id, $vs[$i]->fresh()->nomina_id);
            $this->assertNull($vs[$i]->fresh()->deposito_cobro_id);
        }
        $this->assertSame($bolsaAntes, $n->fresh()->bolsa);
        $this->assertSame([$vs[7]->id, $vs[8]->id], CalculoNomina::cobros(2026, 41)->orderBy('id')->pluck('id')->all());
        $n2 = CalculoNomina::semana(2026, 41);
        CalculoNomina::registrar($n2);
        $this->enviarFacturas([$vs[4], $vs[5]]);
        $this->assertSame('pagada', $vs[4]->fresh()->estatus);
        $this->assertSame(0, CalculoNomina::cobros(2026, 42)->count());
        $this->assertSame($n->id, $vs[4]->fresh()->nomina_id);
        $this->assertSame($n2->id, $vs[7]->fresh()->nomina_id);
    }

    public function test_la_correccion_respeta_utilidades_distintas_y_no_traslada_nomina(): void
    {
        $a = $this->factura(1, ['costo' => 800]);
        $b = $this->factura(2, ['costo' => 400]);
        $dep = CobrosProvisionales::crear($this->datos([$a]), $this->admin);
        $n = CalculoNomina::semana(2026, 40);
        CalculoNomina::registrar($n);
        $rep = $this->guardarRep($this->rep([$b]));
        CobrosProvisionales::confirmar($dep->id, $rep->id, 'Se confirmó otra factura.', $this->admin);
        $this->assertSame('10.00', $n->fresh()->bolsa);
        $this->assertSame($n->id, $a->fresh()->nomina_id);
        $this->assertNull($b->fresh()->nomina_id);
        $this->assertSame('50.00', CalculoNomina::semana(2026, 41)->bolsa);
    }

    public function test_sin_comision_pagada_las_facturas_retiradas_no_entran_a_nomina(): void
    {
        $a = $this->factura(1);
        $b = $this->factura(2);
        $dep = CobrosProvisionales::crear($this->datos([$a]), $this->admin);
        $rep = $this->guardarRep($this->rep([$b]));
        CobrosProvisionales::confirmar($dep->id, $rep->id, 'Corrección antes del sábado.', $this->admin);
        $this->assertSame([$b->id], CalculoNomina::cobros(2026, 40)->pluck('id')->all());
    }

    public function test_rechaza_doble_asignacion_y_lotes_de_otro_cliente_o_importe(): void
    {
        $v = $this->factura(1);
        foreach ([$this->datos([$v], ['importe' => 999]), $this->datos([$v], ['cliente_id' => Cliente::factory()->create(['rfc' => 'OTR120531CU2'])->id])] as $d) {
            try {
                CobrosProvisionales::crear($d, $this->admin);
                $this->fail('Debió rechazar.');
            } catch (ValidationException) {
            }
        }
        $dep = CobrosProvisionales::crear($this->datos([$v]), $this->admin);
        try {
            CobrosProvisionales::crear($this->datos([$v]), $this->admin);
            $this->fail('Debió rechazar doble asignación.');
        } catch (ValidationException) {
        }
        $this->assertSame(1, DepositoCobro::count());
        $this->assertSame(1, $dep->facturas()->count());
    }

    public function test_rechaza_reutilizar_clave_con_datos_distintos(): void
    {
        $d = $this->datos([$this->factura(1)]);
        CobrosProvisionales::crear($d, $this->admin);
        $this->expectException(ValidationException::class);
        CobrosProvisionales::crear(array_replace($d, ['referencia' => 'otro depósito']), $this->admin);
    }

    public function test_rechaza_un_rep_asignado_a_otro_deposito(): void
    {
        $a = $this->factura(1);
        $b = $this->factura(2);
        $d1 = CobrosProvisionales::crear($this->datos([$a]), $this->admin);
        $d2 = CobrosProvisionales::crear($this->datos([$b]), $this->admin);
        $r = $this->guardarRep($this->rep([$a]));
        CobrosProvisionales::confirmar($d1->id, $r->id, 'Confirmado por el cliente.', $this->admin);
        $this->expectException(ValidationException::class);
        CobrosProvisionales::confirmar($d2->id, $r->id, 'No debe reutilizarse el REP.', $this->admin);
    }

    public function test_rechaza_parciales_moneda_distinta_y_uuid_que_no_identifica_factura(): void
    {
        $v = $this->factura(1);
        $dep = CobrosProvisionales::crear($this->datos([$v]), $this->admin);
        foreach (['parcial', 'moneda', 'uuid'] as $caso) {
            $d = $this->rep([$v], ['sicar_id' => 'REP:'.$caso, 'uuid' => (string) Str::uuid(), 'folio' => '1-'.$caso]);
            if ($caso === 'parcial') {
                $d['documentos'][0]['importe_pagado'] = 500;
                $d['documentos'][0]['saldo_insoluto'] = 500;
            }
            if ($caso === 'moneda') {
                $d['moneda'] = 'USD';
            }
            if ($caso === 'uuid') {
                $d['documentos'][0]['uuid'] = (string) Str::uuid();
            }
            $r = $this->guardarRep($d);
            try {
                CobrosProvisionales::confirmar($dep->id, $r->id, 'Intento que debe rechazarse.', $this->admin);
                $this->fail('Debió rechazar.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame('provisional', $dep->fresh()->estatus);
        $this->assertSame(0, DepositoEvento::where('accion', 'conciliacion')->count());
    }

    public function test_anular_conserva_comision_pagada_y_deja_bitacora(): void
    {
        $v = $this->factura(1);
        $dep = CobrosProvisionales::crear($this->datos([$v]), $this->admin);
        $n = CalculoNomina::semana(2026, 40);
        CalculoNomina::registrar($n);
        CobrosProvisionales::anular($dep->id, 'Seleccioné el depósito equivocado.', $this->admin);
        $this->assertSame('anulado', $dep->fresh()->estatus);
        $this->assertSame('pendiente', $v->fresh()->estatus);
        $this->assertSame($n->id, $v->fresh()->nomina_id);
        $this->assertSame(1, DepositoEvento::where('accion', 'anulacion')->count());
    }

    public function test_otro_deposito_no_cambia_asignacion_provisional(): void
    {
        $a = $this->factura(1);
        $b = $this->factura(2);
        $dep = CobrosProvisionales::crear($this->datos([$a]), $this->admin);
        $r = $this->guardarRep($this->rep([$b]));
        CobrosProvisionales::independiente($r->id, 'Es otro depósito recibido y verificado.', $this->admin);
        $this->assertSame('provisional', $dep->fresh()->estatus);
        $this->assertSame('pagada', $b->fresh()->estatus);
        $this->assertSame('independiente', $r->fresh()->estatus);
    }

    public function test_cancelacion_o_cambio_del_rep_avisa_sin_borrar_nomina(): void
    {
        $v = $this->factura(1);
        $dep = CobrosProvisionales::crear($this->datos([$v]), $this->admin);
        $n = CalculoNomina::semana(2026, 40);
        CalculoNomina::registrar($n);
        $r = $this->guardarRep($this->rep([$v]));
        CobrosProvisionales::confirmar($dep->id, $r->id, 'Cliente confirmó el pago.', $this->admin);
        $this->guardarRep($this->rep([$v], ['cancelado' => true]));
        $this->assertSame('revisar', $dep->fresh()->estatus);
        $this->assertTrue($r->fresh()->requiere_revision);
        $this->assertSame($n->id, $v->fresh()->nomina_id);
        $this->assertSame('pagada', $v->fresh()->estatus);
        $this->expectException(ValidationException::class);
        CobrosProvisionales::confirmar($dep->id, $r->id, 'No confirmar un cancelado.', $this->admin);
    }

    public function test_lote_invalido_de_complementos_no_modifica_ventas(): void
    {
        $v = $this->factura(1);
        $r = $this->rep([$v]);
        unset($r['documentos'][0]['saldo_insoluto']);
        $this->withToken('prueba-puente')->postJson('/api/sicar/ventas', ['documentos' => [], 'complementos' => [$r]])->assertUnprocessable();
        $this->assertSame(0, ComplementoPago::count());
        $this->assertSame('pendiente', $v->fresh()->estatus);
    }

    public function test_puente_viejo_no_suma_otro_grupo_y_cambio_de_importe_exige_revision(): void
    {
        $a = $this->factura(1);
        $b = $this->factura(2);
        $dep = CobrosProvisionales::crear($this->datos([$a]), $this->admin);
        $this->enviarFacturas([$b]);
        $this->assertSame('pendiente', $b->fresh()->estatus);
        $a->total = 1100;
        $this->enviarFacturas([$a]);
        $this->assertSame('revisar', $dep->fresh()->estatus);
    }

    public function test_edicion_y_trazabilidad_no_pueden_saltar_la_conciliacion(): void
    {
        $v = $this->factura(1);
        $destino = $this->factura(2);
        CobrosProvisionales::crear($this->datos([$v]), $this->admin);
        Livewire::test(Ventas::class)->set('seleccion', [$v->id])->call('regresarPendiente')->assertHasErrors('conciliacion');
        try {
            Trazabilidad::ligar(collect([$v]), $destino);
            $this->fail('Debió rechazar.');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame('pagada', $v->fresh()->estatus);
    }

    private function xml(): string
    {
        return '<?xml version="1.0"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:p="http://www.sat.gob.mx/Pagos20" xmlns:t="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0" TipoDeComprobante="P" Serie="1" Folio="100"><cfdi:Emisor Rfc="AAAA010101AA1"/><cfdi:Receptor Rfc="CPR120531CU2" Nombre="CLIENTE PRUEBA"/><cfdi:Complemento><p:Pagos Version="2.0"><p:Pago FechaPago="2026-10-02T14:10:55" Monto="1000.00" MonedaP="MXN"><p:DoctoRelacionado Serie="5" Folio="1" IdDocumento="00000000-0000-4000-8000-000000000001" ImpPagado="1000.00" ImpSaldoAnt="1000.00" ImpSaldoInsoluto="0.00" MonedaDR="MXN"/></p:Pago></p:Pagos><t:TimbreFiscalDigital UUID="10000000-0000-4000-8000-000000000100"/></cfdi:Complemento></cfdi:Comprobante>';
    }

    public function test_xml_y_puente_son_el_mismo_complemento_y_no_crean_diferencia_falsa(): void
    {
        $v = $this->factura(1);
        $r = ComplementosSicar::guardar(ComplementosSicar::leerXml($this->xml()), $this->admin->id);
        $this->assertSame('2026-10-02', $r->fecha_pago->toDateString());
        $this->assertSame(100000, CobrosProvisionales::centavos($r->importe));
        $this->guardarRep($this->rep([$v]));
        $this->assertSame(1, ComplementoPago::count());
        $this->assertFalse($r->fresh()->requiere_revision);
        $this->assertSame('REP:100', $r->fresh()->sicar_id);
    }

    public function test_xml_malformado_entidades_y_otro_emisor_se_rechazan(): void
    {
        foreach (['<mal>', '<!DOCTYPE foo [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.$this->xml(),
            str_replace('AAAA010101AA1', 'OTR120531CU2', $this->xml())] as $xml) {
            try {
                ComplementosSicar::leerXml($xml);
                $this->fail('Debió rechazar XML.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, ComplementoPago::count());
    }

    public function test_pantalla_registra_carga_xml_muestra_diferencias_y_confirma(): void
    {
        $a = $this->factura(2);
        $b = $this->factura(1);
        $c = Livewire::test(Depositos::class)->set('clienteId', (string) $this->cliente->id)
            ->set('cuentaId', (string) $this->cuenta->id)->set('fecha', '2026-10-02')->set('importe', '1000')
            ->set('seleccion', [$a->id])->call('guardar')->assertHasNoErrors()->assertSee('DEP-0001');
        $file = UploadedFile::fake()->createWithContent('pago.xml', $this->xml());
        $c->set('archivoXml', $file)->call('importarXml')->assertHasNoErrors()
            ->set('complementoId', (string) ComplementoPago::first()->id)->assertSee('Volverán a pendientes:')->assertSee('5-2')
            ->set('motivo', 'El cliente confirmó la factura correcta.')->call('confirmar')->assertHasNoErrors()->assertSee('conciliado');
        $this->assertSame('pendiente', $a->fresh()->estatus);
        $this->assertSame('pagada', $b->fresh()->estatus);
        $this->get('/finanzas/depositos')->assertOk();
    }

    public function test_complementos_habituales_no_aparecen_ni_como_pendientes_ni_como_historial(): void
    {
        $v = $this->factura(1, ['estatus' => 'pagada', 'fecha_pago' => '2026-10-02']);
        $rep = $this->guardarRep($this->rep([$v]));
        $antes = $v->fresh()->getAttributes();
        Livewire::test(Depositos::class)->assertSee('No tienes depósitos pendientes')
            ->assertDontSee('REP 1-100')->assertDontSee('Es otro depósito; confirmar cobro')
            ->assertDontSee('Consultar complementos de SICAR')->assertDontSee('Complementos sin vincular')
            ->assertViewHas('complementos', fn ($rs) => $rs->isEmpty());
        $this->assertSame($antes, $v->fresh()->getAttributes());
        $this->assertNull($rep->fresh()->deposito_id);
        $this->assertSame(1, ComplementoPago::count());
    }

    public function test_abrir_deposito_conserva_selector_y_alertas_sin_listas_generales(): void
    {
        $a = $this->factura(1);
        $b = $this->factura(2);
        $dep = CobrosProvisionales::crear($this->datos([$a]), $this->admin);
        $rep = $this->guardarRep($this->rep([$b]));
        $otro = $this->guardarRep($this->rep([$a], ['sicar_id' => 'REP:200',
            'uuid' => '10000000-0000-4000-8000-000000000200', 'folio' => '2-200',
            'rfc' => 'OTR120531CU2', 'cliente' => 'OTRO CLIENTE']));
        Livewire::test(Depositos::class)->assertSee('Diferencia detectada:')
            ->assertDontSee('Es otro depósito; confirmar cobro')->call('ver', $dep->id)
            ->assertSee('Complemento de este cliente')->assertDontSee('Complementos disponibles de este cliente')
            ->assertDontSee('Es otro depósito; confirmar cobro')->assertDontSee('REP 2-200')
            ->assertViewHas('complementos', fn ($rs) => $rs->pluck('id')->all() === [$rep->id])
            ->set('complementoId', (string) $rep->id)
            ->assertSee('Volverán a pendientes:')->assertSee('Se agregarán como cobradas:')
            ->assertSee('Es otro depósito; confirmar cobro')
            ->set('motivo', 'El cliente confirmó la factura correcta.')->call('confirmar')->assertHasNoErrors()
            ->assertDontSee('Es otro depósito; confirmar cobro')->assertDontSee('Consultar complementos de SICAR');
        $this->assertSame('conciliado', $dep->fresh()->estatus);
        $this->assertSame('pendiente', $a->fresh()->estatus);
        $this->assertSame('pagada', $b->fresh()->estatus);
        $this->assertNull($otro->fresh()->deposito_id);
    }

    public function test_otro_pago_del_cliente_se_confirma_solo_tras_seleccionarlo_sin_cerrar_el_provisional(): void
    {
        $a = $this->factura(1);
        $b = $this->factura(2, ['total' => 2000, 'subtotal' => 1800, 'iva' => 200]);
        $dep = CobrosProvisionales::crear($this->datos([$a]), $this->admin);
        $rep = $this->guardarRep($this->rep([$b]));
        Livewire::test(Depositos::class)->call('ver', $dep->id)
            ->assertDontSee('Es otro depósito; confirmar cobro')
            ->set('complementoId', (string) $rep->id)->assertSee('Es otro depósito; confirmar cobro')
            ->set('motivo', 'Este complemento es de otro depósito recibido.')
            ->call('independiente', $rep->id)->assertHasNoErrors();
        $this->assertSame('provisional', $dep->fresh()->estatus);
        $this->assertSame('independiente', $rep->fresh()->estatus);
        $this->assertSame('pagada', $b->fresh()->estatus);
        $this->assertNull($b->fresh()->deposito_cobro_id);
    }

    public function test_permiso_revocado_y_usuario_sin_permiso_no_pueden_operar(): void
    {
        $u = User::factory()->create(['rol' => 'vendedor', 'permisos' => ['ventas']]);
        $v = $this->factura(1);
        $this->actingAs($u)->get('/finanzas/depositos')->assertOk();
        $u->update(['permisos' => []]);
        $this->get('/finanzas/depositos')->assertForbidden();
        $this->expectException(HttpException::class);
        CobrosProvisionales::crear($this->datos([$v]), $u);
    }

    public function test_falla_bitacora_revierte_deposito_y_facturas(): void
    {
        $v = $this->factura(1);
        DepositoEvento::creating(fn () => throw new \RuntimeException('Falla deliberada'));
        try {
            CobrosProvisionales::crear($this->datos([$v]), $this->admin);
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DepositoCobro::count());
        $this->assertSame('pendiente', $v->fresh()->estatus);
        $this->assertNull($v->fresh()->deposito_cobro_id);
        DepositoEvento::flushEventListeners();
    }

    public function test_incidente_pausa_comisiones_pendientes_y_se_resuelve_con_rep_vigente(): void
    {
        $a = $this->factura(1);
        $b = $this->factura(2);
        $dep = CobrosProvisionales::crear($this->datos([$a]), $this->admin);
        $r = $this->guardarRep($this->rep([$a]));
        CobrosProvisionales::confirmar($dep->id, $r->id, 'Primera confirmación del cliente.', $this->admin);
        $this->guardarRep($this->rep([$a], ['cancelado' => true]));
        $this->assertSame(0, CalculoNomina::cobros(2026, 40)->count());
        $nuevo = $this->guardarRep($this->rep([$b], ['sicar_id' => 'REP:200', 'folio' => '1-200', 'uuid' => (string) Str::uuid()]));
        CobrosProvisionales::confirmar($dep->id, $nuevo->id, 'El REP vigente reemplaza al cancelado.', $this->admin);
        $this->assertSame('conciliado', $dep->fresh()->estatus);
        $this->assertSame([$b->id], CalculoNomina::cobros(2026, 40)->pluck('id')->all());
        $this->assertSame($dep->id, $r->fresh()->deposito_id, 'El complemento anterior conserva su vínculo histórico.');
    }

    public function test_cambio_y_revision_manual_no_borran_nomina_ni_permiten_reusar_rep(): void
    {
        $a = $this->factura(1);
        $b = $this->factura(2);
        $dep = CobrosProvisionales::crear($this->datos([$a]), $this->admin);
        $n = CalculoNomina::semana(2026, 40);
        CalculoNomina::registrar($n);
        $r = $this->guardarRep($this->rep([$a]));
        CobrosProvisionales::confirmar($dep->id, $r->id, 'Confirmación inicial equivocada.', $this->admin);
        CobrosProvisionales::revisar($dep->id, 'El cliente aclaró la asignación.', $this->admin);
        $this->guardarRep($this->rep([$b]));
        $this->assertTrue($r->fresh()->requiere_revision);
        CobrosProvisionales::confirmar($dep->id, $r->id, 'Revisé el cambio del complemento.', $this->admin);
        $this->assertFalse($r->fresh()->requiere_revision);
        $this->assertSame($n->id, $a->fresh()->nomina_id);
        $this->assertNull($b->fresh()->nomina_id);
    }

    public function test_saldo_igual_no_vincula_solo_dos_depositos_y_muestra_diferencia(): void
    {
        $a = $this->factura(1);
        $b = $this->factura(2);
        $c = $this->factura(3);
        CobrosProvisionales::crear($this->datos([$a]), $this->admin);
        CobrosProvisionales::crear($this->datos([$b]), $this->admin);
        $this->guardarRep($this->rep([$c]));
        Livewire::test(Depositos::class)->assertSee('Diferencia detectada:')->assertSee('5-3');
        $this->assertSame(2, DepositoCobro::where('estatus', 'provisional')->count());
        $this->assertNull(ComplementoPago::first()->deposito_id);
    }

    public function test_xml_anterior_no_reactiva_complemento_cancelado(): void
    {
        $v = $this->factura(1);
        $r = $this->guardarRep($this->rep([$v], ['cancelado' => true]));
        try {
            ComplementosSicar::guardar(ComplementosSicar::leerXml($this->xml()), $this->admin->id);
            $this->fail('Debió rechazar.');
        } catch (ValidationException) {
        }
        $this->assertTrue($r->fresh()->cancelado);
    }

    public function test_independiente_cancelado_pausa_comision_y_no_puede_aplicarse_a_otro_deposito(): void
    {
        $a = $this->factura(1);
        $b = $this->factura(2);
        $dep = CobrosProvisionales::crear($this->datos([$a]), $this->admin);
        $r = $this->guardarRep($this->rep([$b]));
        CobrosProvisionales::independiente($r->id, 'Comprobé que se trata de otro depósito.', $this->admin);
        try {
            CobrosProvisionales::confirmar($dep->id, $r->id, 'No debe cobrar el mismo REP otra vez.', $this->admin);
            $this->fail('Debió rechazar.');
        } catch (ValidationException) {
        }
        $this->guardarRep($this->rep([$b], ['cancelado' => true]));
        $this->assertSame([$a->id], CalculoNomina::cobros(2026, 40)->pluck('id')->all());
    }

    public function test_falla_conciliacion_revierte_reasignacion_y_bitacora(): void
    {
        $a = $this->factura(1);
        $b = $this->factura(2);
        $dep = CobrosProvisionales::crear($this->datos([$a]), $this->admin);
        $r = $this->guardarRep($this->rep([$b]));
        DepositoEvento::creating(fn () => throw new \RuntimeException('Falla deliberada al confirmar'));
        try {
            CobrosProvisionales::confirmar($dep->id, $r->id, 'Debe revertir todo ante un fallo.', $this->admin);
            $this->fail('Debió fallar.');
        } catch (\RuntimeException) {
        }
        DepositoEvento::flushEventListeners();
        $this->assertSame('pagada', $a->fresh()->estatus);
        $this->assertSame('pendiente', $b->fresh()->estatus);
        $this->assertSame('provisional', $dep->fresh()->estatus);
        $this->assertNull($r->fresh()->deposito_id);
        $this->assertSame(0, DepositoEvento::where('accion', 'conciliacion')->count());
    }

    public function test_reabrir_nomina_corregida_no_borra_comision_adelantada(): void
    {
        $a = $this->factura(1);
        $b = $this->factura(2);
        $dep = CobrosProvisionales::crear($this->datos([$a]), $this->admin);
        $n = CalculoNomina::semana(2026, 40);
        CalculoNomina::registrar($n);
        $r = $this->guardarRep($this->rep([$b]));
        CobrosProvisionales::confirmar($dep->id, $r->id, 'El cliente corrigió qué factura pagó.', $this->admin);
        try {
            CalculoNomina::reabrir($n);
            $this->fail('No debe borrar una comisión pagada por adelantado.');
        } catch (ValidationException) {
        }
        $this->assertSame($n->id, $a->fresh()->nomina_id);
        $this->assertSame('pagada', $n->fresh()->estatus);
    }
}

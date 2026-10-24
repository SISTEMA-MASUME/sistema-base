<?php

namespace Tests\Feature;

use App\Livewire\Clientes\Index as ClientesIndex;
use App\Livewire\Clientes\Plazas;
use App\Livewire\Facturacion\Index as Facturacion;
use App\Livewire\Pedidos\ReglasCliente;
use App\Livewire\Pedidos\Ver;
use App\Livewire\Tablero\Index as Tablero;
use App\Mail\PedidoDocumentosMail;
use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Contacto;
use App\Models\Cotizacion;
use App\Models\DocumentoEnvio;
use App\Models\Pedido;
use App\Models\PedidoArchivo;
use App\Models\PedidoPartida;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** T16: reglas de facturación y entrega por cliente. */
class ReglasClienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['masume.cuentas_correo.ventas.usuario' => 'ventas@ejemplo.com']);
        Storage::fake('local');
    }

    private function entrar(string $rol = 'vendedor'): User
    {
        $u = User::factory()->create(['rol' => $rol, 'name' => 'Alberto', 'cuenta_correo' => $rol === 'campo' ? null : 'ventas']);
        $this->actingAs($u);

        return $u;
    }

    private function pedido(Cliente $cliente, array $datos = []): Pedido
    {
        $p = Pedido::factory()->create(['cliente_id' => $cliente->id] + $datos);
        PedidoPartida::create(['pedido_id' => $p->id, 'orden' => 1, 'descripcion' => 'CABLE', 'unidad' => 'PZA', 'cantidad' => 1, 'costo' => 1, 'precio' => 2]);

        return $p->fresh();
    }

    private function archivo(Pedido $p, string $tipo, string $nombre): PedidoArchivo
    {
        $ruta = 'pedidos/'.$p->id.'/'.$tipo.'-'.uniqid().'.bin';
        Storage::disk('local')->put($ruta, 'contenido');

        return PedidoArchivo::create(['pedido_id' => $p->id, 'tipo' => $tipo, 'disco' => 'local', 'ruta' => $ruta, 'nombre_original' => $nombre,
            'mime' => $tipo === 'evidencia' ? 'image/jpeg' : ($tipo === 'factura_xml' ? 'application/xml' : 'application/pdf'), 'tamano' => 9, 'user_id' => auth()->id()]);
    }

    private function textos(Pedido $p): array
    {
        return array_column($p->fresh()->etiquetas(), 'texto');
    }

    public function test_portal_de_facturas_aunque_la_oc_llegue_antes(): void
    {
        $this->entrar();
        $centro = Cliente::factory()->create(['nombre' => 'PLAZA CENTRO', 'sube_factura_portal' => true, 'portal_url' => 'https://portal.centro.mx']);
        $p = $this->pedido($centro, ['numero_oc_cliente' => '10339']);

        // Sin factura todavía: no hay nada que subir.
        $this->assertNotContains('Subir factura al portal', $this->textos($p));

        // Con partida facturada (o PDF en el expediente) aparece la etiqueta.
        $p->partidas()->update(['facturada' => true]);
        $this->assertContains('Subir factura al portal', $this->textos($p));
        $this->assertNotContains('Esperando OC del portal', $this->textos($p));

        $p->partidas()->update(['facturada' => false]);
        $this->archivo($p, 'factura_pdf', 'F-100.pdf');
        $this->assertContains('Subir factura al portal', $this->textos($p));
        $this->get(route('pedidos.index'))->assertSee('Subir factura al portal');

        // "Ya subí la factura" desde el pedido.
        Livewire::test(Ver::class, ['pedido' => $p])->assertSee('Abrir portal de PLAZA CENTRO')->call('marcarFacturaPortal');
        $this->assertNotContains('Subir factura al portal', $this->textos($p));
    }

    public function test_pantalla_pendientes_de_facturacion(): void
    {
        $this->entrar();
        $centro = Cliente::factory()->create(['nombre' => 'PLAZA CENTRO', 'sube_factura_portal' => true, 'portal_url' => 'https://portal.centro.mx']);
        $plazaNorte = Cliente::factory()->create(['nombre' => 'PLAZA NORTE', 'oc_despues_de_facturar' => true, 'sube_factura_portal' => true]);
        $normal = Cliente::factory()->create(['nombre' => 'FANOSA']);

        $conFactura = $this->pedido($centro, ['numero_oc_cliente' => 'L-1']);
        $this->archivo($conFactura, 'factura_pdf', 'F.pdf');
        $sinFactura = $this->pedido($centro, ['numero_oc_cliente' => 'L-2']);
        $portal = $this->pedido($plazaNorte, ['numero_oc_cliente' => null]);
        $sinOc = $this->pedido($normal, ['numero_oc_cliente' => null]);

        $ids = fn ($t) => $t->viewData('pedidos')->pluck('id')->all();
        $t = Livewire::test(Facturacion::class);
        $this->assertEqualsCanonicalizing([$conFactura->id, $portal->id], $ids($t));
        $t->assertSee('Abrir portal')->assertSee('Ya la subí');

        $t->call('marcarSubida', $conFactura->id)->assertSee('quedó como subida al portal');
        $this->assertNotNull($conFactura->fresh()->factura_portal_at);
        $this->assertSame([$portal->id], $ids($t));
        $this->assertSame([$conFactura->id], $ids($t->call('elegir', 'subidas')));
        $t->assertSee('Subida por Alberto');

        $t->call('elegir', 'oc')->assertSee('Esperando OC del portal')->assertSee('Esperando OC del cliente');
        $this->assertEqualsCanonicalizing([$portal->id, $sinOc->id], $ids($t));
        $this->assertNotContains($sinFactura->id, $ids($t));

        // Rol campo no entra.
        $this->entrar('campo');
        $this->get(route('facturacion.index'))->assertForbidden();
    }

    public function test_acme_envia_evidencia_respondiendo_el_correo_de_la_oc(): void
    {
        Mail::fake();
        $this->entrar();
        $acme = Cliente::factory()->create(['nombre' => 'ACME MEXICO', 'envia_evidencia_por_correo' => true]);
        $p = $this->pedido($acme, ['numero_oc_cliente' => '2000145168']);

        $t = Livewire::test(ReglasCliente::class, ['pedidoId' => $p->id])
            ->call('preparar', 'evidencia')->assertSee('Primero sube las fotos');

        $this->archivo($p, 'evidencia', 'entrega1.jpg');
        $this->archivo($p, 'evidencia', 'entrega2.jpg');
        $this->archivo($p, 'factura_pdf', 'F-200.pdf');
        $this->assertContains('Enviar evidencia', $this->textos($p));

        $t->set('remitente', 'no-es-correo')->call('guardarCorreoOc')->assertHasErrors('remitente')
            ->set('remitente', 'compras@acme.com')->set('asuntoOc', 'OC 2000145168 MASUME')->call('guardarCorreoOc')->assertHasNoErrors()
            ->call('preparar', 'evidencia')
            ->assertSet('para', 'compras@acme.com')
            ->assertSet('asunto', 'RE: OC 2000145168 MASUME')
            ->call('enviar')->assertHasNoErrors()->assertSee('Correo enviado a compras@acme.com');

        Mail::assertSent(PedidoDocumentosMail::class, fn ($m) => $m->hasTo('compras@acme.com') && $m->asunto === 'RE: OC 2000145168 MASUME'
            && count($m->archivos) === 3 && $m->mailer === 'smtp_ventas');
        $this->assertNotNull($p->fresh()->evidencia_enviada_at);
        $this->assertNotContains('Enviar evidencia', $this->textos($p));
        $this->assertSame('correo', DocumentoEnvio::where('documento_type', Pedido::class)->firstOrFail()->canal);

        // Un asunto que ya trae "RE:" no se duplica.
        $p->update(['oc_correo_asunto' => 'Re: OC 5', 'evidencia_enviada_at' => null]);
        Livewire::test(ReglasCliente::class, ['pedidoId' => $p->id])->call('preparar', 'evidencia')->assertSet('asunto', 'Re: OC 5');

        // En la tarjeta aparece el recuadro; campo no puede usarlo.
        Livewire::test(Ver::class, ['pedido' => $p])->assertSee('Enviar evidencia de entrega');
        $this->entrar('campo');
        Livewire::test(Ver::class, ['pedido' => $p])->assertDontSee('Enviar evidencia de entrega');
        Livewire::test(ReglasCliente::class, ['pedidoId' => $p->id])->assertForbidden();
    }

    public function test_plazas_y_envio_de_factura_a_la_plaza(): void
    {
        Mail::fake();
        $this->entrar();
        $parques = Cliente::factory()->create(['nombre' => 'PARQUES MANTENIMIENTO']);

        Livewire::test(Plazas::class, ['clienteId' => $parques->id])
            ->call('nueva')->set('nombre', 'lumex mérida')->set('ciudad', 'Mérida')->set('estado', 'Yucatán')
            ->set('correos', 'facturas@parques.mx; no-valido')->call('guardar')->assertHasErrors('correos')
            ->set('correos', 'facturas@parques.mx; cxp@parques.mx')->call('guardar')->assertHasNoErrors()
            ->assertSee('LUMEX MÉRIDA · Mérida, Yucatán');
        $plaza = ClienteSucursal::firstOrFail();
        $this->assertSame('facturas@parques.mx, cxp@parques.mx', $plaza->correos_facturacion);

        $p = $this->pedido($parques, ['numero_oc_cliente' => '555']);
        $t = Livewire::test(ReglasCliente::class, ['pedidoId' => $p->id])
            ->set('sucursalId', (string) $plaza->id)->call('guardarSucursal');
        $this->assertSame($plaza->id, $p->fresh()->sucursal_id);
        $this->get(route('pedidos.index'))->assertSee('📍 Mérida');

        $t->call('preparar', 'factura')->assertSee('Primero sube la factura');
        $this->archivo($p, 'factura_pdf', 'F-9.pdf');
        $this->archivo($p, 'factura_xml', 'F-9.xml');
        $this->assertContains('Enviar factura a la plaza', $this->textos($p));

        $t->call('preparar', 'factura')->assertSet('para', 'facturas@parques.mx, cxp@parques.mx')->call('enviar')->assertHasNoErrors();
        Mail::assertSent(PedidoDocumentosMail::class, fn ($m) => $m->hasTo('facturas@parques.mx') && $m->hasTo('cxp@parques.mx') && count($m->archivos) === 2);
        $this->assertNotNull($p->fresh()->factura_sucursal_enviada_at);
        $this->assertNotContains('Enviar factura a la plaza', $this->textos($p));

        // Una plaza de otro cliente no se puede asignar.
        $otra = ClienteSucursal::create(['cliente_id' => Cliente::factory()->create()->id, 'nombre' => 'AJENA']);
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(ReglasCliente::class, ['pedidoId' => $p->id])->set('sucursalId', (string) $otra->id)->call('guardarSucursal');
    }

    public function test_unir_clientes_como_plazas_con_vista_previa(): void
    {
        $this->entrar('admin');
        $parques = Cliente::factory()->create(['nombre' => 'PARQUES MANTENIMIENTO']);
        $merida = Cliente::factory()->create(['nombre' => 'LUMEX MERIDA', 'correo' => 'merida@lumex.mx']);
        $tuxtla = Cliente::factory()->create(['nombre' => 'LUMEX TUXTLA']);
        Cotizacion::factory()->count(2)->create(['cliente_id' => $merida->id]);
        $pm = $this->pedido($merida, ['numero_oc_cliente' => 'M-1']);
        Contacto::factory()->create(['cliente_id' => $tuxtla->id]);

        $t = Livewire::test(Plazas::class, ['clienteId' => $parques->id])
            ->set('buscarUnir', 'lumex')->assertSee('LUMEX MERIDA')->assertSee('LUMEX TUXTLA')
            ->set('unir', [(string) $merida->id, (string) $tuxtla->id])
            ->call('verVistaPrevia')
            ->assertSee('2 cotizaciones, 1 pedidos y 0 contactos se mueven');
        // La vista previa no cambia nada.
        $this->assertSame($merida->id, $pm->fresh()->cliente_id);

        $t->call('confirmarUnion')->assertSee('2 clientes quedaron como plazas');
        $pm->refresh();
        $this->assertSame($parques->id, $pm->cliente_id);
        $this->assertSame('LUMEX MERIDA', $pm->sucursal->nombre);
        $this->assertSame('merida@lumex.mx', $pm->sucursal->correos_facturacion);
        $this->assertSame(2, Cotizacion::where('cliente_id', $parques->id)->count());
        $this->assertSame(1, Contacto::where('cliente_id', $parques->id)->count());
        $this->assertSoftDeleted($merida);
        $this->assertSame(2, $parques->sucursales()->count());

        // Solo el admin puede unir.
        $this->entrar('vendedor');
        Livewire::test(Plazas::class, ['clienteId' => $parques->id])->set('unir', [(string) $tuxtla->id])->call('confirmarUnion')->assertForbidden();
    }

    public function test_unir_bloquea_oc_repetida(): void
    {
        $this->entrar('admin');
        $parques = Cliente::factory()->create(['nombre' => 'PARQUES']);
        $merida = Cliente::factory()->create(['nombre' => 'LUMEX MERIDA']);
        $this->pedido($parques, ['numero_oc_cliente' => '777']);
        $this->pedido($merida, ['numero_oc_cliente' => 'OC 777']);

        Livewire::test(Plazas::class, ['clienteId' => $parques->id])
            ->set('unir', [(string) $merida->id])->call('verVistaPrevia')
            ->assertSee('No se puede unir mientras haya OC repetidas')
            ->call('confirmarUnion')->assertStatus(422);
        $this->assertNotSoftDeleted($merida);
    }

    public function test_banderas_en_la_captura_del_cliente(): void
    {
        $this->entrar();
        $c = Cliente::factory()->create(['nombre' => 'GRUPO KAPPA']);
        Livewire::test(ClientesIndex::class)->call('editar', $c->id)
            ->set('sube_factura_portal', true)->set('envia_evidencia_por_correo', true)->call('guardar')->assertHasNoErrors();
        $c->refresh();
        $this->assertTrue($c->sube_factura_portal);
        $this->assertTrue($c->envia_evidencia_por_correo);

        // Generar OC después de facturar implica subir la factura al portal.
        $d = Cliente::factory()->create(['nombre' => 'CAMPO VERDE']);
        Livewire::test(ClientesIndex::class)->call('editar', $d->id)->set('oc_despues_de_facturar', true)->call('guardar');
        $this->assertTrue($d->fresh()->sube_factura_portal);
    }

    public function test_tablero_sin_n_mas_uno_con_reglas(): void
    {
        $this->entrar();
        $c = Cliente::factory()->create(['sube_factura_portal' => true, 'envia_evidencia_por_correo' => true]);
        $plaza = ClienteSucursal::create(['cliente_id' => $c->id, 'nombre' => 'PLAZA']);
        foreach (range(1, 20) as $i) {
            $this->pedido($c, ['sucursal_id' => $plaza->id]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(Tablero::class);
        $this->assertLessThan(15, count(DB::getQueryLog()));
    }
}

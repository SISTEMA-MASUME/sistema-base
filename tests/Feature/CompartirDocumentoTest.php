<?php

namespace Tests\Feature;

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoOrdenProveedor;
use App\Livewire\Compartir;
use App\Livewire\OrdenesProveedor\Ver as VerOrden;
use App\Mail\OrdenProveedorMail;
use App\Models\Cliente;
use App\Models\Contacto;
use App\Models\Cotizacion;
use App\Models\CotizacionCambio;
use App\Models\CotizacionPartida;
use App\Models\DocumentoEnvio;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\CompartirDocumento;
use App\Support\Folios;
use App\Support\Telefono;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** T11: compartir cotizaciones y OC a proveedores por WhatsApp, correo y descarga, con registro. */
class CompartirDocumentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['masume.cuentas_correo.ventas.usuario' => 'ventas@ejemplo.com']);
    }

    private function entrar(string $rol = 'vendedor', ?string $cuenta = 'ventas'): User
    {
        $u = User::factory()->create(['rol' => $rol, 'name' => 'Rosa Prueba', 'cuenta_correo' => $cuenta]);
        $this->actingAs($u);

        return $u;
    }

    private function orden(EstadoOrdenProveedor $estado = EstadoOrdenProveedor::Emitida, array $datos = []): OrdenProveedor
    {
        $pedido = Pedido::factory()->create();
        $prov = Proveedor::firstWhere('nombre', 'ACME') ?? Proveedor::factory()->create(['nombre' => 'ACME', 'contacto' => 'Luis', 'telefono' => '33 1234 5678', 'correo' => 'ventas@acme.mx']);
        $orden = OrdenProveedor::create([
            'folio' => Folios::siguiente('oc_proveedor'), 'pedido_id' => $pedido->id, 'proveedor_id' => $prov->id,
            'user_id' => auth()->id(), 'metodo_pago' => 'TARJETA', 'estado' => $estado, 'total_costo' => 150,
        ] + $datos);
        PedidoPartida::create([
            'pedido_id' => $pedido->id, 'orden_proveedor_id' => $orden->id, 'orden' => 1, 'descripcion' => 'LAMPARA',
            'unidad' => 'PZA', 'cantidad' => 3, 'costo' => 50, 'precio' => 987.65,
        ]);

        return $orden->fresh();
    }

    private function cotizacion(string $estado = 'borrador'): Cotizacion
    {
        $cliente = Cliente::factory()->create(['nombre' => 'FANOSA']);
        $contacto = Contacto::factory()->create(['cliente_id' => $cliente->id, 'nombre' => 'Ana', 'telefono' => '+52 1 (33) 9876-5432']);
        $cot = Cotizacion::factory()->create(['cliente_id' => $cliente->id, 'contacto_id' => $contacto->id, 'estado' => $estado]);
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'precio' => 200, 'importe' => 200]);
        $cot->recalcularTotales();

        return $cot->fresh();
    }

    public function test_normaliza_telefonos_de_mexico(): void
    {
        $this->assertSame('523312345678', Telefono::whatsapp('33 1234 5678'));
        $this->assertSame('523312345678', Telefono::whatsapp('(33) 1234-5678'));
        $this->assertSame('523312345678', Telefono::whatsapp('+52 33 1234 5678'));
        $this->assertSame('523312345678', Telefono::whatsapp('+52 1 33 1234 5678'));
        $this->assertSame('523312345678', Telefono::whatsapp('0052 33 1234 5678'));
        $this->assertSame('523312345678', Telefono::whatsapp('044 33 1234 5678'));
        $this->assertNull(Telefono::whatsapp('1234'));
        $this->assertNull(Telefono::whatsapp(null));
        $this->assertNull(Telefono::whatsapp(''));
    }

    public function test_enlace_firmado_valido_expirado_y_alterado(): void
    {
        $u = $this->entrar();
        $orden = $this->orden();
        $enlace = CompartirDocumento::enlace($orden);
        auth()->logout();

        // Válido y sin sesión: sirve el PDF y registra la apertura (sin cambiar el estado).
        $this->get($enlace)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $registro = DocumentoEnvio::where('canal', 'enlace')->firstOrFail();
        $this->assertSame($u->id, $registro->user_id);
        $this->assertSame(EstadoOrdenProveedor::Emitida, $orden->fresh()->estado);

        // La vista previa de WhatsApp no cuenta como descarga.
        $this->withHeader('User-Agent', 'WhatsApp/2.23.20.0 A')->get($enlace)->assertOk();
        $this->assertSame(1, DocumentoEnvio::where('canal', 'enlace')->count());

        // Alterado: otro id u otro usuario con la misma firma.
        $this->get(str_replace('/orden_proveedor/'.$orden->id, '/orden_proveedor/'.($orden->id + 1), $enlace))->assertForbidden();
        $this->get(str_replace('u='.$u->id, 'u=999', $enlace))->assertForbidden();
        $this->get(route('documentos.publico', ['tipo' => 'orden_proveedor', 'id' => $orden->id]))->assertForbidden();

        // Expirado (más de 30 días).
        $this->travel(CompartirDocumento::DIAS_ENLACE + 1)->days();
        $this->get($enlace)->assertForbidden();
    }

    public function test_whatsapp_y_descarga_registran_y_pasan_oc_a_enviada(): void
    {
        $u = $this->entrar();
        $orden = $this->orden(EstadoOrdenProveedor::Emitida, ['requiere_reenvio' => true]);

        $t = Livewire::test(Compartir::class, ['tipo' => 'orden_proveedor', 'documentoId' => $orden->id])
            ->assertSee('Compartir orden de compra')
            ->assertSee("telefono: '523312345678'", false)   // número del proveedor ya normalizado
            ->assertSee('documentos\/orden_proveedor\/'.$orden->id.'?expires=', false)
            ->assertDontSee('987.65')                        // la OC nunca lleva precio de venta
            // Se manda el PDF (mucha gente no puede abrir links); el enlace queda como opción aparte.
            ->assertSee('Descargar imagen y abrir WhatsApp')->assertSee('Mandar enlace en lugar del PDF');
        $this->assertStringNotContainsString('documentos/', $t->viewData('textoWhatsapp'));
        $this->assertStringContainsString('te envío la orden de compra', $t->viewData('textoWhatsapp'));
        // La OC va por WhatsApp como imagen (PNG), no como PDF.
        $this->assertStringContainsString('/ordenes-proveedor/'.$orden->id.'/imagen', $t->viewData('urlPdf'));
        $t->assertSee('Descargar imagen y abrir WhatsApp');
        // Referencia en el texto (no en el PDF): OC del cliente · CLIENTE - PROVEEDOR.
        $orden->load('pedido.cliente', 'proveedor');
        $this->assertStringContainsString('Referencia: Pedido P-'.$orden->pedido->folio.' · '.($orden->pedido->numero_oc_cliente ? 'OC '.$orden->pedido->numero_oc_cliente : 'Sin OC del cliente')
            .' · '.$orden->pedido->cliente->nombre.' - '.$orden->proveedor->nombre, $t->viewData('textoWhatsapp'));

        $t->call('registrarWhatsapp', '', 'descargado');
        $this->assertSame('Contacto elegido en WhatsApp · archivo descargado para adjuntar', DocumentoEnvio::latest('id')->value('destinatario'));
        $t->assertSee('Copiar imagen y abrir WhatsApp')->call('registrarWhatsapp', '', 'copiada');
        $this->assertSame('Contacto elegido en WhatsApp · imagen copiada para pegar', DocumentoEnvio::latest('id')->value('destinatario'));
        DocumentoEnvio::query()->delete();

        $t->call('registrarWhatsapp', '33 1234 5678', 'enlace')->assertDispatched('envio-registrado');
        $orden->refresh();
        $this->assertSame(EstadoOrdenProveedor::Enviada, $orden->estado);
        $this->assertFalse($orden->requiere_reenvio);

        $t->call('registrarDescarga');
        $envios = DocumentoEnvio::orderBy('id')->get();
        $this->assertSame(['whatsapp', 'descarga'], $envios->pluck('canal')->all());
        $this->assertSame('+523312345678 · enlace', $envios[0]->destinatario);
        $this->assertSame($u->id, $envios[1]->user_id);
        $t->assertSee('Historial de envíos')->assertSee('Compartido por WhatsApp')->assertSee('Descarga');

        // Una orden recibida no retrocede a enviada.
        $recibida = $this->orden(EstadoOrdenProveedor::Recibida);
        Livewire::test(Compartir::class, ['tipo' => 'orden_proveedor', 'documentoId' => $recibida->id])->call('registrarDescarga');
        $this->assertSame(EstadoOrdenProveedor::Recibida, $recibida->fresh()->estado);
    }

    public function test_correo_de_oc_a_proveedor_con_pdf_adjunto(): void
    {
        Mail::fake();
        $this->entrar();
        $orden = $this->orden();

        Livewire::test(Compartir::class, ['tipo' => 'orden_proveedor', 'documentoId' => $orden->id])
            ->call('abrirCorreo')
            ->assertSet('para', 'ventas@acme.mx')
            ->assertSet('cc', 'ventas@ejemplo.com')
            ->set('para', 'no-es-correo')->call('enviarCorreo')->assertHasErrors('para')
            ->set('para', 'ventas@acme.mx')->call('enviarCorreo')->assertHasNoErrors()
            ->assertSee('Correo enviado a ventas@acme.mx');

        Mail::assertSent(OrdenProveedorMail::class, fn (OrdenProveedorMail $m) => $m->hasTo('ventas@acme.mx')
            && $m->mailer === 'smtp_ventas' && count($m->attachments()) === 1);
        $this->assertSame(EstadoOrdenProveedor::Enviada, $orden->fresh()->estado);
        $this->assertSame('correo', DocumentoEnvio::firstOrFail()->canal);
    }

    public function test_cotizacion_borrador_pasa_a_enviada_sin_abrir_revision(): void
    {
        $this->entrar();
        $cot = $this->cotizacion();

        Livewire::test(Compartir::class, ['tipo' => 'cotizacion', 'documentoId' => $cot->id])
            ->assertSee("telefono: '523398765432'", false)
            ->assertSee('Hola Ana')
            ->call('registrarWhatsapp', '', 'archivo');

        $cot->refresh();
        $this->assertSame(EstadoCotizacion::Enviada, $cot->estado);
        $this->assertNotNull($cot->enviada_at);
        $this->assertSame(0, (int) $cot->revision);
        $this->assertSame(0, CotizacionCambio::count());
        $this->assertSame('Contacto elegido en WhatsApp · archivo adjunto', DocumentoEnvio::firstOrFail()->destinatario);

        // El editor muestra el componente con los tres botones.
        $this->get(route('cotizaciones.editar', $cot))->assertOk()
            ->assertSee('Compartir cotización')->assertSee('WhatsApp')->assertSee('Descargar PDF')->assertSee('Enviar por correo');
    }

    public function test_campo_comparte_oc_pero_no_cotizaciones(): void
    {
        $this->entrar('campo', null);
        $orden = $this->orden();
        $cot = $this->cotizacion();

        Livewire::test(Compartir::class, ['tipo' => 'orden_proveedor', 'documentoId' => $orden->id])
            ->assertSee('Tu usuario no tiene cuenta de correo de salida.')
            ->call('registrarWhatsapp', '3312345678');
        $this->assertSame(EstadoOrdenProveedor::Enviada, $orden->fresh()->estado);

        $this->get(route('ordenes-proveedor.ver', $orden))->assertOk()->assertSee('Compartir orden de compra');

        Livewire::test(Compartir::class, ['tipo' => 'cotizacion', 'documentoId' => $cot->id])->assertForbidden();
        $this->assertSame(1, DocumentoEnvio::count());
    }

    public function test_la_pantalla_de_la_oc_se_actualiza_al_compartir(): void
    {
        $this->entrar();
        $orden = $this->orden();
        $t = Livewire::test(VerOrden::class, ['orden' => $orden])->assertSee('Marcar como enviada');
        CompartirDocumento::registrar($orden, 'descarga');
        $t->dispatch('envio-registrado')->assertDontSee('Marcar como enviada');
    }

    public function test_descargar_el_pdf_pasa_la_cotizacion_de_borrador_a_enviada(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $cot = Cotizacion::factory()->create(['estado' => 'borrador']);

        // Ver el PDF o bajarlo para adjuntarlo en WhatsApp (registrar=0) no cuenta como descarga.
        $this->get(route('cotizaciones.pdf', $cot))->assertOk();
        $this->get(route('cotizaciones.pdf', ['cotizacion' => $cot, 'descargar' => 1, 'registrar' => 0]))->assertOk();
        $this->assertSame('borrador', $cot->fresh()->estado->value);

        // Cualquier botón "Descargar PDF" la pasa a enviada y queda en el historial.
        $this->get(route('cotizaciones.pdf', ['cotizacion' => $cot, 'descargar' => 1]))->assertOk();
        $this->assertSame('enviada', $cot->fresh()->estado->value);
        $this->assertNotNull($cot->fresh()->enviada_at);
        $this->assertSame(['descarga'], DocumentoEnvio::pluck('canal')->all());
    }

    public function test_arregla_las_que_se_compartieron_y_siguen_en_borrador(): void
    {
        $u = User::factory()->create();
        $cot = Cotizacion::factory()->create(['estado' => 'borrador', 'enviada_at' => null]);
        $otra = Cotizacion::factory()->create(['estado' => 'borrador']);
        DocumentoEnvio::create(['documento_type' => Cotizacion::class, 'documento_id' => $cot->id, 'user_id' => $u->id, 'canal' => 'whatsapp']);

        (include database_path('migrations/2026_10_21_000001_cotizaciones_compartidas_pasan_a_enviadas.php'))->up();

        $this->assertSame('enviada', $cot->fresh()->estado->value);
        $this->assertNotNull($cot->fresh()->enviada_at);
        $this->assertSame('borrador', $otra->fresh()->estado->value);
    }
}

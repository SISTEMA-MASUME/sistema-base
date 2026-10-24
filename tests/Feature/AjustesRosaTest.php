<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\Editor;
use App\Livewire\Cotizaciones\EnviarCorreo;
use App\Livewire\OrdenesProveedor\Generar;
use App\Livewire\Pedidos\Nuevo;
use App\Livewire\Pedidos\Ver;
use App\Mail\CotizacionMail;
use App\Models\Cliente;
use App\Models\Contacto;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\ProveedorContacto;
use App\Models\User;
use App\Services\CotizacionPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Ajustes que salieron al cotizar con Rosa: link de compra, confirmar correo, varios clientes, correo del PDF. */
class AjustesRosaTest extends TestCase
{
    use RefreshDatabase;

    private const LINK = 'https://articulo.mercadolibre.com.mx/MLM-123-rodamiento';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'masume.cuentas_correo.ventas.usuario' => 'ventas@ejemplo.com',
            'masume.cuentas_correo.ventas2.usuario' => 'ventas2@ejemplo.com',
        ]);
    }

    private function entrar(string $rol = 'vendedor', ?string $cuenta = 'ventas'): User
    {
        $u = User::factory()->create(['rol' => $rol, 'cuenta_correo' => $cuenta]);
        $this->actingAs($u);

        return $u;
    }

    public function test_link_de_compra_se_guarda_no_sale_al_cliente_y_llega_al_pedido(): void
    {
        $this->entrar();
        $cot = Cotizacion::factory()->create();
        $partida = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'RODAMIENTO 6205', 'precio' => 100, 'importe' => 100]);

        Livewire::test(Editor::class, ['cotizacion' => $cot])
            // Sin autollenado del navegador: Edge copiaba el costo en Modelo y Costo con IVA.
            ->assertSee('name="partida_0_costo_sin" autocomplete="off"', false)
            ->assertSee('name="partida_0_modelo" autocomplete="off"', false)
            ->assertSee('Link de compra')
            ->set('partidas.0.link_compra', '  '.self::LINK.'  ')
            ->assertSee('Abrir');
        $this->assertSame(self::LINK, $partida->fresh()->link_compra);

        // Nunca en el PDF del cliente.
        $this->assertStringNotContainsString('mercadolibre', CotizacionPdf::html($cot->fresh()));

        // Pasa al pedido y se ve al pedir el material (también el rol campo, que compra).
        $pedido = Pedido::factory()->create(['cliente_id' => $cot->cliente_id]);
        PedidoPartida::desdeCotizacion($partida->fresh(), $pedido, 1);
        $this->assertSame(self::LINK, PedidoPartida::firstOrFail()->link_compra);
        Livewire::test(Ver::class, ['pedido' => $pedido])->assertSee('Dónde comprar')->assertSee(self::LINK, false);
        Livewire::test(Generar::class, ['pedido' => $pedido])->assertSee('Dónde comprar');
        $this->entrar('campo', null);
        Livewire::test(Ver::class, ['pedido' => $pedido])->assertSee('Dónde comprar');

        // Las sugerencias de descripción también lo traen.
        $this->entrar();
        $this->getJson('/clientes/'.$cot->cliente_id.'/sugerencias')->assertOk()->assertJsonFragment(['lk' => self::LINK]);
    }

    public function test_guardar_campos_de_partida_por_id_calcula_y_no_toca_otros_campos(): void
    {
        $this->entrar();
        $cot = Cotizacion::factory()->create();
        $a = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'modelo' => null, 'costo' => null, 'utilidad_pct' => null, 'precio' => 0, 'importe' => 0]);
        $b = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 2, 'modelo' => 'NO-TOCAR']);

        Livewire::test(Editor::class, ['cotizacion' => $cot])
            // Los campos van por id de partida: aunque cambie el orden en pantalla, se guardan en la correcta.
            ->call('guardarCampos', $a->id, ['costo_sin' => '40', 'utilidad_pct' => '25', 'modelo' => 'X-1', 'imagen_path' => 'hack.jpg'])
            ->assertSet('partidas.0.costo_con', '46.40')
            ->assertSet('partidas.0.precio', '50.00')
            ->assertSet('partidas.1.modelo', 'NO-TOCAR')
            ->call('guardarCampos', $a->id, ['precio' => '60'])
            ->assertSet('partidas.0.utilidad_pct', '50');

        $a->refresh();
        $this->assertSame('X-1', $a->modelo);
        $this->assertEquals(40, (float) $a->costo);
        $this->assertEquals(60, (float) $a->precio);
        $this->assertNull($a->imagen_path);            // campo no editable: se ignora
        $this->assertSame('NO-TOCAR', $b->fresh()->modelo);
    }

    public function test_telefono_del_vendedor_del_proveedor_se_guarda_como_contacto(): void
    {
        $this->entrar();
        $cot = Cotizacion::factory()->create();
        $a = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'proveedor_id' => null, 'proveedor_vendedor' => null]);
        $b = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 2, 'proveedor_id' => null, 'proveedor_vendedor' => null]);

        $t = Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->assertSee('Teléfono / WhatsApp del vendedor')
            ->call('guardarCampos', $a->id, ['proveedor' => 'MERCURIO', 'proveedor_vendedor' => 'AGUSTIN HERRERA', 'proveedor_telefono' => '33 1234 5678']);

        $mercurio = Proveedor::where('nombre', 'MERCURIO')->firstOrFail();
        $contacto = $mercurio->contactos()->firstOrFail();
        $this->assertSame('AGUSTIN HERRERA', $contacto->nombre);
        $this->assertSame('33 1234 5678', $contacto->telefono);
        $this->assertSame('33 1234 5678', $a->fresh()->proveedor_telefono);

        // En otra partida, al poner al mismo vendedor el teléfono se llena solo.
        $t->call('guardarCampos', $b->id, ['proveedor' => 'MERCURIO', 'proveedor_vendedor' => 'Agustin Herrera'])
            ->assertSet('partidas.1.proveedor_telefono', '33 1234 5678');
        $this->assertSame(1, $mercurio->contactos()->count());

        // Nunca en el PDF del cliente; sí en el pedido (con WhatsApp).
        $this->assertStringNotContainsString('1234 5678', CotizacionPdf::html($cot->fresh()));
        $pedido = Pedido::factory()->create(['cliente_id' => $cot->cliente_id]);
        PedidoPartida::desdeCotizacion($a->fresh(), $pedido, 1);
        Livewire::test(Ver::class, ['pedido' => $pedido])->assertSee('https://wa.me/523312345678', false);
    }

    public function test_el_telefono_queda_en_la_ficha_del_proveedor_para_futuras_cotizaciones(): void
    {
        $this->entrar();
        $mercurio = Proveedor::factory()->create(['nombre' => 'MERCURIO']);
        ProveedorContacto::create(['proveedor_id' => $mercurio->id, 'nombre' => 'PEDRO', 'telefono' => '3399990000', 'principal' => true]);
        $cot = Cotizacion::factory()->create();
        $a = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'proveedor_id' => null, 'proveedor_vendedor' => null]);
        $b = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 2, 'proveedor_id' => null, 'proveedor_vendedor' => null]);
        $tel = fn (string $nombre) => $mercurio->contactos()->where('nombre', $nombre)->value('telefono');

        $t = Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->call('guardarCampos', $a->id, ['proveedor' => 'MERCURIO', 'proveedor_vendedor' => 'AGUSTIN', 'proveedor_telefono' => '33 1111 1111'])
            // La lista de vendedores guardados del proveedor aparece para elegir.
            ->assertSee('id="vendedores-'.$mercurio->id.'"', false)->assertSee('value="PEDRO"', false);
        $this->assertSame('33 1111 1111', $tel('AGUSTIN'));

        // Agustín cambió de número: se actualiza en la ficha.
        $t->call('guardarCampos', $a->id, ['proveedor_telefono' => '33 2222 2222']);
        $this->assertSame('33 2222 2222', $tel('AGUSTIN'));

        // Se cambia a Pedro: se trae su teléfono y el de Agustín no se le pasa a Pedro.
        $t->call('guardarCampos', $a->id, ['proveedor_vendedor' => 'PEDRO'])->assertSet('partidas.0.proveedor_telefono', '3399990000');
        $this->assertSame('3399990000', $tel('PEDRO'));
        $this->assertSame('33 2222 2222', $tel('AGUSTIN'));

        // Se cambia a un vendedor nuevo sin decir su número: no hereda el de Pedro.
        $t->call('guardarCampos', $a->id, ['proveedor_vendedor' => 'LUIS'])->assertSet('partidas.0.proveedor_telefono', '');
        $this->assertNull($tel('LUIS'));

        // Solo el teléfono, sin nombre: también queda en la ficha del proveedor.
        $t->call('guardarCampos', $b->id, ['proveedor' => 'MERCURIO', 'proveedor_telefono' => '33 5555 5555']);
        $this->assertSame('33 5555 5555', $tel('Vendedor 33 5555 5555'));
        $this->assertSame(4, $mercurio->contactos()->count());
    }

    public function test_cambiar_de_proveedor_no_le_pasa_el_vendedor_del_anterior(): void
    {
        // Se cotizó con DISNOR (Mauro) y se cambió a PILAS Y BATERIAS: Mauro no debe quedar como su contacto.
        $this->entrar();
        $disnor = Proveedor::factory()->create(['nombre' => 'DISNOR']);
        $pilas = Proveedor::factory()->create(['nombre' => 'PILAS Y BATERIAS UNIV']);
        ProveedorContacto::create(['proveedor_id' => $disnor->id, 'nombre' => 'MARCO', 'telefono' => '3311112222', 'principal' => true]);
        $cot = Cotizacion::factory()->create();
        $a = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'proveedor_id' => $disnor->id,
            'proveedor_vendedor' => 'MARCO', 'proveedor_telefono' => '3311112222']);

        $t = Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->call('guardarCampos', $a->id, ['proveedor' => 'PILAS Y BATERIAS UNIV'])
            ->assertSet('partidas.0.proveedor_vendedor', '')->assertSet('partidas.0.proveedor_telefono', '');
        $this->assertSame(0, $pilas->contactos()->count());
        $this->assertNull($a->fresh()->proveedor_vendedor);

        $t->call('guardarCampos', $a->id, ['proveedor_vendedor' => 'MARÍA', 'proveedor_telefono' => '3300001111']);
        $this->assertSame(['MARÍA'], $pilas->contactos()->pluck('nombre')->all());
        $this->assertSame(['MARCO'], $disnor->contactos()->pluck('nombre')->all());

        // Escribir el mismo proveedor otra vez no borra al vendedor.
        $t->call('guardarCampos', $a->id, ['proveedor' => 'PILAS Y BATERIAS UNIV'])->assertSet('partidas.0.proveedor_vendedor', 'MARÍA');
    }

    public function test_cambiar_quien_elaboro_la_cotizacion(): void
    {
        $rosa = $this->entrar('vendedor', 'ventas2');
        $daniel = User::factory()->create(['rol' => 'admin', 'name' => 'Daniel', 'cuenta_correo' => 'ventas', 'activo' => true]);
        $hugo = User::factory()->create(['rol' => 'campo', 'name' => 'Hugo', 'activo' => true]);
        $cot = Cotizacion::factory()->create(['vendedor_id' => $rosa->id]);

        // La empezó Rosa y la termina Daniel: se cambia "Elaboró" y el PDF lleva su nombre y su correo.
        $this->actingAs($daniel);
        Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->assertSee('Elaboró')->assertDontSee('>Hugo<', false)
            ->set('enc.vendedor_id', (string) $daniel->id)->assertHasNoErrors()
            ->set('enc.vendedor_id', (string) $hugo->id)->assertHasErrors('enc.vendedor_id');
        $cot->refresh();
        $this->assertSame($daniel->id, $cot->vendedor_id);
        $html = CotizacionPdf::html($cot);
        $this->assertStringContainsString('Daniel', $html);
        $this->assertStringContainsString('ventas@ejemplo.com', $html);
    }

    public function test_campo_de_una_partida_que_ya_no_existe_no_truena(): void
    {
        // E-QULCPN: una pantalla sin recargar mandó el campo de una partida borrada ("Undefined array key incluir").
        $this->entrar();
        $cot = Cotizacion::factory()->create();
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'UNICA']);

        Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->set('partidas.3.descripcion', 'FANTASMA')
            ->assertOk()
            ->assertCount('partidas', 1)
            ->assertSee('UNICA')
            ->assertDontSee('FANTASMA');
        $this->assertSame(1, $cot->partidas()->count());
    }

    public function test_se_puede_instalar_como_app_en_el_celular(): void
    {
        $this->get('/login')->assertOk()->assertSee('rel="manifest" href="/manifest.webmanifest"', false)->assertSee('apple-touch-icon', false);
        $manifiesto = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('standalone', $manifiesto['display']);
        foreach ($manifiesto['icons'] as $icono) {
            $this->assertFileExists(public_path(ltrim($icono['src'], '/')));
        }
        $this->assertFileExists(public_path('sw.js'));
        $this->entrar();
        $this->get(route('pedidos.index'))->assertSee('rel="manifest"', false);
    }

    public function test_correo_pide_confirmacion_antes_de_enviar(): void
    {
        $this->entrar();
        $cot = Cotizacion::factory()->create();
        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])->call('abrir')
            ->assertSee('data-confirmar-envio', false)
            ->assertSee('¿Seguro que quieres enviar el correo a', false)
            ->assertDontSee('wire:submit="enviar"', false);
    }

    public function test_el_pdf_lleva_el_correo_de_la_cuenta_del_vendedor(): void
    {
        $rosa = $this->entrar('vendedor', 'ventas2');
        $cot = Cotizacion::factory()->create(['vendedor_id' => $rosa->id]);
        $this->assertStringContainsString('ventas2@ejemplo.com', CotizacionPdf::html($cot));
        $this->assertStringContainsString('ventas2@ejemplo.com', CotizacionPdf::pie($cot));
        $this->assertStringNotContainsString('ventas@ejemplo.com', CotizacionPdf::html($cot));

        // Vendedor sin cuenta: el correo general.
        $sinCuenta = User::factory()->create(['cuenta_correo' => null]);
        $otra = Cotizacion::factory()->create(['vendedor_id' => $sinCuenta->id]);
        $this->assertStringContainsString('ventas@ejemplo.com', CotizacionPdf::html($otra));

        // El correo que envía Rosa sale de ventas2 y el PDF adjunto dice ventas2.
        Mail::fake();
        $contacto = Contacto::factory()->create(['cliente_id' => $cot->cliente_id, 'correo' => 'cliente@example.com']);
        $cot->update(['contacto_id' => $contacto->id]);
        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])->call('abrir')->call('enviar')->assertHasNoErrors();
        Mail::assertSent(CotizacionMail::class, fn ($m) => $m->mailer === 'smtp_ventas2' && $m->cuentaCorreo === 'ventas2@ejemplo.com');
    }

    public function test_nuevo_pedido_busca_en_varios_clientes(): void
    {
        $this->entrar();
        $molinos = Cliente::factory()->create(['nombre' => 'GRUPO KAPPA MOLINOS']);
        $central = Cliente::factory()->create(['nombre' => 'GRUPO KAPPA PLANTA CENTRAL']);
        $c1 = Cotizacion::factory()->create(['cliente_id' => $molinos->id]);
        $c2 = Cotizacion::factory()->create(['cliente_id' => $central->id]);
        CotizacionPartida::factory()->create(['cotizacion_id' => $c1->id, 'orden' => 1, 'descripcion' => 'BANDA MOLINOS']);
        $p2 = CotizacionPartida::factory()->create(['cotizacion_id' => $c2->id, 'orden' => 1, 'descripcion' => 'BANDA CENTRAL']);

        $t = Livewire::test(Nuevo::class)
            ->call('seleccionarCliente', $molinos->id)
            ->assertSee('BANDA MOLINOS')->assertDontSee('BANDA CENTRAL')
            ->set('buscarCliente', 'kappa')->assertSee('+ GRUPO KAPPA PLANTA CENTRAL')
            ->call('agregarCliente', $central->id)
            ->assertSee('BANDA MOLINOS')->assertSee('BANDA CENTRAL');

        // Se marcó solo una partida de Planta Central: el pedido es de Planta Central.
        $t->set('seleccion', [(string) $p2->id])->assertSet('clienteId', $central->id)
            ->set('justificacion', 'La OC llega por correo después')->call('irAPaso', 4)->assertSee('El pedido es del cliente')
            ->set('numeroOc', 'K-900')->call('crear')->assertHasNoErrors();
        $pedido = Pedido::where('numero_oc_cliente', 'K-900')->firstOrFail();
        $this->assertSame($central->id, $pedido->cliente_id);

        // Quitar un cliente de la búsqueda quita sus partidas marcadas.
        Livewire::test(Nuevo::class)->call('seleccionarCliente', $molinos->id)->call('agregarCliente', $central->id)
            ->set('seleccion', [(string) $p2->id])->call('quitarCliente', $central->id)
            ->assertSet('seleccion', [])->assertSet('clienteId', $molinos->id)->assertDontSee('BANDA CENTRAL');
    }
}

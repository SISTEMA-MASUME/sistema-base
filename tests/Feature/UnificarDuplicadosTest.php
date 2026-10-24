<?php

namespace Tests\Feature;

use App\Livewire\Compartir;
use App\Livewire\Cotizaciones\Editor;
use App\Livewire\Duplicados\Index as Duplicados;
use App\Livewire\Proveedores\Ver as VerProveedor;
use App\Models\Cliente;
use App\Models\Contacto;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\ProveedorContacto;
use App\Models\User;
use App\Support\Folios;
use App\Support\UnificarDuplicados;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Unificar clientes/proveedores duplicados y varios contactos por proveedor. */
class UnificarDuplicadosTest extends TestCase
{
    use RefreshDatabase;

    private function entrar(string $rol = 'admin'): User
    {
        $u = User::factory()->create(['rol' => $rol]);
        $this->actingAs($u);

        return $u;
    }

    public function test_propone_grupos_parecidos(): void
    {
        $a = Cliente::factory()->create(['nombre' => 'PROVEX']);
        $b = Cliente::factory()->create(['nombre' => 'PROVEX CARLOS']);
        $c = Cliente::factory()->create(['nombre' => 'PROVEX SA DE CV']);
        $d = Cliente::factory()->create(['nombre' => 'VIMIFOS']);
        $e = Cliente::factory()->create(['nombre' => 'VIMIFOZ']);   // error de dedo
        Cliente::factory()->create(['nombre' => 'FANOSA']);
        Cliente::factory()->create(['nombre' => 'ACME MEXICO']);
        Cotizacion::factory()->count(3)->create(['cliente_id' => $b->id]);

        $grupos = UnificarDuplicados::grupos('clientes');
        $this->assertCount(2, $grupos);
        $provex = collect($grupos)->first(fn ($g) => in_array($a->id, $g, true));
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $provex);
        $this->assertSame($b->id, $provex[0]);   // el más usado primero
        $this->assertContains([$d->id, $e->id], array_map(fn ($g) => collect($g)->sort()->values()->all(), $grupos));

        $this->assertSame('Carlos', UnificarDuplicados::contactoDelNombre('PROVEX', 'PROVEX CARLOS'));
        $this->assertSame('Norma Ruiz', UnificarDuplicados::contactoDelNombre('PROVEX', 'PROVEX - NORMA RUIZ'));
        $this->assertNull(UnificarDuplicados::contactoDelNombre('PROVEX', 'PROVEX SA DE CV 2'));
    }

    public function test_magnocentro_y_magnocento_salen_como_duplicados_y_el_cotizador_no_crea_otro(): void
    {
        $this->entrar();
        $bueno = Proveedor::factory()->create(['nombre' => 'MAXIFERRO']);
        $malo = Proveedor::factory()->create(['nombre' => 'MAXIFERO']);
        $grupos = UnificarDuplicados::grupos('proveedores');
        $this->assertTrue(collect($grupos)->contains(fn ($g) => in_array($bueno->id, $g) && in_array($malo->id, $g)));
        $malo->delete();

        // Al escribir el nombre con error de dedo en la cotización se usa el que ya existe.
        $cot = Cotizacion::factory()->create();
        $p = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'proveedor_id' => null]);
        Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->call('guardarCampos', $p->id, ['proveedor' => 'Maxifero'])
            ->assertSee('Se usó el proveedor MAXIFERRO');
        $this->assertSame($bueno->id, $p->fresh()->proveedor_id);
        $this->assertSame(1, Proveedor::where('nombre', 'like', 'MAXIFER%')->count());

        // Un nombre de verdad distinto sí se da de alta.
        Livewire::test(Editor::class, ['cotizacion' => $cot])->call('guardarCampos', $p->id, ['proveedor' => 'FERRETERIA LOPEZ']);
        $this->assertSame('FERRETERIA LOPEZ', $p->fresh()->proveedor->nombre);
    }

    public function test_unir_proveedores_a_mano_con_palomitas(): void
    {
        $this->entrar();
        $a = Proveedor::factory()->create(['nombre' => 'TORNILLOS DEL BAJIO']);
        $b = Proveedor::factory()->create(['nombre' => 'TORN. BAJIO']);   // el sistema no los junta solo
        $cot = Cotizacion::factory()->create();
        CotizacionPartida::factory()->count(2)->create(['cotizacion_id' => $cot->id, 'proveedor_id' => $a->id]);
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'proveedor_id' => $b->id]);

        Livewire::test(Duplicados::class, ['tipo' => 'proveedores'])
            ->set('tipo', 'proveedores')
            ->assertSee('Unir a mano')
            ->set('buscarManual', 'BAJIO')->assertSee('TORNILLOS DEL BAJIO')->assertSee('TORN. BAJIO')
            ->set('manual', [(string) $b->id])->call('unirManual')->assertHasErrors('manual')
            ->set('manual', [(string) $b->id, (string) $a->id])->call('unirManual')
            ->assertSet('principalId', $a->id)   // el más usado se propone como principal
            ->call('verVistaPrevia')->assertSee('1 partidas cotizadas')
            ->call('unir')->assertSee('quedó unido en TORNILLOS DEL BAJIO');

        $this->assertSoftDeleted('proveedores', ['id' => $b->id]);
        $this->assertSame(3, CotizacionPartida::where('proveedor_id', $a->id)->count());
    }

    public function test_une_clientes_sin_perder_datos_y_crea_el_contacto_del_nombre(): void
    {
        $this->entrar();
        $p = Cliente::factory()->create(['nombre' => 'PROVEX', 'rfc' => null, 'telefono' => '3311111111', 'correo' => null]);
        $d = Cliente::factory()->create(['nombre' => 'PROVEX CARLOS', 'rfc' => 'FEF010101AB1', 'telefono' => '3399999999', 'correo' => 'carlos@provex.mx',
            'sube_factura_portal' => true]);
        $cot = Cotizacion::factory()->create(['cliente_id' => $d->id, 'contacto_id' => null]);
        $pedido = Pedido::factory()->create(['cliente_id' => $d->id, 'numero_oc_cliente' => 'A-1']);
        Contacto::factory()->create(['cliente_id' => $d->id, 'nombre' => 'Norma']);

        $t = Livewire::test(Duplicados::class)
            ->assertSee('PROVEX CARLOS')
            ->call('revisar', [$p->id, $d->id])
            ->set('principalId', $p->id)
            ->call('verVistaPrevia')
            ->assertSee('1 cotizaciones, 1 pedidos, 1 contactos')
            ->assertSee('Se crea el contacto');
        $this->assertSame($d->id, $cot->fresh()->cliente_id);   // la vista previa no cambia nada

        $t->call('unir')->assertSee('1 duplicado quedó unido en PROVEX');

        $p->refresh();
        $this->assertSame('FEF010101AB1', $p->rfc);                // dato vacío se completa
        $this->assertSame('carlos@provex.mx', $p->correo);
        $this->assertSame('3311111111', $p->telefono);             // el del principal se queda
        $this->assertStringContainsString('telefono: 3399999999', $p->notas);   // el distinto va a notas
        $this->assertTrue($p->sube_factura_portal);
        $this->assertSoftDeleted($d);
        $this->assertSame($p->id, $pedido->fresh()->cliente_id);
        $carlos = Contacto::where('cliente_id', $p->id)->where('nombre', 'Carlos')->firstOrFail();
        $this->assertSame($carlos->id, $cot->fresh()->contacto_id);
        $this->assertSame($p->id, $cot->fresh()->cliente_id);
        $this->assertSame(2, $p->contactos()->count());
    }

    public function test_no_une_con_oc_repetida_y_solo_admin(): void
    {
        $this->entrar();
        $p = Cliente::factory()->create(['nombre' => 'KAPPA']);
        $d = Cliente::factory()->create(['nombre' => 'KAPPA MOLINOS']);
        Pedido::factory()->create(['cliente_id' => $p->id, 'numero_oc_cliente' => '777']);
        Pedido::factory()->create(['cliente_id' => $d->id, 'numero_oc_cliente' => 'OC 777']);

        Livewire::test(Duplicados::class)->call('revisar', [$p->id, $d->id])->call('verVistaPrevia')
            ->assertSee('No se puede unir: hay OC repetidas.')
            ->call('unir')->assertStatus(422);
        $this->assertNotSoftDeleted($d);

        $this->entrar('vendedor');
        $this->get(route('duplicados.index'))->assertForbidden();
    }

    public function test_une_proveedores_y_conserva_contactos(): void
    {
        $this->entrar();
        $p = Proveedor::factory()->create(['nombre' => 'TRUPER']);
        $d = Proveedor::factory()->create(['nombre' => 'TRUPER GDL', 'contacto' => 'Luis', 'telefono' => '3312345678']);
        ProveedorContacto::create(['proveedor_id' => $p->id, 'nombre' => 'Ana', 'principal' => true]);
        $partida = CotizacionPartida::factory()->create(['proveedor_id' => $d->id]);
        $oc = OrdenProveedor::create(['folio' => Folios::siguiente('oc_proveedor'), 'pedido_id' => Pedido::factory()->create()->id,
            'proveedor_id' => $d->id, 'user_id' => auth()->id(), 'metodo_pago' => 'TARJETA', 'estado' => 'emitida', 'total_costo' => 1]);

        Livewire::test(Duplicados::class)->call('elegirTipo', 'proveedores')->assertSee('TRUPER GDL')
            ->call('revisar', [$d->id, $p->id])->set('principalId', $p->id)
            ->call('verVistaPrevia')->assertSee('1 partidas cotizadas, 1 OC a proveedor')
            ->call('unir');

        $this->assertSame($p->id, $partida->fresh()->proveedor_id);
        $this->assertSame($p->id, $oc->fresh()->proveedor_id);
        $this->assertSoftDeleted($d);
        $this->assertEqualsCanonicalizing(['Ana', 'Luis'], $p->contactos()->pluck('nombre')->all());
        $this->assertSame('Ana', $p->fresh()->datosContacto()['nombre']);   // el principal no cambia
    }

    public function test_varios_contactos_por_proveedor_y_el_principal_recibe_la_oc(): void
    {
        $this->entrar('vendedor');
        $prov = Proveedor::factory()->create(['nombre' => 'ACME', 'telefono' => '3300000000']);

        $t = Livewire::test(VerProveedor::class, ['proveedor' => $prov])
            ->call('nuevoContacto')->set('c_nombre', 'Luis')->set('c_telefono', '33 1111 2222')->call('guardarContacto')
            ->call('nuevoContacto')->set('c_nombre', 'Pedro')->set('c_telefono', '33 3333 4444')->set('c_correo', 'pedro@acme.mx')->call('guardarContacto')
            ->assertSee('Principal');
        $luis = ProveedorContacto::where('nombre', 'Luis')->firstOrFail();
        $pedro = ProveedorContacto::where('nombre', 'Pedro')->firstOrFail();
        $this->assertTrue($luis->principal);   // el primero queda como principal
        $this->assertFalse($pedro->principal);

        // Luis se fue: Pedro pasa a ser el principal.
        $t->call('hacerPrincipal', $pedro->id);
        $this->assertTrue($pedro->fresh()->principal);
        $this->assertFalse($luis->fresh()->principal);
        $this->assertSame(['nombre' => 'Pedro', 'telefono' => '33 3333 4444', 'correo' => 'pedro@acme.mx'], $prov->fresh()->datosContacto());

        $oc = OrdenProveedor::create(['folio' => Folios::siguiente('oc_proveedor'), 'pedido_id' => Pedido::factory()->create()->id,
            'proveedor_id' => $prov->id, 'user_id' => auth()->id(), 'metodo_pago' => 'TARJETA', 'estado' => 'emitida', 'total_costo' => 1]);
        Livewire::test(Compartir::class, ['tipo' => 'orden_proveedor', 'documentoId' => $oc->id])
            ->assertSee("telefono: '523333334444'", false)
            ->assertSee('Hola Pedro');

        // Al borrar al principal, otro toma su lugar.
        $t->call('borrarContacto', $pedro->id);
        $this->assertTrue($luis->fresh()->principal);

        // La lista de proveedores muestra al contacto principal.
        $this->get(route('proveedores.index'))->assertOk()->assertSee('Luis');
    }
}

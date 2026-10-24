<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\Editor;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\Solicitudes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Escribir el nombre de un proveedor/cliente borrado o unido en Duplicados ya no truena por nombre_llave repetido. */
class ProveedorBorradoTest extends TestCase
{
    use RefreshDatabase;

    private function partida(): array
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $cot = Cotizacion::factory()->create();
        $p = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'proveedor_id' => null, 'proveedor_vendedor' => null]);

        return [$cot, $p];
    }

    public function test_proveedor_unido_en_duplicados_usa_el_principal(): void
    {
        [$cot, $p] = $this->partida();
        $principal = Proveedor::create(['nombre' => 'STEREN']);
        $unido = Proveedor::create(['nombre' => 'STEREN COLON']);
        $unido->forceFill(['unido_a_id' => $principal->id])->save();
        $unido->delete();

        Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->call('guardarCampos', $p->id, ['proveedor' => 'Steren Colon'])
            ->assertHasNoErrors();

        $this->assertSame($principal->id, $p->fresh()->proveedor_id);
        $this->assertSame(2, Proveedor::withTrashed()->count());
    }

    public function test_proveedor_borrado_no_se_reactiva_y_avisa(): void
    {
        [$cot, $p] = $this->partida();
        $borrado = Proveedor::create(['nombre' => 'FERRE PATITO']);
        $borrado->delete();

        Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->call('guardarCampos', $p->id, ['proveedor' => 'FERRE PATITO'])
            ->assertSet('mensaje', 'El proveedor FERRE PATITO fue borrado; no se puede usar. Elige otro de la lista.');

        $this->assertNull($p->fresh()->proveedor_id);
        $this->assertTrue($borrado->fresh()->trashed());
        $this->assertSame(1, Proveedor::withTrashed()->count());
    }

    public function test_sucursal_usa_el_proveedor_que_ya_existe(): void
    {
        [$cot, $p] = $this->partida();
        $steren = Proveedor::create(['nombre' => 'STEREN']);

        Livewire::test(Editor::class, ['cotizacion' => $cot])
            ->call('guardarCampos', $p->id, ['proveedor' => 'Steren Plaza del Sol'])
            ->assertSet('partidas.0.proveedor', 'STEREN')
            ->assertSee('Ya existe el proveedor STEREN; se usó ese. La sucursal (PLAZA DEL SOL)', false);

        $this->assertSame($steren->id, $p->fresh()->proveedor_id);
        $this->assertSame(1, Proveedor::count());
    }

    public function test_cliente_borrado_a_proposito_no_se_reactiva(): void
    {
        Cliente::create(['nombre' => 'CLIENTE VIEJO'])->delete();

        $this->expectException(\DomainException::class);
        Solicitudes::asegurarCliente('Cliente Viejo');
    }

    public function test_cliente_borrado_en_por_cotizar_no_truena(): void
    {
        $principal = Cliente::create(['nombre' => 'HOTEL CENTRAL']);
        $unido = Cliente::create(['nombre' => 'HOTEL CENTRAL GDL']);
        $unido->forceFill(['unido_a_id' => $principal->id])->save();
        $unido->delete();

        $this->assertSame($principal->id, Solicitudes::asegurarCliente('Hotel Central GDL')->id);
    }
}

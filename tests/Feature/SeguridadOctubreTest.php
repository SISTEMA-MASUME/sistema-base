<?php

namespace Tests\Feature;

use App\Livewire\Pedidos\Expediente;
use App\Models\Pedido;
use App\Models\PedidoArchivo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/** Revisión de seguridad de octubre (después de la fuga de un cliente): facturas a campo, buscadores y contraseñas. */
class SeguridadOctubreTest extends TestCase
{
    use RefreshDatabase;

    public function test_campo_ve_y_abre_las_facturas_y_la_evidencia_pero_no_cambia_de_pedido(): void
    {
        Storage::fake('local');
        $pedido = Pedido::factory()->create();
        $vendedor = User::factory()->create(['rol' => 'vendedor']);
        $archivo = fn (string $tipo, string $nombre) => PedidoArchivo::create(['pedido_id' => $pedido->id, 'tipo' => $tipo, 'disco' => 'local',
            'ruta' => tap('pedidos/'.$pedido->id.'/'.$nombre, fn ($r) => Storage::disk('local')->put($r, 'x')), 'nombre_original' => $nombre,
            'mime' => str_ends_with($nombre, 'xml') ? 'text/xml' : (str_ends_with($nombre, 'jpg') ? 'image/jpeg' : 'application/pdf'), 'tamano' => 1, 'user_id' => $vendedor->id]);
        $pdf = $archivo('factura_pdf', 'FacCFDI_A-123.pdf');
        $xml = $archivo('factura_xml', 'FacCFDI_A-123.xml');
        $foto = $archivo('evidencia', 'entrega.jpg');

        // El vendedor sí las ve y las abre.
        $this->actingAs($vendedor);
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->assertSee('Facturas y notas')->assertSee('FacCFDI_A-123.pdf');
        $this->get(route('pedidos.archivo', [$pedido, $pdf]))->assertOk();

        // Hugo también: las necesita para entregar (decisión de Daniel, 18-oct-2026). No las sube ni las borra.
        $this->actingAs(User::factory()->create(['rol' => 'campo']));
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->assertSee('Facturas y notas')->assertSee('FacCFDI_A-123.pdf')
            ->call('borrar', $pdf->id)->assertForbidden();
        $this->get(route('pedidos.archivo', [$pedido, $pdf]))->assertOk();
        $this->get(route('pedidos.archivo', [$pedido, $xml]))->assertOk();
        $this->get(route('pedidos.archivo', [$pedido, $foto]))->assertOk();

        // No puede cambiar de pedido escondido en la página.
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->set('pedidoId', 999);
    }

    public function test_ningun_buscador_ni_archive_org_guarda_el_sistema(): void
    {
        $this->get('/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get('/login', ['User-Agent' => 'Mozilla/5.0 (compatible; archive.org_bot +http://archive.org/details/archive.org_bot)'])->assertForbidden();
        $this->get('/login', ['User-Agent' => 'ia_archiver'])->assertForbidden();
        $robots = file_get_contents(public_path('robots.txt'));
        $this->assertStringContainsString("User-agent: *\nDisallow: /\n", $robots);
        $this->assertStringContainsString('X-Robots-Tag "noindex, nofollow, noarchive"', file_get_contents(public_path('.htaccess')));
    }

    public function test_cinco_contrasenas_equivocadas_bloquean_ese_correo_15_minutos(): void
    {
        $rosa = User::factory()->create(['email' => 'rosa@masume.test', 'password' => 'clave-correcta-1']);

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$i])   // desde conexiones distintas
                ->post('/login', ['email' => 'Rosa@masume.test', 'password' => 'mala'.$i])->assertSessionHasErrors(['email' => 'Correo o contraseña incorrectos.']);
        }
        // Ya ni con la correcta: hay que esperar.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->post('/login', ['email' => 'rosa@masume.test', 'password' => 'clave-correcta-1'])
            ->assertSessionHasErrors(['email' => 'Demasiados intentos con este correo. Espera 15 minutos y vuelve a intentar.']);
        $this->assertGuest();

        // Pasados los 15 minutos entra, y al entrar se borra la cuenta de errores.
        $this->travel(16)->minutes();
        $this->post('/login', ['email' => 'rosa@masume.test', 'password' => 'clave-correcta-1'])->assertRedirect();
        $this->assertAuthenticatedAs($rosa);
    }
}

<?php

namespace Tests\Feature;

use App\Http\Controllers\McpOAuthController;
use App\Livewire\Pedidos\Expediente;
use App\Livewire\Solicitudes\Nueva;
use App\Models\Pedido;
use App\Models\PedidoArchivo;
use App\Models\SolicitudArchivo;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Auditoría de seguridad (oct-2026): archivos subidos que se abrían como página web y ejecutaban código, y la pantalla de
 * permiso de IA que no decía a qué sitio se mandaba la llave.
 */
class SeguridadArchivosTest extends TestCase
{
    use RefreshDatabase;

    private const HTML = '<html><body><script>document.title="X"</script>lista</body></html>';

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function vendedor(): User
    {
        return User::factory()->create(['rol' => 'vendedor']);
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'admin']);
    }

    public function test_por_cotizar_rechaza_una_pagina_web_con_nombre_pdf(): void
    {
        Livewire::actingAs($this->vendedor())->test(Nueva::class)
            ->set('clienteTexto', 'CLIENTE PRUEBA')
            ->set('archivos', [UploadedFile::fake()->createWithContent('lista.pdf', self::HTML)])
            ->assertSet('error', fn ($e) => str_contains($e, 'no es un PDF de verdad'))
            ->set('archivos', [UploadedFile::fake()->createWithContent('lista.csv', self::HTML)])
            ->assertSet('error', fn ($e) => str_contains($e, 'no es un Excel de verdad'))
            ->set('archivos', [UploadedFile::fake()->createWithContent('bueno.pdf', self::PDF)])
            ->assertSet('error', '')
            ->call('guardar')->assertHasNoErrors();

        $this->assertSame(['bueno.pdf'], SolicitudArchivo::pluck('nombre')->all());
    }

    public function test_por_cotizar_nunca_sirve_html_aunque_ya_este_guardado(): void
    {
        $admin = $this->admin();
        $s = SolicitudCotizacion::create(['folio' => 1, 'cliente_texto' => 'X', 'estatus' => 'pendiente', 'canal' => 'correo', 'recibida_at' => now(), 'creada_por' => $admin->id]);
        Storage::disk('local')->put('solicitudes/viejo.pdf', self::HTML);
        Storage::disk('local')->put('solicitudes/lista.csv', self::HTML);
        $pdf = $s->archivos()->create(['ruta' => 'solicitudes/viejo.pdf', 'nombre' => 'viejo.pdf', 'mime' => 'application/pdf', 'bytes' => 10]);
        $csv = $s->archivos()->create(['ruta' => 'solicitudes/lista.csv', 'nombre' => 'lista.csv', 'mime' => 'text/csv', 'bytes' => 10]);

        // Un archivo viejo con HTML adentro sale como PDF (el navegador no lo ejecuta) y con nosniff.
        $r = $this->actingAs($admin)->get(route('solicitudes.archivo', $pdf))->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));

        // Excel/CSV no se abren en el navegador: se descargan.
        $r = $this->actingAs($admin)->get(route('solicitudes.archivo', $csv))->assertOk();
        $this->assertStringStartsWith('attachment', (string) $r->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $r->headers->get('Content-Type'));
    }

    public function test_expediente_muestra_el_xml_como_texto_y_no_como_pagina(): void
    {
        $pedido = Pedido::factory()->create();
        $xml = '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>f</title></head><body><script>document.title="X"</script></body></html>';
        Livewire::actingAs($this->vendedor())->test(Expediente::class, ['pedidoId' => $pedido->id])
            ->set('facturas', [UploadedFile::fake()->createWithContent('F-1.xml', $xml)]);
        $a = PedidoArchivo::where('tipo', 'factura_xml')->firstOrFail();

        $r = $this->actingAs($this->admin())->get(route('pedidos.archivo', [$pedido, $a]))->assertOk();
        $this->assertSame('text/plain; charset=utf-8', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));

        $r = $this->get(route('pedidos.archivo', [$pedido, $a, 'descargar' => 1]))->assertOk();
        $this->assertStringStartsWith('attachment', (string) $r->headers->get('Content-Disposition'));
    }

    public function test_la_oc_del_cliente_sale_con_su_tipo_y_nosniff(): void
    {
        Storage::disk('local')->put('pedidos/oc.pdf', self::PDF);
        $pedido = Pedido::factory()->create(['oc_cliente_path' => 'pedidos/oc.pdf']);

        $r = $this->actingAs($this->vendedor())->get(route('pedidos.oc-cliente', $pedido))->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
    }

    /** @return array{0: array<string, string>, 1: string} parámetros de autorización y verificador PKCE */
    private function permisoIa(string $redirect, string $nombre = 'Claude'): array
    {
        $clientId = $this->postJson('/oauth/registro', ['client_name' => $nombre, 'redirect_uris' => [$redirect]])->assertCreated()->json('client_id');
        $verificador = str_repeat('v', 50);
        $reto = rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');

        return [['client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code', 'code_challenge' => $reto, 'code_challenge_method' => 'S256', 'state' => 'x'], $verificador];
    }

    public function test_permiso_de_ia_muestra_el_sitio_y_pide_confirmar_si_no_es_claude_ni_chatgpt(): void
    {
        $admin = $this->admin();
        [$q] = $this->permisoIa('https://atacante.example/cb');

        $this->actingAs($admin)->get('/oauth/autorizar?'.http_build_query($q))->assertOk()
            ->assertSeeText('La llave se enviará a: atacante.example')
            ->assertSeeText('Ese sitio NO es Claude ni ChatGPT')
            ->assertSee('name="confirmo_destino"', false);

        // Sin la confirmación no se emite código.
        $this->actingAs($admin)->post('/oauth/autorizar', $q + ['permitir' => '1'])->assertStatus(422);
        $this->assertSame(0, \DB::table('mcp_codigos')->count());
        // "No" siempre se puede.
        $this->actingAs($admin)->post('/oauth/autorizar', $q + ['permitir' => '0'])->assertRedirect('https://atacante.example/cb?error=access_denied&state=x');
        // Con la confirmación expresa sí (por si Daniel usa un asistente propio).
        $this->actingAs($admin)->post('/oauth/autorizar', $q + ['permitir' => '1', 'confirmo_destino' => '1'])->assertRedirect();
        $this->assertSame(1, \DB::table('mcp_codigos')->count());
    }

    public function test_permiso_de_ia_con_claude_no_pide_confirmacion(): void
    {
        $admin = $this->admin();
        [$q] = $this->permisoIa('https://claude.ai/api/mcp/auth_callback');

        $this->actingAs($admin)->get('/oauth/autorizar?'.http_build_query($q))->assertOk()
            ->assertSeeText('La llave se enviará a: claude.ai')
            ->assertDontSeeText('NO es Claude ni ChatGPT')
            ->assertDontSee('name="confirmo_destino"', false);
        $destino = $this->actingAs($admin)->post('/oauth/autorizar', $q + ['permitir' => '1'])->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://claude.ai/api/mcp/auth_callback?code=', $destino);
        // Un subdominio parecido pero ajeno no cuenta como conocido.
        $this->assertFalse(McpOAuthController::destino('https://claude.ai.atacante.example/cb')['conocido']);
        $this->assertFalse(McpOAuthController::destino('https://falsoclaude.ai/cb')['conocido']);
        $this->assertTrue(McpOAuthController::destino('https://chatgpt.com/connector_platform_oauth_redirect')['conocido']);
    }
}

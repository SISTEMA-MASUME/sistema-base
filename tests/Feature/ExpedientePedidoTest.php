<?php

namespace Tests\Feature;

use App\Livewire\Pedidos\Expediente;
use App\Livewire\Pedidos\Ver;
use App\Livewire\Tablero\Index as Tablero;
use App\Models\Pedido;
use App\Models\PedidoArchivo;
use App\Models\PedidoPartida;
use App\Models\User;
use App\Models\Venta;
use App\Support\DocumentoPropio;
use App\Support\FacturaArchivo;
use App\Support\Trazabilidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mpdf\Mpdf;
use Tests\TestCase;

class ExpedientePedidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function usuario(string $rol = 'vendedor'): User
    {
        return User::factory()->create(['rol' => $rol]);
    }

    private function archivo(Pedido $p, string $tipo, User $u, string $ext = 'jpg'): PedidoArchivo
    {
        $ruta = 'pedidos/'.$p->id.'/x/'.uniqid().'.'.$ext;
        Storage::disk('local')->put($ruta, 'contenido');

        return PedidoArchivo::create([
            'pedido_id' => $p->id, 'tipo' => $tipo, 'ruta' => $ruta, 'nombre_original' => 'a.'.$ext,
            'mime' => $ext === 'jpg' ? 'image/jpeg' : 'application/pdf', 'tamano' => 9, 'user_id' => $u->id,
        ]);
    }

    public function test_sube_y_borra_facturas_pdf_y_xml(): void
    {
        $u = $this->usuario();
        $this->actingAs($u);
        $pedido = Pedido::factory()->create();

        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->set('facturas', [
                UploadedFile::fake()->create('F-1.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->createWithContent('F-1.xml', '<cfdi/>'),
            ])
            ->assertHasNoErrors()
            ->assertSee('archivos de factura guardados')
            ->assertSee('F-1.xml');

        $this->assertSame(['factura_pdf', 'factura_xml'], $pedido->archivos()->pluck('tipo')->sort()->values()->all());
        $a = $pedido->archivos()->first();
        Storage::disk('local')->assertExists($a->ruta);

        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->call('borrar', $a->id);
        Storage::disk('local')->assertMissing($a->ruta);
        $this->assertSame(1, $pedido->archivos()->count());
    }

    private const UUID = 'a1b2c3d4-e5f6-4789-abcd-0123456789ab';

    private function xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Serie="5" Folio="5300" Total="116.00">'
            .'<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" UUID="'.self::UUID.'"/></cfdi:Complemento></cfdi:Comprobante>';
    }

    private function venta(array $extra = []): Venta
    {
        return Venta::create($extra + ['tipo' => 'factura', 'folio' => '5-5300', 'cliente_nombre' => 'X', 'fecha' => '2026-10-01',
            'subtotal' => 100, 'iva' => 16, 'total' => 116, 'uuid' => strtoupper(self::UUID), 'origen' => 'sicar']);
    }

    public function test_subir_el_xml_liga_el_pedido_con_su_factura_sin_duplicar(): void
    {
        $this->actingAs($this->usuario());
        $pedido = Pedido::factory()->create();
        $venta = $this->venta();

        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->set('facturas', [UploadedFile::fake()->createWithContent('F5300.xml', $this->xml())])
            ->assertSet('mensaje', fn ($m) => str_contains($m, 'ligada con la factura 5-5300'));
        $this->assertTrue($pedido->ventas()->whereKey($venta->id)->exists());
        $this->assertSame('5-5300', PedidoArchivo::where('tipo', 'factura_xml')->value('cfdi_folio'));

        // El PDF de la misma factura (UUID en texto comprimido) no la vuelve a ligar.
        $pdf = "%PDF-1.4\n1 0 obj<<>>stream\n".gzcompress('BT (Folio fiscal: '.strtoupper(self::UUID).') Tj ET')."\nendstream\nendobj";
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->set('facturas', [UploadedFile::fake()->createWithContent('factura.pdf', $pdf)]);
        $this->assertSame(1, $pedido->ventas()->count());
        $this->assertSame(strtoupper(self::UUID), PedidoArchivo::where('tipo', 'factura_pdf')->value('cfdi_uuid'));
        $this->assertSame(1, $pedido->actividades()->where('descripcion', 'like', '%al subir su archivo%')->count());
    }

    public function test_si_la_factura_llega_de_sicar_despues_del_archivo_se_liga_sola(): void
    {
        config(['services.sicar.token' => 'secreto']);
        $this->actingAs($this->usuario());
        $pedido = Pedido::factory()->create();
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->set('facturas', [UploadedFile::fake()->createWithContent('F5300.xml', $this->xml())]);
        $this->assertFalse($pedido->ventas()->exists());

        $this->withToken('secreto')->postJson('/api/sicar/ventas', ['documentos' => [[
            'sicar_id' => 'F:5300', 'tipo' => 'factura', 'folio' => '5-5300', 'fecha' => '2026-10-01', 'cliente' => 'Otro nombre SA',
            'subtotal' => 100, 'iva' => 16, 'total' => 116, 'uuid' => self::UUID, 'cancelada' => false,
        ]]])->assertOk()->assertJson(['ligadas_a_pedido' => 1]);
        $this->assertTrue($pedido->ventas()->where('folio', '5-5300')->exists());
    }

    public function test_rechaza_factura_de_otro_tipo(): void
    {
        $this->actingAs($this->usuario());
        $pedido = Pedido::factory()->create();

        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->set('facturas', [UploadedFile::fake()->create('f.docx', 10)])
            ->assertSee('La factura debe ser un PDF o un XML');
        $this->assertSame(0, $pedido->archivos()->count());
    }

    public function test_foto_grande_se_redimensiona_a_1600_y_guarda_nota_y_partidas(): void
    {
        ini_set('memory_limit', '512M'); // una imagen de 4000x3000 en GD ocupa ~50 MB
        $this->actingAs($this->usuario('campo'));
        $pedido = Pedido::factory()->create();
        $p = PedidoPartida::factory()->create(['pedido_id' => $pedido->id]);

        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->set('notaFoto', 'Recibió: Juan, almacén')
            ->set('partidasFoto', [(string) $p->id, '99999'])
            ->set('fotos', [UploadedFile::fake()->image('e.jpg', 4000, 3000)])
            ->assertHasNoErrors()
            ->assertSee('Foto de evidencia guardada');

        $a = $pedido->archivos()->firstOrFail();
        $this->assertSame('evidencia', $a->tipo);
        $this->assertSame('Recibió: Juan, almacén', $a->notas);
        $this->assertSame([$p->id], $a->partidas_ids);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($a->ruta));
        $this->assertLessThanOrEqual(1600, max($w, $h));
        $this->assertSame(1600, max($w, $h));
    }

    public function test_campo_sube_evidencia_pero_no_factura_ni_borra_factura(): void
    {
        $vendedor = $this->usuario();
        $campo = $this->usuario('campo');
        $pedido = Pedido::factory()->create();
        $factura = $this->archivo($pedido, 'factura_pdf', $vendedor, 'pdf');
        $this->actingAs($campo);

        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->set('fotos', [UploadedFile::fake()->image('e.jpg', 800, 600)])
            ->assertHasNoErrors();
        $this->assertSame(1, $pedido->archivos()->where('tipo', 'evidencia')->count());

        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->set('facturas', [UploadedFile::fake()->create('f.pdf', 10, 'application/pdf')])
            ->assertForbidden();

        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->call('borrar', $factura->id)
            ->assertForbidden();

        // Ve todo el expediente, también las facturas (decisión de Daniel), y borra su propia evidencia.
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->assertSee('a.pdf');
        $propia = $pedido->archivos()->where('tipo', 'evidencia')->first();
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->call('borrar', $propia->id);
        $this->assertSame(0, $pedido->archivos()->where('tipo', 'evidencia')->count());
    }

    public function test_solo_quien_subio_o_admin_borra(): void
    {
        $dueno = $this->usuario();
        $otro = $this->usuario();
        $admin = $this->usuario('admin');
        $pedido = Pedido::factory()->create();
        $a = $this->archivo($pedido, 'evidencia', $dueno);

        $this->actingAs($otro);
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->call('borrar', $a->id)->assertForbidden();

        $this->actingAs($admin);
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->call('borrar', $a->id);
        $this->assertSame(0, PedidoArchivo::count());
    }

    public function test_la_descarga_requiere_sesion_y_pedido_correcto(): void
    {
        $u = $this->usuario();
        $pedido = Pedido::factory()->create();
        $otroPedido = Pedido::factory()->create();
        $a = $this->archivo($pedido, 'factura_pdf', $u, 'pdf');

        $this->get(route('pedidos.archivo', [$pedido, $a]))->assertRedirect();

        $this->actingAs($u);
        $this->get(route('pedidos.archivo', [$pedido, $a]))->assertOk();
        $this->get(route('pedidos.archivo', ['pedido' => $pedido, 'archivo' => $a, 'descargar' => 1]))->assertOk()->assertDownload('a.pdf');
        $this->get(route('pedidos.archivo', [$otroPedido, $a]))->assertNotFound();

        // Hugo sí abre las facturas: las necesita para entregar (decisión de Daniel, 18-oct-2026).
        $this->actingAs($this->usuario('campo'));
        $this->get(route('pedidos.archivo', [$pedido, $a]))->assertOk();
    }

    public function test_no_hay_boton_de_marcar_todas_entregadas(): void
    {
        $this->actingAs($this->usuario());
        $pedido = Pedido::factory()->create();
        PedidoPartida::factory()->create(['pedido_id' => $pedido->id]);

        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->set('fotos', [UploadedFile::fake()->image('e.jpg', 800, 600)])
            ->assertDontSee('Marcar todas como entregadas');
        $this->assertSame('pendiente', PedidoPartida::first()->estado->value);
    }

    public function test_tablero_muestra_iconos_sin_n_mas_1(): void
    {
        $u = $this->usuario();
        $this->actingAs($u);
        $primero = Pedido::factory()->create(['oc_cliente_path' => 'pedidos/oc-cliente/x.pdf']);
        $this->archivo($primero, 'factura_pdf', $u, 'pdf');
        $this->archivo($primero, 'evidencia', $u);
        $this->archivo($primero, 'evidencia', $u);

        $consultas = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(Tablero::class);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        // Insignia 📎 = OC del cliente + factura + 2 evidencias (como el clip de Trello).
        Livewire::test(Tablero::class)->assertSee('📎 4')->assertSee('🧾')->assertSee('Evidencia de entrega');
        $antes = $consultas();

        foreach (range(1, 5) as $i) {
            $p = Pedido::factory()->create(['oc_cliente_path' => 'pedidos/oc-cliente/y.pdf']);
            $this->archivo($p, 'evidencia', $u);
            $this->archivo($p, 'factura_xml', $u, 'pdf');
        }
        $this->assertSame($antes, $consultas());
    }

    private function pdfConAutor(string $autor, string $titulo, string $texto = 'x'): string
    {
        $m = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        $m->SetTitle($titulo);
        $m->SetAuthor($autor);
        $m->WriteHTML($texto);

        return $m->Output('', 'S');
    }

    public function test_reemplazar_y_quitar_la_oc_desde_el_expediente(): void
    {
        $u = $this->usuario();
        $this->actingAs($u);
        $pedido = Pedido::factory()->create(['oc_cliente_path' => 'pedidos/oc-cliente/vieja.pdf']);
        Storage::disk('local')->put('pedidos/oc-cliente/vieja.pdf', '%PDF-1.4 vieja');

        $buena = UploadedFile::fake()->createWithContent('oc.pdf', $this->pdfConAutor('HOTEL', 'Orden de compra 4509'));
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->assertSee('OC del cliente')->assertSee('Facturas y notas')
            ->set('nuevaOc', $buena)->assertSet('error', '')->assertSee('Se reemplazó el archivo de la OC del cliente.');
        $ruta = $pedido->fresh()->oc_cliente_path;
        $this->assertNotSame('pedidos/oc-cliente/vieja.pdf', $ruta);
        Storage::disk('local')->assertMissing('pedidos/oc-cliente/vieja.pdf');

        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->call('quitarOc')->assertSee('Se quitó el archivo');
        $this->assertNull($pedido->fresh()->oc_cliente_path);
        Storage::disk('local')->assertMissing($ruta);
        $this->assertDatabaseHas('pedido_actividades', ['pedido_id' => $pedido->id, 'descripcion' => 'quitó el archivo de la OC del cliente']);
    }

    public function test_no_deja_subir_nuestra_cotizacion_ni_una_factura_como_oc(): void
    {
        $this->actingAs($this->usuario());
        $pedido = Pedido::factory()->create(['oc_cliente_path' => null]);

        $cot = UploadedFile::fake()->createWithContent('OC 6265.pdf', $this->pdfConAutor('MASUME INDUSTRIAL', 'Cotización 8633'));
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->set('nuevaOc', $cot)
            ->assertSet('error', 'Ese PDF lo hizo nuestro sistema (Cotización 8633): no es la OC del cliente. Sube el archivo que mandó el cliente.');
        $this->assertNull($pedido->fresh()->oc_cliente_path);

        $fac = UploadedFile::fake()->createWithContent('oc.pdf', "%PDF-1.4\nFolio fiscal 6F9D1C2A-1B2C-4D5E-8F90-ABCDEF123456\n%%EOF");
        Livewire::test(Ver::class, ['pedido' => $pedido])->set('archivoOc', $fac)
            ->assertHasErrors('archivoOc');
        $this->assertNull($pedido->fresh()->oc_cliente_path);
    }

    public function test_la_oc_con_identificador_interno_del_pdf_no_se_toma_por_factura(): void
    {
        // Le pasó a Daniel con una OC de DELTA: el programa que la hizo le pone "uuid:…" en los metadatos (como a todo PDF).
        $this->actingAs($this->usuario());
        $pedido = Pedido::factory()->create(['oc_cliente_path' => null]);
        $xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><pdf:Producer>WPTools</pdf:Producer>'
            .'<xmpMM:DocumentID>uuid:acf8b35c-f53a-4e9c-bd69-9f615b3519f8</xmpMM:DocumentID></x:xmpmeta>';
        $oc = "%PDF-1.4\n1 0 obj<</Type/Metadata>>stream\n".$xmp."\nendstream\nendobj\n"
            ."2 0 obj<<>>stream\n".gzcompress('BT (PURCHASE ORDER MX40037376) Tj ET')."\nendstream\nendobj\n%%EOF";
        $this->assertNull(DocumentoPropio::motivoNoEsOc($oc, 'MX40037376_MASUME_2026_MANTTO.pdf'));
        // Aunque venga comprimido o sin el bloque completo, el "uuid:" del archivo tampoco cuenta.
        $this->assertNull(FacturaArchivo::leer("%PDF-1.4\n<xmpMM:InstanceID>uuid:e783a238-92d0-487f-ac4b-f081d22d15b0</xmpMM:InstanceID>", 'factura_pdf')['uuid']);
        $this->assertNull(FacturaArchivo::leer('%PDF-1.4 /DocID (uuid:e783a238-92d0-487f-ac4b-f081d22d15b0)', 'factura_pdf')['uuid']);

        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->set('nuevaOc', UploadedFile::fake()->createWithContent('MX40037376_MASUME_2026_MANTTO.pdf', $oc))
            ->assertSet('error', null);
        $this->assertNotNull($pedido->fresh()->oc_cliente_path);

        // Una factura de verdad (folio fiscal en su texto) se sigue deteniendo, aunque también traiga metadatos.
        $fac = "%PDF-1.4\n".$xmp."\nFolio fiscal 6F9D1C2A-1B2C-4D5E-8F90-ABCDEF123456\n%%EOF";
        $this->assertStringContainsString('6F9D1C2A-1B2C-4D5E-8F90-ABCDEF123456', DocumentoPropio::motivoNoEsOc($fac));
    }

    public function test_campo_no_reemplaza_ni_quita_la_oc(): void
    {
        $this->actingAs($this->usuario('campo'));
        $pedido = Pedido::factory()->create(['oc_cliente_path' => 'pedidos/oc-cliente/x.pdf']);
        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])->assertDontSee('wire:click="quitarOc"', false)
            ->call('quitarOc')->assertForbidden();
    }

    public function test_factura_de_sicar_y_sus_archivos_salen_juntos(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        $this->actingAs($admin);
        $pedido = Pedido::factory()->create();
        $venta = Venta::create(['tipo' => 'factura', 'folio' => '5-5077', 'folio_llave' => Venta::llave('factura', '5-5077'), 'fecha' => '2026-10-02',
            'cliente_nombre' => 'HOTEL', 'subtotal' => 100, 'iva' => 16, 'total' => 5482.74, 'costo' => 50, 'estatus' => 'pendiente', 'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);
        Trazabilidad::ligarPedido($pedido, $venta);
        PedidoArchivo::create(['pedido_id' => $pedido->id, 'tipo' => 'factura_xml', 'disco' => 'local', 'ruta' => 'x/a.xml', 'nombre_original' => 'FacCFDI_5-5077.xml',
            'mime' => 'text/xml', 'tamano' => 10, 'user_id' => $admin->id, 'cfdi_uuid' => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE', 'cfdi_folio' => '5-5077']);
        // El PDF de la misma factura (mismo nombre) aunque no se haya leído su folio.
        PedidoArchivo::create(['pedido_id' => $pedido->id, 'tipo' => 'factura_pdf', 'disco' => 'local', 'ruta' => 'x/a.pdf', 'nombre_original' => 'FacCFDI_5-5077.pdf',
            'mime' => 'application/pdf', 'tamano' => 10, 'user_id' => $admin->id]);
        PedidoArchivo::create(['pedido_id' => $pedido->id, 'tipo' => 'factura_xml', 'disco' => 'local', 'ruta' => 'x/b.xml', 'nombre_original' => 'otra.xml',
            'mime' => 'text/xml', 'tamano' => 10, 'user_id' => $admin->id, 'cfdi_folio' => '5-6000']);

        $t = Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->assertSee('Factura 5-5077')->assertSee('Pendiente de cobro')
            ->assertSee('Factura 5-6000')->assertSee('Esperando que llegue de SICAR');
        $grupos = $t->viewData('gruposFacturas');
        $this->assertCount(2, $grupos);
        $this->assertSame(2, $grupos[0]['archivos']->count());
        $this->assertSame([], $grupos[0]['falta']);
    }

    public function test_la_liga_de_la_oc_cambia_al_reemplazarla_y_no_se_guarda_en_cache(): void
    {
        $this->actingAs($this->usuario());
        $pedido = Pedido::factory()->create(['oc_cliente_path' => 'pedidos/oc-cliente/vieja.pdf']);
        Storage::disk('local')->put('pedidos/oc-cliente/vieja.pdf', '%PDF-1.4 vieja');
        $antes = $pedido->urlOc();

        $this->get($antes)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        Livewire::test(Expediente::class, ['pedidoId' => $pedido->id])
            ->set('nuevaOc', UploadedFile::fake()->createWithContent('oc.pdf', $this->pdfConAutor('HOTEL', 'OC 1')))
            ->assertSee(e($pedido->fresh()->urlOc()), false);
        $this->assertNotSame($antes, $pedido->fresh()->urlOc());
    }
}

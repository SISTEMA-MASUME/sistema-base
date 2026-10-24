<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\Editor;
use App\Livewire\Solicitudes\Index;
use App\Livewire\Solicitudes\Nueva;
use App\Livewire\Solicitudes\Ver;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\IaConsumo;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use App\Support\CompartirDocumento;
use App\Support\Folios;
use App\Support\LectorXlsx;
use App\Support\ProductosCotizados;
use App\Support\Solicitudes;
use App\Support\TablaArchivo;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Bandeja "Por cotizar". Nunca llama a la API real: la IA va con Http::fake(). */
class SolicitudesCotizacionTest extends TestCase
{
    use RefreshDatabase;

    private const POST = 'api.openai.com/v1/responses';

    private const GET = 'api.openai.com/v1/responses/*';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.openai.key' => 'sk-test', 'services.openai.modelo' => 'gpt-6-luna', 'services.openai.tope_mensual_usd' => 10.0]);
    }

    private function vendedor(string $nombre = 'Rosa'): User
    {
        return User::factory()->create(['name' => $nombre, 'rol' => 'vendedor']);
    }

    private function solicitud(array $o = []): SolicitudCotizacion
    {
        $cliente = array_key_exists('cliente_id', $o) ? null : Cliente::where('nombre', 'KAPPA')->first() ?? Cliente::factory()->create(['nombre' => 'KAPPA']);

        return SolicitudCotizacion::create($o + [
            'folio' => Folios::siguiente('solicitud'),
            'cliente_id' => $cliente?->id,
            'canal' => 'whatsapp',
            'recibida_at' => now()->subHour(),
            'detalle' => 'Necesita brocas y discos',
            'partidas' => [
                ['cantidad' => 10, 'unidad' => 'PZA', 'descripcion' => 'BROCA 1/4', 'modelo' => 'B-14'],
                ['cantidad' => 2, 'unidad' => 'CJA', 'descripcion' => 'DISCO DE CORTE 4 1/2', 'modelo' => ''],
            ],
            'estatus' => 'pendiente',
        ]);
    }

    private function respuestaIa(array $datos): array
    {
        return [
            'id' => 'resp_1', 'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($datos)]]]],
            'usage' => ['input_tokens' => 3000, 'output_tokens' => 500],
        ];
    }

    // ---------------------------------------------------------------- alta

    public function test_crear_solicitud_con_partidas_y_folio_propio(): void
    {
        $u = $this->vendedor();
        $cliente = Cliente::where('nombre', 'KAPPA')->first() ?? Cliente::factory()->create(['nombre' => 'KAPPA']);

        Livewire::actingAs($u)->test(Nueva::class)
            ->call('seleccionarCliente', $cliente->id)
            ->set('canal', 'correo')
            ->set('recibida', '2026-10-01T10:32')
            ->set('detalle', 'Lo pidieron por correo')
            ->set('partidas', [['cantidad' => '5', 'unidad' => 'PZA', 'descripcion' => 'LIMA PLANA', 'modelo' => ''], ['cantidad' => '', 'unidad' => '', 'descripcion' => '', 'modelo' => '']])
            ->call('guardar')
            ->assertHasNoErrors();

        $s = SolicitudCotizacion::firstOrFail();
        $this->assertSame('S-1', $s->folioTexto());
        $this->assertSame($cliente->id, $s->cliente_id);
        $this->assertSame('pendiente', $s->estatus);
        $this->assertSame('2026-10-01 10:32', $s->recibida_at->format('Y-m-d H:i'));
        $this->assertCount(1, $s->partidas);
        $this->assertSame($u->id, $s->creada_por);
        $this->assertSame(1, $s->eventos()->where('tipo', 'creada')->count());
    }

    public function test_pide_cliente_o_nombre(): void
    {
        Livewire::actingAs($this->vendedor())->test(Nueva::class)
            ->set('detalle', 'algo')
            ->call('guardar')
            ->assertHasErrors('clienteTexto')
            // El aviso también sale junto al botón y la pantalla sube a él (en pantallas chicas parecía trabado).
            ->assertDispatched('mostrar-error')->assertSee('No se guardó: Escoge el cliente o escribe su nombre.');
        $this->assertSame(0, SolicitudCotizacion::count());

        Livewire::actingAs($this->vendedor('Alberto'))->test(Nueva::class)->set('clienteTexto', 'CLIENTE SIN NADA')->call('guardar')
            ->assertDispatched('mostrar-error')->assertSee('No se guardó: Anota qué pidió el cliente');
    }

    public function test_sube_pdf_e_imagen_y_se_descargan_solo_con_sesion(): void
    {
        $u = $this->vendedor();
        $c = Livewire::actingAs($u)->test(Nueva::class)
            ->set('clienteTexto', 'CLIENTE NUEVO SA')
            ->set('archivos', [UploadedFile::fake()->createWithContent('lista.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")])
            ->call('guardar');
        $s = SolicitudCotizacion::firstOrFail();
        $a = $s->archivos()->firstOrFail();
        Storage::disk('local')->assertExists($a->ruta);

        $this->actingAs($u)->get(route('solicitudes.archivo', $a))->assertOk();
        auth()->logout();
        $this->get(route('solicitudes.archivo', $a))->assertRedirect();
    }

    // ---------------------------------------------------------------- IA

    public function test_ia_llena_partidas_agrega_sin_borrar_y_encuentra_cliente(): void
    {
        $kappa = Cliente::factory()->create(['nombre' => 'KAPPA']);
        $s = $this->solicitud(['cliente_id' => null, 'cliente_texto' => null, 'partidas' => [['cantidad' => 1, 'unidad' => 'PZA', 'descripcion' => 'A MANO', 'modelo' => '']]]);
        $s->archivos()->create(['ruta' => 'solicitudes/x.pdf', 'nombre' => 'x.pdf', 'mime' => 'application/pdf', 'bytes' => 10]);
        Storage::disk('local')->put('solicitudes/x.pdf', '%PDF-1.4 falso');

        Http::fake([
            self::POST => Http::response(['id' => 'resp_1', 'status' => 'queued'], 200),
            self::GET => Http::sequence()
                ->push(['id' => 'resp_1', 'status' => 'in_progress'], 200)
                ->push($this->respuestaIa([
                    'cliente_nombre' => 'Kappa.', 'contacto' => 'Ing. Pérez',
                    'partidas' => [
                        ['cantidad' => 10, 'unidad' => 'PZA', 'descripcion' => 'BROCA 1/4', 'modelo' => 'B-14'],
                        ['cantidad' => null, 'unidad' => null, 'descripcion' => 'GUANTES', 'modelo' => null],
                    ],
                    'notas' => 'Urgente',
                ]), 200),
        ]);

        $c = Livewire::actingAs($this->vendedor())->test(Nueva::class, ['solicitud' => $s])
            ->call('leerConIa');
        $this->assertSame('leyendo', $s->fresh()->ia_estado);
        $c->call('consultarIa');                       // sigue leyendo
        $this->assertSame('leyendo', $s->fresh()->ia_estado);
        $c->call('consultarIa');                       // termina

        $s->refresh();
        $this->assertSame('lista', $s->ia_estado);
        $this->assertCount(3, $s->partidas);           // la de a mano + 2 de la IA
        $this->assertSame('A MANO', $s->partidas[0]['descripcion']);
        $this->assertSame($kappa->id, $s->cliente_id);
        $this->assertSame('Ing. Pérez', $s->contacto_texto);
        $this->assertStringContainsString('Urgente', $s->detalle);
        $this->assertNotNull($s->archivos()->first()->leido_at);
        $this->assertGreaterThan(0, (float) IaConsumo::sum('costo_usd'));
        $c->assertSet('clienteId', $kappa->id)->assertCount('partidas', 3);
    }

    /** .xlsx mínimo con una hoja. */
    private function excelSolicitud(array $filas): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'sol').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $comp = [];
        $xml = '';
        foreach ($filas as $r => $fila) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach ($fila as $c => $v) {
                $celda = LectorXlsx::letra($c).($r + 1);
                if (is_int($v) || is_float($v)) {
                    $xml .= "<c r=\"$celda\"><v>$v</v></c>";
                } else {
                    $comp[] = htmlspecialchars($v, ENT_XML1);
                    $xml .= "<c r=\"$celda\" t=\"s\"><v>".(count($comp) - 1).'</v></c>';
                }
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Requisicion" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($x) => "<si><t>$x</t></si>", $comp)).'</sst>');
        $zip->close();

        return $ruta;
    }

    public function test_excel_y_csv_se_suben_y_la_ia_los_lee_como_texto(): void
    {
        $xlsx = $this->excelSolicitud([['CANT', 'DESCRIPCION', 'MODELO'], [10, 'BROCA 1/4 COBALTO', 'B-14'], [2.5, 'CINTA TEFLON', null]]);
        $u = $this->vendedor();
        $c = Livewire::actingAs($u)->test(Nueva::class)->set('clienteTexto', 'CLIENTE EXCEL SA')
            ->set('archivos', [
                UploadedFile::fake()->createWithContent('requisicion.xlsx', file_get_contents($xlsx)),
                UploadedFile::fake()->createWithContent('lista.csv', "Cantidad;Producto\n3;GUANTE NITRILO\n"),
            ])->assertHasNoErrors()->call('guardar');
        @unlink($xlsx);
        $s = SolicitudCotizacion::firstOrFail();
        $this->assertSame(2, $s->archivos()->count());
        $this->assertTrue($s->archivos()->where('nombre', 'requisicion.xlsx')->where('mime', TablaArchivo::MIMES['xlsx'])->exists());

        // El texto que va a la IA trae las filas del Excel.
        $a = $s->archivos()->where('nombre', 'requisicion.xlsx')->first();
        $texto = TablaArchivo::comoTexto(Storage::disk('local')->path($a->ruta), $a->mime);
        $this->assertStringContainsString("10\tBROCA 1/4 COBALTO\tB-14", $texto);
        $this->assertStringContainsString('2.5', $texto);
        $csv = $s->archivos()->where('nombre', 'lista.csv')->first();
        $this->assertStringContainsString("3\tGUANTE NITRILO", TablaArchivo::comoTexto(Storage::disk('local')->path($csv->ruta), $csv->mime));

        Http::fake([
            self::POST => Http::response(['id' => 'resp_x', 'status' => 'queued'], 200),
            self::GET => Http::response($this->respuestaIa(['cliente_nombre' => null, 'contacto' => null, 'notas' => null,
                'partidas' => [['cantidad' => 10, 'unidad' => 'PZA', 'descripcion' => 'BROCA 1/4 COBALTO', 'modelo' => 'B-14']]]), 200),
        ]);
        $ln = Livewire::actingAs($u)->test(Nueva::class, ['solicitud' => $s])->call('leerConIa');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains(json_encode($r->data()), 'COBALTO')
            && ! str_contains(json_encode($r->data()), 'input_file'));
        $ln->call('consultarIa');
        $this->assertSame('BROCA 1/4 COBALTO', $s->fresh()->partidas[0]['descripcion']);
        $this->assertSame([], Storage::disk('local')->files('solicitudes/tmp'));   // el texto temporal se borra
    }

    public function test_excel_viejo_xls_no_se_acepta(): void
    {
        Livewire::actingAs($this->vendedor())->test(Nueva::class)
            ->set('archivos', [UploadedFile::fake()->create('viejo.xls', 10)])
            ->assertHasErrors('archivos.0')->assertSee('guárdalo como .xlsx');
    }

    public function test_ia_con_cliente_desconocido_guarda_el_texto(): void
    {
        $s = $this->solicitud(['cliente_id' => null]);
        $s->archivos()->create(['ruta' => 'solicitudes/x.jpg', 'nombre' => 'x.jpg', 'mime' => 'image/jpeg', 'bytes' => 10]);
        Storage::disk('local')->put('solicitudes/x.jpg', 'jpg falso');
        Http::fake([
            self::POST => Http::response(['id' => 'resp_1', 'status' => 'queued'], 200),
            self::GET => Http::response($this->respuestaIa(['cliente_nombre' => 'Taller Nuevo', 'contacto' => null, 'partidas' => [], 'notas' => null]), 200),
        ]);
        Livewire::actingAs($this->vendedor())->test(Nueva::class, ['solicitud' => $s])->call('leerConIa')->call('consultarIa');

        $s->refresh();
        $this->assertNull($s->cliente_id);
        $this->assertSame('TALLER NUEVO', $s->cliente_texto);
        // PDF y fotos usan el modelo que corresponde a su tipo.
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['model'] === 'gpt-6-sol');
    }

    public function test_ia_con_error_no_rompe_la_solicitud(): void
    {
        $s = $this->solicitud();
        $s->archivos()->create(['ruta' => 'solicitudes/x.pdf', 'nombre' => 'x.pdf', 'mime' => 'application/pdf', 'bytes' => 10]);
        Storage::disk('local')->put('solicitudes/x.pdf', '%PDF-1.4 falso');
        Http::fake([
            self::POST => Http::response(['id' => 'resp_1', 'status' => 'queued'], 200),
            self::GET => Http::response(['id' => 'resp_1', 'status' => 'failed', 'error' => ['message' => 'boom']], 200),
        ]);

        $c = Livewire::actingAs($this->vendedor())->test(Nueva::class, ['solicitud' => $s])->call('leerConIa')->call('consultarIa');

        $s->refresh();
        $this->assertSame('error', $s->ia_estado);
        $this->assertStringContainsString('No se pudo leer', $s->ia_mensaje);
        $this->assertCount(2, $s->partidas);
        $c->assertSee('No se pudo leer')->call('guardar')->assertRedirect(route('solicitudes.ver', $s));
    }

    public function test_ia_respeta_el_tope_mensual(): void
    {
        config(['services.openai.tope_mensual_usd' => 0.0]);
        Http::fake();
        $s = $this->solicitud();
        $s->archivos()->create(['ruta' => 'solicitudes/x.pdf', 'nombre' => 'x.pdf', 'mime' => 'application/pdf', 'bytes' => 10]);
        Storage::disk('local')->put('solicitudes/x.pdf', '%PDF-1.4 falso');

        Livewire::actingAs($this->vendedor())->test(Nueva::class, ['solicitud' => $s])->call('leerConIa');

        $this->assertSame('error', $s->fresh()->ia_estado);
        $this->assertStringContainsString('tope mensual', $s->fresh()->ia_mensaje);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- tomar

    public function test_tomar_crea_cotizacion_con_folio_partidas_sin_costo_y_vendedor(): void
    {
        $rosa = $this->vendedor();
        $s = $this->solicitud();

        $cot = Solicitudes::tomar($s, $rosa);

        $s->refresh();
        $this->assertSame('en_proceso', $s->estatus);
        $this->assertSame($rosa->id, $s->tomada_por);
        $this->assertSame($cot->id, $s->cotizacion_id);
        $this->assertNotNull($s->tomada_at);
        $this->assertSame($rosa->id, $cot->vendedor_id);
        $this->assertSame($s->cliente_id, $cot->cliente_id);
        $this->assertSame('borrador', $cot->estado->value);
        $this->assertNotEmpty($cot->folio);
        $this->assertNull($cot->notas);   // las notas salen en el PDF del cliente
        $p = $cot->partidas;
        $this->assertCount(2, $p);
        $this->assertSame('BROCA 1/4', $p[0]->descripcion);
        $this->assertSame('B-14', $p[0]->modelo);
        $this->assertEquals(10, $p[0]->cantidad);
        $this->assertNull($p[0]->costo);
        $this->assertNull($p[0]->proveedor_id);
        $this->assertNull($p[1]->modelo);
        $this->assertSame($s->id, Cotizacion::find($cot->id)->solicitud->id);
    }

    public function test_tomar_sin_cliente_dado_de_alta_pide_escogerlo(): void
    {
        $s = $this->solicitud(['cliente_id' => null, 'cliente_texto' => 'TALLER NUEVO']);
        try {
            Solicitudes::tomar($s, $this->vendedor());
            $this->fail('Debió pedir el cliente');
        } catch (DomainException $e) {
            $this->assertStringContainsString('cliente', $e->getMessage());
        }
        $this->assertSame('pendiente', $s->fresh()->estatus);
        $this->assertSame(0, Cotizacion::count());

        // Desde la pantalla se da de alta con el nombre escrito y ya se puede tomar.
        Livewire::actingAs($this->vendedor())->test(Ver::class, ['solicitud' => $s])->call('darDeAltaCliente')->assertHasNoErrors();
        $this->assertNotNull($s->fresh()->cliente_id);
        $this->assertSame('TALLER NUEVO', $s->fresh()->cliente->nombre);
    }

    public function test_dos_vendedores_toman_la_misma_el_segundo_recibe_ya_la_esta_trabajando(): void
    {
        $rosa = $this->vendedor('Rosa');
        $alberto = $this->vendedor('Alberto');
        $s = $this->solicitud();
        // Los dos abrieron la pantalla cuando seguía pendiente (modelos "viejos" en memoria).
        $paraRosa = SolicitudCotizacion::find($s->id);
        $paraAlberto = SolicitudCotizacion::find($s->id);

        Solicitudes::tomar($paraRosa, $rosa);
        try {
            Solicitudes::tomar($paraAlberto, $alberto);
            $this->fail('El segundo no debió poder tomarla');
        } catch (DomainException $e) {
            $this->assertSame('Ya la está trabajando Rosa.', $e->getMessage());
        }
        $this->assertSame(1, Cotizacion::count());
        $this->assertSame($rosa->id, $s->fresh()->tomada_por);

        // Y por pantalla.
        Livewire::actingAs($alberto)->test(Ver::class, ['solicitud' => $s])->call('tomar')->assertSee('Ya la está trabajando Rosa.');
        $this->assertSame(1, Cotizacion::count());
    }

    // ---------------------------------------------------------------- soltar, reasignar, descartar

    public function test_soltar_solo_quien_la_tomo_o_admin_y_no_borra_la_cotizacion(): void
    {
        $rosa = $this->vendedor('Rosa');
        $otro = $this->vendedor('Alberto');
        $admin = User::factory()->create(['rol' => 'admin']);
        $s = $this->solicitud();
        $cot = Solicitudes::tomar($s, $rosa);

        try {
            Solicitudes::soltar($s, $otro);
            $this->fail('Otro vendedor no puede soltarla');
        } catch (DomainException) {
        }
        $this->assertSame('en_proceso', $s->fresh()->estatus);

        Solicitudes::soltar($s, $rosa);
        $s->refresh();
        $this->assertSame('pendiente', $s->estatus);
        $this->assertNull($s->tomada_por);
        $this->assertNull($s->cotizacion_id);
        $this->assertNotNull(Cotizacion::find($cot->id));   // el borrador se conserva

        Solicitudes::tomar($s, $otro);
        Solicitudes::soltar($s->fresh(), $admin);            // el admin sí puede
        $this->assertSame('pendiente', $s->fresh()->estatus);
    }

    public function test_reasignar_solo_admin_cambia_dueno_y_vendedor_de_la_cotizacion(): void
    {
        $rosa = $this->vendedor('Rosa');
        $alberto = $this->vendedor('Alberto');
        $admin = User::factory()->create(['rol' => 'admin']);
        $s = $this->solicitud();
        $cot = Solicitudes::tomar($s, $rosa);

        try {
            Solicitudes::reasignar($s, $rosa, $alberto);
            $this->fail('Un vendedor no reasigna');
        } catch (DomainException) {
        }
        Livewire::actingAs($admin)->test(Ver::class, ['solicitud' => $s])->set('reasignarA', (string) $alberto->id)->call('reasignar')->assertSee('Reasignada a Alberto');

        $this->assertSame($alberto->id, $s->fresh()->tomada_por);
        $this->assertSame($alberto->id, $cot->fresh()->vendedor_id);
        $this->assertSame(1, $s->eventos()->where('tipo', 'reasignada')->count());
    }

    public function test_descartar_pide_motivo(): void
    {
        $u = $this->vendedor();
        $s = $this->solicitud();
        $c = Livewire::actingAs($u)->test(Ver::class, ['solicitud' => $s]);
        $c->set('motivo', 'no')->call('descartar')->assertSee('mínimo 5 letras');
        $this->assertSame('pendiente', $s->fresh()->estatus);

        $c->set('motivo', 'Ya la compró en otro lado')->call('descartar');
        $this->assertSame('descartada', $s->fresh()->estatus);
        $this->assertSame('Ya la compró en otro lado', $s->fresh()->motivo_descarte);
    }

    // ---------------------------------------------------------------- cotizada y tiempos

    public function test_al_registrar_el_primer_envio_queda_cotizada_y_se_calculan_los_minutos(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $rosa = $this->vendedor();
        $s = $this->solicitud(['recibida_at' => '2026-10-01 08:00:00']);

        Carbon::setTestNow('2026-10-01 08:30:00');
        $cot = Solicitudes::tomar($s, $rosa);
        $this->assertSame(30, $s->fresh()->minutosHastaTomar());
        $this->assertNull($s->fresh()->minutosHastaEnviar());

        Carbon::setTestNow('2026-10-01 10:15:00');
        CompartirDocumento::registrar($cot, 'whatsapp', null, $rosa->id);
        Carbon::setTestNow('2026-10-02 09:00:00');
        CompartirDocumento::registrar($cot->fresh(), 'correo', 'a@b.com', $rosa->id);   // el segundo envío no mueve el tiempo

        $s->refresh();
        $this->assertSame('cotizada', $s->estatus);
        $this->assertSame(135, $s->minutosHastaEnviar());   // 2 h 15 min
        $this->assertSame('2 h 15 min', Solicitudes::humano($s->minutosHastaEnviar()));
        $this->assertSame(1, $s->eventos()->where('tipo', 'cotizada')->count());
        Carbon::setTestNow();
    }

    public function test_abrir_el_enlace_no_cuenta_como_envio(): void
    {
        $s = $this->solicitud();
        $u = $this->vendedor();
        $cot = Solicitudes::tomar($s, $u);
        CompartirDocumento::registrar($cot, 'enlace', null, $u->id);
        $this->assertSame('en_proceso', $s->fresh()->estatus);
        $this->assertNull($s->fresh()->minutosHastaEnviar());
    }

    public function test_formato_humano_de_tiempos(): void
    {
        $this->assertSame('45 min', Solicitudes::humano(45));
        $this->assertSame('1 h', Solicitudes::humano(60));
        $this->assertSame('1 día 3 h', Solicitudes::humano(1440 + 180));
        $this->assertSame('2 días', Solicitudes::humano(2880));
        $this->assertSame('—', Solicitudes::humano(null));
    }

    public function test_metricas_tambien_cuentan_las_cotizaciones_hechas_directo(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $rosa = $this->vendedor('Rosa');
        $alberto = $this->vendedor('Alberto');
        // Rosa: una desde Por cotizar (60 min).
        $s = $this->solicitud(['recibida_at' => '2026-10-05 08:00:00']);
        Carbon::setTestNow('2026-10-05 08:10:00');
        $cot = Solicitudes::tomar($s, $rosa);
        Carbon::setTestNow('2026-10-05 09:00:00');
        CompartirDocumento::registrar($cot, 'whatsapp', null, $rosa->id);
        // Alberto: dos directas (30 y 90 min de creada a enviada) y una sin enviar; otra de septiembre no cuenta.
        $cliente = Cliente::factory()->create();
        foreach ([['2026-10-06 10:00:00', 30], ['2026-10-07 10:00:00', 90], ['2026-10-08 10:00:00', null], ['2026-09-20 10:00:00', 15]] as [$creada, $min]) {
            Carbon::setTestNow($creada);
            $c = Cotizacion::factory()->create(['cliente_id' => $cliente->id, 'vendedor_id' => $alberto->id, 'estado' => 'borrador']);
            // Cada directa: 2 partidas con costo (100 × 2 a $150 → utilidad 100) y 1 sin costo ($50); una excluida no cuenta.
            CotizacionPartida::factory()->create(['cotizacion_id' => $c->id, 'orden' => 1, 'cantidad' => 2, 'costo' => 100, 'precio' => 150, 'importe' => 300, 'incluir' => true]);
            CotizacionPartida::factory()->create(['cotizacion_id' => $c->id, 'orden' => 2, 'cantidad' => 1, 'costo' => 10, 'precio' => 20, 'importe' => 20, 'incluir' => true]);
            CotizacionPartida::factory()->create(['cotizacion_id' => $c->id, 'orden' => 3, 'cantidad' => 1, 'costo' => null, 'precio' => 50, 'importe' => 50, 'incluir' => true]);
            CotizacionPartida::factory()->create(['cotizacion_id' => $c->id, 'orden' => 4, 'cantidad' => 9, 'costo' => 1, 'precio' => 99, 'importe' => 891, 'incluir' => false]);
            if ($min !== null) {
                Carbon::setTestNow(Carbon::parse($creada)->addMinutes($min));
                CompartirDocumento::registrar($c, 'correo', 'cliente@ejemplo.com', $alberto->id);
            }
        }
        Carbon::setTestNow('2026-10-20 12:00:00');

        $m = Solicitudes::metricas('2026-10');

        $this->assertSame(1, $m['cotizadas']);            // las de Por cotizar no cambian
        $this->assertSame(2, $m['directas']);
        $this->assertEqualsWithDelta(60, $m['promedio_directas'], 0.01);
        $abr = collect($m['vendedores'])->firstWhere('nombre', 'Alberto');
        $this->assertSame(['cotizadas' => 0, 'directas' => 2, 'total' => 2], ['cotizadas' => $abr['cotizadas'], 'directas' => $abr['directas'], 'total' => $abr['total']]);
        $this->assertEqualsWithDelta(60, $abr['promedio_directas'], 0.01);
        // 2 directas enviadas × (3 partidas, $370, utilidad 110, 1 sin costo).
        $this->assertSame([6, 740.0, 220.0, 2], [$abr['partidas'], $abr['monto'], $abr['utilidad'], $abr['partidas_sin_costo']]);
        $rub = collect($m['vendedores'])->firstWhere('nombre', 'Rosa');
        $this->assertSame([1, 0], [$rub['cotizadas'], $rub['directas']]);

        Livewire::actingAs($rosa)->withQueryParams(['pestana' => 'tiempos'])->test(Index::class)
            ->assertSee('Directo en Cotizaciones: 2')->assertSee('Hechas directo en Cotizaciones')
            ->assertSee('$740.00')->assertSee('$220.00')->assertSee('29.7%')->assertSee('2 sin costo')
            ->assertDontSee('Trabajo por día');
    }

    public function test_metricas_por_perfil_aunque_la_cotizacion_y_el_pedido_digan_otro_nombre(): void
    {
        Carbon::setTestNow('2026-10-06 10:00:00');
        $alberto = $this->vendedor('Alberto');
        $rosa = $this->vendedor('Rosa');
        $ivonne = $this->vendedor('Ivonne');
        $cliente = Cliente::factory()->create();

        // Cotización directa con "Elaboró" = Ivonne, pero la envió Alberto desde su perfil → cuenta para Alberto.
        $c = Cotizacion::factory()->create(['cliente_id' => $cliente->id, 'vendedor_id' => $ivonne->id, 'estado' => 'borrador']);
        CotizacionPartida::factory()->create(['cotizacion_id' => $c->id, 'orden' => 1, 'cantidad' => 1, 'costo' => 80, 'precio' => 100, 'importe' => 100, 'incluir' => true]);
        Carbon::setTestNow('2026-10-06 10:20:00');
        CompartirDocumento::registrar($c, 'correo', 'cliente@ejemplo.com', $alberto->id);

        // Pedido a nombre de Ivonne (responsable), dado de alta por Rosa desde su perfil → cuenta para Rosa.
        Carbon::setTestNow('2026-10-07 11:00:00');
        $this->actingAs($rosa);
        $pedido = Pedido::factory()->create(['cliente_id' => $cliente->id, 'responsable_id' => $ivonne->id]);
        PedidoActividad::registrar($pedido, 'crear', 'creó la tarjeta');
        foreach ([[2, 150.0], [3, 100.0]] as $i => [$cant, $precio]) {
            PedidoPartida::factory()->create(['pedido_id' => $pedido->id, 'orden' => $i + 1, 'cantidad' => $cant, 'precio' => $precio, 'costo' => 50]);
        }
        $pedido->recalcularTotales();
        $prov = Proveedor::factory()->create();
        OrdenProveedor::create(['folio' => 1, 'pedido_id' => $pedido->id, 'proveedor_id' => $prov->id, 'user_id' => $rosa->id, 'metodo_pago' => 'TARJETA', 'estado' => 'emitida', 'total_costo' => 250]);
        OrdenProveedor::create(['folio' => 2, 'pedido_id' => $pedido->id, 'proveedor_id' => $prov->id, 'user_id' => $rosa->id, 'metodo_pago' => 'TARJETA', 'estado' => 'cancelada', 'total_costo' => 1]);
        // Sin "creó la tarjeta" anotado → al responsable (Alberto). Los de Trello y los cancelados no cuentan.
        Pedido::factory()->create(['cliente_id' => $cliente->id, 'responsable_id' => $alberto->id]);
        Pedido::factory()->create(['cliente_id' => $cliente->id, 'responsable_id' => $alberto->id, 'trello_card_id' => 'abc']);
        Pedido::factory()->create(['cliente_id' => $cliente->id, 'responsable_id' => $alberto->id, 'cancelado_at' => now()]);
        Carbon::setTestNow('2026-10-20 12:00:00');

        $m = Solicitudes::metricas('2026-10');
        $por = collect($m['vendedores'])->keyBy('nombre');
        $this->assertFalse($por->has('Ivonne'));
        $this->assertSame([1, 1, 1, 100.0], [$por['Alberto']['directas'], $por['Alberto']['total'], $por['Alberto']['partidas'], $por['Alberto']['monto']]);
        $this->assertSame([1, 2, 600.0, 1], [$por['Rosa']['pedidos'], $por['Rosa']['pedidos_partidas'], $por['Rosa']['pedidos_monto'], $por['Rosa']['ordenes']]);
        $this->assertSame(1, $por['Alberto']['pedidos']);
        $this->assertSame(2, $m['pedidos']);

        $dia = Solicitudes::porDia('2026-10');
        $this->assertSame(['cot' => 1, 'ped' => 0], collect($dia['dias'])->first(fn ($d) => $d['fecha']->isSameDay('2026-10-06'))['por']['Alberto']);
        $this->assertSame(['cot' => 0, 'ped' => 1], collect($dia['dias'])->first(fn ($d) => $d['fecha']->isSameDay('2026-10-07'))['por']['Rosa']);
        $this->assertSame('2026-10-20', $dia['dias'][0]['fecha']->format('Y-m-d'));   // el más reciente primero, hasta hoy
        $this->assertNotContains('Ivonne', $dia['personas']);

        // Trabajo por día: solo el admin.
        $admin = User::factory()->create(['name' => 'Daniel', 'rol' => 'admin']);
        Livewire::actingAs($admin)->withQueryParams(['pestana' => 'tiempos', 'mes' => '2026-10'])->test(Index::class)
            ->assertSee('Trabajo por día')->assertSee('Pedidos procesados')->assertSee('$600.00');
        Livewire::actingAs($rosa)->withQueryParams(['pestana' => 'tiempos', 'mes' => '2026-10'])->test(Index::class)
            ->assertDontSee('Trabajo por día')->assertSee('Pedidos procesados');
        Carbon::setTestNow();
    }

    public function test_metricas_promedio_y_mediana_por_vendedor(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $rosa = $this->vendedor('Rosa');
        $alberto = $this->vendedor('Alberto');
        // Tardanzas hasta enviar: 60, 120 y 600 min → promedio 260, mediana 120.
        foreach ([[$rosa, 60], [$rosa, 120], [$alberto, 600]] as [$u, $min]) {
            $s = $this->solicitud(['recibida_at' => '2026-10-05 08:00:00']);
            Carbon::setTestNow('2026-10-05 08:10:00');
            $cot = Solicitudes::tomar($s, $u);
            Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00')->addMinutes($min));
            CompartirDocumento::registrar($cot, 'whatsapp', null, $u->id);
        }
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->solicitud(['recibida_at' => '2026-10-10 09:00:00']);                              // pendiente
        $this->solicitud(['recibida_at' => '2026-09-10 09:00:00']);                              // otro mes
        $this->solicitud(['recibida_at' => '2026-10-11 09:00:00', 'estatus' => 'descartada']);

        $m = Solicitudes::metricas('2026-10');

        $this->assertSame(5, $m['llegaron']);
        $this->assertSame(3, $m['cotizadas']);
        $this->assertSame(1, $m['pendientes']);
        $this->assertSame(1, $m['descartadas']);
        $this->assertEqualsWithDelta(260, $m['promedio_enviar'], 0.01);
        $this->assertEqualsWithDelta(120, $m['mediana_enviar'], 0.01);
        $this->assertEqualsWithDelta(10, $m['promedio_tomar'], 0.01);
        $this->assertSame('Rosa', $m['vendedores'][0]['nombre']);
        $this->assertSame(2, $m['vendedores'][0]['cotizadas']);
        $this->assertEqualsWithDelta(90, $m['vendedores'][0]['promedio'], 0.01);

        Livewire::actingAs($rosa)->test(Index::class)->set('pestana', 'tiempos')->set('mes', '2026-10')->assertSee('Rosa')->assertSee('2 h');
        Carbon::setTestNow();
    }

    public function test_semaforo_de_pendientes(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $this->assertSame('verde', Solicitudes::semaforo($this->solicitud(['recibida_at' => '2026-10-01 09:00:00'])));
        $this->assertSame('amarillo', Solicitudes::semaforo($this->solicitud(['recibida_at' => '2026-10-01 07:00:00'])));
        $this->assertSame('rojo', Solicitudes::semaforo($this->solicitud(['recibida_at' => '2026-09-30 11:00:00'])));
        Carbon::setTestNow();
    }

    // ---------------------------------------------------------------- pantallas y permisos

    public function test_lista_pendientes_mas_viejas_primero_y_buscador(): void
    {
        $u = $this->vendedor();
        $nueva = $this->solicitud(['recibida_at' => now()->subHour(), 'detalle' => 'tornillos']);
        $vieja = $this->solicitud(['recibida_at' => now()->subDays(2), 'detalle' => 'cinta', 'partidas' => [['cantidad' => 1, 'unidad' => 'PZA', 'descripcion' => 'MARTILLO PENA', 'modelo' => '']]]);

        Livewire::actingAs($u)->test(Index::class)
            ->assertSeeInOrder([$vieja->folioTexto(), $nueva->folioTexto()])
            ->set('buscar', 'martillo')->assertSee($vieja->folioTexto())->assertDontSee($nueva->folioTexto());
    }

    public function test_en_proceso_muestra_quien_la_trabaja_y_deshabilita_a_otros(): void
    {
        $rosa = $this->vendedor('Rosa');
        $alberto = $this->vendedor('Alberto');
        $s = $this->solicitud();
        $cot = Solicitudes::tomar($s, $rosa);

        Livewire::actingAs($alberto)->test(Index::class, ['pestana' => 'en_proceso'])->set('pestana', 'en_proceso')
            ->assertSee('La está trabajando Rosa')->assertSee('cotización '.$cot->folio);
        Livewire::actingAs($alberto)->test(Ver::class, ['solicitud' => $s])->assertSee('La está trabajando Rosa');
    }

    public function test_el_editor_muestra_de_que_solicitud_viene(): void
    {
        $rosa = $this->vendedor();
        $s = $this->solicitud();
        $cot = Solicitudes::tomar($s, $rosa);

        Livewire::actingAs($rosa)->test(Editor::class, ['cotizacion' => $cot])->assertSee('Viene de la solicitud')->assertSee($s->folioTexto());
    }

    public function test_campo_y_ned_no_entran_pero_admin_y_vendedor_si(): void
    {
        $s = $this->solicitud();
        foreach (['campo', 'nedtracking'] as $rol) {
            $u = User::factory()->create(['rol' => $rol]);
            foreach (['/por-cotizar', '/por-cotizar/nueva', "/por-cotizar/{$s->id}"] as $ruta) {
                $r = $this->actingAs($u)->get($ruta);
                $this->assertContains($r->status(), [302, 403], "$rol en $ruta");
            }
        }
        foreach (['admin', 'vendedor'] as $rol) {
            $u = User::factory()->create(['rol' => $rol]);
            $this->actingAs($u)->get('/por-cotizar')->assertOk()->assertSee('Por cotizar');
            $this->actingAs($u)->get('/por-cotizar/nueva')->assertOk();
            $this->actingAs($u)->get("/por-cotizar/{$s->id}")->assertOk();
        }
    }

    public function test_menu_muestra_globito_con_pendientes_a_vendedores_y_no_a_campo(): void
    {
        $this->solicitud();
        $this->solicitud();
        $this->actingAs($this->vendedor())->get('/cotizaciones')->assertSee('Por cotizar');
        $this->actingAs(User::factory()->create(['rol' => 'campo']))->get('/pedidos')->assertDontSee('Por cotizar');
    }

    // ---------------------------------------------------------------- productos ya cotizados

    private function yaCotizada(string $descripcion, array $o = []): CotizacionPartida
    {
        $cot = Cotizacion::factory()->create(['cliente_id' => $o['cliente_id'] ?? Cliente::factory()]);
        unset($o['cliente_id']);

        return CotizacionPartida::create($o + [
            'cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => $descripcion, 'unidad' => 'PZA', 'cantidad' => 1,
            'costo' => 80, 'utilidad_pct' => 25, 'precio' => 100, 'importe' => 100,
        ]);
    }

    public function test_busca_por_modelo_ignorando_guiones_espacios_y_mayusculas(): void
    {
        $p = $this->yaCotizada('LIJADORA ORBITAL', ['modelo' => 'SPA-2800']);
        $this->yaCotizada('OTRA COSA', ['modelo' => 'SPA-2801']);

        foreach (['spa2800', 'SPA 2800', 'spa-2800.'] as $m) {
            $r = ProductosCotizados::buscar($m, 'algo que no se parece');
            $this->assertCount(1, $r, $m);
            $this->assertSame($p->id, $r[0]['partida_id']);
            $this->assertSame('mismo modelo', $r[0]['motivo']);
        }
    }

    public function test_busca_por_descripcion_con_palabras_de_mas_y_en_otro_orden(): void
    {
        $prov = Proveedor::factory()->create(['nombre' => 'TRUPER']);
        $p = $this->yaCotizada('Disco de corte metal 4-1/2 marca Dewalt', ['proveedor_id' => $prov->id, 'costo' => 42.5]);
        $this->yaCotizada('Disco de corte metal 7 pulgadas Dewalt');          // otra medida
        $this->yaCotizada('Broca 1/4 concreto');                              // otro producto
        $this->yaCotizada('Guantes de carnaza');

        $r = ProductosCotizados::buscar(null, 'DISCO CORTE 4 1/2 METAL DEWALT');

        $this->assertSame($p->id, $r[0]['partida_id']);
        $this->assertSame('TRUPER', $r[0]['proveedor']);
        $this->assertEquals(42.5, $r[0]['costo']);
        $this->assertSame('6 de 6 palabras', $r[0]['motivo']);
        $this->assertCount(1, $r);   // ni el disco de 7", ni la broca, ni los guantes
    }

    public function test_no_confunde_medidas_ni_productos(): void
    {
        $this->yaCotizada('BROCA 3/8 PARA CONCRETO');
        $this->yaCotizada('DISCO 1/4 DIAMANTE');

        $this->assertSame([], ProductosCotizados::buscar(null, 'BROCA 1/4 CONCRETO'));
        $this->assertSame([], ProductosCotizados::buscar(null, 'GUANTES DE NITRILO'));
        $this->assertSame([], ProductosCotizados::buscar(null, 'de la para'));   // solo palabras vacías
    }

    public function test_sin_duplicados_y_prefiere_el_mismo_cliente(): void
    {
        $kappa = Cliente::factory()->create();
        $this->yaCotizada('CINTA AISLANTE 19MM NEGRA');
        $mia = $this->yaCotizada('Cinta aislante 19mm negra', ['cliente_id' => $kappa->id]);   // misma descripción normalizada

        $r = ProductosCotizados::buscar(null, 'cinta aislante 19 mm negra', 5, $kappa->id);
        $this->assertCount(1, $r);
        $this->assertSame($mia->id, $r[0]['partida_id']);
    }

    public function test_elegir_sugerencia_liga_y_tomar_copia_costo_proveedor_y_recalcula_precio(): void
    {
        $prov = Proveedor::factory()->create();
        $origen = $this->yaCotizada('Disco de corte metal 4-1/2 marca Dewalt', [
            'modelo' => 'DW-44', 'marca' => 'DEWALT', 'proveedor_id' => $prov->id, 'proveedor_vendedor' => 'JUAN', 'proveedor_telefono' => '3312345678',
            'costo' => 40, 'utilidad_pct' => 50, 'precio' => 10, 'tiempo_entrega' => 'Inmediata', 'link_compra' => 'https://prov.mx/disco',
        ]);
        $cliente = Cliente::factory()->create();
        $s = $this->solicitud(['cliente_id' => $cliente->id, 'partidas' => [], 'detalle' => null]);
        $rosa = $this->vendedor();

        $c = Livewire::actingAs($rosa)->test(Nueva::class, ['solicitud' => $s])
            ->set('partidas', [
                ['cantidad' => '12', 'unidad' => 'PZA', 'descripcion' => 'DISCO CORTE 4 1/2 METAL DEWALT', 'modelo' => '', 'ligada' => null],
                ['cantidad' => '3', 'unidad' => 'PZA', 'descripcion' => 'PRODUCTO RARO NUEVO', 'modelo' => '', 'ligada' => null],
            ])
            ->call('sugerir')
            ->assertSee('¿Es este?')->assertSee('Sí, es este');
        $c->call('elegirSugerencia', 0, 0);
        $c->assertSet('partidas.0.descripcion', 'Disco de corte metal 4-1/2 marca Dewalt')->assertSet('partidas.0.cantidad', '12');
        $c->call('guardar');

        $this->assertSame($origen->id, $s->fresh()->partidas[0]['ligada']['partida_id']);
        $cot = Solicitudes::tomar($s->fresh(), $rosa);

        [$ligada, $nueva] = [$cot->partidas[0], $cot->partidas[1]];
        $this->assertEquals(12, $ligada->cantidad);
        $this->assertEquals(40, $ligada->costo);
        $this->assertEquals(50, $ligada->utilidad_pct);
        $this->assertEquals(60.00, $ligada->precio);        // 40 × 1.5, recalculado (no el 10 viejo)
        $this->assertEquals(720.00, $ligada->importe);
        $this->assertSame($prov->id, $ligada->proveedor_id);
        $this->assertSame('JUAN', $ligada->proveedor_vendedor);
        $this->assertSame('3312345678', $ligada->proveedor_telefono);
        $this->assertSame('Inmediata', $ligada->tiempo_entrega);
        $this->assertSame('https://prov.mx/disco', $ligada->link_compra);
        $this->assertSame('DEWALT', $ligada->marca);
        $this->assertNull($nueva->costo);                    // producto nuevo: sin costo ni proveedor
        $this->assertNull($nueva->proveedor_id);
        $this->assertEquals(720.00, $cot->fresh()->subtotal);
    }

    public function test_buscar_otro_usa_el_texto_libre_y_sin_resultados_queda_como_nuevo(): void
    {
        $this->yaCotizada('Lima plana bastarda 8 pulgadas', ['modelo' => 'LP-8']);
        $c = Livewire::actingAs($this->vendedor())->test(Nueva::class)
            ->set('partidas', [['cantidad' => '1', 'unidad' => '', 'descripcion' => 'ALGO', 'modelo' => '', 'ligada' => null]])
            ->set('consultas.0', 'lp8')->call('buscarOtro', 0)
            ->assertSee('Lima plana bastarda 8 pulgadas');
        $c->set('consultas.0', 'zzzzz')->call('buscarOtro', 0)->assertSee('producto nuevo');
        $this->assertSame([], $c->get('sugerencias.0'));
    }

    public function test_las_cotizadas_se_quitan_de_la_lista_al_dia(): void
    {
        $this->actingAs($this->vendedor());
        $hoy = $this->solicitud(['estatus' => 'cotizada', 'cliente_id' => null, 'cliente_texto' => 'CLIENTE DE HOY']);
        $vieja = $this->solicitud(['estatus' => 'cotizada', 'cliente_id' => null, 'cliente_texto' => 'CLIENTE DE AYER']);
        SolicitudCotizacion::whereKey($vieja->id)->toBase()->update(['updated_at' => now()->subDays(2)]);

        Livewire::test(Index::class)->set('pestana', 'cotizada')
            ->assertSee('CLIENTE DE HOY')->assertDontSee('CLIENTE DE AYER')
            ->assertSee('Últimas 24 horas')
            ->set('buscar', 'AYER')->assertSee('CLIENTE DE AYER');
        $this->assertNotNull($hoy->fresh());
        $this->assertNotNull($vieja->fresh());
    }

    public function test_texto_pegado_se_acomoda_en_partidas_con_ia_y_busca_lo_ya_cotizado(): void
    {
        $u = $this->vendedor('Alberto');
        $cliente = Cliente::factory()->create(['nombre' => 'HOTEL TEXTO']);
        $cot = Cotizacion::factory()->create(['cliente_id' => $cliente->id, 'vendedor_id' => $u->id, 'estado' => 'enviada']);
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'EMBOLO AUTOLIMPIEZA HELVEX P/FLUXOMETRO',
            'modelo' => 'SF-741', 'cantidad' => 1, 'costo' => 100, 'precio' => 150]);
        $texto = "EMBOLO AUTOLIMPIEZA HELVEX SF-741 P/FLUX\n3\nPZA\nCESPOL FOSET 49365 FLEX P/LAVABO 1 1/4\"\n5\nPZA";

        Http::fake([
            self::POST => Http::response(['id' => 'resp_t', 'status' => 'queued'], 200),
            self::GET => Http::response($this->respuestaIa(['cliente_nombre' => null, 'contacto' => null, 'notas' => null, 'partidas' => [
                ['cantidad' => 3, 'unidad' => 'PZA', 'descripcion' => 'EMBOLO AUTOLIMPIEZA HELVEX P/FLUX', 'modelo' => 'SF-741'],
                ['cantidad' => 5, 'unidad' => 'PZA', 'descripcion' => 'CESPOL FOSET FLEX P/LAVABO 1 1/4"', 'modelo' => '49365'],
            ]]), 200),
        ]);
        $c = Livewire::actingAs($u)->test(Nueva::class)->set('clienteId', $cliente->id)->set('detalle', $texto)
            ->call('acomodarTexto')->assertSet('iaEstado', 'leyendo');
        // Va como texto (no como PDF) y con la regla de renglones sueltos.
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains(json_encode($r->data()), 'EMBOLO AUTOLIMPIEZA')
            && str_contains(json_encode($r->data()), 'renglones sueltos') && ! str_contains(json_encode($r->data()), 'input_file'));

        $c->call('consultarIa')->assertSet('iaEstado', 'lista');
        $partidas = $c->get('partidas');
        $this->assertCount(2, $partidas);
        $this->assertSame('SF-741', $partidas[0]['modelo']);
        $this->assertEquals(3, $partidas[0]['cantidad']);
        // Compara contra lo ya cotizado: el émbolo sale sugerido (el humano confirma "Sí, es este").
        $this->assertNotEmpty($c->get('sugerencias')[0] ?? []);
        $this->assertSame('Texto pegado.txt', SolicitudCotizacion::firstOrFail()->archivos()->first()->nombre);
        $this->assertSame($texto, trim(SolicitudCotizacion::firstOrFail()->detalle));

        // El mismo texto otra vez no se vuelve a mandar (no se paga dos veces).
        $c->call('acomodarTexto');
        $this->assertStringContainsString('ya se acomodó', $c->get('error'));
        Http::assertSentCount(2);   // un POST y un GET
    }

    public function test_acomodar_sin_texto_avisa_y_ver_sugiere_acomodar_si_solo_hay_texto(): void
    {
        Http::fake();
        $u = $this->vendedor();
        Livewire::actingAs($u)->test(Nueva::class)->set('clienteTexto', 'ALGUIEN')->call('acomodarTexto')
            ->assertSet('error', 'Primero pega en "Qué pidió" el texto que mandó el cliente.');
        Http::assertNothingSent();

        $s = $this->solicitud(['partidas' => [], 'detalle' => "BROCA 1/4\n10\nPZA"]);
        Livewire::actingAs($u)->test(Ver::class, ['solicitud' => $s])->assertSee('Acomodar en partidas con IA');
    }
}

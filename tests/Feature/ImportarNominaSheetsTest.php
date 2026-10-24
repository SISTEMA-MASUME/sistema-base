<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Importar;
use App\Livewire\Finanzas\Nomina as PantallaNomina;
use App\Models\Nomina;
use App\Models\NominaLinea;
use App\Models\User;
use App\Models\Venta;
use App\Support\CalculoNomina;
use App\Support\ImportadorNominaSheets;
use App\Support\LectorXlsx;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/** Importar la hoja "HISTORIAL NOMINA" del Sheets (Excel descargado): servicio y pantalla de importar. */
class ImportarNominaSheetsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
        parent::tearDown();
    }

    private function serial(string $fecha): int
    {
        return (new DateTimeImmutable($fecha))->diff(new DateTimeImmutable('1899-12-30'))->days;
    }

    /** Arma un .xlsx mínimo como el que descarga Google Sheets: hoja => renglones (cada uno, lista de celdas). */
    private function excel(array $hojas): string
    {
        $base = tempnam(sys_get_temp_dir(), 'nom');
        $ruta = $base.'.xlsx';
        @unlink($base);
        $this->temporales[] = $ruta;
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $compartidas = [];
        $libro = '';
        $rels = '';
        $i = 0;
        foreach ($hojas as $nombre => $filas) {
            $i++;
            $xml = '';
            foreach ($filas as $r => $fila) {
                $xml .= '<row r="'.($r + 1).'">';
                foreach ($fila as $c => $v) {
                    if ($v === null || $v === '') {
                        continue;
                    }
                    $celda = LectorXlsx::letra($c).($r + 1);
                    if (is_int($v) || is_float($v)) {
                        $xml .= "<c r=\"$celda\"><v>$v</v></c>";
                    } else {
                        $compartidas[] = htmlspecialchars($v, ENT_XML1);
                        $xml .= "<c r=\"$celda\" t=\"s\"><v>".(count($compartidas) - 1).'</v></c>';
                    }
                }
                $xml .= '</row>';
            }
            $zip->addFromString("xl/worksheets/sheet$i.xml", '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>');
            $libro .= '<sheet name="'.htmlspecialchars($nombre, ENT_XML1)."\" sheetId=\"$i\" r:id=\"rId$i\"/>";
            $rels .= "<Relationship Id=\"rId$i\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$i.xml\"/>";
        }
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$libro.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .implode('', array_map(fn ($s) => "<si><t>$s</t></si>", $compartidas)).'</sst>');
        $zip->close();

        return $ruta;
    }

    private const ENCABEZADO = ['FECHA', 'TIPO', 'AÑO', 'MES', 'SEMANA', 'EMPLEADO / CONCEPTO', 'BASE', 'COMISIÓN / REPARTO', 'TOTAL', 'NOTAS'];

    /** Renglón de la hoja: [fecha, tipo, año, mes, semana, empleado, base, comisión, total, notas]. */
    private function semanal(float|int $fecha, int $semana, string $empleado, ?int $base, ?float $comision, ?float $total, ?string $notas = null): array
    {
        return [$fecha, 'NOMINA SEMANAL', 2026, null, $semana, $empleado, $base, $comision, $total, $notas];
    }

    /** El historial de prueba: semanas 24 y 23 (en ese orden, como el Sheets), con total de semana, un descuento negativo y un reparto. */
    private function historial(): array
    {
        $f24 = $this->serial('2026-06-13') + 0.44820087963;
        $f23 = $this->serial('2026-06-13') + 0.45284891204;

        return [
            self::ENCABEZADO,
            [$this->serial('2026-06-12') + 0.6873514, 'REPARTO ERNESTO', 2026, 5, null, 'ERNESTO PÉREZ (socio 20%)', null, 4798.4038, 4798.4038,
                'Facturas cobradas: 168 | Utilidad NETA (base reparto): $23992.02 | Ernesto 20%: $4798.40'],
            $this->semanal($f24, 24, 'ALBERTO', 3000, 3085.71, 6085.71, 'se rebajo 500 restan 500 guantes '),
            $this->semanal($f24, 24, 'ROSA RUIZ', 2400, 2324, 4724),
            $this->semanal($f24, 24, 'ERNESTO PÉREZ', 4000, 1000, 5000),
            $this->semanal($f24, 24, 'IVONNE GARZA', 2250, 0, 2250),
            $this->semanal($f24, 24, '— TOTAL SEMANA —', null, null, 18059.71),
            $this->semanal($f23, 23, 'ALBERTO', 3000, 3540, 6540),
            $this->semanal($f23, 23, 'HUGO', 2500, -300, 2200, 'se rebajaron 800 jgo de herramientas'),
            $this->semanal($f23, 23, '— TOTAL SEMANA —', null, null, 8740),
        ];
    }

    private function equipo(): array
    {
        return [
            User::factory()->create(['name' => 'Alberto']),
            User::factory()->create(['name' => 'Rosa Ruiz']),
            User::factory()->create(['name' => 'Ernesto Pérez']),
            User::factory()->create(['name' => 'Hugo']),
        ];
    }

    // -------------------------------------------------------------------------------------- servicio

    public function test_la_vista_previa_cuenta_semanas_y_repartos_sin_escribir(): void
    {
        $this->equipo();
        $ruta = $this->excel(['VENTAS 2026' => [['CLIENTE']], 'HISTORIAL NOMINA' => $this->historial()]);
        $this->assertTrue(ImportadorNominaSheets::hayHoja($ruta));
        $this->assertFalse(ImportadorNominaSheets::hayHoja($this->excel(['VENTAS 2026' => [['CLIENTE']]])));

        $r = (new ImportadorNominaSheets)->analizar($ruta);
        $this->assertNull($r['error']);
        $this->assertFalse($r['guardado']);
        $this->assertSame(9, $r['filas']);
        $this->assertSame(2, $r['semanas']['a_importar']);
        $this->assertSame(0, $r['semanas']['ya_estaban']);
        $this->assertSame('23 de 2026', $r['semanas']['primera']);
        $this->assertSame('24 de 2026', $r['semanas']['ultima']);
        $this->assertSame(26799.71, $r['semanas']['total']);   // 18,059.71 + 8,740
        $this->assertSame(1, $r['repartos']['a_importar']);
        $this->assertSame(4798.4, $r['repartos']['total']);
        $this->assertSame(7, $r['lineas']);                    // sin los renglones "— TOTAL SEMANA —"
        $this->assertSame(['IVONNE GARZA'], $r['sin_usuario']);
        $this->assertSame(0, $r['anomalias_total'], 'los totales de semana cuadran');
        $this->assertSame(0, Nomina::count(), 'analizar no escribe nada');
    }

    public function test_importa_semanas_pagadas_con_sus_lineas_y_el_reparto(): void
    {
        [$alberto, $rosa, $ernesto, $hugo] = $this->equipo();
        $r = (new ImportadorNominaSheets)->importar($this->excel(['HISTORIAL NOMINA' => $this->historial()]));
        $this->assertTrue($r['guardado']);
        $this->assertSame(3, Nomina::count());

        $n24 = Nomina::where(['tipo' => 'semanal', 'anio' => 2026, 'semana' => 24])->firstOrFail();
        $this->assertSame('pagada', $n24->estatus);
        $this->assertSame('sheets', $n24->origen);
        $this->assertSame('2026-06-08', $n24->desde->toDateString());   // lunes
        $this->assertSame('2026-06-13', $n24->hasta->toDateString());   // sábado
        $this->assertSame('2026-06-13', $n24->pagada_at->toDateString());
        $this->assertSame('0.00', $n24->utilidad);
        $this->assertSame('0.00', $n24->bolsa);
        $this->assertNull($n24->user_id);
        $this->assertSame(4, $n24->lineas()->count());   // sin el renglón de total
        $this->assertSame(18059.71, $n24->total());

        $abra = $n24->lineas()->where('nombre', 'ALBERTO')->firstOrFail();
        $this->assertSame($alberto->id, $abra->user_id);
        $this->assertSame('3000.00', $abra->sueldo_base);
        $this->assertSame('3085.71', $abra->comision);
        $this->assertSame('6085.71', $abra->total);
        $this->assertSame('se rebajo 500 restan 500 guantes', $abra->notas);
        $this->assertSame($rosa->id, $n24->lineas()->where('nombre', 'ROSA RUIZ')->value('user_id'));
        $this->assertSame($ernesto->id, $n24->lineas()->where('nombre', 'ERNESTO PÉREZ')->value('user_id'));
        $ivonne = $n24->lineas()->where('nombre', 'IVONNE GARZA')->firstOrFail();
        $this->assertNull($ivonne->user_id, 'sin usuario con ese nombre: queda solo el nombre');
        $this->assertNull($ivonne->notas);

        // La comisión negativa del Sheets era un descuento y se conserva tal cual.
        $n23 = Nomina::where(['tipo' => 'semanal', 'semana' => 23])->firstOrFail();
        $y = $n23->lineas()->where('nombre', 'HUGO')->firstOrFail();
        $this->assertSame($hugo->id, $y->user_id);
        $this->assertSame('-300.00', $y->comision);
        $this->assertSame('2200.00', $y->total);
        $this->assertSame('2026-06-01', $n23->desde->toDateString());

        // Reparto del socio de mayo.
        $rep = Nomina::where('tipo', 'reparto')->firstOrFail();
        $this->assertSame(2026, $rep->anio);
        $this->assertSame(5, $rep->mes);
        $this->assertSame('2026-05-01', $rep->desde->toDateString());
        $this->assertSame('2026-05-31', $rep->hasta->toDateString());
        $this->assertSame('pagada', $rep->estatus);
        $this->assertSame(1, $rep->lineas()->count());
        $linea = $rep->lineas()->firstOrFail();
        $this->assertSame($ernesto->id, $linea->user_id);
        $this->assertSame('4798.40', $linea->comision);
        $this->assertSame('4798.40', $linea->total);
        $this->assertStringContainsString('Capital retenido', 'Capital retenido'.$linea->notas);
        $this->assertStringContainsString('Ernesto 20%: $4798.40', $linea->notas);

        // Lo del Sheets no recalcula nada si alguien llama al cálculo.
        CalculoNomina::recalcular($n24);
        $this->assertSame('3085.71', $abra->fresh()->comision);
    }

    public function test_no_duplica_lo_que_ya_esta_en_el_sistema(): void
    {
        $this->equipo();
        $ruta = $this->excel(['HISTORIAL NOMINA' => $this->historial()]);
        // La semana 24 ya existe (la hizo el sistema, aunque sea borrador) y el reparto de mayo también.
        Nomina::create(['tipo' => 'semanal', 'anio' => 2026, 'semana' => 24, 'desde' => '2026-06-08', 'hasta' => '2026-06-13']);
        Nomina::create(['tipo' => 'reparto', 'anio' => 2026, 'mes' => 5, 'desde' => '2026-05-01', 'hasta' => '2026-05-31']);

        $previa = (new ImportadorNominaSheets)->analizar($ruta);
        $this->assertSame(1, $previa['semanas']['a_importar']);
        $this->assertSame(1, $previa['semanas']['ya_estaban']);
        $this->assertSame(0, $previa['repartos']['a_importar']);
        $this->assertSame(1, $previa['repartos']['ya_estaban']);

        (new ImportadorNominaSheets)->importar($ruta);
        $this->assertSame(3, Nomina::count());
        $this->assertSame(0, Nomina::where(['semana' => 24])->first()->lineas()->count(), 'la existente no se toca');
        $this->assertSame(2, NominaLinea::count());   // solo las 2 de la semana 23

        // Importar otra vez: nada nuevo.
        $otra = (new ImportadorNominaSheets)->importar($ruta);
        $this->assertSame(0, $otra['semanas']['a_importar'] + $otra['repartos']['a_importar']);
        $this->assertSame(3, Nomina::count());
        $this->assertSame(2, NominaLinea::count());
    }

    public function test_reimportar_el_mismo_excel_no_duplica(): void
    {
        $this->equipo();
        $ruta = $this->excel(['HISTORIAL NOMINA' => $this->historial()]);
        (new ImportadorNominaSheets)->importar($ruta);
        $lineas = NominaLinea::count();
        $r = (new ImportadorNominaSheets)->importar($ruta);
        $this->assertSame(2, $r['semanas']['ya_estaban']);
        $this->assertSame(1, $r['repartos']['ya_estaban']);
        $this->assertSame(3, Nomina::count());
        $this->assertSame($lineas, NominaLinea::count());
    }

    public function test_avisa_de_renglones_raros_y_totales_que_no_cuadran(): void
    {
        $f = $this->serial('2026-06-13');
        $filas = [
            self::ENCABEZADO,
            $this->semanal($f, 30, 'ALBERTO', 3000, 1000, 4000),
            $this->semanal($f, 30, '— TOTAL SEMANA —', null, null, 9999),                   // no cuadra
            [$f, 'NOMINA SEMANAL', 2026, null, null, 'ROSA RUIZ', 2400, 0, 2400, null],   // sin semana
            [$f, 'OTRA COSA', 2026, null, 31, 'ROSA RUIZ', 2400, 0, 2400, null],           // tipo desconocido
            [],
            // La misma semana capturada dos veces con fechas distintas: vale la más reciente.
            $this->semanal($f, 31, 'ALBERTO', 3000, 100, 3100),
            $this->semanal($f + 1, 31, 'ALBERTO', 3000, 200, 3200),
        ];
        $r = (new ImportadorNominaSheets)->importar($this->excel(['HISTORIAL NOMINA' => $filas]));
        $this->assertSame(2, $r['ignoradas']);
        $this->assertSame(3, $r['anomalias_total']);
        $textos = implode(' | ', $r['anomalias']);
        $this->assertStringContainsString('Semana 30 de 2026: las personas suman $4,000.00 pero el renglón de total dice $9,999.00', $textos);
        $this->assertStringContainsString('no trae año y semana válidos', $textos);
        $this->assertStringContainsString('Semana 31 de 2026: viene capturada más de una vez', $textos);
        $this->assertSame('3200.00', Nomina::where('semana', 31)->firstOrFail()->lineas()->value('total'));
        $this->assertSame(1, Nomina::where('semana', 31)->firstOrFail()->lineas()->count());
    }

    public function test_hoja_sin_encabezados_o_ausente_no_truena(): void
    {
        $r = (new ImportadorNominaSheets)->analizar($this->excel(['HISTORIAL NOMINA' => [['a', 'b'], [1, 2]]]));
        $this->assertStringContainsString('No encontré los encabezados', $r['error']);
        $r = (new ImportadorNominaSheets)->importar($this->excel(['Otra' => [['a']]]));
        $this->assertStringContainsString('no trae la hoja', $r['error']);
        $this->assertSame(0, Nomina::count());
    }

    // -------------------------------------------------------------------------------------- pantalla

    public function test_la_pantalla_importa_solo_el_historial_de_nomina_sin_duplicar(): void
    {
        Storage::fake('local');
        $this->equipo();
        $admin = User::factory()->create(['rol' => 'admin']);
        $this->actingAs($admin);
        $archivo = fn () => UploadedFile::fake()->createWithContent('Ventas_MASUME_SHEETS.xlsx', file_get_contents($this->excel(['HISTORIAL NOMINA' => $this->historial()])));

        $c = Livewire::test(Importar::class)
            ->set('archivo', $archivo())->assertHasNoErrors()
            ->assertSee('Revisa antes de importar')->assertSee('Historial de nómina')
            ->assertSee('Semanas nuevas')->assertSee('Repartos del socio nuevos')
            ->assertSee('de la 23 de 2026 a la 24 de 2026')
            ->assertSee('Sin usuario en el sistema: IVONNE GARZA')
            ->assertSee('Reparto del socio, Mayo 2026')
            ->assertDontSee('Facturas y notas nuevas');
        $this->assertSame(0, Nomina::count(), 'todavía no se importa nada');

        $c->call('importar')
            ->assertSee('Listo: se importaron 2 semanas y 1 reparto del historial de nómina (0 ya estaban).')
            ->assertSee('Ver el historial de nómina')->assertDontSee('Ir a Ventas');
        $this->assertSame(3, Nomina::count());
        $this->assertSame([], Storage::disk('local')->files('finanzas'), 'la copia temporal se borra');

        // Otra vez el mismo Excel: nada nuevo y no aparece el botón de importar.
        Livewire::test(Importar::class)->set('archivo', $archivo())
            ->assertSee('No hay nada nuevo que importar')->assertDontSee('>Importar</button>', false);
        $this->assertSame(3, Nomina::count());
    }

    public function test_la_pantalla_importa_ventas_y_nomina_del_mismo_excel(): void
    {
        Storage::fake('local');
        $this->equipo();
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $ventas = [
            ['CLIENTE', 'FECHA FACTURA', 'TIPO DE DOCUMENTO', 'FOLIO', 'COMPRA', 'ESTATUS', 'VENTA'],
            ['ACME SA', $this->serial('2026-09-01'), 'FACTURA', '5-100', 80, 'PENDIENTE', 100],
        ];
        $ruta = $this->excel(['VENTAS 2026' => $ventas, 'HISTORIAL NOMINA' => $this->historial()]);

        Livewire::test(Importar::class)->set('archivo', UploadedFile::fake()->createWithContent('x.xlsx', file_get_contents($ruta)))
            ->assertSee('Facturas y notas nuevas')->assertSee('Historial de nómina')
            ->call('importar')
            ->assertSee('Listo: se importaron 1 facturas y notas de venta del Sheets')
            ->assertSee('se importaron 2 semanas y 1 reparto')
            ->assertSee('Ir a Ventas')->assertSee('Ver el historial de nómina');
        $this->assertSame(1, Venta::count());
        $this->assertSame(3, Nomina::count());
    }

    public function test_un_excel_sin_ventas_ni_nomina_sigue_avisando(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        Livewire::test(Importar::class)
            ->set('archivo', UploadedFile::fake()->createWithContent('otro.xlsx', file_get_contents($this->excel(['Hoja1' => [['A', 'B'], [1, 2]]]))))
            ->assertSee('No encontré hojas de ventas');
        $this->assertSame([], Storage::disk('local')->files('finanzas'));
    }

    public function test_lo_importado_se_ve_en_el_historial_y_no_se_puede_reabrir(): void
    {
        $this->equipo();
        $admin = User::factory()->create(['rol' => 'admin']);
        $this->actingAs($admin);
        (new ImportadorNominaSheets)->importar($this->excel(['HISTORIAL NOMINA' => $this->historial()]));
        $n24 = Nomina::where(['tipo' => 'semanal', 'semana' => 24])->firstOrFail();

        $c = Livewire::withQueryParams(['ver' => 'historial'])->test(PantallaNomina::class)
            ->set('histAnio', '2026')
            ->assertSee('Semana 24')->assertSee('Semana 23')->assertSee('Reparto del socio · Mayo 2026')->assertSee('del Sheets');
        $personas = collect($c->viewData('personas'))->keyBy('nombre');
        $this->assertEquals(6085.71 + 6540, $personas['Alberto']['total']);
        $this->assertEquals(6000, $personas['Alberto']['sueldo']);
        $this->assertEquals(3085.71 + 3540, $personas['Alberto']['comisiones']);
        $this->assertEquals(-300, $personas['Hugo']['bonos']);   // la comisión negativa cuenta como descuento
        $this->assertEquals(4798.4, $personas['Ernesto Pérez']['reparto']);
        $this->assertEquals(4000, $personas['Ernesto Pérez']['sueldo']);

        // Mayo trae solo el reparto (las semanas son de junio).
        $c->set('histMes', '5')->assertSee('Reparto del socio · Mayo 2026')->assertDontSee('Semana 24');

        // En la pestaña Semana sale pagada y de solo lectura, y ni el admin la reabre.
        Livewire::withQueryParams(['ver' => 'semana'])->test(PantallaNomina::class)->set('anio', 2026)->set('semana', 24)
            ->assertSee('Pagada')->assertSee('viene del Sheets')->assertDontSee('Registrar pago')->assertDontSee('Reabrir semana')
            ->call('reabrirSemana')->assertSee('viene del Sheets: no se puede reabrir');
        $this->assertTrue($n24->fresh()->pagada());
        $this->assertSame(1, Nomina::where(['tipo' => 'semanal', 'semana' => 24])->count(), 'no se crea otro borrador encima');

        // El reparto importado tampoco.
        Livewire::withQueryParams(['ver' => 'reparto'])->test(PantallaNomina::class)->set('repAnio', 2026)->set('repMes', 5)
            ->assertSee('viene del Sheets')->assertDontSee('Reabrir')->call('reabrirReparto')->assertSee('no se puede reabrir');
    }
}

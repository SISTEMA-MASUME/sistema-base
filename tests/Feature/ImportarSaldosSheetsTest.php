<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Importar;
use App\Models\Cuenta;
use App\Models\SaldoCorte;
use App\Models\User;
use App\Support\ImportadorSaldosSheets;
use App\Support\LectorXlsx;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/** Importar las hojas CONTROL y SALDOS del Sheets: cuentas, tarjetas, préstamo y un corte de saldos. */
class ImportarSaldosSheetsTest extends TestCase
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

    /** Arma un .xlsx mínimo como el que descarga Google Sheets: hoja => renglones. */
    private function excel(array $hojas): string
    {
        $base = tempnam(sys_get_temp_dir(), 'gas');
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

    private function control(array $cambios = []): array
    {
        $f = [
            0 => [null, 'CONTROL DE PAGOS'],
            3 => [null, '1) SALDOS REALES — copia de las apps'],
            4 => [null, 'HEY BANCO (MASUME)', 1000],
            5 => [null, 'BBVA DEBITO', 500],
            6 => [null, 'EFECTIVO', 200],
            7 => [null, 'CAJITA IMPUESTOS', 50],
            8 => [null, 'LIQUIDEZ OPERATIVA', 1700],
            9 => [null, 'RESERVAS (cajitas)', 50],
            16 => [null, '2) TARJETAS — actualiza'],
            17 => [null, 'TARJETA', 'MUNDO', 'CORTE', 'LÍMITE PAGO', 'LÍNEA', 'SALDO HOY ✎', 'PAGO S/INT ✎', 'FECHA LÍMITE ✎'],
            18 => [null, 'BBVA PLATINUM', 'MASUME', 'Día 9', 'Día 29', 10000, 9000, 0, $this->serial('2026-10-29')],
            19 => [null, 'AMEX PLATINUM', 'PERSONAL', 'Día 3', 'Día 23', 5000, 1000, 800],
            20 => [null, 'TOTALES', null, null, null, null, 10000, 800],
        ];

        $r = $cambios + $f;
        ksort($r);

        return $r;
    }

    private function saldos(): array
    {
        return [
            3 => [null, 'RESUMEN'],
            8 => [null, 'Jorge Ríos: $320,000 (abonado: $180,000)  |  Cartera MASUME: $1,078,389.824 (calculada de VENTAS)'],
            11 => [null, 'CUENTA', 'SALDO ACTUAL'],
            12 => [null, 'HEY BANCO', 38000],
            13 => [null, 'GBM S&P500', 70000],
            14 => [null, 'TOTAL DISPONIBLE', 108000],
            32 => [null, 'CRÉDITO', 'SALDO', 'PAGO'],
            33 => [null, 'CREDITO BBVA', 300000],
            34 => [null, 'DEUDA URREA', 20000],
            35 => [null, 'TOTAL CRÉDITOS', 320000],
        ];
    }

    private function subir(string $ruta)
    {
        return UploadedFile::fake()->createWithContent('Ventas.xlsx', file_get_contents($ruta));
    }

    public function test_lee_cuentas_tarjetas_creditos_y_prestamo(): void
    {
        $r = (new ImportadorSaldosSheets)->analizar($this->excel(['CONTROL' => $this->control(), 'SALDOS' => $this->saldos()]));

        $this->assertSame([], $r['avisos']);
        $c = collect($r['cuentas'])->keyBy('nombre');
        $this->assertSame(1000.0, $c['HEY BANCO']['saldo']);          // gana CONTROL sobre SALDOS (38,000)
        $this->assertSame('masume', $c['HEY BANCO']['de']);
        $this->assertSame('apartado', $c['CAJITA IMPUESTOS']['tipo']);
        $this->assertSame('efectivo', $c['EFECTIVO']['tipo']);
        $this->assertSame('inversion', $c['GBM S&P500']['tipo']);
        $this->assertSame('prestamo', $c['CREDITO BBVA']['tipo']);
        $this->assertSame('masume', $c['CREDITO BBVA']['de']);
        $this->assertSame('por_cobrar', $c['PRÉSTAMO JORGE RÍOS']['tipo']);
        $this->assertSame(140000.0, $c['PRÉSTAMO JORGE RÍOS']['saldo']);
        $this->assertArrayNotHasKey('LIQUIDEZ OPERATIVA', $c->all());
        $t = $c['BBVA PLATINUM'];
        $this->assertSame([9, 29, 10000.0, 9000.0, '2026-10-29'], [$t['dia_corte'], $t['dia_pago'], $t['limite'], $t['saldo'], $t['fecha_limite']]);
        $this->assertSame('personal', $c['AMEX PLATINUM']['de']);
        $this->assertSame(800.0, $c['AMEX PLATINUM']['pago']);
        $this->assertTrue(collect($r['conflictos'])->contains(fn ($x) => str_contains($x, 'HEY BANCO')));
        $this->assertSame(0, Cuenta::count(), 'analizar no escribe nada');
    }

    public function test_importa_un_corte_y_no_repite(): void
    {
        $ruta = $this->excel(['CONTROL' => $this->control(), 'SALDOS' => $this->saldos()]);
        $existente = Cuenta::create(['nombre' => 'Hey Banco', 'tipo' => 'debito', 'de' => 'personal']);

        $r = (new ImportadorSaldosSheets)->importar($ruta);
        $this->assertTrue($r['guardado']);
        $this->assertSame(1, SaldoCorte::count());
        $corte = SaldoCorte::first();
        $this->assertSame('Importado del Sheets', $corte->notas);
        $this->assertSame('2026-10-01', $corte->fecha->format('Y-m-d'));
        $this->assertSame(10, $corte->lineas()->count());
        $this->assertSame(1000.0, (float) $corte->lineas()->where('cuenta_id', $existente->id)->value('saldo'));
        $this->assertSame('personal', $existente->fresh()->de, 'las cuentas que ya existían conservan su "De"');
        $bbva = Cuenta::where('nombre', 'BBVA PLATINUM')->first();
        $this->assertSame(['credito', 9, 29], [$bbva->tipo, $bbva->dia_corte, $bbva->dia_pago]);

        $otra = (new ImportadorSaldosSheets)->importar($ruta);
        $this->assertTrue($otra['omitido']);
        $this->assertSame(1, SaldoCorte::count());
        (new ImportadorSaldosSheets)->importar($ruta, true);
        $this->assertSame(2, SaldoCorte::count());
    }

    public function test_si_un_bloque_no_cuadra_avisa_y_no_lo_importa(): void
    {
        // La liquidez no coincide con la suma de los saldos, y una tarjeta trae un corte ilegible.
        $control = $this->control([8 => [null, 'LIQUIDEZ OPERATIVA', 9999], 18 => [null, 'BBVA PLATINUM', 'MASUME', 'por ahí', 'Día 29', 10000, 9000, 0]]);
        $r = (new ImportadorSaldosSheets)->analizar($this->excel(['CONTROL' => $control]));

        $textos = implode(' | ', $r['avisos']);
        $this->assertStringContainsString('LIQUIDEZ OPERATIVA', $textos);
        $this->assertStringContainsString('BBVA PLATINUM', $textos);
        $nombres = collect($r['cuentas'])->pluck('nombre');
        $this->assertFalse($nombres->contains('BBVA DEBITO'));
        $this->assertFalse($nombres->contains('BBVA PLATINUM'));
    }

    public function test_sin_las_hojas_avisa(): void
    {
        $ruta = $this->excel(['OTRA' => [[null, 'x']]]);
        $this->assertFalse(ImportadorSaldosSheets::hayHojas($ruta));
        $this->assertNotEmpty((new ImportadorSaldosSheets)->analizar($ruta)['avisos']);
    }

    public function test_la_pantalla_de_importar_lo_integra_al_boton_unico(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $ruta = $this->excel(['CONTROL' => $this->control(), 'SALDOS' => $this->saldos()]);

        Livewire::test(Importar::class)->set('archivo', $this->subir($ruta))->assertHasNoErrors()
            ->assertSee('Cuentas y saldos')->assertSee('PRÉSTAMO JORGE RÍOS')->assertSee('$140,000.00')->assertSee('wire:click="importar"', false)
            ->call('importar')->assertSee('se importaron 10 cuentas');
        $this->assertSame(1, SaldoCorte::count());

        Livewire::test(Importar::class)->set('archivo', $this->subir($ruta))
            ->assertSee('Los saldos ya se importaron antes.')->assertDontSee('wire:click="importar"', false)
            ->set('reimportarSaldos', true)->call('importar');
        $this->assertSame(2, SaldoCorte::count());
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Importar;
use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\User;
use App\Models\Venta;
use App\Support\ImportadorGastosSheets;
use App\Support\LectorXlsx;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/** Importar las hojas GASTOS 2025 / 2024 / 2023 del Sheets (Excel descargado): servicio y pantalla de importar. */
class ImportarGastosSheetsTest extends TestCase
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

    /** GASTOS 2025: encabezado en el renglón 1; el resumen (GASTO ENERO, totales) vive más a la derecha. */
    private function hoja2025(): array
    {
        $resumen = [11 => 'GASTO ENERO', 12 => 0, 15 => 255857.991];

        return [
            [null, 'MES', 'SEMANA', 'MASUME / PERSONAL', 'FECHA', 'MONTO', 'TIPO DE MOVIMIENTO', 'DESCRIPCION', 'PAGADO CON', 'CATEGORIA', 'CUENTA DESTINO '],
            [2025, 1, 1, 'PERSONAL', $this->serial('2025-01-02'), 5763, 'Gasto ', 'SPEI INVEX - A TARJETA', 'Hey Banco (Débito)', 'PAGO TARJETAS PATY'] + $resumen,
            [2025, 1, 1, 'MASUME', $this->serial('2025-01-03'), 20000, 'movimiento entre cuentas', 'pago credito yo te presto ', 'Hey Banco (Debito)', 'PAGO PRESTAMO', 'YoTePresto'],
            [2025, 1, 1, 'MASUME', $this->serial('2025-01-03'), 2550.5, null, null, 'HEY BANCO', 'GASTOS OPERATIVOS '],
            [2025, 1, 2, 'MASUME', $this->serial('2025-02-10'), 1000, 'Gasto', 'Gasolina', 'BBVA (Crédito)', 'GASOLINA'],
            [2025, 1, 2, 'MASUME', $this->serial('2025-02-10'), 1000, 'Gasto', 'Gasolina', 'BBVA (Crédito)', 'GASOLINA'],   // idéntico: es otro gasto
            [2025, 1, 2, 'MASUME', $this->serial('2025-02-11'), 300, 'Gasto', 'Pago', 'BBVA DEBITO', 'ALIMENTOS'],
            [2025, 1, 2, 'MASUME', $this->serial('2025-02-12'), 300, 'Gasto', 'Pago', 'Volaris Invex Morada', 'ALIMENTOS'],
            [2025, 1, 2, 'MASUME', $this->serial('2025-02-13'), 300, 'Gasto', 'Pago', 'Despegar Invex Verde', 'ALIMENTOS'],
            [2025, 1, 2, 'MASUME', $this->serial('2025-02-14'), 300, 'Gasto', 'Pago', 'Banamex Costco', 'ALIMENTOS'],
            [2025, 1, 2, 'MASUME', $this->serial('2025-02-15'), 300, 'Gasto', 'Pago', 'CREDITO BBVA', 'ALIMENTOS'],
            [2026, 3, 9, 'MASUME', $this->serial('2026-03-02'), '$1,250.50', 'Gasto', 'Monto en texto', 'BBVA PLATINUM', 'IMPUESTOS'],
            [2026, 3, 9, 'MASUME', $this->serial('2026-03-03'), 0, 'Gasto', 'Monto cero', 'EFECTIVO', 'IMPUESTOS'],            // se salta
            [2026, 3, 9, 'MASUME', $this->serial('2026-03-04'), 'poco', 'Gasto', 'Monto raro', 'EFECTIVO', 'IMPUESTOS'],       // anomalía
            [2026, 3, 9, 'MASUME', null, 99, 'Gasto', 'Sin fecha', 'EFECTIVO', 'IMPUESTOS'],                                   // anomalía
            [2026, 3, 9, 'MASUME', 1, 99, 'Gasto', 'Fecha 1899', 'EFECTIVO', 'IMPUESTOS'],                                     // se salta
            [null, null, null, null, null, null, null, null, null, null, null, 'GASTO FEBRERO', 0, null, null, 4000],          // solo resumen
            [2026, 4, 14, null, $this->serial('2026-04-06'), 800, 'Gasto', 'Sin MASUME/PERSONAL', 'EFECTIVO', 'ALIMENTOS'],    // MASUME + aviso
        ];
    }

    /** GASTOS 2024 (y 2023): 8 renglones vacíos arriba y encabezado en el 9. */
    private function hoja2024(): array
    {
        $vacios = array_fill(0, 8, []);

        return array_merge($vacios, [
            ['MES', 'SEMANA', 'FECHA', 'COSTO', 'GASTO', 'DESCRIPCION', 'PAGADO CON', null, null, 'GASTO ENERO', 53901.686],
            [1, 1, $this->serial('2024-01-02'), 1159.48, 'GASOLINA', 'GASOLINA RAM', 'CREDITO PLATA', null, null, 'NOMINA', 43553.786],
            [1, 1, $this->serial('2024-01-03'), 651.28, 'Seguro ', 'seguro ram', 'Costco Credito '],
            [6, 25, '23-06-24', 1243.27, 'GASOLINA', 'GASOLINA RAM', 'HEY BANCO '],
            [6, 25, '25/06/2024', 100, 'GASOLINA', 'texto con diagonal', 'hey banco'],
            [6, 25, 'cualquier cosa', 100, 'GASOLINA', 'fecha ilegible', 'hey banco'],   // anomalía
        ]);
    }

    private function hoja2023(): array
    {
        $vacios = array_fill(0, 8, []);

        return array_merge($vacios, [
            [null, null, 'FECHA', 'COSTO', 'GASTO', 'DESCRIPCION', 'PAGADO CON'],
            [null, null, $this->serial('2023-05-10'), 500, 'TARJETAS', 'TARJETAS DE PRESENTACION', 'TRANSFER'],
            [null, null, '22 DE MAYO', 700, 'oxxo', 'COMIDA', 'COSTCO BANAMEX'],
            [null, null, $this->serial('2023-05-23'), 1500, 'gasolina', 'GASOLINA', 'HEY BANCO (DEBITO)'],
        ]);
    }

    private function libro(): string
    {
        return $this->excel(['VENTAS 2026' => [['x']], 'GASTOS 2025' => $this->hoja2025(), 'GASTOS 2024' => $this->hoja2024(), 'GASTOS 2023' => $this->hoja2023(), 'REGISTRO' => [['x']]]);
    }

    // ===== Servicio =====

    public function test_reconoce_las_hojas_de_gastos(): void
    {
        $this->assertTrue(ImportadorGastosSheets::hayHojas($this->libro()));
        $this->assertFalse(ImportadorGastosSheets::hayHojas($this->excel(['VENTAS 2026' => [['x']], 'DEUDA 2025' => [['x']]])));
    }

    public function test_la_vista_previa_lee_las_tres_formas_de_hoja_sin_escribir_nada(): void
    {
        $r = (new ImportadorGastosSheets)->analizar($this->libro());

        $this->assertSame(0, Gasto::count());
        $this->assertSame(0, Cuenta::count());
        $this->assertSame(['GASTOS 2025', 'GASTOS 2024', 'GASTOS 2023'], array_column($r['hojas'], 'nombre'));
        [$h25, $h24, $h23] = $r['hojas'];

        // 2025: encabezado en el 1, solo se leen las columnas del encabezado (nada de los resúmenes de la derecha).
        $this->assertSame(1, $h25['fila_encabezado']);
        $this->assertSame(['de' => 'D', 'fecha' => 'E', 'monto' => 'F', 'tipo' => 'G', 'descripcion' => 'H', 'cuenta' => 'I', 'categoria' => 'J', 'destino' => 'K'], $h25['columnas']);
        $this->assertSame(16, $h25['filas']);            // sin el renglón de solo resumen
        $this->assertSame(12, $h25['a_importar']);
        $this->assertSame(4, $h25['saltadas']);          // monto cero, monto raro, sin fecha y fecha 1899
        $this->assertSame(1, $h25['movimientos']);
        $this->assertEquals(5763, $h25['por_anio'][2025]['personal']);
        $this->assertEquals(2550.5 + 1000 + 1000 + 300 * 5, $h25['por_anio'][2025]['masume']);   // el movimiento de 20,000 no es gasto
        $this->assertEquals(20000, $h25['por_anio'][2025]['movimientos']);
        $this->assertEquals(1250.5 + 800, $h25['por_anio'][2026]['masume']);                     // la misma hoja trae 2026
        $this->assertSame(3, $h25['anomalias_total']);   // monto raro, sin fecha y sin MASUME/PERSONAL

        // 2024: encabezado en el 9, sin MASUME / PERSONAL → todo MASUME; COSTO es el monto y GASTO la categoría.
        $this->assertSame(9, $h24['fila_encabezado']);
        $this->assertSame(['fecha' => 'C', 'monto' => 'D', 'categoria' => 'E', 'descripcion' => 'F', 'cuenta' => 'G'], $h24['columnas']);
        $this->assertSame(4, $h24['a_importar']);
        $this->assertSame(1, $h24['saltadas']);
        $this->assertEqualsWithDelta(1159.48 + 651.28 + 1243.27 + 100, $h24['por_anio'][2024]['masume'], 0.001);
        $this->assertEquals(0, $h24['por_anio'][2024]['personal']);
        $this->assertSame(1, $h24['anomalias_total']);

        $this->assertSame(3, $h23['a_importar']);
        $this->assertEquals(2700, $h23['por_anio'][2023]['masume']);

        $this->assertSame(19, $r['totales']['a_importar']);
        $this->assertSame(0, $r['totales']['ya_estaban']);
        $this->assertSame(1, $r['totales']['movimientos']);
        $this->assertEquals(2700, $r['totales']['por_anio'][2023]['masume']);
        $this->assertSame([2023, 2024, 2025, 2026], array_keys($r['totales']['por_anio']));
        $this->assertSame(4, $r['anomalias_total']);
        $this->assertContains('GASTOS 2024, renglón 14: la fecha «cualquier cosa» no se entiende, no se importa.', $r['anomalias']);
    }

    public function test_importa_con_categorias_tipos_fechas_de_texto_y_alias_de_cuentas(): void
    {
        $r = (new ImportadorGastosSheets)->importar($this->libro());
        $this->assertSame(19, $r['totales']['insertadas']);
        $this->assertSame(19, Gasto::count());
        $this->assertSame(19, Gasto::where('origen', 'sheets')->count());

        // Categoría en mayúsculas y con espacios colapsados; tipo según el texto.
        $this->assertSame(2550.5, (float) Gasto::where('categoria', 'GASTOS OPERATIVOS')->firstOrFail()->monto);
        $mov = Gasto::where('tipo', 'movimiento')->firstOrFail();
        $this->assertSame('PAGO PRESTAMO', $mov->categoria);
        $this->assertSame('YOTEPRESTO', $mov->cuentaDestino->nombre);
        $this->assertSame('masume', $mov->de);
        $this->assertSame('personal', Gasto::where('monto', 5763)->firstOrFail()->de);
        $this->assertSame('SEGUROS', Gasto::where('monto', 651.28)->firstOrFail()->categoria);
        $this->assertSame('masume', Gasto::where('descripcion', 'Sin MASUME/PERSONAL')->firstOrFail()->de);
        $this->assertNull(Gasto::where('monto', 2550.5)->firstOrFail()->descripcion);

        // Fechas: serial, dd-mm-aa, dd/mm/aaaa y "22 DE MAYO" con el año de la hoja.
        $this->assertSame('2024-06-23', Gasto::where('monto', 1243.27)->firstOrFail()->fecha->toDateString());
        $this->assertSame('2024-06-25', Gasto::where('descripcion', 'texto con diagonal')->firstOrFail()->fecha->toDateString());
        $this->assertSame('2023-05-22', Gasto::where('descripcion', 'COMIDA')->firstOrFail()->fecha->toDateString());
        $this->assertSame(1250.5, (float) Gasto::where('descripcion', 'Monto en texto')->firstOrFail()->monto);

        // Alias: cada grupo de nombres termina en una sola cuenta.
        $this->assertSame(['HEY BANCO (DÉBITO)'], Gasto::where('monto', 5763)->get()->map(fn ($g) => $g->cuenta->nombre)->all());
        $this->assertSame('HEY BANCO (DÉBITO)', Gasto::where('monto', 2550.5)->firstOrFail()->cuenta->nombre);               // HEY BANCO
        $this->assertSame('HEY BANCO (DÉBITO)', $mov->cuenta->nombre);                                                       // Hey Banco (Debito)
        $this->assertSame('BBVA PLATINUM', Gasto::where('monto', 1000)->firstOrFail()->cuenta->nombre);                      // BBVA (Crédito)
        $this->assertSame('BBVA PLATINUM', Gasto::where('descripcion', 'Monto en texto')->firstOrFail()->cuenta->nombre);
        $this->assertSame('BBVA (DÉBITO)', Gasto::whereDate('fecha', '2025-02-11')->firstOrFail()->cuenta->nombre);             // BBVA DEBITO
        $this->assertSame('VOLARIS INVEX', Gasto::whereDate('fecha', '2025-02-12')->firstOrFail()->cuenta->nombre);
        $this->assertSame('DESPEGAR INVEX', Gasto::whereDate('fecha', '2025-02-13')->firstOrFail()->cuenta->nombre);
        $this->assertSame('COSTCO BANAMEX', Gasto::whereDate('fecha', '2025-02-14')->firstOrFail()->cuenta->nombre);             // Banamex Costco
        $this->assertSame('COSTCO BANAMEX', Gasto::where('descripcion', 'COMIDA')->firstOrFail()->cuenta->nombre);           // COSTCO BANAMEX
        $this->assertSame('CREDITO BBVA', Gasto::whereDate('fecha', '2025-02-15')->firstOrFail()->cuenta->nombre);              // se queda
        $this->assertSame('HEY BANCO (DÉBITO)', Gasto::where('monto', 1243.27)->firstOrFail()->cuenta->nombre);             // 2024: "HEY BANCO "
        $this->assertSame('HEY BANCO (DÉBITO)', Gasto::where('descripcion', 'GASOLINA')->where('monto', 1500)->firstOrFail()->cuenta->nombre);   // 2023: "HEY BANCO (DEBITO)"
        // Ambiguas ("CREDITO PLATA", "TRANSFER"): no se inventa cuenta; el texto queda en la descripción.
        $plata = Gasto::where('monto', 1159.48)->firstOrFail();
        $this->assertNull($plata->cuenta_id);
        $this->assertStringContainsString('(pagado con: CREDITO PLATA)', $plata->descripcion);
        $this->assertNull(Gasto::where('monto', 500)->firstOrFail()->cuenta_id);
        $this->assertNull(Gasto::where('descripcion', 'Gasolina')->first()->cuenta_destino_id);
        $this->assertSame(1, Cuenta::where('nombre_llave', 'HEYBANCODEBITO')->count());
        $this->assertSame('credito', Cuenta::where('nombre', 'BBVA PLATINUM')->firstOrFail()->tipo);
        $this->assertSame('debito', Cuenta::where('nombre', 'HEY BANCO (DÉBITO)')->firstOrFail()->tipo);

        // Los gastos importados no tienen usuario.
        $this->assertSame(0, Gasto::whereNotNull('user_id')->count());
    }

    public function test_no_duplica_al_importar_otra_vez_y_las_repetidas_si_entran_dos_veces(): void
    {
        $ruta = $this->libro();
        (new ImportadorGastosSheets)->importar($ruta);
        // monto 1000: en MariaDB la comparación no distingue mayúsculas y "GASOLINA" (2023, 1500) también coincidiría.
        $this->assertSame(2, Gasto::where('descripcion', 'Gasolina')->where('monto', 1000)->count(), 'dos renglones idénticos son dos gastos');
        $this->assertSame(19, Gasto::count());
        $cuentas = Cuenta::count();

        $previa = (new ImportadorGastosSheets)->analizar($ruta);
        $this->assertSame(0, $previa['totales']['a_importar']);
        $this->assertSame(19, $previa['totales']['ya_estaban']);

        $r = (new ImportadorGastosSheets)->importar($ruta);
        $this->assertSame(0, $r['totales']['insertadas']);
        $this->assertSame(19, Gasto::count());
        $this->assertSame($cuentas, Cuenta::count());

        // Si uno se borró a mano, no reaparece; y si el Excel crece, solo entra lo nuevo.
        Gasto::where('descripcion', 'texto con diagonal')->firstOrFail()->delete();
        $hoja = $this->hoja2024();
        $hoja[] = [6, 26, $this->serial('2024-06-28'), 321, 'GASOLINA', 'gasto nuevo', 'EFECTIVO'];
        $nuevo = $this->excel(['GASTOS 2025' => $this->hoja2025(), 'GASTOS 2024' => $hoja, 'GASTOS 2023' => $this->hoja2023()]);
        $r = (new ImportadorGastosSheets)->importar($nuevo);
        $this->assertSame(1, $r['totales']['insertadas']);
        $this->assertSame(19, $r['totales']['ya_estaban']);
        $this->assertSame(0, Gasto::where('descripcion', 'texto con diagonal')->count());
        $this->assertSame(1, Gasto::where('descripcion', 'gasto nuevo')->count());
    }

    public function test_una_hoja_sin_encabezado_se_avisa_y_no_se_importa(): void
    {
        $r = (new ImportadorGastosSheets)->importar($this->excel(['GASTOS 2022' => [['A', 'B'], [1, 2]], 'GASTOS 2023' => $this->hoja2023()]));
        $this->assertStringContainsString('No encontré los encabezados', $r['hojas'][0]['error']);
        $this->assertSame(3, $r['totales']['insertadas']);
        $this->assertSame(3, Gasto::count());
    }

    public function test_las_anomalias_se_limitan_a_30(): void
    {
        $filas = [['FECHA', 'MONTO']];
        for ($i = 0; $i < 40; $i++) {
            $filas[] = [$this->serial('2025-03-01'), 'raro'.$i];
        }
        $r = (new ImportadorGastosSheets)->analizar($this->excel(['GASTOS 2025' => $filas]));
        $this->assertSame(40, $r['anomalias_total']);
        $this->assertCount(30, $r['anomalias']);
        $this->assertCount(30, $r['hojas'][0]['anomalias']);
    }

    // ===== Pantalla =====

    public function test_la_pantalla_muestra_la_vista_previa_de_gastos_y_los_importa_con_un_solo_boton(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $archivo = fn () => UploadedFile::fake()->createWithContent('Ventas_MASUME_SHEETS.xlsx', file_get_contents($this->libro()));

        $c = Livewire::test(Importar::class)
            ->set('archivo', $archivo())->assertHasNoErrors()
            ->assertSee('Revisa antes de importar')->assertSee('Gastos (hojas GASTOS)')->assertSee('Gastos nuevos')
            ->assertSee('Movimientos entre cuentas')->assertSee('GASTOS 2024')->assertSee('Encabezados en el renglón 9')
            ->assertSee('(se creará la cuenta)')->assertSee('3 datos por revisar')
            ->assertSee('no se importa')->assertSee('$2,700.00');
        $this->assertSame(0, Gasto::count(), 'todavía no se importa nada');

        $c->call('importar')->assertSee('Listo: se importaron 19 gastos de las hojas de gastos (0 ya estaban)')->assertSee('Ir a Gastos');
        $this->assertSame(19, Gasto::count());
        $this->assertSame([], Storage::disk('local')->files('finanzas'), 'la copia temporal se borra');

        // Otra vez el mismo Excel: nada nuevo y no aparece el botón de importar.
        Livewire::test(Importar::class)->set('archivo', $archivo())
            ->assertSee('No hay nada nuevo que importar')->assertDontSee('>Importar</button>', false);
        $this->assertSame(19, Gasto::count());
    }

    public function test_un_excel_solo_con_gastos_sirve_y_se_importa_junto_con_las_ventas(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['rol' => 'admin']));

        Livewire::test(Importar::class)
            ->set('archivo', UploadedFile::fake()->createWithContent('g.xlsx', file_get_contents($this->excel(['GASTOS 2023' => $this->hoja2023()]))))
            ->assertHasNoErrors()->assertDontSee('No encontré hojas de ventas')->assertSee('Gastos nuevos')->assertDontSee('Facturas y notas nuevas');

        $ventas = [
            ['FOLIO', 'FECHA', 'CLIENTE', 'COSTO', 'VENTA', 'IVA', 'TOTAL', 'ESTATUS'],
            ['A-1', $this->serial('2026-03-01'), 'ACME', 100, 150, 24, 174, 'PAGADA'],
        ];
        $ruta = $this->excel(['VENTAS 2026' => $ventas, 'GASTOS 2023' => $this->hoja2023()]);
        Livewire::test(Importar::class)->set('archivo', UploadedFile::fake()->createWithContent('x.xlsx', file_get_contents($ruta)))
            ->assertSee('Gastos nuevos')
            ->call('importar')->assertSee('Listo: se importaron 3 gastos');
        $this->assertSame(3, Gasto::count());
        $this->assertSame(1, Venta::count());
    }
}

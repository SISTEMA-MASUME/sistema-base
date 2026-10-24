<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\Importar;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\User;
use App\Models\Venta;
use App\Support\ImportadorVentasSheets;
use App\Support\LectorXlsx;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/** Importar el historial de ventas del Google Sheets (Excel descargado): lector, conversiones y pantalla (solo admin). */
class ImportarVentasSheetsTest extends TestCase
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

    /** Número de serie de Excel de una fecha (como lo guarda Google Sheets). */
    private function serial(string $fecha): int
    {
        return (new DateTimeImmutable($fecha))->diff(new DateTimeImmutable('1899-12-30'))->days;
    }

    /** Renglón por letra de columna: ['B' => 'x', 'D' => 5] → [null, 'x', null, 5]. */
    private function fila(array $porLetra): array
    {
        $fila = [];
        foreach ($porLetra as $letra => $valor) {
            $fila[ord($letra) - 65] = $valor;
        }
        $mayor = $fila === [] ? -1 : max(array_keys($fila));
        for ($i = 0; $i <= $mayor; $i++) {
            $fila[$i] ??= null;
        }
        ksort($fila);

        return $fila;
    }

    /** Arma un .xlsx mínimo como el que descarga Google Sheets: hoja => renglones (cada uno, lista de celdas). */
    private function excel(array $hojas): string
    {
        $base = tempnam(sys_get_temp_dir(), 'vta');
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
                        continue;   // celda vacía: no se escribe, la columna se respeta por la referencia
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

    /**
     * El libro de prueba, con la forma real del Sheets: VENTAS 2026 con encabezado en el renglón 1 y VENTAS 2024 con
     * el encabezado repetido en el 2 y el bueno en el 11. $extra agrega renglones al final de VENTAS 2026.
     */
    private function libro(array $extra = []): string
    {
        $enc26 = $this->fila(['A' => 'ESTATUS PORTAL', 'B' => 'CLIENTE', 'C' => 'CONCEPTO', 'D' => 'FECHA FACTURA', 'E' => 'TIPO DE DOCUMENTO',
            'F' => 'FOLIO', 'G' => 'COMPRA', 'H' => 'ESTATUS', 'I' => 'FECHA DE PAGO', 'J' => 'VENTA', 'K' => 'UTILIDAD', 'L' => 'PORCENTAJE',
            'M' => 'IVA', 'N' => 'PAGO', 'Q' => 'FACTURA SUSTITUTA', 'R' => 'CUENTA DEPOSITO']);
        $v2026 = [
            $enc26,
            // 2: folio que Sheets convirtió en fecha ("5-5052" = 1/mayo/5052), pagada con fecha de pago con hora, en una cuenta
            $this->fila(['A' => 'CARGADA', 'B' => 'Hotel Aurora S.A. de C.V.', 'C' => 'MINI SPLIT', 'D' => $this->serial('2026-01-05'), 'E' => 'FACTURA',
                'F' => $this->serial('5052-05-01'), 'G' => 4000, 'H' => 'PAGADA', 'I' => $this->serial('2026-04-23') + 0.50496, 'J' => 5000.1,
                'K' => 1000.1, 'L' => 0.25, 'M' => 800.016, 'N' => 5800.116, 'R' => 'Hey Banco (Débito)']),
            // 3: nota de venta pendiente; IVA y PAGO vacíos (se calculan); portal "NO APLICA"
            $this->fila(['A' => 'NO APLICA', 'B' => 'PLAZA NORTE', 'C' => 'GUANTES', 'D' => $this->serial('2026-01-06'), 'E' => 'NOTA DE VENTA',
                'F' => 'NOTA-298', 'G' => 100, 'H' => 'PENDIENTE', 'J' => 200]),
            // 4 y 5: una factura sustituida y su sustituta
            $this->fila(['B' => 'CAMPO VERDE', 'C' => 'CABLE', 'D' => $this->serial('2026-02-01'), 'E' => 'FACTURA', 'F' => '5-3869', 'G' => 700,
                'H' => 'SUSTITUIDA', 'J' => 1000, 'Q' => '5-3870']),
            $this->fila(['B' => 'CAMPO VERDE', 'C' => 'CABLE', 'D' => $this->serial('2026-02-10'), 'E' => 'FACTURA', 'F' => '5-3870', 'G' => 700,
                'H' => 'PAGADA', 'I' => $this->serial('2026-03-12'), 'J' => 1000]),
            // 6 y 7: el mismo folio dos veces en la hoja (entra solo el primero)
            $this->fila(['B' => 'PLAZA NORTE', 'C' => 'FOCOS', 'D' => $this->serial('2026-02-12'), 'E' => 'FACTURA', 'F' => '5-4925', 'G' => 300,
                'H' => 'PAGADA', 'I' => $this->serial('2026-03-20'), 'J' => 500]),
            $this->fila(['B' => 'PLAZA NORTE', 'C' => 'FOCOS', 'D' => $this->serial('2026-02-12'), 'E' => 'FACTURA', 'F' => '5-4925', 'G' => 300,
                'H' => 'PAGADA', 'I' => $this->serial('2026-03-20'), 'J' => 500]),
            // 8: renglón de totales (no cuenta)
            $this->fila(['B' => 'TOTAL', 'J' => 99999]),
            // 9: sin fecha (anomalía, no se importa)
            $this->fila(['B' => 'ACME', 'C' => 'SIN FECHA', 'E' => 'FACTURA', 'F' => '5-4002', 'H' => 'PAGADA', 'J' => 10]),
            // 10: sin cliente pero con folio y fecha: es una venta real
            $this->fila(['B' => null, 'C' => 'DISCO DE CORTE 4-1/2"', 'D' => $this->serial('2026-01-07'), 'E' => 'FACTURA', 'F' => '5-3346', 'G' => 1500.5,
                'H' => 'PAGADA', 'I' => $this->serial('2026-02-06'), 'J' => 2000.75]),
            // 11: ya está en el sistema
            $this->fila(['B' => 'ACME', 'C' => 'YA ESTABA', 'D' => $this->serial('2026-01-08'), 'E' => 'FACTURA', 'F' => '5-4001', 'G' => 1, 'H' => 'PAGADA',
                'I' => $this->serial('2026-02-08'), 'J' => 2]),
            // 12: montos en texto y uno que no se puede leer; pagada sin fecha de pago
            $this->fila(['B' => 'ACME', 'C' => 'TEXTOS', 'D' => $this->serial('2026-05-02'), 'E' => 'FACTURA', 'F' => '5-4100', 'G' => 'abc', 'H' => 'PAGADA',
                'J' => '$1,234.50']),
            // 13: factura de diciembre capturada con año futuro y pagada antes
            $this->fila(['B' => 'ACME', 'C' => 'AÑO MAL', 'D' => $this->serial('2026-12-23'), 'E' => 'FACTURA', 'F' => '5-4200', 'G' => 60, 'H' => 'PAGADA',
                'I' => $this->serial('2026-03-06'), 'J' => 100]),
            ...$extra,
        ];

        $enc24 = $this->fila(['B' => 'CLIENTE', 'C' => 'CONCEPTO', 'D' => 'FECHA', 'E' => 'FACTURA VENT', 'F' => 'COMPRA', 'G' => 'ESTATUS',
            'H' => 'FECHA DE PAGO', 'I' => 'VENTA', 'J' => 'UTILIDAD', 'K' => 'PORCENTAJE', 'L' => 'IVA', 'M' => 'RETENCION', 'N' => 'PAGO']);
        $v2024 = [
            [],
            $enc24,   // copia del encabezado sin FACTURA SUSTITUTA ni CUENTA DEPOSITO
            [], [], [], [], [],
            $this->fila(['G' => 'RECUPERADO', 'H' => 4080963.259]),
            $this->fila(['G' => 'PORCENTAJE UTILIDAD BRUTA', 'H' => 0.2169630468]),
            $this->fila(['G' => 'UTILIDAD BRUTA PAGADA', 'H' => 893096.57]),
            $this->fila(['B' => 'CLIENTE', 'C' => 'CONCEPTO', 'D' => 'FECHA', 'E' => 'FACTURA VENT', 'F' => 'COMPRA', 'G' => 'ESTATUS',
                'H' => 'FECHA DE PAGO', 'I' => 'VENTA', 'J' => 'UTILIDAD', 'K' => 'PORCENTAJE', 'L' => 'IVA', 'M' => 'RETENCION', 'N' => 'PAGO',
                'Q' => 'FACTURA SUSTITUTA', 'R' => 'CUENTA DEPOSITO']),
            // 12: fecha y fecha de pago en texto sin año (año de la hoja); IVA y retención con decimales largos
            $this->fila(['B' => 'FERRETERIA UNO S.A. DE C.V.', 'C' => 'TUERCA UNION', 'D' => '22 DE MAYO', 'E' => '5-397', 'F' => 200, 'G' => 'PAGADA',
                'H' => '8 DE JUNIO', 'I' => 300.3, 'J' => 100.3, 'K' => 0.5, 'L' => 48.048, 'M' => 3.75375, 'N' => 344.59425]),
            // 13: repetida de la hoja 2026
            $this->fila(['B' => 'CAMPO VERDE', 'C' => 'CABLE VIEJO', 'D' => $this->serial('2024-02-01'), 'E' => '5-3870', 'F' => 1, 'G' => 'PAGADA',
                'H' => $this->serial('2024-03-01'), 'I' => 10]),
            // 14: la misma nota escrita distinto ("NOTA 298" / "NOTA-298")
            $this->fila(['B' => 'PLAZA NORTE', 'C' => 'GUANTES VIEJOS', 'D' => $this->serial('2024-02-02'), 'E' => 'NOTA 298', 'F' => 1, 'G' => 'PENDIENTE', 'I' => 10]),
            // 15 y 16: notas con espacio, y sin número
            $this->fila(['B' => 'PLAZA NORTE', 'C' => 'CINTA', 'D' => $this->serial('2024-03-04'), 'E' => 'NOTA 90 ', 'F' => 50, 'G' => 'PAGADA',
                'H' => $this->serial('2024-04-03'), 'I' => 80]),
            $this->fila(['B' => 'PLAZA NORTE', 'C' => 'GUANTES', 'D' => $this->serial('2024-06-07'), 'E' => 'nota', 'F' => 100, 'G' => 'PAGADA',
                'H' => $this->serial('2024-07-07'), 'I' => 150]),
            // 17: "por cancelar" (estatus que no estaba en la lista)
            $this->fila(['B' => 'CENTRO COMERCIAL PLAZA NORTE', 'C' => 'LINTERNA', 'D' => $this->serial('2024-07-02'), 'E' => '5-851', 'G' => 'P/CANCELAR']),
            // 18: pago en enero de la factura de diciembre (sin año: el siguiente)
            $this->fila(['B' => 'BODEGAS DEL NORTE', 'C' => 'PILAS', 'D' => '20 DE DICIEMBRE', 'E' => '5-900', 'F' => 100, 'G' => 'PAGADA',
                'H' => '5 DE ENERO', 'I' => 200]),
            // 19: pendiente con la "fecha de pago" que el Sheets calcula (factura + 30): no se guarda
            $this->fila(['B' => 'BODEGAS DEL NORTE', 'C' => 'BROCAS', 'D' => $this->serial('2024-08-01'), 'E' => '5-901', 'F' => 300, 'G' => 'PENDIENTE',
                'H' => $this->serial('2024-08-31'), 'I' => 400]),
            // 20: con retención, en la misma cuenta escrita sin acento
            $this->fila(['B' => 'FERRETERIA UNO S.A. DE C.V.', 'C' => 'DISCOS', 'D' => $this->serial('2024-01-31'), 'E' => '5-902', 'F' => 700, 'G' => 'PAGADA',
                'H' => $this->serial('2024-03-01'), 'I' => 1000, 'L' => 160, 'M' => 40, 'N' => 1120, 'R' => 'HEY BANCO (DEBITO)']),
        ];

        return $this->excel(['CONTROL' => [['no', 'es', 'de ventas']], 'VENTAS 2024' => $v2024, 'VENTAS 2026' => $v2026]);
    }

    private function sistemaConClientes(): void
    {
        Cliente::factory()->create(['nombre' => 'HOTEL AURORA']);
        Cliente::factory()->create(['nombre' => 'PLAZA NORTE']);
        Venta::create(['tipo' => 'factura', 'folio' => '5-4001', 'cliente_nombre' => 'ACME', 'fecha' => '2026-01-01', 'origen' => 'manual']);
    }

    // ---------------------------------------------------------------- lector

    public function test_lector_respeta_columnas_tipos_y_renglones_vacios(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'lec');
        $ruta = $base.'.xlsx';
        @unlink($base);
        $this->temporales[] = $ruta;
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook '.$ns.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Oculta" sheetId="1" state="hidden" r:id="rId1"/><sheet name="Datos" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="/xl/worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>');
        // 0 "uno"; 1 texto con formato rico (y fonética que no cuenta); 2 texto vacío
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst '.$ns.'><si><t>uno</t></si><si><r><t xml:space="preserve">Hola </t></r><r><rPr><b/></rPr><t>mundo</t></r><rPh sb="0" eb="1"><t>FONETICA</t></rPh></si><si><t/></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet '.$ns.'><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>');
        $zip->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0"?><worksheet '.$ns.'><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" s="9"/><c r="D1"><v>46027.0</v></c><c r="E1"><v>2.5</v></c></row>'
            .'<row r="2"/>'
            .'<row r="4"><c r="B4" t="s"><v>1</v></c><c r="C4" t="s"><v>2</v></c><c r="F4" t="inlineStr"><is><t>a &lt; b</t></is></c>'
            .'<c r="G4" t="str"><f>A1&amp;"x"</f><v>unox</v></c><c r="H4" t="str"><f>""</f><v></v></c><c r="I4" t="b"><v>1</v></c>'
            .'<c r="J4" t="e"><v>#DIV/0!</v></c><c r="K4"><f>1+1</f><v>2</v></c><c r="AB4"><v>7</v></c></row>'
            .'<row r="7"><c r="A7" s="3"/></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $lector = new LectorXlsx($ruta);
        $this->assertSame(['Oculta', 'Datos'], $lector->hojas(), 'también las ocultas, en el orden del libro');
        $this->assertSame([['uno']], $lector->filas('Oculta'));

        $filas = $lector->filas('Datos');
        $this->assertCount(4, $filas, 'el renglón 7 solo trae una celda vacía: se recorta');
        $this->assertSame(['uno', null, null, 46027, 2.5], $filas[0], 'la celda vacía B1 no corre las columnas');
        $this->assertSame([], $filas[1]);
        $this->assertSame([], $filas[2], 'el renglón 3 no existe en el archivo pero el 4 sigue siendo el índice 3');
        $esperado = array_fill(0, 28, null);
        $esperado[1] = 'Hola mundo';   // B
        $esperado[5] = 'a < b';        // F: inlineStr
        $esperado[6] = 'unox';         // G: resultado guardado de una fórmula de texto
        $esperado[8] = true;           // I: booleano
        $esperado[9] = '#DIV/0!';      // J: error de fórmula
        $esperado[10] = 2;             // K: valor guardado de una fórmula
        $esperado[27] = 7;             // AB
        $this->assertSame($esperado, $filas[3]);

        $this->assertSame('AB', LectorXlsx::letra(27));
        $this->assertSame('2026-01-05', LectorXlsx::fecha(46027)->format('Y-m-d'));
        $this->assertSame('2026-01-05', LectorXlsx::fecha(46027.9)->format('Y-m-d'), 'la hora se ignora');
        $this->assertSame('5052-05-01', LectorXlsx::fecha($this->serial('5052-05-01'))->format('Y-m-d'));
    }

    public function test_lector_avisa_si_no_es_un_excel(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'lec');
        $this->temporales[] = $ruta;
        file_put_contents($ruta, 'esto no es un zip');
        $this->expectException(InvalidArgumentException::class);
        new LectorXlsx($ruta);
    }

    // ------------------------------------------------------------ importador

    public function test_analizar_cuenta_todo_sin_guardar_nada(): void
    {
        $this->sistemaConClientes();
        $clientes = Cliente::count();

        $r = (new ImportadorVentasSheets)->analizar($this->libro());

        $this->assertFalse($r['guardado']);
        $this->assertSame(['VENTAS 2026', 'VENTAS 2024'], array_column($r['hojas'], 'nombre'), 'de la más nueva a la más vieja; CONTROL se ignora');
        [$h26, $h24] = $r['hojas'];

        $this->assertSame(2026, $h26['anio']);
        $this->assertSame(1, $h26['fila_encabezado']);
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'M', 'N', 'Q', 'R'], collect($h26['columnas'])->flatten()->sort()->values()->all());
        $this->assertSame(11, $h24['fila_encabezado'], 'el encabezado bueno de 2024 es el del renglón 11, no la copia del 2');
        $this->assertSame('Q', $h24['columnas']['sustituta']);
        $this->assertSame('R', $h24['columnas']['cuenta']);

        $this->assertSame(
            ['filas' => 11, 'saltadas' => 1, 'a_importar' => 8, 'ya_estaban' => 1, 'repetidas' => 1, 'pagadas' => 6, 'pendientes' => 1, 'canceladas' => 1],
            collect($h26)->only(['filas', 'saltadas', 'a_importar', 'ya_estaban', 'repetidas', 'pagadas', 'pendientes', 'canceladas'])->all(),
        );
        $this->assertSame(['5-4925'], $h26['repetidas_folios']);
        $this->assertEqualsWithDelta(10035.35, $h26['venta'], 0.001);   // sin la sustituida (cancelada)
        $this->assertEqualsWithDelta(6660.50, $h26['costo'], 0.001);
        $this->assertEqualsWithDelta(3374.85, $h26['utilidad'], 0.001);
        $this->assertSame(5, $h26['anomalias_total']);
        $this->assertCount(5, $h26['anomalias']);

        $this->assertSame(
            ['filas' => 9, 'saltadas' => 0, 'a_importar' => 7, 'ya_estaban' => 0, 'repetidas' => 2, 'pagadas' => 5, 'pendientes' => 1, 'canceladas' => 1],
            collect($h24)->only(['filas', 'saltadas', 'a_importar', 'ya_estaban', 'repetidas', 'pagadas', 'pendientes', 'canceladas'])->all(),
        );
        $this->assertSame(['5-3870 (ya está en VENTAS 2026)', 'NOTA-298 (ya está en VENTAS 2026)'], $h24['repetidas_folios']);
        $this->assertEqualsWithDelta(2130.30, $h24['venta'], 0.001);
        $this->assertEqualsWithDelta(1450.00, $h24['costo'], 0.001);
        $this->assertSame(2, $h24['anomalias_total']);

        $t = $r['totales'];
        $this->assertSame(15, $t['a_importar']);
        $this->assertSame(3, $t['repetidas']);
        $this->assertSame(1, $t['ya_estaban']);
        $this->assertSame(1, $t['saltadas']);
        $this->assertSame(7, $t['anomalias_total']);
        $this->assertEqualsWithDelta(12165.65, $t['venta'], 0.001);
        $this->assertSame([['nombre' => 'Hey Banco (Débito)', 'ventas' => 2, 'nueva' => true]], $r['cuentas']);

        // Solo mirar: no se creó nada.
        $this->assertSame(1, Venta::count());
        $this->assertSame(0, Cuenta::count());
        $this->assertSame($clientes, Cliente::count());
    }

    public function test_importa_con_las_conversiones_del_sheets(): void
    {
        $this->sistemaConClientes();
        $aurora = Cliente::where('nombre', 'HOTEL AURORA')->value('id');
        $plazaNorte = Cliente::where('nombre', 'PLAZA NORTE')->value('id');

        $r = (new ImportadorVentasSheets)->importar($this->libro());

        $this->assertTrue($r['guardado']);
        $this->assertSame(15, $r['totales']['insertadas']);
        $this->assertSame(16, Venta::count());   // las 15 y la que ya estaba
        $this->assertSame(['sheets'], Venta::where('origen', 'sheets')->pluck('origen')->unique()->values()->all());
        $v = fn (string $folio, string $tipo = 'factura') => Venta::where('folio', $folio)->where('tipo', $tipo)->firstOrFail();

        // Folio que venía como número (Sheets lo convirtió en fecha) y fecha de pago con hora.
        $a = $v('5-5052');
        $this->assertSame('factura|5-5052', $a->folio_llave);
        $this->assertSame($aurora, $a->cliente_id, 'sin "S.A. de C.V." coincide con el cliente del sistema');
        $this->assertSame('HOTEL AURORA S.A. DE C.V', $a->cliente_nombre);
        $this->assertSame('2026-01-05', $a->fecha->toDateString());
        $this->assertSame('2026-04-23', $a->fecha_pago->toDateString());
        $this->assertSame('pagada', $a->estatus);
        $this->assertSame('cargada', $a->estatus_portal);
        $this->assertSame(['4000.00', '5000.10', '800.02', '0.00', '5800.12'], [$a->costo, $a->subtotal, $a->iva, $a->retencion, $a->total]);
        $this->assertSame('MINI SPLIT', $a->concepto);
        $cuenta = Cuenta::where('nombre_llave', 'HEYBANCODEBITO')->firstOrFail();
        $this->assertSame($cuenta->id, $a->cuenta_id);
        $this->assertSame('debito', $cuenta->tipo);

        // Nota de venta: tipo, IVA y total calculados, pendiente sin fecha de pago, portal "NO APLICA".
        $n = $v('NOTA-298', 'nota');
        $this->assertSame($plazaNorte, $n->cliente_id);
        $this->assertSame(['pendiente', null, 'no_aplica'], [$n->estatus, $n->fecha_pago, $n->estatus_portal]);
        $this->assertSame(['100.00', '200.00', '32.00', '232.00'], [$n->costo, $n->subtotal, $n->iva, $n->total]);

        // SUSTITUIDA: cancelada con nota y la factura que la sustituyó.
        $s = $v('5-3869');
        $this->assertSame('cancelada', $s->estatus);
        $this->assertSame('Sustituida (del Sheets)', $s->notas);
        $this->assertSame('5-3870', $s->factura_sustituta);
        $this->assertNull($s->cliente_id, 'no se crean clientes');
        $this->assertSame(1, Venta::where('folio', '5-3870')->count(), 'la repetida entre hojas entra una sola vez');
        $this->assertSame('2026-02-10', $v('5-3870')->fecha->toDateString(), 'gana la de la hoja más nueva');
        $this->assertSame('300.00', $v('5-4925')->costo);

        // Sin cliente: se importa igual, avisando.
        $sc = $v('5-3346');
        $this->assertSame('SIN CLIENTE', $sc->cliente_nombre);
        $this->assertNull($sc->cliente_id);
        $this->assertSame('2000.75', $sc->subtotal);

        // Montos en texto, uno ilegible, pagada sin fecha de pago.
        $t = $v('5-4100');
        $this->assertSame(['0.00', '1234.50', '197.52', '1432.02'], [$t->costo, $t->subtotal, $t->iva, $t->total]);
        $this->assertSame(['pagada', null], [$t->estatus, $t->fecha_pago]);

        // Hoja 2024: fechas en texto sin año (año de la hoja), retención, IVA y total con decimales largos.
        $f = $v('5-397');
        $this->assertSame('2024-05-22', $f->fecha->toDateString());
        $this->assertSame('2024-06-08', $f->fecha_pago->toDateString());
        $this->assertSame(['200.00', '300.30', '48.05', '3.75', '344.59'], [$f->costo, $f->subtotal, $f->iva, $f->retencion, $f->total]);
        $this->assertSame('FERRETERIA UNO S.A. DE C.V', $f->cliente_nombre);
        $ret = $v('5-902');
        $this->assertSame(['1000.00', '160.00', '40.00', '1120.00'], [$ret->subtotal, $ret->iva, $ret->retencion, $ret->total]);
        $this->assertSame($cuenta->id, $ret->cuenta_id, 'la misma cuenta escrita sin acento');
        $this->assertSame(1, Cuenta::count());

        // Pago en texto sin año antes de la factura: el año siguiente; pendiente no guarda "fecha de pago".
        $this->assertSame('2025-01-05', $v('5-900')->fecha_pago->toDateString());
        $this->assertSame('2024-12-20', $v('5-900')->fecha->toDateString());
        $pen = $v('5-901');
        $this->assertSame(['pendiente', null], [$pen->estatus, $pen->fecha_pago]);

        // Estatus que no estaba en la lista.
        $c = $v('5-851');
        $this->assertSame('cancelada', $c->estatus);
        $this->assertSame('Estatus en el Sheets: P/CANCELAR', $c->notas);

        // Notas: una sola secuencia sin importar cómo se escribió.
        $this->assertSame(['nota|90', 'NOTA-90'], [$v('NOTA-90', 'nota')->folio_llave, $v('NOTA-90', 'nota')->folio]);
        $this->assertSame(1, Venta::where('tipo', 'nota')->where('folio_llave', 'nota|298')->count(), '"NOTA 298" de 2024 es la misma que "NOTA-298" de 2026');
        $this->assertSame(['NOTA-SN-2024-F16'], Venta::where('folio', 'like', 'NOTA-SN%')->pluck('folio')->all());

        // Los mensajes de revisión.
        $anomalias = collect($r['hojas'])->pluck('anomalias')->flatten()->implode("\n");
        $this->assertStringContainsString('Hoja VENTAS 2026 fila 9: sin fecha válida (vacío), no se importó', $anomalias);
        $this->assertStringContainsString('Hoja VENTAS 2026 fila 10: sin cliente, se importó como "SIN CLIENTE"', $anomalias);
        $this->assertStringContainsString('Hoja VENTAS 2026 fila 12: monto no válido en COMPRA (abc), se tomó $0.00', $anomalias);
        $this->assertStringContainsString('Hoja VENTAS 2026 fila 12: pagada sin fecha de pago', $anomalias);
        $this->assertStringContainsString('Hoja VENTAS 2026 fila 13: fecha de factura 2026-12-23 en el futuro y fecha de pago 2026-03-06 anterior a la factura (¿error de año?)', $anomalias);
        $this->assertStringContainsString('Hoja VENTAS 2024 fila 16: nota sin número, se importó como NOTA-SN-2024-F16', $anomalias);
        $this->assertStringContainsString('Hoja VENTAS 2024 fila 17: estatus "P/CANCELAR": se importó como cancelada', $anomalias);
    }

    public function test_reimportar_no_duplica_y_solo_agrega_lo_nuevo(): void
    {
        $this->sistemaConClientes();
        $importador = new ImportadorVentasSheets;

        $primera = $importador->importar($this->libro());
        $this->assertSame(15, $primera['totales']['insertadas']);
        $this->assertSame(16, Venta::count());

        // El mismo Excel otra vez: todo "ya estaba" (también lo repetido sigue contando como repetido).
        $segunda = $importador->importar($this->libro());
        $this->assertSame(0, $segunda['totales']['insertadas']);
        $this->assertSame(0, $segunda['totales']['a_importar']);
        $this->assertSame(16, $segunda['totales']['ya_estaban']);
        $this->assertSame(3, $segunda['totales']['repetidas']);
        $this->assertSame(16, Venta::count());
        $this->assertSame(1, Cuenta::count());

        // Un Excel más nuevo con una factura más: entra solo esa.
        $nuevo = [$this->fila(['B' => 'ACME', 'C' => 'NUEVA', 'D' => $this->serial('2026-06-01'), 'E' => 'FACTURA', 'F' => '5-5000', 'G' => 10, 'H' => 'PENDIENTE', 'J' => 20])];
        $tercera = $importador->importar($this->libro($nuevo));
        $this->assertSame(1, $tercera['totales']['insertadas']);
        $this->assertSame(17, Venta::count());
        $this->assertSame('5-5000', Venta::latest('id')->first()->folio);
    }

    public function test_el_renglon_de_solo_pago_de_la_hoja_nueva_se_completa_con_los_montos_de_la_vieja(): void
    {
        $enc26 = $this->fila(['B' => 'CLIENTE', 'D' => 'FECHA FACTURA', 'E' => 'TIPO DE DOCUMENTO', 'F' => 'FOLIO', 'G' => 'COMPRA', 'H' => 'ESTATUS', 'I' => 'FECHA DE PAGO', 'J' => 'VENTA']);
        $enc25 = $this->fila(['B' => 'CLIENTE', 'D' => 'FECHA FACTURA', 'E' => 'FACTURA', 'F' => 'COMPRA', 'G' => 'ESTATUS', 'H' => 'FECHA DE PAGO', 'I' => 'VENTA']);
        $r = (new ImportadorVentasSheets)->importar($this->excel([
            'VENTAS 2026' => [$enc26, $this->fila(['B' => 'ACME MEXICO', 'D' => $this->serial('2025-12-19'), 'E' => 'FACTURA', 'F' => '5-3593', 'H' => 'PAGADA', 'I' => $this->serial('2026-03-06')])],
            'VENTAS 2025' => [$enc25, $this->fila(['B' => 'ACME MEXICO', 'D' => $this->serial('2025-12-19'), 'E' => '5-3593', 'F' => 1408.64, 'G' => 'PAGADA', 'H' => $this->serial('2026-03-06'), 'I' => 1803.07])],
        ]));

        $v = Venta::where('folio', '5-3593')->sole();
        $this->assertSame('1803.07', $v->subtotal);
        $this->assertSame('1408.64', $v->costo);
        $this->assertSame('pagada', $v->estatus);
        $this->assertSame('2026-03-06', $v->fecha_pago->toDateString());
        $this->assertSame(1, $r['hojas'][1]['completadas']);
        $this->assertSame(0, $r['hojas'][1]['repetidas']);
        $this->assertSame(1, $r['totales']['insertadas']);
    }

    public function test_diciembre_capturado_con_el_anio_siguiente_se_corrige_al_anio_de_la_hoja(): void
    {
        $enc = $this->fila(['B' => 'CLIENTE', 'D' => 'FECHA FACTURA', 'E' => 'FACTURA', 'F' => 'COMPRA', 'G' => 'ESTATUS', 'H' => 'FECHA DE PAGO', 'I' => 'VENTA']);
        $futuro = now()->addYear()->year;
        (new ImportadorVentasSheets)->importar($this->excel(['VENTAS '.($futuro - 1) => [
            $enc,
            $this->fila(['B' => 'ACME', 'D' => $this->serial("{$futuro}-12-22"), 'E' => '5-77', 'G' => 'PAGADA', 'H' => $this->serial(($futuro - 1).'-12-30'), 'I' => 100]),
        ]]));
        $this->assertSame(($futuro - 1).'-12-22', Venta::where('folio', '5-77')->sole()->fecha->toDateString());
    }

    public function test_perdona_fechas_con_errores_de_dedo_y_no_pierde_lo_ilegible(): void
    {
        $enc = $this->fila(['B' => 'CLIENTE', 'C' => 'CONCEPTO', 'D' => 'FECHA', 'E' => 'FACTURA VENT', 'F' => 'COMPRA', 'G' => 'ESTATUS',
            'H' => 'FECHA DE PAGO', 'I' => 'VENTA', 'J' => 'FACTURA']);
        $r = (new ImportadorVentasSheets)->importar($this->excel(['VENTAS COMPRAS 2023' => [
            $enc,
            $this->fila(['B' => 'ACME', 'D' => '7 DEJULIO', 'E' => '5-1', 'G' => 'PAGADA', 'H' => '21 DE JULIO', 'I' => 100]),            // 2: el DE pegado al mes
            $this->fila(['B' => 'ACME', 'D' => '25 DE SEPTIEMRBE', 'E' => '5-2', 'G' => 'PAGADA', 'H' => '8 DE OCTUBRE', 'I' => 100]),    // 3: mes con letras cambiadas
            $this->fila(['B' => 'ACME', 'D' => 'NO', 'E' => '5-9', 'I' => 100]),                                                          // 4: no es fecha
            $this->fila(['B' => 'ACME', 'D' => (string) $this->serial('2023-03-15'), 'E' => '5-3', 'G' => 'PAGADA', 'H' => '16 DE MARZO', 'I' => 100]),   // 5: serial como texto
            $this->fila(['B' => 'ACME', 'D' => '08 -01-24', 'E' => '5-4', 'G' => 'PAGADA', 'H' => '08/02/2024', 'I' => 100]),            // 6: dd-mm-aa y dd/mm/aaaa
            $this->fila(['B' => 'ACME', 'D' => $this->serial('2023-05-11'), 'J' => 97801, 'G' => 'PAGADA', 'H' => '30 DE MAYO', 'I' => 100]),  // 7: folio solo en FACTURA y es un número
            $this->fila(['B' => 'ACME', 'D' => $this->serial('2023-05-12'), 'G' => 'PAGADA', 'I' => 100]),                                // 8: sin folio
            $this->fila(['B' => 'ACME', 'D' => $this->serial('2023-12-20'), 'E' => 'NOTA 9', 'H' => 'FACTURADA 2024', 'I' => 0]),         // 9: nota que se facturó después
            $this->fila(['B' => 'ACME', 'D' => $this->serial('2023-12-21'), 'E' => 'NOT-38', 'G' => 'CANCELADA']),                         // 10: "NOTA" mal escrita
        ]]));

        $h = $r['hojas'][0];
        $this->assertSame(9, $h['filas']);
        $this->assertSame(1, $h['saltadas']);   // la fecha "NO"; el renglón sin folio entra con folio provisional
        $this->assertSame(8, $h['a_importar']);
        $fechas = Venta::orderBy('id')->get()->mapWithKeys(fn ($v) => [$v->folio => $v->fecha->toDateString()])->all();
        $this->assertSame(['5-1' => '2023-07-07', '5-2' => '2023-09-25', '5-3' => '2023-03-15', '5-4' => '2024-01-08', '97801' => '2023-05-11', 'SF-2023-F8' => '2023-05-12', 'NOTA-9' => '2023-12-20', 'NOTA-38' => '2023-12-21'], $fechas);
        $this->assertSame('nota|38', Venta::where('folio', 'NOTA-38')->value('folio_llave'), '"NOT-38" es la nota 38');
        $this->assertSame('2024-02-08', Venta::where('folio', '5-4')->first()->fecha_pago->toDateString());
        $this->assertSame('2023-03-16', Venta::where('folio', '5-3')->first()->fecha_pago->toDateString());

        // "FACTURADA 2024" no es una fecha: queda en las notas y la nota queda pendiente.
        $nota = Venta::where('folio', 'NOTA-9')->first();
        $this->assertSame(['nota', 'pendiente', null], [$nota->tipo, $nota->estatus, $nota->fecha_pago]);
        $this->assertSame('Fecha de pago en el Sheets: FACTURADA 2024', $nota->notas);

        $anomalias = implode("\n", $h['anomalias']);
        $this->assertStringContainsString('fila 3: fecha "25 DE SEPTIEMRBE" leída como 2023-09-25', $anomalias);
        $this->assertStringContainsString('fila 4: sin fecha válida (NO), no se importó', $anomalias);
        $this->assertStringContainsString('fila 7: folio numérico', $anomalias);
        $this->assertStringContainsString('fila 8: sin folio, se importó como SF-2023-F8', $anomalias);
        $this->assertStringContainsString('fila 9: fecha de pago no válida (FACTURADA 2024)', $anomalias);
        $this->assertStringNotContainsString('fila 2:', $anomalias, '"7 DEJULIO" se lee bien y no se avisa');
    }

    public function test_el_lector_aguanta_3000_renglones_por_32_columnas(): void
    {
        $filas = [];
        for ($r = 0; $r < 3000; $r++) {
            $fila = [];
            for ($c = 0; $c < 32; $c++) {
                $fila[] = $c % 4 === 0 ? 'texto '.($r % 50).'-'.$c : ($c % 7 === 0 ? null : $r * 100 + $c + 0.25);
            }
            $filas[] = $fila;
        }
        $ruta = $this->excel(['VENTAS 2026' => $filas]);
        unset($filas);
        gc_collect_cycles();

        memory_reset_peak_usage();
        $antes = memory_get_usage();
        $inicio = microtime(true);
        $leidas = (new LectorXlsx($ruta))->filas('VENTAS 2026');
        $segundos = microtime(true) - $inicio;
        $memoria = (memory_get_peak_usage() - $antes) / 1048576;

        $this->assertCount(3000, $leidas);
        $this->assertSame('texto 49-0', $leidas[2999][0]);
        $this->assertSame(1500 * 100 + 5 + 0.25, $leidas[1500][5]);
        $this->assertNull($leidas[10][7]);
        $this->assertLessThan(5, $segundos, 'unos 0.2 s; el tope es holgado para no fallar en máquinas lentas');
        $this->assertLessThan(30, $memoria, 'se lee en flujo: unos 4 MB para 96,000 celdas');
    }

    public function test_una_hoja_sin_encabezado_se_avisa_y_sin_hojas_de_ventas_no_se_importa(): void
    {
        $r = (new ImportadorVentasSheets)->analizar($this->excel([
            'VENTAS 2026' => [['A', 'B'], [1, 2]],
            'VENTAS 2025' => [$this->fila(['B' => 'CLIENTE', 'C' => 'CONCEPTO', 'E' => 'FACTURA', 'F' => 'COMPRA'])],
        ]));
        $this->assertStringContainsString('No encontré el renglón de encabezados', $r['hojas'][0]['error']);
        $this->assertStringContainsString('No encontré la columna FECHA, VENTA', $r['hojas'][1]['error']);
        $this->assertSame(0, $r['totales']['a_importar']);

        $this->expectException(InvalidArgumentException::class);
        (new ImportadorVentasSheets)->analizar($this->excel(['GASTOS 2026' => [['A']]]));
    }

    // -------------------------------------------------------------- pantalla

    public function test_pantalla_sube_muestra_la_vista_previa_e_importa(): void
    {
        Storage::fake('local');
        $this->sistemaConClientes();
        $this->actingAs(User::factory()->create(['rol' => 'admin']));

        $archivo = UploadedFile::fake()->createWithContent('Ventas_MASUME_SHEETS.xlsx', file_get_contents($this->libro()));
        $pantalla = Livewire::test(Importar::class)
            ->assertSee('1. En Google Sheets: Archivo → Descargar → Excel (.xlsx)')
            ->set('archivo', $archivo)->assertHasNoErrors()
            ->assertSee('Revisa antes de importar')
            ->assertSee('VENTAS 2026')->assertSee('VENTAS 2024')
            ->assertSee('Encabezados en el renglón 11')
            ->assertSee('$10,035.35')->assertSee('$2,130.30')
            ->assertSee('Facturas y notas nuevas')
            ->assertSee('Folios repetidos')->assertSee('5-3870 (ya está en VENTAS 2026)')
            ->assertSee('Hoja VENTAS 2026 fila 9: sin fecha válida')
            ->assertSee('Hey Banco (Débito): 2 ventas (se creará la cuenta)');
        $this->assertCount(1, Storage::disk('local')->files('finanzas'), 'copia temporal del Excel');
        $this->assertSame(1, Venta::count(), 'todavía no se importa nada');

        $pantalla->call('importar')
            ->assertSee('Listo: se importaron 15 facturas y notas de venta del Sheets')
            ->assertSee('Ir a Ventas');
        $this->assertSame(16, Venta::count());
        $this->assertSame([], Storage::disk('local')->files('finanzas'), 'la copia temporal se borra');

        // Subir el mismo archivo otra vez: no hay nada nuevo.
        Livewire::test(Importar::class)
            ->set('archivo', UploadedFile::fake()->createWithContent('Ventas_MASUME_SHEETS.xlsx', file_get_contents($this->libro())))
            ->assertSee('No hay nada nuevo que importar')->assertDontSee('>Importar</button>', false)
            ->call('cancelar')
            ->assertSee('1. En Google Sheets: Archivo → Descargar → Excel (.xlsx)');
        $this->assertSame([], Storage::disk('local')->files('finanzas'), 'cancelar borra la copia');
        $this->assertSame(16, Venta::count());
    }

    public function test_pantalla_no_deja_importar_dos_veces_al_mismo_tiempo(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $pantalla = Livewire::test(Importar::class)
            ->set('archivo', UploadedFile::fake()->createWithContent('Ventas.xlsx', file_get_contents($this->libro())));

        $otro = Cache::lock('finanzas-importar-ventas', 60);
        $this->assertTrue($otro->get());   // alguien más está importando
        $pantalla->call('importar')->assertSee('Ya hay una importación en curso');
        $this->assertSame(0, Venta::count());
        $this->assertCount(1, Storage::disk('local')->files('finanzas'), 'la copia sigue ahí para reintentar');

        $otro->release();
        $pantalla->call('importar')->assertSee('Listo: se importaron');
        $this->assertSame(16, Venta::count());
    }

    public function test_pantalla_avisa_si_el_archivo_no_sirve(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['rol' => 'admin']));

        Livewire::test(Importar::class)
            ->set('archivo', UploadedFile::fake()->createWithContent('ventas.csv', "a,b\n1,2\n"))
            ->assertSee('Sube el Excel (.xlsx)')
            ->set('archivo', UploadedFile::fake()->createWithContent('ventas.xlsx', 'no soy un excel'))
            ->assertSee('El archivo no es un Excel (.xlsx)')
            ->set('archivo', UploadedFile::fake()->createWithContent('otro.xlsx', file_get_contents($this->excel(['Hoja1' => [['A', 'B'], [1, 2]]]))))
            ->assertSee('No encontré hojas de ventas')->assertDontSee('>Importar</button>', false);
        $this->assertSame([], Storage::disk('local')->files('finanzas'));
    }

    public function test_solo_el_admin_entra_a_importar_ventas(): void
    {
        $this->get('/finanzas/importar')->assertRedirect('/login');   // sin sesión: al login

        foreach (['vendedor', 'campo'] as $rol) {
            $this->actingAs(User::factory()->create(['rol' => $rol]));
            $this->get('/finanzas/importar')->assertForbidden();
            Livewire::test(Importar::class)->assertForbidden();
        }

        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => ['ventas']]));
        $this->get('/finanzas/importar')->assertForbidden();   // el permiso de Ventas no alcanza: importar es solo del admin

        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $this->get('/finanzas/importar')->assertOk()->assertSee('Importar del Sheets');
    }
}

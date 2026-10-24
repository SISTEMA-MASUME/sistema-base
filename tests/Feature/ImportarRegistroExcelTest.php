<?php

namespace Tests\Feature;

use App\Livewire\Registro\Importar;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\Folios;
use App\Support\ImportadorRegistro;
use App\Support\LectorRegistroExcel;
use App\Support\UnificarDuplicados;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/** Importar el REGISTRO de Google Sheets subiendo el Excel descargado (pantalla solo admin). */
class ImportarRegistroExcelTest extends TestCase
{
    use RefreshDatabase;

    /** Arma un .xlsx mínimo como el que descarga Google Sheets. */
    private function excel(array $hojas): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'reg').'.xlsx';
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
                foreach (array_values($fila) as $c => $v) {
                    $celda = chr(65 + $c).($r + 1);
                    if ($v === null || $v === '') {
                        continue;
                    }
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
            $libro .= "<sheet name=\"$nombre\" sheetId=\"$i\" r:id=\"rId$i\"/>";
            $rels .= "<Relationship Id=\"rId$i\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$i.xml\"/>";
        }
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$libro.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .implode('', array_map(fn ($s) => "<si><t>$s</t></si>", $compartidas)).'</sst>');
        $zip->close();

        return $ruta;
    }

    private function registro(): string
    {
        return $this->excel([
            'TABLA_COT_HDR' => [
                ['CLIENTE', 'RESPONSABLE', 'UID_COT', 'FECHA', 'LINK_PDF', 'ID_COTIZACION', 'FORMATO_ORIGEN', 'Status'],
                ['PLAZA NORTE', 'Rosa Ruiz', 10500, '28/09/2026', 'https://drive/10500', '', '', ''],
                ['HOTEL AURORA', 'Alberto Ramos', 10600, 46294, 'https://drive/10600', '', '', ''],   // fecha como número de Excel (29/09/2026)
                ['HOTEL AURORA', 'Alberto Ramos', '10600.01C', '29/09/2026', 'https://drive/oc', 'OC INTERNA - DISNOR', 'DISNOR', 'COLOCADO'],
            ],
            'TABLA_COT_DET' => [
                ['UID_LINEA', 'UID_COT', 'CANTIDAD', 'DESCRIPCION', 'UM', 'PROVEEDOR', 'VENDEDOR', 'MODELO', 'COSTO SIN IVA', 'COSTO CON IVA', 'LEADTIME', 'UTILIDAD', 'PRECIO SIN IVA', 'PRECIO NETO', 'STATUS'],
                [10600.01, 10600, 3, 'ENGRAPADORA', 'PZA', 'DISNOR', '', 'SKU-1', 100, 116, 'INMEDIATO', 30, 130, 150.8, 'COTIZADO'],
                [10600.02, 10600, 1, 'GUANTES', 'PAR', 'MAXIFERRO', '', '', 50, 58, '', 20, 60, 69.6, 'COTIZADO'],
                [10500.01, 10500, 1, 'YA EXISTE', 'PZA', '', '', '', 1, '', '', '', 2, '', ''],
            ],
            'DIRECTORIO_CLIENTES' => [['N', 'NOMBRE', 'DIRECCION', 'CONTACTO', 'CORREO'], [1, 'HOTEL AURORA', 'Av. Juárez 1', 'LUIS', 'luis@hotel.mx']],
            'CONFIG' => [['CLAVE', 'VALOR'], ['CONSECUTIVO', 10601]],
        ]);
    }

    public function test_lee_el_excel_como_el_registro(): void
    {
        $d = (new LectorRegistroExcel)->leer($this->registro());
        $this->assertSame([], $d['faltan']);
        $this->assertSame(10601, $d['consecutivo']);
        $this->assertSame('29/09/2026', $d['hdr'][1]['fecha']);
        $this->assertSame('10600', $d['hdr'][1]['uid']);
        $this->assertSame('ENGRAPADORA', $d['det'][0]['descripcion']);
        $this->assertSame('100', $d['det'][0]['costo_sin']);
        $this->assertSame('130', $d['det'][0]['precio_sin']);
        $this->assertSame('150.8', $d['det'][0]['precio_neto']);
    }

    public function test_importa_lo_nuevo_conserva_folios_del_sistema_y_ajusta_el_consecutivo(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        $this->actingAs($admin);
        Cotizacion::factory()->create(['folio' => 10500]);   // ya importada antes
        $hoy = Cotizacion::factory()->create(['folio' => 90015]);   // hecha hoy en el sistema
        \DB::table('contadores')->updateOrInsert(['clave' => 'cotizacion'], ['ultimo' => 90015]);

        $archivo = UploadedFile::fake()->createWithContent('REGISTRO_COTIZACIONES_MASUME.xlsx', file_get_contents($this->registro()));
        Livewire::test(Importar::class)
            ->set('archivo', $archivo)->assertHasNoErrors()
            ->assertSee('Cotizaciones nuevas')->assertSee('HOTEL AURORA')
            ->assertSee('la siguiente será la', false)->assertSee('10602')
            ->call('importar')->assertSee('se importaron 1 cotizaciones');

        $c = Cotizacion::where('folio', 10600)->with('partidas', 'cliente')->firstOrFail();
        $this->assertSame('HOTEL AURORA', $c->cliente->nombre);
        $this->assertSame('2026-09-29', $c->fecha->toDateString());
        $this->assertSame(['ENGRAPADORA', 'GUANTES'], $c->partidas->pluck('descripcion')->all());
        $this->assertEquals(450, (float) $c->subtotal);   // 3×130 + 60
        $this->assertSame('https://drive/10600', $c->pdf_original_url);
        $this->assertSame(90015, (int) $hoy->fresh()->folio);   // la del sistema conserva su folio
        $this->assertSame(3, Cotizacion::count());            // la OC INTERNA no se importa
        $this->assertSame(10602, Folios::siguiente());        // mañana sigue el consecutivo del REGISTRO
    }

    public function test_no_duplica_clientes_proveedores_ni_usuarios_ya_unidos_o_eliminados(): void
    {
        $admin = User::factory()->create(['rol' => 'admin']);
        $this->actingAs($admin);
        // Proveedores: MAXIFERO se unió a MAXIFERRO en Duplicados; DISNOR existe tal cual.
        $magno = Proveedor::factory()->create(['nombre' => 'MAXIFERRO']);
        $malo = Proveedor::factory()->create(['nombre' => 'MAXIFERO']);
        UnificarDuplicados::unir('proveedores', $magno->id, [$malo->id]);
        $disnor = Proveedor::factory()->create(['nombre' => 'DISNOR']);
        // Cliente: "HOTEL AURORA" se unió (antes de guardar a cuál) a "HOTEL AURORA EJECUTIVO".
        $bueno = Cliente::factory()->create(['nombre' => 'HOTEL AURORA EJECUTIVO']);
        Cliente::factory()->create(['nombre' => 'HOTEL AURORA'])->delete();
        // Usuario eliminado.
        $alberto = User::factory()->create(['name' => 'Alberto Ramos (usuario inactivo)', 'rol' => 'vendedor', 'activo' => false]);

        $d = (new LectorRegistroExcel)->leer($this->registro());
        $d['det'][1]['proveedor'] = 'MAXIFERO';
        (new ImportadorRegistro)->importar($d);

        $c = Cotizacion::where('folio', 10600)->with('partidas')->firstOrFail();
        $this->assertSame($bueno->id, $c->cliente_id);
        $this->assertSame([$disnor->id, $magno->id], $c->partidas->pluck('proveedor_id')->all());
        $this->assertSame($alberto->id, $c->vendedor_id);
        $this->assertSame(1, Proveedor::where('nombre', 'like', 'MAXIFER%')->count());
        $this->assertSame(1, Cliente::where('nombre', 'like', 'HOTEL AURORA%')->count());
        $this->assertSame(1, User::where('name', 'like', 'Alberto%')->count());

        // Importar el mismo archivo otra vez (días después, con cotizaciones nuevas del sistema) no duplica
        // nada ni regresa el consecutivo.
        Cotizacion::factory()->create(['folio' => 10610]);
        (new ImportadorRegistro)->importar($d);
        $this->assertSame(10611, Folios::siguiente());
        $this->assertSame(1, Cotizacion::where('folio', 10600)->count());
        $this->assertSame(2, $c->partidas()->count());
    }

    public function test_avisa_si_no_es_el_registro_y_solo_admin(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $otro = UploadedFile::fake()->createWithContent('otro.xlsx', file_get_contents($this->excel(['Hoja1' => [['A', 'B'], [1, 2]]])));
        Livewire::test(Importar::class)->set('archivo', $otro)->assertSee('No encontré la hoja TABLA_COT_HDR')->assertDontSee('>Importar</button>', false);

        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $this->get('/importar-registro')->assertForbidden();
    }
}

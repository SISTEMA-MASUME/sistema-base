<?php

namespace Tests\Concerns;

use App\Support\LectorXlsx;
use ZipArchive;

/** Arma un .xlsx mínimo para pruebas: hoja => renglones (cada renglón, lista de celdas; número = celda numérica). */
trait ArmaExcel
{
    /** @var list<string> */
    private array $excelesTemporales = [];

    protected function excel(array $hojas): string
    {
        $base = tempnam(sys_get_temp_dir(), 'xls');
        $ruta = $base.'.xlsx';
        @unlink($base);
        $this->excelesTemporales[] = $ruta;
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $compartidas = [];
        $libro = $rels = '';
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

    protected function borrarExceles(): void
    {
        foreach ($this->excelesTemporales as $r) {
            @unlink($r);
        }
    }
}

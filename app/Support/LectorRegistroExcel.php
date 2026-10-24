<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Lee el REGISTRO_COTIZACIONES_MASUME descargado de Google Sheets como Excel (.xlsx) y lo deja con la
 * misma forma que el JSON del importador: hdr, det, clientes y consecutivo (CONFIG!B2).
 *
 * Sin librerías: un .xlsx es un ZIP con XML. Las columnas se reconocen por su encabezado (renglón 1).
 */
final class LectorRegistroExcel
{
    /** Encabezados aceptados (normalizados) para cada campo. */
    private const HDR = [
        'cliente' => ['CLIENTE'],
        'responsable' => ['RESPONSABLE', 'VENDEDOR'],
        'uid' => ['UID_COT', 'UID', 'FOLIO'],
        'fecha' => ['FECHA'],
        'link_pdf' => ['LINK_PDF', 'PDF'],
    ];

    private const DET = [
        'uid_linea' => ['UID_LINEA', 'UID_PARTIDA', 'ID_LINEA', 'UID_LIN'],
        'uid' => ['UID_COT', 'UID', 'FOLIO'],
        'cantidad' => ['CANTIDAD', 'CANT'],
        'descripcion' => ['DESCRIPCION'],
        'um' => ['UM', 'U_M', 'UNIDAD'],
        'proveedor' => ['PROVEEDOR'],
        'vendedor_prov' => ['VENDEDOR_PROV', 'VENDEDOR_PROVEEDOR', 'VENDEDOR'],
        'modelo' => ['MODELO'],
        'costo_sin' => ['COSTO_SIN_IVA', 'COSTO_SIN', 'COSTO'],
        'costo_con' => ['COSTO_CON_IVA', 'COSTO_CON', 'COSTO_NETO'],
        'leadtime' => ['LEADTIME', 'LEAD_TIME', 'TIEMPO_DE_ENTREGA', 'TIEMPO_ENTREGA', 'ENTREGA'],
        'utilidad' => ['UTILIDAD'],
        'precio_sin' => ['PRECIO_SIN_IVA', 'PRECIO_SIN', 'PRECIO_UNITARIO', 'PRECIO'],
        'precio_neto' => ['PRECIO_NETO', 'PRECIO_CON_IVA', 'PRECIO_CON'],
        'status' => ['STATUS', 'ESTATUS', 'ESTADO'],
    ];

    private const CLIENTES = [
        'nombre' => ['NOMBRE', 'CLIENTE', 'RAZON_SOCIAL'],
        'direccion' => ['DIRECCION', 'DOMICILIO'],
        'contacto' => ['CONTACTO', 'ATENCION'],
        'correo' => ['CORREO', 'EMAIL', 'E_MAIL'],
    ];

    /** Campos sin los cuales no se puede importar. */
    public const OBLIGATORIOS = ['hdr' => ['cliente', 'uid'], 'det' => ['uid', 'descripcion', 'cantidad']];

    private array $compartidas = [];

    /**
     * @return array{hdr: list<array>, det: list<array>, clientes: list<array>, consecutivo: int, columnas: array, faltan: list<string>}
     */
    public function leer(string $ruta): array
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('El archivo no es un Excel (.xlsx). En Google Sheets usa Archivo → Descargar → Microsoft Excel.');
        }
        try {
            $this->compartidas = $this->cadenasCompartidas($zip);
            $hojas = $this->hojas($zip);

            $hdr = $this->tabla($zip, $hojas, 'TABLA_COT_HDR', self::HDR, ['fecha']);
            $det = $this->tabla($zip, $hojas, 'TABLA_COT_DET', self::DET);
            $cli = $this->tabla($zip, $hojas, 'DIRECTORIO_CLIENTES', self::CLIENTES);
            $config = isset($hojas['CONFIG']) ? $this->filas($zip, $hojas['CONFIG'], 2) : [];
        } finally {
            $zip->close();
        }

        $faltan = [];
        foreach (['hdr' => [$hdr, 'TABLA_COT_HDR'], 'det' => [$det, 'TABLA_COT_DET']] as $clave => [$t, $hoja]) {
            if ($t === null) {
                $faltan[] = "No encontré la hoja {$hoja}.";

                continue;
            }
            foreach (self::OBLIGATORIOS[$clave] as $campo) {
                if (! isset($t['columnas'][$campo])) {
                    $faltan[] = "En {$hoja} no encontré la columna de \"{$campo}\".";
                }
            }
        }

        return [
            'hdr' => $hdr['filas'] ?? [],
            'det' => $det['filas'] ?? [],
            'clientes' => $cli['filas'] ?? [],
            'consecutivo' => (int) preg_replace('/\D/', '', (string) ($config[1][1] ?? '0')),
            'columnas' => ['hdr' => $hdr['encabezados'] ?? [], 'det' => $det['encabezados'] ?? [], 'clientes' => $cli['encabezados'] ?? []],
            'faltan' => $faltan,
        ];
    }

    public static function normalizar(string $encabezado): string
    {
        $t = strtoupper(Str::ascii(trim($encabezado)));

        return trim(preg_replace('/[^A-Z0-9]+/', '_', $t), '_');
    }

    /**
     * @return array{filas: list<array>, columnas: array<string,int>, encabezados: array<string,string>}|null
     */
    private function tabla(ZipArchive $zip, array $hojas, string $nombre, array $mapa, array $fechas = []): ?array
    {
        $ruta = $hojas[$nombre] ?? null;
        if ($ruta === null) {
            return null;
        }
        $filas = $this->filas($zip, $ruta);
        $encabezado = array_shift($filas) ?? [];

        // Para cada campo, la primera columna cuyo encabezado coincide (exacto primero, luego "empieza con").
        $columnas = [];
        $usadas = [];
        foreach ([true, false] as $exacto) {
            foreach ($mapa as $campo => $alias) {
                if (isset($columnas[$campo])) {
                    continue;
                }
                foreach ($encabezado as $i => $texto) {
                    if (isset($usadas[$i])) {
                        continue;
                    }
                    $n = self::normalizar((string) $texto);
                    foreach ($alias as $a) {
                        if ($n !== '' && ($exacto ? $n === $a : str_starts_with($n, $a))) {
                            $columnas[$campo] = $i;
                            $usadas[$i] = true;
                            break 2;
                        }
                    }
                }
            }
        }

        $salida = [];
        foreach ($filas as $f) {
            $r = [];
            foreach (array_keys($mapa) as $campo) {
                $v = isset($columnas[$campo]) ? ($f[$columnas[$campo]] ?? '') : '';
                if (in_array($campo, $fechas, true) && is_numeric($v)) {
                    // Fecha como número de serie de Excel.
                    $v = Carbon::create(1899, 12, 30)->addDays((int) $v)->format('d/m/Y');
                }
                $r[$campo] = is_string($v) ? trim($v) : $v;
            }
            if (implode('', array_map('strval', $r)) !== '') {
                $salida[] = $r;
            }
        }

        return [
            'filas' => $salida,
            'columnas' => $columnas,
            'encabezados' => array_map(fn ($i) => (string) ($encabezado[$i] ?? ''), $columnas),
        ];
    }

    /** @return array<string,string> nombre de hoja => ruta dentro del ZIP */
    private function hojas(ZipArchive $zip): array
    {
        $libro = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        if (! $libro || ! $rels) {
            throw new RuntimeException('No pude leer las hojas del Excel.');
        }
        $destinos = [];
        foreach ($rels->Relationship as $r) {
            $destinos[(string) $r['Id']] = 'xl/'.ltrim(preg_replace('#^/?xl/#', '', (string) $r['Target']), '/');
        }
        $hojas = [];
        foreach ($libro->sheets->sheet as $s) {
            $id = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if (isset($destinos[$id])) {
                $hojas[strtoupper(trim((string) $s['name']))] = $destinos[$id];
            }
        }

        return $hojas;
    }

    /** @return list<string> */
    private function cadenasCompartidas(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $lista = [];
        $r = new XMLReader;
        $r->XML($xml);
        // Se avanza de <si> en <si> con next() (read() después de next() se saltaría uno).
        while ($r->read() && ! ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si')) {
        }
        while ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si') {
            $nodo = simplexml_load_string($r->readOuterXml());
            $texto = '';
            foreach ($nodo->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $texto .= (string) $t;
            }
            $lista[] = $texto;
            if (! $r->next('si')) {
                break;
            }
        }

        return $lista;
    }

    /**
     * Filas de una hoja como arreglos por índice de columna (0 = A).
     *
     * @return list<array<int, string|float>>
     */
    private function filas(ZipArchive $zip, string $ruta, ?int $limite = null): array
    {
        $xml = $zip->getFromName($ruta);
        if ($xml === false) {
            return [];
        }
        $r = new XMLReader;
        $r->XML($xml);
        $filas = [];
        $fila = null;
        $col = 0;
        $tipo = '';
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'row') {
                $fila = [];
                $num = (int) $r->getAttribute('r');
                // Renglones vacíos intermedios: se respetan para que el encabezado sea el renglón 1.
                while ($num > 0 && count($filas) < $num - 1) {
                    $filas[] = [];
                }
                if ($r->isEmptyElement) {
                    $filas[] = [];
                    $fila = null;
                }
            } elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'c' && $fila !== null) {
                $col = self::indiceColumna((string) $r->getAttribute('r'));
                $tipo = (string) $r->getAttribute('t');
                if ($tipo === 'inlineStr') {
                    $nodo = simplexml_load_string($r->readOuterXml());
                    $fila[$col] = implode('', array_map('strval', $nodo->xpath('.//*[local-name()="t"]') ?: []));
                }
            } elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'v' && $fila !== null) {
                $v = $r->readString();
                $fila[$col] = match ($tipo) {
                    's' => $this->compartidas[(int) $v] ?? '',
                    'str', 'b', 'e' => $v,
                    default => is_numeric($v) ? self::numero($v) : $v,
                };
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'row' && $fila !== null) {
                $filas[] = $fila;
                $fila = null;
                if ($limite !== null && count($filas) >= $limite) {
                    break;
                }
            }
        }

        return $filas;
    }

    /** Número como texto limpio: 6191 (no "6191.0") y 9000.01 tal cual. */
    private static function numero(string $v): string
    {
        $f = (float) $v;

        return floor($f) === $f && abs($f) < 1e15 ? (string) (int) $f : rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
    }

    private static function indiceColumna(string $celda): int
    {
        $letras = preg_replace('/\d/', '', strtoupper($celda));
        $n = 0;
        foreach (str_split($letras) as $l) {
            $n = $n * 26 + (ord($l) - 64);
        }

        return max(0, $n - 1);
    }
}

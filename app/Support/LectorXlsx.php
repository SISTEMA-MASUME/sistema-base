<?php

namespace App\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use XMLReader;
use ZipArchive;

/**
 * Lector genérico de archivos Excel (.xlsx) sin librerías: un .xlsx es un ZIP con XML. Lo usan las pantallas
 * que importan datos de Google Sheets (Finanzas → Importar ventas).
 *
 *   $lector = new LectorXlsx($ruta);
 *   $lector->hojas();            // ['CONTROL', 'VENTAS 2026', ...] en el orden del libro (también las ocultas)
 *   $lector->filas('VENTAS 2026'); // list<list<mixed>>: renglón 1 = índice 0, columna A = índice 0
 *
 * Valores crudos: número como int/float, texto (de sharedStrings o inlineStr, también los de formato rico),
 * booleano como bool, error de fórmula (#DIV/0!) como texto y celda vacía como null. En las fórmulas se
 * lee el valor guardado (<v>), nunca se evalúan. Cada celda se coloca por su referencia (B7), así que las
 * celdas vacías no corren las columnas. Los renglones vacíos se respetan (el renglón N siempre es el índice
 * N-1) pero se recortan los vacíos del final. Las fechas llegan como número de serie: usa fecha().
 *
 * Se lee en flujo (XMLReader sobre el ZIP): 3,000 renglones × 32 columnas tardan una fracción de segundo.
 */
final class LectorXlsx
{
    /** Topes para que un archivo pequeño no pida memoria sin fin (el historial completo de ventas cabe de sobra). */
    private const MAX_FILAS = 60000;

    private const MAX_COLUMNAS = 200;

    private const OPCIONES_XML = LIBXML_NONET | LIBXML_PARSEHUGE | LIBXML_COMPACT;

    private const NS_RELACIONES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private ZipArchive $zip;

    private string $ruta;

    /** @var array<string, string>|null nombre de hoja => ruta del XML dentro del ZIP */
    private ?array $rutas = null;

    private string $rutaCompartidas = 'xl/sharedStrings.xml';

    /** @var list<string>|null */
    private ?array $compartidas = null;

    public function __construct(string $ruta)
    {
        $this->ruta = realpath($ruta) ?: $ruta;
        $this->zip = new ZipArchive;
        if (! is_file($this->ruta) || $this->zip->open($this->ruta) !== true || $this->zip->locateName('xl/workbook.xml') === false) {
            throw new InvalidArgumentException('El archivo no es un Excel (.xlsx). En Google Sheets usa Archivo → Descargar → Microsoft Excel.');
        }
    }

    public function __destruct()
    {
        @$this->zip->close();
    }

    /**
     * Nombres de las hojas, en el orden del libro (incluye las ocultas).
     *
     * @return list<string>
     */
    public function hojas(): array
    {
        return array_keys($this->rutasDeHojas());
    }

    /**
     * Todos los renglones de una hoja: renglón 1 = índice 0; cada renglón es una lista donde la columna A es el
     * índice 0 (null si la celda está vacía; sin los vacíos del final).
     *
     * @return list<list<mixed>>
     */
    public function filas(string $hoja): array
    {
        $ruta = $this->rutasDeHojas()[$hoja] ?? null;
        if ($ruta === null) {
            // Por si el nombre viene con otras mayúsculas o espacios de más.
            foreach ($this->rutasDeHojas() as $nombre => $r) {
                if (mb_strtoupper(trim($nombre)) === mb_strtoupper(trim($hoja))) {
                    $ruta = $r;
                    break;
                }
            }
        }
        if ($ruta === null) {
            throw new InvalidArgumentException("No encontré la hoja \"{$hoja}\" en el Excel.");
        }
        $this->compartidas();   // se cargan antes de recorrer la hoja

        return $this->leer(function () use ($ruta) {
            $x = $this->abrir($ruta);
            $filas = [];
            if ($x === null) {
                return $filas;
            }
            $ultimo = 0;
            while ($x->read()) {
                if ($x->nodeType !== XMLReader::ELEMENT || $x->localName !== 'row') {
                    continue;
                }
                $numero = (int) $x->getAttribute('r');
                if ($numero <= $ultimo) {
                    $numero = $ultimo + 1;
                }
                // Un archivo chico puede decir "fila 1,000,000": no se rellenan huecos sin fin (memoria).
                if ($numero > self::MAX_FILAS) {
                    break;
                }
                for ($i = $ultimo + 1; $i < $numero; $i++) {
                    $filas[] = [];
                }
                $ultimo = $numero;
                $filas[] = $x->isEmptyElement ? [] : $this->fila($x);
            }
            $x->close();
            while ($filas !== [] && end($filas) === []) {
                array_pop($filas);
            }

            return $filas;
        });
    }

    /**
     * Recorre una hoja renglón por renglón SIN guardarla completa en memoria (listas de precios de miles de renglones).
     * $porFila recibe (índice del renglón, celdas) —índice 0 = renglón 1— y puede regresar false para parar.
     * No tiene el tope de MAX_FILAS (no acumula), pero sí el de columnas.
     */
    public function recorrer(string $hoja, callable $porFila): void
    {
        $ruta = $this->rutasDeHojas()[$hoja] ?? null;
        if ($ruta === null) {
            throw new InvalidArgumentException("No encontré la hoja \"{$hoja}\" en el Excel.");
        }
        $this->compartidas();
        $this->leer(function () use ($ruta, $porFila) {
            $x = $this->abrir($ruta);
            if ($x === null) {
                return;
            }
            $ultimo = 0;
            while ($x->read()) {
                if ($x->nodeType !== XMLReader::ELEMENT || $x->localName !== 'row') {
                    continue;
                }
                $numero = max((int) $x->getAttribute('r'), $ultimo + 1);
                $ultimo = $numero;
                $celdas = $x->isEmptyElement ? [] : $this->fila($x);
                if ($porFila($numero - 1, $celdas) === false) {
                    break;
                }
            }
            $x->close();
        });
    }

    /** Número de serie de Excel/Sheets → fecha (se ignora la hora). null si no cabe en una fecha. */
    public static function fecha(int|float $serial): ?DateTimeImmutable
    {
        // Del año 10 al 9999 (lo que cabe en una fecha).
        if (! is_finite((float) $serial) || $serial < -690_000 || $serial > 2_958_465) {
            return null;
        }
        $dias = (int) floor($serial);

        return (new DateTimeImmutable('1899-12-30 00:00:00'))->modify(sprintf('%+d days', $dias));
    }

    /** Letras de la columna: 0 = A, 25 = Z, 26 = AA. */
    public static function letra(int $indice): string
    {
        $letras = '';
        for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letras = chr(65 + ($n - 1) % 26).$letras;
        }

        return $letras;
    }

    /** @return array<string, string> */
    private function rutasDeHojas(): array
    {
        if ($this->rutas !== null) {
            return $this->rutas;
        }

        return $this->leer(function () {
            $destinos = [];
            $x = $this->abrir('xl/_rels/workbook.xml.rels');
            while ($x && $x->read()) {
                if ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'Relationship') {
                    $destino = self::resolver((string) $x->getAttribute('Target'));
                    $destinos[(string) $x->getAttribute('Id')] = $destino;
                    if (str_ends_with((string) $x->getAttribute('Type'), '/sharedStrings')) {
                        $this->rutaCompartidas = $destino;
                    }
                }
            }
            $x?->close();

            $rutas = [];
            $x = $this->abrir('xl/workbook.xml');
            while ($x && $x->read()) {
                if ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'sheet') {
                    $id = $x->getAttribute('r:id') ?? $x->getAttributeNs('id', self::NS_RELACIONES);
                    if ($id !== null && isset($destinos[$id])) {
                        $rutas[(string) $x->getAttribute('name')] = $destinos[$id];
                    }
                }
            }
            $x?->close();
            if ($rutas === []) {
                throw new InvalidArgumentException('No pude leer las hojas del Excel.');
            }

            return $this->rutas = $rutas;
        });
    }

    /** Ruta dentro del ZIP: "worksheets/sheet1.xml" → "xl/worksheets/sheet1.xml"; "/xl/…" y "../" también. */
    private static function resolver(string $destino): string
    {
        $completa = str_starts_with($destino, '/') ? ltrim($destino, '/') : 'xl/'.$destino;
        $partes = [];
        foreach (explode('/', $completa) as $parte) {
            if ($parte === '..') {
                array_pop($partes);
            } elseif ($parte !== '' && $parte !== '.') {
                $partes[] = $parte;
            }
        }

        return implode('/', $partes);
    }

    /** Corre una lectura con los errores de XML en silencio y avisa (excepción) si el archivo está dañado. */
    private function leer(callable $lectura): mixed
    {
        $anterior = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $resultado = $lectura();
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) {
                    throw new InvalidArgumentException('El Excel está dañado o no se pudo leer ('.trim($error->message).').');
                }
            }

            return $resultado;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }

    /** XMLReader sobre una parte del ZIP, en flujo (sin cargar todo el XML); null si no existe. */
    private function abrir(string $entrada): ?XMLReader
    {
        if ($this->zip->locateName($entrada) === false) {
            return null;
        }
        $x = new XMLReader;
        // El nombre del ZIP va como URL: "%" y "#" tienen que ir codificados.
        $url = 'zip://'.str_replace(['%', '#'], ['%25', '%23'], $this->ruta).'#'.$entrada;
        if (@$x->open($url, null, self::OPCIONES_XML)) {
            return $x;
        }
        // Plan B: cargar el XML completo.
        $xml = $this->zip->getFromName($entrada);

        return $xml !== false && $x->XML($xml, null, self::OPCIONES_XML) ? $x : null;
    }

    /** @return list<mixed> las celdas del renglón (el lector está sobre <row>); termina en </row>. */
    private function fila(XMLReader $x): array
    {
        $fila = [];
        $columna = -1;
        $profundidad = $x->depth;
        while ($x->read()) {
            if ($x->nodeType === XMLReader::END_ELEMENT) {
                if ($x->depth === $profundidad) {
                    break;
                }

                continue;
            }
            if ($x->nodeType !== XMLReader::ELEMENT || $x->localName !== 'c') {
                continue;
            }
            $indice = self::indiceColumna((string) $x->getAttribute('r'));
            $columna = $indice >= 0 ? $indice : $columna + 1;
            if ($columna >= self::MAX_COLUMNAS) {
                continue;
            }
            $valor = $this->celda($x);
            if ($valor === null) {
                continue;
            }
            for ($i = count($fila); $i < $columna; $i++) {
                $fila[] = null;
            }
            $fila[$columna] = $valor;
        }
        while ($fila !== [] && end($fila) === null) {
            array_pop($fila);
        }

        return $fila;
    }

    /** Valor de la celda (el lector está sobre <c>); termina en </c>. */
    private function celda(XMLReader $x): mixed
    {
        $tipo = (string) $x->getAttribute('t');
        if ($x->isEmptyElement) {
            return null;
        }
        $profundidad = $x->depth;
        $v = null;
        $texto = null;
        while ($x->read()) {
            if ($x->nodeType === XMLReader::END_ELEMENT) {
                if ($x->depth === $profundidad) {
                    break;
                }

                continue;
            }
            if ($x->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($x->localName === 'v') {
                $v = $x->readString();
            } elseif ($x->localName === 'is') {
                $texto = self::textoDe($x);
            }
        }

        $valor = match ($tipo) {
            's' => $v === null ? null : ($this->compartidas()[(int) $v] ?? null),
            'inlineStr' => $texto,
            'b' => $v === null || $v === '' ? null : $v === '1',
            'str', 'e', 'd' => $v,
            default => $v === null || $v === '' ? null : self::numero($v),
        };

        return $valor === '' ? null : $valor;
    }

    /** Texto de un <si> (cadenas compartidas) o <is> (en la celda): une los <t>, también los de formato rico, sin la fonética (<rPh>). */
    private static function textoDe(XMLReader $x): string
    {
        if ($x->isEmptyElement) {
            return '';
        }
        $texto = '';
        $profundidad = $x->depth;
        $fonetica = -1;
        while ($x->read()) {
            if ($x->nodeType === XMLReader::END_ELEMENT) {
                if ($x->depth === $profundidad) {
                    break;
                }
                if ($x->depth === $fonetica && $x->localName === 'rPh') {
                    $fonetica = -1;
                }

                continue;
            }
            if ($x->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($x->localName === 'rPh') {
                $fonetica = $x->isEmptyElement ? -1 : $x->depth;
            } elseif ($x->localName === 't' && $fonetica < 0) {
                $texto .= $x->readString();
            }
        }

        return $texto;
    }

    /** @return list<string> */
    private function compartidas(): array
    {
        if ($this->compartidas !== null) {
            return $this->compartidas;
        }
        $this->rutasDeHojas();   // trae la ruta de las cadenas compartidas desde las relaciones del libro

        return $this->compartidas = $this->leer(function () {
            $lista = [];
            $x = $this->abrir($this->rutaCompartidas);
            while ($x && $x->read()) {
                if ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'si') {
                    $lista[] = self::textoDe($x);
                }
            }
            $x?->close();

            return $lista;
        });
    }

    /** "46027.0" → 46027 (int); "46135.5049" → float; lo que no sea número queda como texto. */
    private static function numero(string $v): int|float|string
    {
        if (! is_numeric($v)) {
            return $v;
        }
        $f = (float) $v;

        return floor($f) === $f && abs($f) < 1e15 ? (int) $f : $f;
    }

    /** "AB12" → 27; -1 si la referencia no trae letras. */
    private static function indiceColumna(string $referencia): int
    {
        $n = 0;
        for ($i = 0, $largo = strlen($referencia); $i < $largo; $i++) {
            $c = ord($referencia[$i]);
            if ($c >= 97 && $c <= 122) {
                $c -= 32;
            }
            if ($c < 65 || $c > 90) {
                break;
            }
            $n = $n * 26 + ($c - 64);
        }

        return $n - 1;
    }
}

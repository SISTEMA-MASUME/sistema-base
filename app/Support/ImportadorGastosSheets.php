<?php

namespace App\Support;

use App\Models\Cuenta;
use App\Models\Gasto;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trae a Gastos el historial de las hojas GASTOS del Google Sheets de Daniel (Excel descargado): GASTOS 2025 (trae
 * también 2026), GASTOS 2024 y GASTOS 2023. Cada hoja tiene su forma, así que el encabezado se busca en los primeros
 * 20 renglones (el que tenga FECHA y MONTO o COSTO) y solo se leen las columnas de ESE encabezado: los resúmenes que
 * están más a la derecha (GASTO ENERO, totales…) se ignoran.
 *
 *   2025: MASUME / PERSONAL, FECHA, MONTO, TIPO DE MOVIMIENTO, DESCRIPCION, PAGADO CON, CATEGORIA, CUENTA DESTINO.
 *   2024 y 2023: FECHA, COSTO (= monto), GASTO (= categoría), DESCRIPCION, PAGADO CON; sin MASUME / PERSONAL → MASUME.
 *
 * - Se salta lo que no tiene fecha válida (vacía, año 1899), lo de monto vacío o cero y los montos que no son número
 *   (estos últimos se avisan como "dato por revisar").
 * - Categoría en mayúsculas y con espacios colapsados. "Movimiento entre cuentas" (el texto contiene MOVIMIENTO) no es
 *   gasto: se guarda pero no suma en los totales.
 * - "Pagado con" pasa por ALIAS_CUENTAS (los nombres que se escribieron de varias maneras) y de ahí a Cuenta::obtener.
 * - Es idempotente: cada renglón lleva una llave (hoja, fecha, monto, descripción, cuenta, categoría y el número de
 *   repetición de esa misma combinación dentro de la hoja); si ya existe, "ya estaba" (también si se borró después).
 *
 * analizar() no escribe nada (vista previa); importar() hace lo mismo y guarda, todo en una transacción.
 */
final class ImportadorGastosSheets
{
    private const MAX_ANOMALIAS = 30;

    /** Una fecha capturada con un año imposible (0026, 2062) no se cuela. */
    private const ANIO_MINIMO = 2000;

    /** Llave de Nombres::llave() del texto del Sheets => nombre con el que queda la cuenta. */
    private const ALIAS_CUENTAS = [
        'HEYBANCO' => 'Hey Banco (Débito)', 'HEYBANCODEBITO' => 'Hey Banco (Débito)', 'DEBITOHEYBANCO' => 'Hey Banco (Débito)',
        'HEYDEBITO' => 'Hey Banco (Débito)', 'HEY' => 'Hey Banco (Débito)', 'HEYTRANSFER' => 'Hey Banco (Débito)',
        'HEYTC' => 'Hey Banco (Crédito)', 'HEYCREDITO' => 'Hey Banco (Crédito)', 'HEYBANCOCREDITO' => 'Hey Banco (Crédito)',
        'CREDITOHEYBANCO' => 'Hey Banco (Crédito)',
        'BBVADEBITO' => 'BBVA (Débito)',
        'BBVACREDITO' => 'BBVA PLATINUM', 'BBVAPLATINUM' => 'BBVA PLATINUM',
        'VOLARISINVEXMORADA' => 'VOLARIS INVEX', 'VOLARISINVEX' => 'VOLARIS INVEX',
        'DESPEGARINVEXVERDE' => 'DESPEGAR INVEX', 'DESPEGARINVEX' => 'DESPEGAR INVEX',
        'BANAMEXCOSTCO' => 'COSTCO BANAMEX', 'COSTCOBANAMEX' => 'COSTCO BANAMEX', 'COSTCO' => 'COSTCO BANAMEX',
        'COSTCOCREDITO' => 'COSTCO BANAMEX', 'CREDITOCOSTCO' => 'COSTCO BANAMEX', 'CREDITOBANAMEX' => 'COSTCO BANAMEX',
        'TARJETADECREDITOBNX' => 'COSTCO BANAMEX',
        'EFECTIVOFARMACIA' => 'EFECTIVO', 'EFECTIVOFARM' => 'EFECTIVO', 'EFEC' => 'EFECTIVO', 'EFETIVO' => 'EFECTIVO',
        'MERCADOPAGOCREDITO' => 'MERCADO PAGO CREDITO',
    ];

    /**
     * "Pagado con" que no se sabe a qué cuenta es ("INVEX" ¿Volaris o Despegar?, "CREDITO", "TRANSFER"…): no se inventa
     * una cuenta; el texto queda en la descripción.
     */
    private const CUENTAS_AMBIGUAS = ['INVEX', 'TARJETAINVEX', 'INEX', 'DEBITOMASUME', 'DEBITMASUME', 'CREDITOMASUME',
        'CREDITO', 'BBVA', 'TRANSFER', 'TRASNFER', 'TRANSFERENCIA', 'PLATA', 'CREDITOPLATA', 'DEPOSITO',
        'TARJETAEFECTIVO', 'EFECTIVOTARJETA', 'TARJETADECREDITO', 'TRANSFEREFECTIVO', 'AMERICANEXPRESS', 'MERCADOPAGO', 'CREDIO'];

    private const MESES = ['ENE' => 1, 'FEB' => 2, 'MAR' => 3, 'ABR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AGO' => 8,
        'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DIC' => 12];

    /** ¿El Excel trae alguna hoja de gastos? */
    public static function hayHojas(string $rutaXlsx): bool
    {
        return self::hojasDeGastos(new LectorXlsx($rutaXlsx)) !== [];
    }

    /** Qué se importaría (no escribe nada). */
    public function analizar(string $rutaXlsx): array
    {
        return $this->correr($rutaXlsx, false);
    }

    /** Importa lo nuevo y regresa el mismo resumen con lo realmente insertado ('insertadas'). */
    public function importar(string $rutaXlsx): array
    {
        return $this->correr($rutaXlsx, true);
    }

    /** El nombre con el que queda la cuenta de un "Pagado con" del Sheets (null si está vacío). */
    public static function cuentaDe(mixed $texto): ?string
    {
        $limpio = Str::squish((string) $texto);
        if ($limpio === '') {
            return null;
        }

        return self::ALIAS_CUENTAS[Nombres::llave($limpio)] ?? mb_substr($limpio, 0, 80);
    }

    private function correr(string $ruta, bool $guardar): array
    {
        $lector = new LectorXlsx($ruta);
        $r = [
            'guardado' => $guardar, 'hojas' => [], 'cuentas' => [], 'anomalias_total' => 0, 'anomalias' => [],
            'totales' => ['filas' => 0, 'a_importar' => 0, 'ya_estaban' => 0, 'saltadas' => 0, 'movimientos' => 0, 'insertadas' => 0, 'por_anio' => []],
        ];
        $nuevas = [];
        $usos = [];        // llave de la cuenta => renglones que "pagó"
        $escrituras = [];  // llave de la cuenta => [cómo se escribió => veces]

        foreach (self::hojasDeGastos($lector) as $hoja) {
            [$h, $filas] = $this->procesarHoja($lector, $hoja['nombre'], $hoja['anio']);

            $existentes = [];
            foreach (array_chunk(array_column($filas, 'llave'), 400) as $trozo) {
                foreach (Gasto::withTrashed()->whereIn('llave_importacion', $trozo)->pluck('llave_importacion') as $llave) {
                    $existentes[$llave] = true;
                }
            }
            foreach ($filas as $f) {
                isset($existentes[$f['llave']]) ? $h['ya_estaban']++ : $h['a_importar']++;
                if (! isset($existentes[$f['llave']])) {
                    $nuevas[] = $f;
                }
                $anio = (int) substr($f['fecha'], 0, 4);
                $h['por_anio'][$anio] ??= ['n' => 0, 'masume' => 0.0, 'personal' => 0.0, 'movimientos' => 0.0];
                $h['por_anio'][$anio]['n']++;
                if ($f['tipo'] === 'movimiento') {
                    $h['movimientos']++;
                    $h['por_anio'][$anio]['movimientos'] = round($h['por_anio'][$anio]['movimientos'] + $f['monto'], 2);
                } else {
                    $h['por_anio'][$anio][$f['de']] = round($h['por_anio'][$anio][$f['de']] + $f['monto'], 2);
                }
                foreach (array_filter([$f['cuenta'], $f['destino']]) as $c) {
                    $escrituras[Nombres::llave($c)][$c] = ($escrituras[Nombres::llave($c)][$c] ?? 0) + 1;
                }
                if ($f['cuenta'] !== null) {
                    $usos[Nombres::llave($f['cuenta'])] = ($usos[Nombres::llave($f['cuenta'])] ?? 0) + 1;
                }
            }
            ksort($h['por_anio']);

            $t = &$r['totales'];
            foreach (['filas', 'a_importar', 'ya_estaban', 'saltadas', 'movimientos'] as $k) {
                $t[$k] += $h[$k];
            }
            foreach ($h['por_anio'] as $anio => $x) {
                $t['por_anio'][$anio] ??= ['n' => 0, 'masume' => 0.0, 'personal' => 0.0, 'movimientos' => 0.0];
                foreach ($x as $k => $v) {
                    $t['por_anio'][$anio][$k] = round($t['por_anio'][$anio][$k] + $v, 2);
                }
            }
            unset($t);

            $r['anomalias_total'] += $h['anomalias_total'];
            foreach ($h['anomalias'] as $a) {
                if (count($r['anomalias']) < self::MAX_ANOMALIAS) {
                    $r['anomalias'][] = "{$h['nombre']}, {$a}";
                }
            }
            $r['hojas'][] = $h;
        }
        ksort($r['totales']['por_anio']);

        // Una misma cuenta escrita con otras mayúsculas o espacios ("debito masume", "Debito Masume ") es una sola:
        // se queda con la forma que más se escribió.
        $nombres = [];
        foreach ($escrituras as $llave => $formas) {
            arsort($formas);
            $nombres[$llave] = array_key_first($formas);
        }
        foreach ($nuevas as &$f) {
            $f['cuenta'] = $f['cuenta'] === null ? null : $nombres[Nombres::llave($f['cuenta'])];
            $f['destino'] = $f['destino'] === null ? null : $nombres[Nombres::llave($f['destino'])];
        }
        unset($f);

        // Cuentas que aparecen (y cuáles se crearían).
        $existentes = Cuenta::pluck('nombre_llave')->flip();
        foreach ($usos as $llave => $n) {
            $r['cuentas'][] = ['nombre' => Nombres::limpiar($nombres[$llave]), 'gastos' => $n, 'nueva' => ! isset($existentes[$llave])];
        }
        usort($r['cuentas'], fn ($a, $b) => $b['gastos'] <=> $a['gastos'] ?: strcmp($a['nombre'], $b['nombre']));

        if ($guardar && $nuevas) {
            DB::transaction(function () use ($nuevas, &$r) {
                $ids = [];
                $cuenta = function (?string $nombre) use (&$ids) {
                    if ($nombre === null) {
                        return null;
                    }

                    return $ids[$nombre] ??= Cuenta::obtener($nombre)?->id;
                };
                foreach ($nuevas as $f) {
                    Gasto::create([
                        'fecha' => $f['fecha'], 'monto' => $f['monto'], 'de' => $f['de'], 'tipo' => $f['tipo'],
                        'categoria' => $f['categoria'], 'descripcion' => $f['descripcion'],
                        'cuenta_id' => $cuenta($f['cuenta']), 'cuenta_destino_id' => $cuenta($f['destino']),
                        'origen' => 'sheets', 'llave_importacion' => $f['llave'],
                    ]);
                    $r['totales']['insertadas']++;
                }
            });
        }

        return $r;
    }

    /**
     * @return array{0: array, 1: list<array>} resumen de la hoja y sus renglones válidos (con su llave de importación)
     */
    private function procesarHoja(LectorXlsx $lector, string $nombre, ?int $anioHoja): array
    {
        $h = [
            'nombre' => $nombre, 'anio' => $anioHoja, 'fila_encabezado' => null, 'columnas' => [], 'error' => null,
            'filas' => 0, 'saltadas' => 0, 'a_importar' => 0, 'ya_estaban' => 0, 'movimientos' => 0, 'por_anio' => [],
            'anomalias_total' => 0, 'anomalias' => [],
        ];
        $filas = $lector->filas($nombre);
        $columnas = null;
        foreach (array_slice($filas, 0, 20, true) as $i => $fila) {
            if (($c = self::columnas($fila)) !== null) {
                $columnas = $c;
                $h['fila_encabezado'] = $i + 1;
                break;
            }
        }
        if ($columnas === null) {
            $h['error'] = 'No encontré los encabezados (FECHA y MONTO o COSTO) en los primeros 20 renglones.';

            return [$h, []];
        }
        foreach ($columnas as $campo => $indice) {
            $h['columnas'][$campo] = LectorXlsx::letra($indice);
        }

        $anomalia = function (string $texto) use (&$h) {
            $h['anomalias_total']++;
            if (count($h['anomalias']) < self::MAX_ANOMALIAS) {
                $h['anomalias'][] = $texto;
            }
        };

        $validas = [];
        $repeticiones = [];
        foreach (array_slice($filas, $h['fila_encabezado'], null, true) as $i => $fila) {
            $renglon = $i + 1;
            $celda = fn (string $campo) => isset($columnas[$campo]) ? ($fila[$columnas[$campo]] ?? null) : null;
            $crudoFecha = $celda('fecha');
            $crudoMonto = $celda('monto');
            $vacio = fn ($v) => $v === null || (is_string($v) && trim($v) === '');
            if ($vacio($crudoFecha) && $vacio($crudoMonto)) {
                continue;   // renglón vacío (o solo con resúmenes a la derecha)
            }
            $h['filas']++;

            // Monto: vacío o cero se salta sin avisar; un texto que no es número, sí.
            $monto = self::monto($crudoMonto);
            if ($monto === false) {
                $h['saltadas']++;
                $anomalia("renglón {$renglon}: el monto «".trim((string) $crudoMonto).'» no es un número, no se importa.');

                continue;
            }
            if ($monto === null || $monto == 0.0) {
                $h['saltadas']++;

                continue;
            }
            $fecha = self::fecha($crudoFecha, $anioHoja);
            if ($fecha === null) {
                $h['saltadas']++;
                if ($vacio($crudoFecha)) {
                    $anomalia("renglón {$renglon}: trae monto de $".number_format($monto, 2).' pero no fecha, no se importa.');
                } elseif (! is_numeric($crudoFecha)) {   // un número que no da fecha es la fecha vacía de una fórmula (año 1899): se salta sin avisar
                    $anomalia("renglón {$renglon}: la fecha «".trim((string) $crudoFecha).'» no se entiende, no se importa.');
                }

                continue;
            }
            if ((int) $fecha->format('Y') < self::ANIO_MINIMO || $fecha->format('Y-m-d') > now()->addYear()->format('Y-m-d')) {
                $h['saltadas']++;
                $anomalia("renglón {$renglon}: la fecha {$fecha->format('d/m/Y')} no es razonable, no se importa.");

                continue;
            }
            if (abs($monto) > 999999999999) {
                $h['saltadas']++;
                $anomalia("renglón {$renglon}: el monto es demasiado grande, no se importa.");

                continue;
            }
            if ($monto < 0) {
                $anomalia("renglón {$renglon}: el monto es negativo ($".number_format($monto, 2).'); se importa tal cual.');
            }

            $deTexto = self::norm($celda('de'));
            if (isset($columnas['de']) && $deTexto === '') {
                $anomalia("renglón {$renglon}: no dice si es MASUME o personal; se toma como MASUME.");
            }
            $de = str_contains($deTexto, 'PERSONAL') ? 'personal' : 'masume';
            $tipo = str_contains(self::norm($celda('tipo')), 'MOVIMIENTO') ? 'movimiento' : 'gasto';
            $categoria = Str::upper(Str::squish((string) $celda('categoria')));
            $categoria = $categoria === '' ? null : mb_substr($categoria, 0, 60);
            $descripcion = Str::squish((string) $celda('descripcion'));
            $descripcion = $descripcion === '' ? null : $descripcion;
            $crudo = Str::squish((string) $celda('cuenta'));
            $cuenta = self::cuentaDe($crudo);
            $destino = self::cuentaDe($celda('destino'));
            $llaveCuenta = $cuenta === null ? '' : Nombres::llave($cuenta);
            if ($cuenta !== null && in_array(Nombres::llave($crudo), self::CUENTAS_AMBIGUAS, true)) {
                $cuenta = null;
                $descripcion = trim(($descripcion ?? '').' (pagado con: '.$crudo.')');
            }

            $base = implode('|', [$nombre, $fecha->format('Y-m-d'), number_format($monto, 2, '.', ''), $descripcion ?? '',
                $llaveCuenta, $categoria ?? '']);
            $n = $repeticiones[$base] = ($repeticiones[$base] ?? 0) + 1;

            $validas[] = [
                'llave' => sha1($base.'|'.$n), 'fecha' => $fecha->format('Y-m-d'), 'monto' => $monto, 'de' => $de, 'tipo' => $tipo,
                // La llave usa la categoría tal como venía (reimportar no duplica); lo guardado ya va en la lista general.
                'categoria' => CategoriasGasto::sugerir($categoria, $descripcion)['categoria'], 'descripcion' => $descripcion, 'cuenta' => $cuenta, 'destino' => $destino,
            ];
        }

        return [$h, $validas];
    }

    /** @return list<array{nombre: string, anio: ?int}> */
    private static function hojasDeGastos(LectorXlsx $lector): array
    {
        $hojas = [];
        foreach ($lector->hojas() as $nombre) {
            if (str_starts_with(self::norm($nombre), 'GASTOS')) {
                $hojas[] = ['nombre' => $nombre, 'anio' => preg_match('/(?<!\d)(\d{4})(?!\d)/', $nombre, $m) ? (int) $m[1] : null];
            }
        }

        return $hojas;
    }

    /** @return array<string, int>|null columna (índice) de cada campo si el renglón es el encabezado */
    private static function columnas(array $fila): ?array
    {
        $c = [];
        foreach ($fila as $i => $valor) {
            $h = self::norm($valor);
            $campo = match (true) {
                $h === 'FECHA' => 'fecha',
                $h === 'MONTO' || $h === 'COSTO' => 'monto',
                str_contains($h, 'PERSONAL') => 'de',
                str_starts_with($h, 'TIPO') => 'tipo',
                str_starts_with($h, 'DESCRIPCION') => 'descripcion',
                str_starts_with($h, 'PAGADO CON') => 'cuenta',
                $h === 'CATEGORIA' || $h === 'GASTO' => 'categoria',
                str_starts_with($h, 'CUENTA DESTINO') => 'destino',
                default => null,
            };
            if ($campo !== null) {
                $c[$campo] ??= $i;
            }
        }

        return isset($c['fecha'], $c['monto']) ? $c : null;
    }

    /** Mayúsculas, sin acentos ni espacios de más. */
    private static function norm(mixed $v): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtoupper(Str::ascii((string) $v))));
    }

    /** @return float|null|false número; null si está vacío; false si es un texto que no se puede leer como número */
    private static function monto(mixed $v): float|null|false
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }
        if (is_string($v)) {
            $v = str_replace(['$', ',', ' ', "\u{a0}"], '', $v);
        }

        return is_numeric($v) ? round((float) $v, 2) : false;
    }

    /** Número de serie de Excel o texto (dd/mm/aaaa, dd-mm-aa, aaaa-mm-dd, "22 DE MAYO" con el año de la hoja). */
    private static function fecha(mixed $v, ?int $anioHoja): ?DateTimeImmutable
    {
        if (is_numeric($v)) {
            if ((float) $v < 1) {
                return null;
            }
            $f = LectorXlsx::fecha((float) $v);

            return $f !== null && (int) $f->format('Y') > 1900 ? $f : null;
        }
        $t = self::norm($v);
        if ($t === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $t, $m)) {
            return self::armar((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2}|\d{4})$/', $t, $m)) {
            return self::armar(strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{1,2}) (?:DE |DEL )?([A-Z]{3,})\.?(?: (?:DE |DEL )?(\d{4}))?$/', $t, $m)) {
            $mes = self::MESES[substr($m[2], 0, 3)] ?? null;
            $anio = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $anioHoja;

            return $mes !== null && $anio !== null ? self::armar($anio, $mes, (int) $m[1]) : null;
        }

        return null;
    }

    private static function armar(int $anio, int $mes, int $dia): ?DateTimeImmutable
    {
        return checkdate($mes, $dia, $anio) ? new DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $mes, $dia)) : null;
    }
}

<?php

namespace App\Support;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Venta;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Trae al módulo de Finanzas el historial de ventas del Google Sheets de Daniel (Excel descargado).
 *
 * Lee todas las hojas cuyo nombre empieza con "VENTAS" (VENTAS 2026, VENTAS 2025, VENTAS 2024, VENTAS COMPRAS 2023…)
 * de la más nueva a la más vieja (el año son los 4 dígitos del nombre). En cada hoja el encabezado se busca en los
 * primeros 20 renglones (el que tenga CLIENTE y una columna de folio; si hay varios, el que reconozca más columnas
 * y, en empate, el último: las hojas 2024 y 2023 repiten el encabezado en el renglón 2 y el bueno está en el 11).
 * Las columnas se ubican por su encabezado, así que las hojas pueden tener distinta forma.
 *
 * Es idempotente: una factura que ya está en el sistema (misma llave de folio, tipo + folio) no se vuelve a traer,
 * y si el folio se repite en el Excel (en la misma hoja o en otra) solo entra la primera, o sea la de la hoja más nueva.
 *
 * analizar() no escribe nada (vista previa); importar() hace lo mismo e inserta en lotes, una transacción por hoja.
 * Las cuentas de depósito (Cuenta::obtener) solo se crean al importar. Nunca se crean clientes.
 *
 * Decisiones sobre datos reales del Sheets (todas se avisan como anomalías):
 * - Un renglón sin cliente pero con folio y fecha es una venta real: entra como "SIN CLIENTE".
 * - Un renglón sin folio (2023) entra con folio provisional "SF-<año de la hoja>-F<renglón>" (fijo: reimportar no duplica).
 * - Fecha de factura en el futuro en una hoja de un año anterior (diciembre 2025 capturado como 2026): se usa el año
 *   de la hoja.
 * - Las notas de venta son una sola secuencia escrita distinto según el año ("NOTA 90", "NOTA-298"): el folio siempre
 *   queda "NOTA-<número>", y sin número "NOTA-SN-<año de la hoja>-F<renglón>".
 * - La fecha de pago solo se guarda si la venta queda pagada (en 2024 esa columna es una fórmula: factura + 30 días).
 * - Estatus como "P/CANCELAR" o "REFACTURADA" entran como canceladas, con el estatus original en las notas.
 */
final class ImportadorVentasSheets
{
    /** Encabezados (normalizados) de cada campo; si hay varios, el primero que exista en la hoja. */
    private const ENCABEZADOS = [
        'cliente' => ['CLIENTE'],
        'concepto' => ['CONCEPTO'],
        'fecha' => ['FECHA FACTURA', 'FECHA'],
        'folio' => ['FOLIO', 'FACTURA VENT', 'FACTURA'],   // todas: si la celda viene vacía se prueba la siguiente
        'tipo' => ['TIPO DE DOCUMENTO'],
        'costo' => ['COMPRA'],
        'estatus' => ['ESTATUS'],
        'fecha_pago' => ['FECHA DE PAGO'],
        'venta' => ['VENTA'],
        'iva' => ['IVA'],
        'retencion' => ['RETENCION'],
        'total' => ['PAGO'],
        'portal' => ['ESTATUS PORTAL'],
        'sustituta' => ['FACTURA SUSTITUTA'],
        'cuenta' => ['CUENTA DEPOSITO'],
    ];

    private const OBLIGATORIAS = ['cliente' => 'CLIENTE', 'fecha' => 'FECHA', 'folio' => 'FOLIO o FACTURA', 'venta' => 'VENTA'];

    private const PORTAL = [
        'CARGADA' => 'cargada', 'NO APLICA' => 'no_aplica', 'EVIDENCIA ENVIADA' => 'evidencia',
        'PROGRAMADA PARA PAGO' => 'programada', 'POR CARGAR' => 'por_cargar',
    ];

    private const MESES = [
        'ENERO' => 1, 'ENE' => 1, 'FEBRERO' => 2, 'FEB' => 2, 'MARZO' => 3, 'MAR' => 3, 'ABRIL' => 4, 'ABR' => 4,
        'MAYO' => 5, 'MAY' => 5, 'JUNIO' => 6, 'JUN' => 6, 'JULIO' => 7, 'JUL' => 7, 'AGOSTO' => 8, 'AGO' => 8,
        'SEPTIEMBRE' => 9, 'SETIEMBRE' => 9, 'SEPT' => 9, 'SEP' => 9, 'OCTUBRE' => 10, 'OCT' => 10,
        'NOVIEMBRE' => 11, 'NOV' => 11, 'DICIEMBRE' => 12, 'DIC' => 12,
    ];

    private const NOMBRES_MESES = [1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO',
        8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE'];

    /** Mensajes de anomalías que se guardan por hoja (el total se cuenta completo). */
    public const MAX_ANOMALIAS = 30;

    private const MAX_REPETIDAS = 100;

    private const LOTE = 200;

    private const SIN_CLIENTE = 'SIN CLIENTE';

    // Estado de una corrida (se reinicia en cada analizar/importar).
    /** @var array<string, true> llaves de folio que ya están en la base (también las borradas) */
    private array $existentes = [];

    /** @var array<string, string> llave de folio => hoja donde se vio primero en este Excel */
    private array $vistos = [];

    /** @var array<string, array> llave de folio => renglón listo para insertar (se insertan al final, por hoja) */
    private array $filasGuardadas = [];

    /** @var array<string, int> */
    private array $clientesPorLlave = [];

    /** @var array<string, int> */
    private array $clientesPorBase = [];

    /** @var array<string, int|null> */
    private array $clienteDe = [];

    /** @var array<string, array{nombre: string, ventas: int}> */
    private array $cuentasUsadas = [];

    /** @var array<string, int> */
    private array $cuentaIds = [];

    private string $ahora = '';

    private string $limiteFuturo = '';

    /** ¿El Excel trae hojas de ventas (VENTAS 2026…)? */
    public static function hayHojas(string $rutaXlsx): bool
    {
        return self::hojasDeVentas(new LectorXlsx($rutaXlsx)) !== [];
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

    private function correr(string $ruta, bool $guardar): array
    {
        $lector = new LectorXlsx($ruta);
        $hojas = self::hojasDeVentas($lector);
        if ($hojas === []) {
            throw new InvalidArgumentException('No encontré hojas de ventas en el Excel (deben llamarse "VENTAS 2026", "VENTAS 2025"…). ¿Es el archivo correcto?');
        }

        $this->existentes = [];
        foreach (DB::table('ventas')->pluck('folio_llave') as $llave) {
            $this->existentes[self::plegar((string) $llave)] = true;
        }
        $this->vistos = [];
        $this->filasGuardadas = [];
        $this->cargarClientes();
        $this->cuentasUsadas = [];
        $this->cuentaIds = [];
        $this->ahora = now()->format('Y-m-d H:i:s');
        $this->limiteFuturo = now()->addDays(7)->format('Y-m-d');

        $resultado = ['guardado' => $guardar, 'hojas' => [], 'totales' => [], 'cuentas' => []];
        // Primero se leen todas las hojas (una más vieja puede completar los montos de un renglón de la nueva) y al
        // final se inserta, una transacción por hoja.
        $porHoja = [];
        foreach ($hojas as $hoja) {
            $porHoja[] = $this->procesarHoja($lector, $hoja['nombre'], $hoja['anio']);
        }
        foreach ($porHoja as [$resumen, $claves]) {
            if ($guardar) {
                $resumen['insertadas'] = $this->insertar(array_map(fn (string $c) => $this->filasGuardadas[$c], $claves));
            }
            $resultado['hojas'][] = $resumen;
        }

        $resultado['totales'] = self::totales($resultado['hojas'], $guardar);
        $conocidas = Cuenta::query()->pluck('nombre_llave')->flip();
        foreach ($this->cuentasUsadas as $llave => $c) {
            $resultado['cuentas'][] = $c + ['nueva' => ! $guardar && ! isset($conocidas[$llave])];
        }

        return $resultado;
    }

    /**
     * Hojas "VENTAS…" de la más nueva a la más vieja (sin año al final; mismo año en el orden del libro).
     *
     * @return list<array{nombre: string, anio: ?int}>
     */
    private static function hojasDeVentas(LectorXlsx $lector): array
    {
        $hojas = [];
        foreach ($lector->hojas() as $orden => $nombre) {
            if (str_starts_with(self::norm($nombre), 'VENTAS')) {
                $anio = preg_match('/(?<!\d)(\d{4})(?!\d)/', $nombre, $m) ? (int) $m[1] : null;
                $hojas[] = ['nombre' => $nombre, 'anio' => $anio, 'orden' => $orden];
            }
        }
        usort($hojas, fn ($a, $b) => [$b['anio'] ?? 0, $a['orden']] <=> [$a['anio'] ?? 0, $b['orden']]);

        return array_map(fn ($h) => ['nombre' => $h['nombre'], 'anio' => $h['anio']], $hojas);
    }

    /** @return array{0: array, 1: list<string>} resumen de la hoja y las llaves de sus renglones por insertar */
    private function procesarHoja(LectorXlsx $lector, string $nombre, ?int $anio): array
    {
        $r = [
            'nombre' => $nombre, 'anio' => $anio, 'fila_encabezado' => null, 'columnas' => [], 'error' => null,
            'filas' => 0, 'saltadas' => 0, 'a_importar' => 0, 'ya_estaban' => 0, 'repetidas' => 0, 'repetidas_folios' => [], 'completadas' => 0,
            'pagadas' => 0, 'pendientes' => 0, 'canceladas' => 0, 'venta' => 0.0, 'costo' => 0.0, 'utilidad' => 0.0,
            'anomalias_total' => 0, 'anomalias' => [], 'leves' => [],
        ];
        $filas = $lector->filas($nombre);

        $encabezado = self::encabezado($filas);
        if ($encabezado === null) {
            $r['error'] = 'No encontré el renglón de encabezados (con CLIENTE y FOLIO, FACTURA VENT o FACTURA) en los primeros 20 renglones.';
            unset($r['leves']);

            return [$r, []];
        }
        [$indice, $cols] = $encabezado;
        $r['fila_encabezado'] = $indice + 1;
        foreach ($cols as $campo => $c) {
            $r['columnas'][$campo] = is_array($c) ? array_map([LectorXlsx::class, 'letra'], $c) : LectorXlsx::letra($c);
        }
        $faltan = [];
        foreach (self::OBLIGATORIAS as $campo => $texto) {
            if (! isset($cols[$campo])) {
                $faltan[] = $texto;
            }
        }
        if ($faltan !== []) {
            $r['error'] = 'No encontré la columna '.implode(', ', $faltan).' en el encabezado (renglón '.($indice + 1).').';
            unset($r['leves']);

            return [$r, []];
        }

        $claves = [];
        $centavos = ['venta' => 0, 'costo' => 0];
        for ($i = $indice + 1, $ultimo = count($filas); $i < $ultimo; $i++) {
            $fila = $filas[$i];
            if ($fila === []) {
                continue;
            }
            $renglon = $i + 1;
            $celda = fn (string $campo) => isset($cols[$campo]) ? ($fila[$cols[$campo]] ?? null) : null;

            $cliente = self::texto($celda('cliente'));
            [$folio, $avisoFolio] = self::folioDe($fila, $cols['folio']);
            $sinCliente = false;
            if ($cliente === '') {
                if ($folio === null) {
                    continue;   // renglón vacío o de resumen
                }
                $sinCliente = true;
            } elseif (($norm = self::norm($cliente)) === 'CLIENTE' || preg_match('/^TOTAL(?:ES)?(?![A-Z0-9])/', $norm)) {
                continue;   // encabezado repetido o renglón de totales
            }
            $r['filas']++;

            [$fecha, , $fechaDudosa] = self::fecha($celda('fecha'), $anio);
            if ($fecha === null) {
                $this->anomalia($r, $renglon, 'sin fecha válida ('.self::muestra($celda('fecha')).'), no se importó');
                $r['saltadas']++;

                continue;
            }
            $esNota = str_contains(self::norm(self::texto($celda('tipo'))), 'NOTA') || self::pareceNota($folio);
            $avisosPrevios = $avisoFolio && ! $esNota ? [$avisoFolio] : [];   // en las notas el número suelto es lo normal
            if ($folio === null && ! $esNota) {
                // Venta real sin folio (2023): entra con un folio provisional fijo por hoja y renglón, así una segunda
                // importación del mismo Excel no la duplica.
                $folio = 'SF-'.($anio !== null ? "{$anio}-" : '')."F{$renglon}";
                $avisosPrevios[] = "sin folio, se importó como {$folio}";
            }
            if ($fechaDudosa) {
                $avisosPrevios[] = 'fecha "'.self::muestra($celda('fecha')).'" leída como '.$fecha->format('Y-m-d');
            }
            $tipo = $esNota ? 'nota' : 'factura';
            if ($esNota) {
                [$folio, $avisoNota] = self::folioNota($folio, $anio, $renglon);
                if ($avisoNota) {
                    $avisosPrevios[] = $avisoNota;
                }
            }
            if (mb_strlen($folio) > 40) {
                $folio = mb_substr($folio, 0, 40);
                $avisosPrevios[] = 'folio demasiado largo, se recortó a 40 letras';
            }

            $llave = Venta::llave($tipo, $folio);
            $clave = self::plegar($llave);
            $completar = false;
            if (isset($this->vistos[$clave])) {
                // En la hoja 2026 se anotó solo el PAGO (sin montos) de facturas de 2025 cobradas en 2026: si el renglón
                // que se quedó no trae montos y este sí, este lo completa (montos, costo y fecha; el pago se conserva).
                $previa = $this->filasGuardadas[$clave] ?? null;
                $completar = $previa !== null && $this->vistos[$clave] !== $nombre && (float) $previa['subtotal'] == 0.0
                    && (self::monto($celda('venta')) ?? 0) > 0;
                if (! $completar) {
                    $r['repetidas']++;
                    if (count($r['repetidas_folios']) < self::MAX_REPETIDAS) {
                        $r['repetidas_folios'][] = $this->vistos[$clave] === $nombre ? $folio : "{$folio} (ya está en {$this->vistos[$clave]})";
                    }

                    continue;
                }
            } else {
                $this->vistos[$clave] = $nombre;
                if (isset($this->existentes[$clave])) {
                    $r['ya_estaban']++;

                    continue;
                }
            }

            // A partir de aquí es una venta que sí se va a importar: sus anomalías se avisan.
            foreach ($avisosPrevios as $aviso) {
                $this->anomalia($r, $renglon, $aviso);
            }
            if ($sinCliente) {
                $this->anomalia($r, $renglon, 'sin cliente, se importó como "'.self::SIN_CLIENTE.'"');
            }

            $costo = $this->montoDe($r, $renglon, 'COMPRA', $celda('costo'));
            $subtotal = $this->montoDe($r, $renglon, 'VENTA', $celda('venta'));
            $retencion = $this->montoDe($r, $renglon, 'RETENCION', $celda('retencion'));
            $iva = self::vacio($celda('iva')) ? null : $this->montoDe($r, $renglon, 'IVA', $celda('iva'), null);
            $iva ??= round($subtotal * 0.16, 2);
            $total = self::vacio($celda('total')) ? null : $this->montoDe($r, $renglon, 'PAGO', $celda('total'), null);
            $total ??= round($subtotal + $iva - $retencion, 2);

            // Fecha de pago: en texto sin año usa el de la factura (y si queda antes de la factura, el año siguiente).
            $pago = null;
            $pagoInvalido = false;
            if (! self::vacio($celda('fecha_pago'))) {
                [$pago, $conAnio, $pagoDudoso] = self::fecha($celda('fecha_pago'), (int) $fecha->format('Y'));
                if ($pago === null) {
                    $pagoInvalido = true;
                    $this->anomalia($r, $renglon, 'fecha de pago no válida ('.self::muestra($celda('fecha_pago')).')');
                } elseif (! $conAnio && $pago < $fecha) {
                    $pago = $pago->modify('+1 year');
                }
                if ($pago !== null && $pagoDudoso) {
                    $this->anomalia($r, $renglon, 'fecha de pago "'.self::muestra($celda('fecha_pago')).'" leída como '.$pago->format('Y-m-d'));
                }
            }
            // Fechas que parecen error de año (en 2025 hay facturas de diciembre capturadas como 2026): si la hoja es de
            // un año anterior y la fecha cae en el futuro, se usa el año de la hoja.
            $futura = $fecha->format('Y-m-d') > $this->limiteFuturo;
            if ($futura && $anio !== null && (int) $fecha->format('Y') > $anio
                && checkdate((int) $fecha->format('n'), (int) $fecha->format('j'), $anio)) {
                $corregida = new DateTimeImmutable(sprintf('%04d-%s', $anio, $fecha->format('m-d')));
                if ($pago === null || $pago >= $corregida) {
                    $this->anomalia($r, $renglon, 'fecha de factura '.$fecha->format('Y-m-d').' en el futuro, se corrigió a '.$corregida->format('Y-m-d').' (año de la hoja)');
                    $fecha = $corregida;
                    $futura = false;
                }
            }
            $pagoAntes = $pago !== null && $pago < $fecha;
            if ($futura || $pagoAntes) {
                $this->anomalia($r, $renglon, ($futura ? 'fecha de factura '.$fecha->format('Y-m-d').' en el futuro' : 'factura del '.$fecha->format('Y-m-d'))
                    .($pagoAntes ? ' y fecha de pago '.$pago->format('Y-m-d').' anterior a la factura' : '').' (¿error de año?)');
            }

            [$estatus, $notas, $avisoEstatus] = self::estatus(self::texto($celda('estatus')), $pago !== null);
            if ($avisoEstatus) {
                $this->anomalia($r, $renglon, $avisoEstatus);
            }
            if ($pagoInvalido) {   // lo que decía ("FACTURADA 2024") no se pierde
                $notas = trim($notas."\nFecha de pago en el Sheets: ".mb_substr(self::texto($celda('fecha_pago')), 0, 100));
            }
            if ($estatus === 'pagada' && $pago === null && ! $pagoInvalido) {
                $this->anomalia($r, $renglon, 'pagada sin fecha de pago', true);
            }
            if ($estatus !== 'pagada') {
                $pago = null;
            }

            $portal = null;
            $portalTexto = self::norm(self::texto($celda('portal')));
            if ($portalTexto !== '') {
                $portal = self::PORTAL[$portalTexto] ?? null;
                if ($portal === null) {
                    $this->anomalia($r, $renglon, 'estatus del portal "'.self::muestra($celda('portal')).'" no reconocido, se dejó vacío');
                }
            }

            $cuenta = mb_substr(self::texto($celda('cuenta')), 0, 80);
            if ($cuenta !== '' && Nombres::llave($cuenta) !== '') {
                $c = Nombres::llave($cuenta);
                $this->cuentasUsadas[$c] ??= ['nombre' => $cuenta, 'ventas' => 0];
                $this->cuentasUsadas[$c]['ventas']++;
            } else {
                $cuenta = null;
            }

            $sustituta = null;
            if (! self::vacio($celda('sustituta'))) {
                [$sustituta, $avisoSustituta] = self::folioDeCelda($celda('sustituta'));
                $sustituta = $sustituta === null ? null : mb_substr($sustituta, 0, 40);
                if ($avisoSustituta) {
                    $this->anomalia($r, $renglon, 'factura sustituta: '.$avisoSustituta);
                }
            }

            $nombreCliente = $sinCliente ? self::SIN_CLIENTE : (mb_substr(Nombres::limpiar($cliente), 0, 255) ?: self::SIN_CLIENTE);
            $concepto = self::texto($celda('concepto'));

            if ($estatus !== 'cancelada') {   // lo cancelado no cuenta como venta (igual que Venta::vigentes); en centavos para no arrastrar decimales
                $centavos['venta'] += (int) round($subtotal * 100);
                $centavos['costo'] += (int) round($costo * 100);
            }

            $nueva = [
                'tipo' => $tipo, 'folio' => $folio, 'folio_llave' => $llave,
                'cliente_id' => $sinCliente ? null : $this->clienteId($nombreCliente), 'cliente_nombre' => $nombreCliente,
                'concepto' => $concepto === '' ? null : $concepto, 'fecha' => $fecha->format('Y-m-d'),
                'costo' => self::decimal($costo), 'costo_sicar' => null,
                'subtotal' => self::decimal($subtotal), 'iva' => self::decimal($iva),
                'retencion' => self::decimal($retencion), 'total' => self::decimal($total),
                'estatus' => $estatus, 'fecha_pago' => $pago?->format('Y-m-d'),
                'cuenta_id' => null, 'pago_referencia' => null, 'estatus_portal' => $portal, 'metodo_pago' => null,
                'factura_sustituta' => $sustituta, 'uuid' => null, 'sicar_id' => null,
                'origen' => 'sheets', 'notas' => $notas,
                'created_at' => $this->ahora, 'updated_at' => $this->ahora,
                'cuenta' => $cuenta,   // se cambia por cuenta_id al insertar
            ];

            if ($completar) {
                $previa = $this->filasGuardadas[$clave];
                foreach (['fecha', 'costo', 'subtotal', 'iva', 'retencion', 'total'] as $campo) {
                    $previa[$campo] = $nueva[$campo];
                }
                $previa['concepto'] ??= $nueva['concepto'];
                if ($previa['cliente_nombre'] === self::SIN_CLIENTE) {
                    [$previa['cliente_id'], $previa['cliente_nombre']] = [$nueva['cliente_id'], $nueva['cliente_nombre']];
                }
                if ($previa['estatus'] === 'pendiente' && $nueva['estatus'] === 'pagada') {
                    $previa['estatus'] = 'pagada';
                }
                if ($previa['estatus'] === 'pagada') {
                    $previa['fecha_pago'] ??= $nueva['fecha_pago'];
                }
                $previa['cuenta'] ??= $nueva['cuenta'];
                $previa['notas'] = trim(($previa['notas'] ?? '')."\nMontos tomados de la hoja {$nombre} (en {$this->vistos[$clave]} venía solo el pago).");
                $this->filasGuardadas[$clave] = $previa;
                $r['completadas']++;
                $this->anomalia($r, $renglon, "folio {$folio}: en {$this->vistos[$clave]} venía sin montos, se completó con esta hoja", true);

                continue;
            }

            $r['a_importar']++;
            $r[['pagada' => 'pagadas', 'pendiente' => 'pendientes', 'cancelada' => 'canceladas'][$estatus]]++;
            $this->filasGuardadas[$clave] = $nueva;
            $claves[] = $clave;
        }

        $r['venta'] = $centavos['venta'] / 100;
        $r['costo'] = $centavos['costo'] / 100;
        $r['utilidad'] = ($centavos['venta'] - $centavos['costo']) / 100;
        $r['anomalias'] = array_slice(array_merge($r['anomalias'], $r['leves']), 0, self::MAX_ANOMALIAS);
        unset($r['leves']);

        return [$r, $claves];
    }

    /** Inserta en lotes de 200 dentro de una transacción (si algo falla, la hoja no se importa a medias). */
    private function insertar(array $filas): int
    {
        if ($filas === []) {
            return 0;
        }

        return DB::transaction(function () use ($filas) {
            foreach (array_chunk($filas, self::LOTE) as $lote) {
                foreach ($lote as &$fila) {
                    $cuenta = $fila['cuenta'];
                    unset($fila['cuenta']);
                    $fila['cuenta_id'] = $cuenta === null ? null : ($this->cuentaIds[$cuenta] ??= Cuenta::obtener($cuenta)->id);
                }
                unset($fila);
                DB::table('ventas')->insert($lote);
            }

            return count($filas);
        });
    }

    /**
     * El renglón de encabezados: de los primeros 20, los que tienen CLIENTE y una columna de folio; gana el que
     * reconoce más columnas y, en empate, el último.
     *
     * @return array{0: int, 1: array<string, int|list<int>>}|null índice del renglón (0 = renglón 1) y columnas
     */
    private static function encabezado(array $filas): ?array
    {
        $mejor = null;
        foreach (array_slice($filas, 0, 20, true) as $i => $fila) {
            $cols = self::columnas($fila);
            if (! isset($cols['cliente']) || empty($cols['folio'])) {
                continue;
            }
            if ($mejor === null || count($cols) >= $mejor['puntos']) {
                $mejor = ['indice' => $i, 'cols' => $cols, 'puntos' => count($cols)];
            }
        }

        return $mejor === null ? null : [$mejor['indice'], $mejor['cols']];
    }

    /** @return array<string, int|list<int>> campo => columna (folio: lista de columnas, en orden de preferencia) */
    private static function columnas(array $fila): array
    {
        $porTexto = [];
        foreach ($fila as $i => $valor) {
            if (is_string($valor) && ($n = self::norm($valor)) !== '') {
                $porTexto[$n] ??= $i;   // si un encabezado se repite (dos "ESTATUS"), cuenta el primero
            }
        }
        $cols = [];
        foreach (self::ENCABEZADOS as $campo => $alias) {
            foreach ($alias as $a) {
                if (! isset($porTexto[$a])) {
                    continue;
                }
                if ($campo === 'folio') {
                    $cols['folio'][] = $porTexto[$a];
                } else {
                    $cols[$campo] = $porTexto[$a];
                    break;
                }
            }
        }

        return $cols;
    }

    /**
     * Folio del renglón: la primera columna de folio que no venga vacía.
     *
     * @param  list<int>  $columnas
     * @return array{0: ?string, 1: ?string} [folio o null si no trae, aviso si hubo que adivinarlo]
     */
    private static function folioDe(array $fila, array $columnas): array
    {
        foreach ($columnas as $c) {
            $v = $fila[$c] ?? null;
            if (! self::vacio($v)) {
                return self::folioDeCelda($v);
            }
        }

        return [null, null];
    }

    /**
     * Folio de una celda. Texto: limpio y en mayúsculas. Número: Sheets convirtió el folio en fecha ("5-3317" →
     * 1/mayo/3317, "5-8" → 5/agosto): se recupera "5-{año}" si es mayo día 1 o 2, "5-{mes}" si es día 5.
     *
     * @return array{0: ?string, 1: ?string} [folio, aviso]
     */
    private static function folioDeCelda(mixed $v): array
    {
        if (! is_int($v) && ! is_float($v)) {
            $texto = mb_strtoupper(self::texto($v));

            return [$texto === '' ? null : $texto, null];
        }
        $fecha = LectorXlsx::fecha($v);
        if ($fecha !== null) {
            [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha->format('Y-n-j')));
            if ($mes === 5 && $dia <= 2) {
                return ["5-{$anio}", null];
            }
            if ($dia === 5) {
                return ["5-{$mes}", null];
            }
            if ($anio >= 2000 && $anio <= 2100) {   // parece una fecha de verdad: se usa la fecha como folio
                return [$fecha->format('Y-m-d'), "folio numérico {$v} que no pude leer como folio, se usó la fecha {$fecha->format('Y-m-d')}"];
            }
        }
        if (floor($v) == $v && $v > 0 && $v < 1e15) {   // un número normal (p. ej. 97801): se guarda tal cual
            return [(string) (int) $v, "folio numérico {$v} que no parece fecha, se guardó tal cual"];
        }

        return [null, null];
    }

    /** El folio empieza con "NOTA" (o con el error de dedo "NOT-38"). */
    private static function pareceNota(?string $folio): bool
    {
        return $folio !== null && (str_starts_with($folio, 'NOTA') || preg_match('/^NOT[\s.\-#:]*\d/', $folio) === 1);
    }

    /**
     * Las notas de venta son una sola secuencia que se escribe distinto según el año ("NOTA 90", "NOTA-298", "NOTA 32 "):
     * el folio siempre queda "NOTA-<número>". Si no trae número ("nota") queda "NOTA-SN-<año de la hoja>-F<renglón>"
     * para no perderla como repetida (Venta::llave solo compara el número en las notas).
     *
     * @return array{0: string, 1: ?string} [folio, aviso]
     */
    private static function folioNota(?string $folio, ?int $anio, int $renglon): array
    {
        $folio = (string) $folio;
        if (preg_match('/NOTA\D*(\d+)/', $folio, $m) || preg_match('/^\D*(\d+)\D*$/', $folio, $m)) {
            return ['NOTA-'.(ltrim($m[1], '0') ?: '0'), null];
        }
        $sn = 'NOTA-SN-'.($anio !== null ? "{$anio}-" : '')."F{$renglon}";

        return [$sn, 'nota sin número, se importó como '.$sn];
    }

    /**
     * Fecha de una celda: número = serial de Excel (sin la hora); texto "22 DE MAYO", "22 MAYO 2023", "22/05/2023",
     * "2023-05-22". Sin año en el texto usa $anioPorDefecto. Solo acepta años de 2000 a 2100. Perdona "7 DEJULIO" y
     * meses con una o dos letras mal ("SEPTIEMRBE"): en ese caso la fecha se marca como dudosa para avisarlo.
     *
     * @return array{0: ?DateTimeImmutable, 1: bool, 2: bool} [fecha, el año venía en la celda, fecha dudosa]
     */
    private static function fecha(mixed $v, ?int $anioPorDefecto): array
    {
        $fecha = null;
        $conAnio = true;
        $dudosa = false;
        if (is_int($v) || is_float($v)) {
            $fecha = LectorXlsx::fecha($v);
        } elseif (is_string($v) && preg_match('/^\d{5}(?:\.\d+)?$/', trim($v))) {   // el número de serie escrito como texto
            $fecha = LectorXlsx::fecha((float) $v);
        } elseif (is_string($v) && trim($v) !== '') {
            $t = trim($v);
            $dia = $mes = $anio = null;
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ].*)?$/', $t, $m)) {
                [$anio, $mes, $dia] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            } else {
                $n = self::norm($t);
                $hora = '(?: \d{1,2} \d{2}(?: \d{2})?)?';
                if (preg_match('/^(\d{1,2}) (\d{1,2}) (\d{4}|\d{2})'.$hora.'$/', $n, $m)) {
                    [$dia, $mes, $anio] = [(int) $m[1], (int) $m[2], (int) $m[3]];
                    $anio = $anio < 100 ? 2000 + $anio : $anio;
                } elseif (preg_match('/^(\d{1,2}) (?:DE )?([A-Z]+)(?: (?:DEL? )?(\d{4}|\d{2}))?$/', $n, $m)) {
                    [$mes, $dudosa] = self::mesPorNombre($m[2]);
                    if ($mes !== null) {
                        $dia = (int) $m[1];
                        $anio = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
                        $anio = $anio !== null && $anio < 100 ? 2000 + $anio : $anio;
                    }
                }
            }
            if ($dia !== null) {
                $conAnio = $anio !== null;
                $anio ??= $anioPorDefecto;
                if ($anio !== null && checkdate($mes, $dia, $anio)) {
                    $fecha = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $mes, $dia));
                }
            }
        }
        if ($fecha !== null && ((int) $fecha->format('Y') < 2000 || (int) $fecha->format('Y') > 2100)) {
            $fecha = null;
        }

        return [$fecha, $conAnio, $fecha !== null && $dudosa];
    }

    /**
     * Número de mes de un nombre ("MAYO", "SEPT", "DEJULIO" con el DE pegado). Con una o dos letras mal
     * ("SEPTIEMRBE") se acepta si un solo mes se le parece y se marca como dudoso.
     *
     * @return array{0: ?int, 1: bool} [mes, dudoso]
     */
    private static function mesPorNombre(string $palabra): array
    {
        if (isset(self::MESES[$palabra])) {
            return [self::MESES[$palabra], false];
        }
        if (str_starts_with($palabra, 'DE') && isset(self::MESES[substr($palabra, 2)])) {
            return [self::MESES[substr($palabra, 2)], false];
        }
        $palabra = str_starts_with($palabra, 'DE') && strlen($palabra) > 6 ? substr($palabra, 2) : $palabra;
        if (strlen($palabra) < 5) {
            return [null, false];
        }
        $mejor = null;
        $distancia = 99;
        $empate = false;
        foreach (self::NOMBRES_MESES as $numero => $nombre) {
            $d = levenshtein($palabra, $nombre);
            if ($d < $distancia) {
                [$distancia, $mejor, $empate] = [$d, $numero, false];
            } elseif ($d === $distancia) {
                $empate = true;
            }
        }

        return $distancia <= 2 && ! $empate ? [$mejor, true] : [null, false];
    }

    /** @return array{0: string, 1: ?string, 2: ?string} [estatus, notas, aviso] */
    private static function estatus(string $crudo, bool $tienePago): array
    {
        $n = self::norm($crudo);
        $segunPago = $tienePago ? 'pagada' : 'pendiente';

        return match (true) {
            $n === '' => [$segunPago, null, null],
            str_starts_with($n, 'PAGAD') => ['pagada', null, null],
            str_starts_with($n, 'PENDIENT') => ['pendiente', null, null],
            str_starts_with($n, 'SUSTITUID') => ['cancelada', 'Sustituida (del Sheets)', null],
            str_starts_with($n, 'CANCELAD') => ['cancelada', null, null],
            str_starts_with($n, 'REFACTURAD') => ['cancelada', 'Refacturada (del Sheets)', "estatus \"{$crudo}\": se importó como cancelada"],
            str_contains($n, 'CANCEL') => ['cancelada', "Estatus en el Sheets: {$crudo}", "estatus \"{$crudo}\": se importó como cancelada"],
            default => [$segunPago, "Estatus en el Sheets: {$crudo}", "estatus \"{$crudo}\" no reconocido: se importó como {$segunPago}"],
        };
    }

    /**
     * Monto de una celda de la hoja. Vacío = 0; texto: sin $, comas ni espacios; si no se puede, o no cabe en la
     * columna, 0 y anomalía. Con $siFalla = null regresa null en vez de 0 cuando no se pudo leer.
     */
    private function montoDe(array &$resumen, int $fila, string $columna, mixed $v, ?float $siFalla = 0.0): ?float
    {
        $monto = self::monto($v);
        if ($monto === null) {
            $this->anomalia($resumen, $fila, "monto no válido en {$columna} (".self::muestra($v).')'.($siFalla === null ? ', se calculó' : ', se tomó $0.00'));

            return $siFalla;
        }

        return $monto;
    }

    /** null si no es un monto válido; 0 si viene vacío. */
    private static function monto(mixed $v): ?float
    {
        if (self::vacio($v)) {
            return 0.0;
        }
        if (is_int($v) || is_float($v)) {
            $n = (float) $v;
        } elseif (is_string($v)) {
            $limpio = str_replace(['$', ',', ' ', "\u{A0}"], '', trim($v));
            if (preg_match('/^\((.+)\)$/', $limpio, $m)) {   // (1,234.50) = negativo
                $limpio = '-'.$m[1];
            }
            if ($limpio === '' || ! is_numeric($limpio)) {
                return null;
            }
            $n = (float) $limpio;
        } else {
            return null;
        }

        return is_finite($n) && abs($n) < 1e12 ? round($n, 2) : null;   // la columna es decimal(14,2)
    }

    /**
     * Anota una anomalía: se cuenta siempre y se guarda el mensaje (máx. 30 por hoja). Las leves (se importa igual y
     * son muchas, como "pagada sin fecha de pago") solo ocupan los lugares que dejen las demás.
     */
    private function anomalia(array &$resumen, int $fila, string $mensaje, bool $leve = false): void
    {
        $resumen['anomalias_total']++;
        $lista = $leve ? 'leves' : 'anomalias';
        if (count($resumen[$lista]) < self::MAX_ANOMALIAS) {
            $resumen[$lista][] = "Hoja {$resumen['nombre']} fila {$fila}: {$mensaje}";
        }
    }

    private function cargarClientes(): void
    {
        $this->clientesPorLlave = [];
        $this->clientesPorBase = [];
        $this->clienteDe = [];
        foreach (Cliente::query()->orderBy('id')->get(['id', 'nombre', 'nombre_llave']) as $c) {
            $llave = $c->nombre_llave ?: Nombres::llave($c->nombre);
            if ($llave !== '') {
                $this->clientesPorLlave[$llave] ??= $c->id;
            }
            $base = UnificarDuplicados::base($c->nombre);
            if (strlen($base) >= 4) {
                $this->clientesPorBase[$base] ??= $c->id;
            }
        }
        // Los que se unieron a otro en Duplicados apuntan al principal (su nombre viejo sigue en el Sheets).
        foreach (Cliente::onlyTrashed()->whereNotNull('unido_a_id')->get() as $c) {
            if ($principal = $c->vigente()) {
                $this->clientesPorLlave[$c->nombre_llave ?: Nombres::llave($c->nombre)] ??= $principal->id;
                $base = UnificarDuplicados::base($c->nombre);
                if (strlen($base) >= 4) {
                    $this->clientesPorBase[$base] ??= $principal->id;
                }
            }
        }
    }

    /** El cliente del sistema con ese nombre (igual, o igual sin "S.A. de C.V."); null si no hay (no se crea). */
    private function clienteId(string $nombre): ?int
    {
        if (array_key_exists($nombre, $this->clienteDe)) {
            return $this->clienteDe[$nombre];
        }
        $llave = Nombres::llave($nombre);
        $base = UnificarDuplicados::base($nombre);

        return $this->clienteDe[$nombre] = ($llave !== '' ? ($this->clientesPorLlave[$llave] ?? null) : null)
            ?? (strlen($base) >= 4 ? ($this->clientesPorBase[$base] ?? null) : null);
    }

    /** @param  list<array>  $hojas */
    private static function totales(array $hojas, bool $guardado): array
    {
        $t = ['hojas' => count($hojas), 'filas' => 0, 'saltadas' => 0, 'a_importar' => 0, 'ya_estaban' => 0, 'repetidas' => 0, 'completadas' => 0,
            'pagadas' => 0, 'pendientes' => 0, 'canceladas' => 0, 'venta' => 0.0, 'costo' => 0.0, 'utilidad' => 0.0, 'anomalias_total' => 0];
        if ($guardado) {
            $t['insertadas'] = 0;
        }
        foreach ($hojas as $h) {
            foreach (array_keys($t) as $k) {
                if ($k !== 'hojas' && isset($h[$k])) {
                    $t[$k] += $h[$k];
                }
            }
        }
        foreach (['venta', 'costo', 'utilidad'] as $k) {
            $t[$k] = round($t[$k], 2);
        }

        return $t;
    }

    /** Mayúsculas, sin acentos y con espacios simples (lo que no es letra ni número se vuelve espacio). */
    private static function norm(?string $texto): string
    {
        return trim(preg_replace('/[^A-Z0-9]+/', ' ', strtoupper(Str::ascii((string) $texto))));
    }

    /** Texto limpio de una celda (espacios simples); los números sin ".0". */
    private static function texto(mixed $v): string
    {
        if ($v === null || is_bool($v)) {
            return '';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $v));
    }

    private static function vacio(mixed $v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '');
    }

    /** Lo que traía la celda, corto, para los mensajes. */
    private static function muestra(mixed $v): string
    {
        $t = self::texto($v);

        return $t === '' ? 'vacío' : mb_substr($t, 0, 40);
    }

    private static function dinero(float $v): string
    {
        return '$'.number_format($v, 2);
    }

    private static function decimal(float $v): string
    {
        return number_format($v == 0 ? 0.0 : $v, 2, '.', '');
    }

    /** Llave de comparación de folios: como Venta::llave pero sin acentos ni mayúsculas (así coincide también en MariaDB). */
    private static function plegar(string $llave): string
    {
        return strtolower(Str::ascii($llave));
    }
}

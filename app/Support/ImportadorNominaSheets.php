<?php

namespace App\Support;

use App\Models\Nomina;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trae a Nómina el historial de pagos del Google Sheets de Daniel (hoja "HISTORIAL NOMINA" del Excel descargado).
 *
 * Encabezado en el renglón 1: FECHA, TIPO, AÑO, MES, SEMANA, EMPLEADO / CONCEPTO, BASE, COMISIÓN / REPARTO, TOTAL, NOTAS.
 * - TIPO "NOMINA SEMANAL": se agrupa por (AÑO, SEMANA) y queda como nómina semanal ya pagada (origen sheets) con
 *   lunes–sábado de Semanas::rango y la fecha de pago de la columna FECHA. Cada empleado es una línea (la comisión del
 *   Sheets puede ser negativa: era un descuento). El renglón "— TOTAL SEMANA —" no es una persona: solo se usa para
 *   revisar que la suma cuadre.
 * - TIPO "REPARTO ERNESTO": reparto mensual al socio (AÑO, MES), una línea con el monto.
 * - Es idempotente: si ya existe la nómina de esa semana (o ese mes) no se vuelve a traer.
 * - Cada línea se liga al usuario que tenga ese primer nombre (ERNESTO PÉREZ → Ernesto); si no hay, queda solo el nombre.
 *
 * analizar() no escribe nada (vista previa); importar() hace lo mismo y guarda, todo en una transacción.
 */
final class ImportadorNominaSheets
{
    public const HOJA = 'HISTORIAL NOMINA';

    private const MAX_ANOMALIAS = 30;

    /** Un mismo nombre escrito de dos maneras. */
    private const ALIAS = ['ANDREA' => 'ANDREA'];

    private const MESES = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

    /** @var array<string, int> primer nombre normalizado => id del usuario */
    private array $usuarios = [];

    /** ¿El Excel trae la hoja del historial de nómina? */
    public static function hayHoja(string $rutaXlsx): bool
    {
        return self::buscarHoja(new LectorXlsx($rutaXlsx)) !== null;
    }

    /** Qué se importaría (no escribe nada). */
    public function analizar(string $rutaXlsx): array
    {
        return $this->correr($rutaXlsx, false);
    }

    /** Importa lo nuevo y regresa el mismo resumen (con 'guardado' => true). */
    public function importar(string $rutaXlsx): array
    {
        return $this->correr($rutaXlsx, true);
    }

    private function correr(string $ruta, bool $guardar): array
    {
        $lector = new LectorXlsx($ruta);
        $r = [
            'guardado' => $guardar, 'hoja' => null, 'error' => null, 'filas' => 0, 'ignoradas' => 0,
            'semanas' => ['a_importar' => 0, 'ya_estaban' => 0, 'total' => 0.0, 'primera' => null, 'ultima' => null, 'detalle' => []],
            'repartos' => ['a_importar' => 0, 'ya_estaban' => 0, 'total' => 0.0, 'detalle' => []],
            'lineas' => 0, 'sin_usuario' => [], 'anomalias_total' => 0, 'anomalias' => [],
        ];
        $nombre = self::buscarHoja($lector);
        if ($nombre === null) {
            $r['error'] = 'El Excel no trae la hoja "'.self::HOJA.'".';

            return $r;
        }
        $r['hoja'] = $nombre;
        $filas = $lector->filas($nombre);
        $columnas = null;
        $primera = 0;
        foreach (array_slice($filas, 0, 5, true) as $i => $fila) {
            $c = self::columnas($fila);
            if ($c !== null) {
                $columnas = $c;
                $primera = $i + 1;
                break;
            }
        }
        if ($columnas === null) {
            $r['error'] = 'No encontré los encabezados de la hoja "'.$nombre.'" (FECHA, TIPO, AÑO, SEMANA, EMPLEADO, BASE, COMISIÓN, TOTAL).';

            return $r;
        }
        $this->cargarUsuarios();

        $semanas = [];
        $repartos = [];
        $totalesHoja = [];
        $sinUsuario = [];
        $anomalia = function (string $texto) use (&$r) {
            $r['anomalias_total']++;
            if (count($r['anomalias']) < self::MAX_ANOMALIAS) {
                $r['anomalias'][] = $texto;
            }
        };

        foreach (array_slice($filas, $primera, null, true) as $i => $fila) {
            $renglon = $i + 1;
            $celda = fn (string $campo) => isset($columnas[$campo]) ? ($fila[$columnas[$campo]] ?? null) : null;
            $tipo = self::norm($celda('tipo'));
            $empleado = trim((string) $celda('empleado'));
            if ($tipo === '' && $empleado === '') {
                continue;   // renglón vacío
            }
            $r['filas']++;
            $anio = self::entero($celda('anio'));
            $fecha = self::fechaHora($celda('fecha'));

            if (str_contains($tipo, 'SEMANAL')) {
                $semana = self::entero($celda('semana'));
                if ($anio === null || $semana === null || $semana < 1 || $semana > 53) {
                    $r['ignoradas']++;
                    $anomalia("Renglón {$renglon}: no trae año y semana válidos, no se importa.");

                    continue;
                }
                $llave = "{$anio}|{$semana}";
                $clave = $fecha?->format('Y-m-d H:i:s') ?? '';
                if (str_contains(self::norm($empleado), 'TOTAL')) {
                    $totalesHoja[$llave][$clave] = self::dinero($celda('total'));

                    continue;
                }
                if ($empleado === '') {
                    $r['ignoradas']++;

                    continue;
                }
                $semanas[$llave]['anio'] = $anio;
                $semanas[$llave]['semana'] = $semana;
                $semanas[$llave]['grupos'][$clave][] = $this->linea($empleado, $celda);
            } elseif (str_contains($tipo, 'REPARTO')) {
                $mes = self::entero($celda('mes'));
                if ($anio === null || $mes === null || $mes < 1 || $mes > 12 || $empleado === '') {
                    $r['ignoradas']++;
                    $anomalia("Renglón {$renglon}: el reparto no trae año y mes válidos, no se importa.");

                    continue;
                }
                $repartos["{$anio}|{$mes}"]['anio'] = $anio;
                $repartos["{$anio}|{$mes}"]['mes'] = $mes;
                $repartos["{$anio}|{$mes}"]['grupos'][$fecha?->format('Y-m-d H:i:s') ?? ''][] = $this->linea($empleado, $celda);
            } else {
                $r['ignoradas']++;
            }
        }

        $semanasNuevas = [];
        foreach ($semanas as $llave => $s) {
            [$clave, $lineas] = $this->grupoVigente($s['grupos'], $llave, $anomalia, "Semana {$s['semana']} de {$s['anio']}");
            $suma = round(array_sum(array_column($lineas, 'total')), 2);
            $totalHoja = $totalesHoja[$llave][$clave] ?? (count($totalesHoja[$llave] ?? []) === 1 ? reset($totalesHoja[$llave]) : null);
            if ($totalHoja !== null && abs($suma - $totalHoja) > 0.05) {
                $anomalia("Semana {$s['semana']} de {$s['anio']}: las personas suman $".number_format($suma, 2).' pero el renglón de total dice $'.number_format($totalHoja, 2).'.');
            }
            $yaEstaba = Nomina::where('tipo', 'semanal')->where('anio', $s['anio'])->where('semana', $s['semana'])->exists();
            [$lunes, $sabado] = Semanas::rango($s['anio'], $s['semana']);
            $r['semanas']['detalle'][] = ['anio' => $s['anio'], 'semana' => $s['semana'], 'etiqueta' => Semanas::etiqueta($s['anio'], $s['semana']),
                'personas' => count($lineas), 'total' => $suma, 'nueva' => ! $yaEstaba];
            if ($yaEstaba) {
                $r['semanas']['ya_estaban']++;

                continue;
            }
            $r['semanas']['a_importar']++;
            $r['semanas']['total'] += $suma;
            $semanasNuevas[] = ['tipo' => 'semanal', 'anio' => $s['anio'], 'semana' => $s['semana'], 'mes' => null,
                'desde' => $lunes->toDateString(), 'hasta' => $sabado->toDateString(), 'fecha' => $clave, 'lineas' => $lineas, 'bolsa' => 0];
        }
        usort($r['semanas']['detalle'], fn ($a, $b) => [$a['anio'], $a['semana']] <=> [$b['anio'], $b['semana']]);
        $nuevas = array_values(array_filter($r['semanas']['detalle'], fn ($d) => $d['nueva']));
        if ($nuevas) {
            $r['semanas']['primera'] = $nuevas[0]['semana'].' de '.$nuevas[0]['anio'];
            $r['semanas']['ultima'] = end($nuevas)['semana'].' de '.end($nuevas)['anio'];
        }

        $repartosNuevos = [];
        foreach ($repartos as $llave => $p) {
            [$clave, $lineas] = $this->grupoVigente($p['grupos'], $llave, $anomalia, 'Reparto '.self::MESES[$p['mes']].' '.$p['anio']);
            $suma = round(array_sum(array_column($lineas, 'total')), 2);
            $yaEstaba = Nomina::where('tipo', 'reparto')->where('anio', $p['anio'])->where('mes', $p['mes'])->exists();
            $desde = Carbon::create($p['anio'], $p['mes'], 1);
            $r['repartos']['detalle'][] = ['anio' => $p['anio'], 'mes' => $p['mes'], 'etiqueta' => self::MESES[$p['mes']].' '.$p['anio'],
                'personas' => count($lineas), 'total' => $suma, 'nueva' => ! $yaEstaba];
            if ($yaEstaba) {
                $r['repartos']['ya_estaban']++;

                continue;
            }
            $r['repartos']['a_importar']++;
            $r['repartos']['total'] += $suma;
            $repartosNuevos[] = ['tipo' => 'reparto', 'anio' => $p['anio'], 'semana' => null, 'mes' => $p['mes'],
                'desde' => $desde->toDateString(), 'hasta' => $desde->copy()->endOfMonth()->toDateString(), 'fecha' => $clave, 'lineas' => $lineas, 'bolsa' => $suma];
        }

        foreach (array_merge($semanasNuevas, $repartosNuevos) as $n) {
            foreach ($n['lineas'] as $l) {
                $r['lineas']++;
                if ($l['user_id'] === null) {
                    $sinUsuario[$l['nombre']] = true;
                }
            }
        }
        $r['sin_usuario'] = array_keys($sinUsuario);
        $r['semanas']['total'] = round($r['semanas']['total'], 2);
        $r['repartos']['total'] = round($r['repartos']['total'], 2);

        if ($guardar) {
            DB::transaction(function () use ($semanasNuevas, $repartosNuevos) {
                foreach (array_merge($semanasNuevas, $repartosNuevos) as $n) {
                    $nomina = Nomina::create([
                        'tipo' => $n['tipo'], 'anio' => $n['anio'], 'semana' => $n['semana'], 'mes' => $n['mes'],
                        'desde' => $n['desde'], 'hasta' => $n['hasta'], 'utilidad' => 0, 'bolsa' => $n['bolsa'],
                        'estatus' => 'pagada', 'pagada_at' => $n['fecha'] !== '' ? $n['fecha'] : $n['hasta'].' 12:00:00', 'origen' => 'sheets',
                    ]);
                    foreach ($n['lineas'] as $l) {
                        $nomina->lineas()->create($l);
                    }
                }
            });
        }

        return $r;
    }

    /**
     * Si la misma semana (o mes) viene capturada en dos pagos con fechas distintas, vale la más reciente.
     *
     * @return array{0: string, 1: list<array>}
     */
    private function grupoVigente(array $grupos, string $llave, callable $anomalia, string $nombre): array
    {
        ksort($grupos);
        if (count($grupos) > 1) {
            $anomalia("{$nombre}: viene capturada más de una vez con fechas distintas; se toma la más reciente.");
        }
        $clave = array_key_last($grupos);

        return [(string) $clave, $grupos[$clave]];
    }

    /** @return array{user_id: ?int, nombre: string, sueldo_base: float, comision_pct: int, comision: float, comision_manual: bool, ajuste: int, descuento_prestamo: int, total: float, notas: ?string} */
    private function linea(string $empleado, callable $celda): array
    {
        $base = self::dinero($celda('base'));
        $comision = self::dinero($celda('comision'));
        $totalCelda = $celda('total');
        $total = $totalCelda === null || $totalCelda === '' ? round($base + $comision, 2) : self::dinero($totalCelda);
        $notas = trim((string) $celda('notas'));

        return [
            'user_id' => $this->usuarios[self::primerNombre($empleado)] ?? null,
            'nombre' => mb_substr($empleado, 0, 120), 'sueldo_base' => $base, 'comision_pct' => 0, 'comision' => $comision,
            // Lo del Sheets ya está pagado y no se recalcula nunca.
            'comision_manual' => true, 'ajuste' => 0, 'descuento_prestamo' => 0, 'total' => $total,
            'notas' => $notas === '' ? null : $notas,
        ];
    }

    private function cargarUsuarios(): void
    {
        $this->usuarios = [];
        foreach (User::whereNull('eliminado_at')->where('rol', '!=', 'nedtracking')->orderByDesc('activo')->orderBy('id')->get(['id', 'name']) as $u) {
            $this->usuarios[self::primerNombre($u->name)] ??= $u->id;
        }
    }

    public static function primerNombre(string $nombre): string
    {
        $p = explode(' ', mb_strtoupper(Str::ascii(trim($nombre))))[0] ?? '';

        return self::ALIAS[$p] ?? $p;
    }

    private static function buscarHoja(LectorXlsx $lector): ?string
    {
        foreach ($lector->hojas() as $nombre) {
            if (self::norm($nombre) === self::HOJA) {
                return $nombre;
            }
        }

        return null;
    }

    /** @return array<string, int>|null columna (índice) de cada campo si el renglón es el encabezado */
    private static function columnas(array $fila): ?array
    {
        $c = [];
        foreach ($fila as $i => $valor) {
            $h = self::norm($valor);
            $campo = match (true) {
                $h === 'FECHA' => 'fecha',
                $h === 'TIPO' => 'tipo',
                $h === 'ANO' => 'anio',
                $h === 'MES' => 'mes',
                $h === 'SEMANA' => 'semana',
                str_starts_with($h, 'EMPLEADO') => 'empleado',
                $h === 'BASE' => 'base',
                str_starts_with($h, 'COMISION') => 'comision',
                $h === 'TOTAL' => 'total',
                $h === 'NOTAS' => 'notas',
                default => null,
            };
            if ($campo !== null) {
                $c[$campo] ??= $i;
            }
        }

        return isset($c['tipo'], $c['anio'], $c['empleado'], $c['total']) ? $c : null;
    }

    /** Mayúsculas, sin acentos ni espacios de más. */
    private static function norm(mixed $v): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtoupper(Str::ascii((string) $v))));
    }

    private static function entero(mixed $v): ?int
    {
        return is_numeric($v) ? (int) round((float) $v) : null;
    }

    private static function dinero(mixed $v): float
    {
        if (is_string($v)) {
            $v = str_replace(['$', ',', ' '], '', $v);
        }

        return is_numeric($v) ? round((float) $v, 2) : 0.0;
    }

    /** Fecha y hora de un número de serie de Excel (la hora va en la parte decimal). */
    private static function fechaHora(mixed $v): ?DateTimeImmutable
    {
        if (! is_numeric($v)) {
            return null;
        }
        $dia = LectorXlsx::fecha((float) $v);
        if ($dia === null) {
            return null;
        }
        $segundos = (int) round((((float) $v) - floor((float) $v)) * 86400);

        return $dia->modify(sprintf('+%d seconds', min(86399, $segundos)));
    }
}

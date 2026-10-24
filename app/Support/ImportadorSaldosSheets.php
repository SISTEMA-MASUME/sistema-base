<?php

namespace App\Support;

use App\Models\Cuenta;
use App\Models\SaldoCorte;
use App\Models\SaldoLinea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa las cuentas y saldos de las hojas CONTROL (saldos reales, tarjetas) y SALDOS (débito, créditos, préstamo a Jorge)
 * del Sheets: crea/actualiza cuentas y deja UN corte de saldos con la fecha de hoy y la nota "Importado del Sheets".
 * Los encabezados se buscan por texto (TARJETA, SALDO HOY, CUENTA, CRÉDITO…), no por celda fija. Si un bloque no
 * cuadra (número ilegible, total que no coincide) se avisa y ese renglón o bloque no se importa. Si una cuenta viene en
 * las dos hojas con saldo distinto se usa la de CONTROL (es más reciente).
 */
final class ImportadorSaldosSheets
{
    public const NOTA = 'Importado del Sheets';

    public static function hayHojas(string $rutaXlsx): bool
    {
        return self::hojaDe(new LectorXlsx($rutaXlsx), 'CONTROL') !== null || self::hojaDe(new LectorXlsx($rutaXlsx), 'SALDOS') !== null;
    }

    public function analizar(string $rutaXlsx): array
    {
        return $this->correr($rutaXlsx, false);
    }

    /** Importa; si ya hay un corte importado del Sheets y no se pide $forzar, no hace nada ('omitido'). */
    public function importar(string $rutaXlsx, bool $forzar = false): array
    {
        return $this->correr($rutaXlsx, true, $forzar);
    }

    public static function yaImportado(): bool
    {
        return SaldoCorte::where('notas', self::NOTA)->exists();
    }

    private function correr(string $ruta, bool $guardar, bool $forzar = false): array
    {
        $lector = new LectorXlsx($ruta);
        $r = ['guardado' => false, 'omitido' => false, 'ya_importado' => self::yaImportado(), 'cuentas' => [], 'avisos' => [], 'conflictos' => [], 'hojas' => []];
        /** @var array<string, array> $cuentas llave => datos */
        $cuentas = [];

        $control = self::hojaDe($lector, 'CONTROL');
        $saldos = self::hojaDe($lector, 'SALDOS');
        if ($control !== null) {
            $r['hojas'][] = $control;
            $this->leerControl($lector->filas($control), $cuentas, $r);
        }
        $deSaldos = [];
        if ($saldos !== null) {
            $r['hojas'][] = $saldos;
            $this->leerSaldos($lector->filas($saldos), $deSaldos, $r);
        }
        // Lo de SALDOS solo entra si CONTROL no trae la cuenta; si trae otro saldo gana CONTROL y se avisa.
        foreach ($deSaldos as $llave => $d) {
            if (! isset($cuentas[$llave])) {
                $cuentas[$llave] = $d;
            } elseif (abs($cuentas[$llave]['saldo'] - $d['saldo']) >= 0.01) {
                $r['conflictos'][] = "{$cuentas[$llave]['nombre']}: CONTROL dice \${$this->f($cuentas[$llave]['saldo'])} y SALDOS dice \${$this->f($d['saldo'])}; se usa el de CONTROL (es más reciente).";
            }
        }
        if ($control === null && $saldos === null) {
            $r['avisos'][] = 'El Excel no trae las hojas CONTROL ni SALDOS.';
        }

        $existentes = Cuenta::all()->keyBy('nombre_llave');
        foreach ($cuentas as $llave => $c) {
            $c['nueva'] = ! isset($existentes[$llave]);
            $r['cuentas'][] = $c;
        }
        usort($r['cuentas'], fn ($a, $b) => [$a['grupo'], $a['orden']] <=> [$b['grupo'], $b['orden']]);

        if ($guardar) {
            if ($r['ya_importado'] && ! $forzar) {
                $r['omitido'] = true;

                return $r;
            }
            if ($r['cuentas']) {
                DB::transaction(function () use ($r) {
                    $corte = SaldoCorte::create(['fecha' => today(), 'user_id' => auth()->id(), 'notas' => self::NOTA]);
                    $orden = (int) Cuenta::max('orden');
                    foreach ($r['cuentas'] as $c) {
                        $cuenta = Cuenta::where('nombre_llave', Nombres::llave($c['nombre']))->first() ?? new Cuenta(['nombre' => $c['nombre'], 'de' => $c['de'], 'orden' => ++$orden]);
                        $cuenta->tipo = $c['tipo'];
                        $cuenta->activa = true;
                        if ($c['tipo'] === 'credito') {
                            $cuenta->dia_corte = $c['dia_corte'];
                            $cuenta->dia_pago = $c['dia_pago'];
                            $cuenta->limite = $c['limite'];
                            $cuenta->de = $c['de'];
                        }
                        $cuenta->save();
                        SaldoLinea::create([
                            'saldo_corte_id' => $corte->id, 'cuenta_id' => $cuenta->id, 'saldo' => $c['saldo'],
                            'pago_sin_intereses' => $c['pago'], 'fecha_limite' => $c['fecha_limite'],
                        ]);
                    }
                });
                $r['guardado'] = true;
            }
        }

        return $r;
    }

    /** CONTROL: saldos reales (débito/efectivo/cajitas) y tarjetas. */
    private function leerControl(array $filas, array &$cuentas, array &$r): void
    {
        // Encabezado de tarjetas: renglón con "TARJETA" en B y "SALDO HOY" en alguna columna.
        $enc = null;
        foreach ($filas as $i => $f) {
            $textos = array_map(fn ($c) => self::norm($c), $f);
            if (($textos[1] ?? '') === 'TARJETA' && collect($textos)->contains(fn ($t) => str_contains($t, 'SALDO HOY'))) {
                $enc = $i;
                break;
            }
        }

        // Saldos reales: desde el título "SALDOS REALES" hasta el encabezado de tarjetas.
        $ini = null;
        foreach ($filas as $i => $f) {
            if (str_contains(self::norm($f[1] ?? ''), 'SALDOS REALES')) {
                $ini = $i;
                break;
            }
        }
        if ($ini === null) {
            $r['avisos'][] = 'CONTROL: no encontré el bloque "SALDOS REALES"; no se importan sus saldos de débito.';
        } else {
            $bloque = [];
            $liquidez = null;
            for ($i = $ini + 1; $i < count($filas) && $i !== $enc; $i++) {
                $nombre = trim((string) ($filas[$i][1] ?? ''));
                $n = self::norm($nombre);
                if ($nombre === '' || preg_match('/^\d\)/', $n)) {
                    if ($bloque) {
                        break;
                    }

                    continue;
                }
                $valor = $filas[$i][2] ?? null;
                if (str_starts_with($n, 'LIQUIDEZ')) {
                    $liquidez = is_numeric($valor) ? (float) $valor : null;

                    continue;
                }
                if (str_starts_with($n, 'RESERVAS') || str_starts_with($n, 'TOTAL')) {
                    continue;
                }
                if (! is_numeric($valor)) {
                    $r['avisos'][] = 'CONTROL renglón '.($i + 1).": \"{$nombre}\" no trae un saldo que se pueda leer; no se importa.";

                    continue;
                }
                $nombre = trim(preg_replace('/\s*\(MASUME\)\s*/iu', ' ', $nombre));
                $tipo = str_starts_with($n, 'CAJITA') ? 'apartado' : (str_contains($n, 'EFECTIVO') ? 'efectivo' : 'debito');
                $bloque[] = ['nombre' => $nombre, 'tipo' => $tipo, 'saldo' => (float) $valor, 'de' => stripos($filas[$i][1], 'MASUME') !== false ? 'masume' : 'personal', 'fila' => $i + 1];
            }
            $suma = collect($bloque)->where('tipo', '!=', 'apartado')->sum('saldo');
            if ($liquidez !== null && abs($suma - $liquidez) > 1) {
                $r['avisos'][] = 'CONTROL: la suma de los saldos de débito ($'.$this->f($suma).') no coincide con "LIQUIDEZ OPERATIVA" ($'.$this->f($liquidez).'); no se importa ese bloque.';
            } else {
                foreach ($bloque as $k => $b) {
                    $this->agregar($cuentas, $b + ['orden' => $k, 'grupo' => 1, 'origen' => 'CONTROL']);
                }
            }
        }

        if ($enc === null) {
            $r['avisos'][] = 'CONTROL: no encontré el encabezado de tarjetas (TARJETA … SALDO HOY); no se importan las tarjetas.';

            return;
        }
        $col = [];
        foreach ($filas[$enc] as $j => $c) {
            $t = self::norm($c);
            foreach (['mundo' => 'MUNDO', 'corte' => 'CORTE', 'pago' => 'LIMITE PAGO', 'linea' => 'LINEA', 'saldo' => 'SALDO HOY', 'sin_int' => 'PAGO S/INT', 'fecha' => 'FECHA LIMITE'] as $k => $texto) {
                if (! isset($col[$k]) && str_contains($t, $texto)) {
                    $col[$k] = $j;
                }
            }
        }
        $faltan = array_diff(['mundo', 'corte', 'pago', 'linea', 'saldo'], array_keys($col));
        if ($faltan) {
            $r['avisos'][] = 'CONTROL: al encabezado de tarjetas le faltan columnas ('.implode(', ', $faltan).'); no se importan las tarjetas.';

            return;
        }
        $tarjetas = [];
        $total = null;
        for ($i = $enc + 1; $i < count($filas); $i++) {
            $nombre = trim((string) ($filas[$i][1] ?? ''));
            if ($nombre === '') {
                break;
            }
            if (str_starts_with(self::norm($nombre), 'TOTAL')) {
                $total = is_numeric($filas[$i][$col['saldo']] ?? null) ? (float) $filas[$i][$col['saldo']] : null;
                break;
            }
            $f = $filas[$i];
            $corte = self::dia($f[$col['corte']] ?? null);
            $pago = self::dia($f[$col['pago']] ?? null);
            $saldo = $f[$col['saldo']] ?? null;
            $linea = $f[$col['linea']] ?? null;
            if (! is_numeric($saldo) || ! is_numeric($linea) || $corte === null || $pago === null) {
                $r['avisos'][] = 'CONTROL renglón '.($i + 1).": la tarjeta \"{$nombre}\" tiene corte, pago, línea o saldo que no se pueden leer; no se importa.";

                continue;
            }
            $mundo = self::norm($f[$col['mundo']] ?? '');
            $sinInt = isset($col['sin_int']) && is_numeric($f[$col['sin_int']] ?? null) ? (float) $f[$col['sin_int']] : null;
            $fechaLim = isset($col['fecha']) && is_numeric($f[$col['fecha']] ?? null) ? LectorXlsx::fecha($f[$col['fecha']]) : null;
            $tarjetas[] = [
                'nombre' => $nombre, 'tipo' => 'credito', 'de' => $mundo === 'PERSONAL' ? 'personal' : 'masume', 'saldo' => (float) $saldo,
                'dia_corte' => $corte, 'dia_pago' => $pago, 'limite' => (float) $linea, 'pago' => $sinInt,
                'fecha_limite' => $fechaLim?->format('Y-m-d'), 'orden' => count($tarjetas), 'grupo' => 2, 'origen' => 'CONTROL',
            ];
        }
        $suma = collect($tarjetas)->sum('saldo');
        if ($total !== null && abs($suma - $total) > 1) {
            $r['avisos'][] = 'CONTROL: la suma de las tarjetas ($'.$this->f($suma).') no coincide con "TOTALES" ($'.$this->f($total).'); no se importan las tarjetas.';

            return;
        }
        foreach ($tarjetas as $t) {
            $this->agregar($cuentas, $t);
        }
    }

    /** SALDOS: cuentas de débito, tarjetas (solo si CONTROL no las trae), créditos fijos y el préstamo a Jorge. */
    private function leerSaldos(array $filas, array &$cuentas, array &$r): void
    {
        // Préstamo: "Jorge Ríos: $320,000 (abonado: $180,000)".
        $jorge = false;
        foreach ($filas as $i => $f) {
            $texto = (string) ($f[1] ?? '');
            if (preg_match('/^\s*([^:|]+?)\s*:\s*\$?\s*([\d,]+(?:\.\d+)?)\s*\(\s*abonado\s*:\s*\$?\s*([\d,]+(?:\.\d+)?)\s*\)/iu', $texto, $m)) {
                $prestado = (float) str_replace(',', '', $m[2]);
                $abonado = (float) str_replace(',', '', $m[3]);
                $this->agregar($cuentas, [
                    'nombre' => 'PRÉSTAMO '.mb_strtoupper(trim($m[1])), 'tipo' => 'por_cobrar', 'de' => 'personal',
                    'saldo' => max(0.0, $prestado - $abonado), 'orden' => 0, 'grupo' => 4, 'origen' => 'SALDOS',
                ]);
                $jorge = true;
                break;
            }
        }
        if (! $jorge) {
            $r['avisos'][] = 'SALDOS: no pude leer el préstamo ("Nombre: $total (abonado: $abonado)"); no se importa. Agrégalo a mano en Cuentas si lo necesitas.';
        }

        $bloque = function (callable $esEncabezado, callable $armar, string $nombreBloque) use ($filas, &$cuentas, &$r) {
            $enc = null;
            foreach ($filas as $i => $f) {
                if ($esEncabezado(array_map(fn ($c) => self::norm($c), $f))) {
                    $enc = $i;
                    break;
                }
            }
            if ($enc === null) {
                $r['avisos'][] = "SALDOS: no encontré el bloque de {$nombreBloque}; no se importa.";

                return;
            }
            $items = [];
            $total = null;
            for ($i = $enc + 1; $i < count($filas); $i++) {
                $nombre = trim((string) ($filas[$i][1] ?? ''));
                if ($nombre === '') {
                    break;
                }
                if (str_starts_with(self::norm($nombre), 'TOTAL')) {
                    $total = is_numeric($filas[$i][2] ?? null) ? (float) $filas[$i][2] : null;
                    break;
                }
                if (! is_numeric($filas[$i][2] ?? null)) {
                    $r['avisos'][] = 'SALDOS renglón '.($i + 1).": \"{$nombre}\" no trae un saldo que se pueda leer; no se importa.";

                    continue;
                }
                $items[] = $armar($nombre, (float) $filas[$i][2], $filas[$i], count($items));
            }
            $suma = collect($items)->sum('saldo');
            if ($total !== null && abs($suma - $total) > 1) {
                $r['avisos'][] = "SALDOS: la suma de {$nombreBloque} (\${$this->f($suma)}) no coincide con su total (\${$this->f($total)}); no se importa ese bloque.";

                return;
            }
            foreach ($items as $it) {
                $this->agregar($cuentas, $it);
            }
        };

        $bloque(fn ($t) => ($t[1] ?? '') === 'CUENTA' && str_contains($t[2] ?? '', 'SALDO ACTUAL'), function ($nombre, $saldo, $f, $k) {
            $n = self::norm($nombre);

            return ['nombre' => $nombre, 'tipo' => preg_match('/GBM|S&P|INVERS/', $n) ? 'inversion' : (str_contains($n, 'EFECTIVO') ? 'efectivo' : 'debito'),
                'de' => 'personal', 'saldo' => $saldo, 'orden' => 50 + $k, 'grupo' => 1, 'origen' => 'SALDOS'];
        }, 'cuentas de débito');

        $bloque(fn ($t) => ($t[1] ?? '') === 'CREDITO' && ($t[2] ?? '') === 'SALDO', fn ($nombre, $saldo, $f, $k) => [
            'nombre' => $nombre, 'tipo' => 'prestamo', 'de' => 'masume', 'saldo' => $saldo, 'orden' => $k, 'grupo' => 3, 'origen' => 'SALDOS',
        ], 'créditos fijos');

        // Tarjetas de SALDOS: sirven para detectar saldos distintos; solo se importan si CONTROL no trae la tarjeta (sin corte ni límite).
        $enc = null;
        foreach ($filas as $i => $f) {
            if (self::norm($f[1] ?? '') === 'TARJETA' && self::norm($f[2] ?? '') === 'DEUDA') {
                $enc = $i;
                break;
            }
        }
        for ($i = ($enc ?? count($filas)) + 1; $i < count($filas); $i++) {
            $nombre = trim((string) ($filas[$i][1] ?? ''));
            if ($nombre === '' || str_starts_with(self::norm($nombre), 'TOTAL')) {
                break;
            }
            if (is_numeric($filas[$i][2] ?? null)) {
                $this->agregar($cuentas, [
                    'nombre' => $nombre, 'tipo' => 'credito', 'de' => 'masume', 'saldo' => (float) $filas[$i][2], 'dia_corte' => null,
                    'dia_pago' => self::dia($filas[$i][4] ?? null), 'limite' => null, 'pago' => null, 'fecha_limite' => null,
                    'orden' => 99, 'grupo' => 2, 'origen' => 'SALDOS',
                ]);
            }
        }
    }

    private function agregar(array &$cuentas, array $c): void
    {
        $llave = Nombres::llave($c['nombre']);
        $c['nombre'] = Nombres::limpiar($c['nombre']);
        $cuentas[$llave] = $c + ['pago' => null, 'fecha_limite' => null, 'dia_corte' => null, 'dia_pago' => null, 'limite' => null];
    }

    private function f(float $n): string
    {
        return number_format($n, 2);
    }

    private static function hojaDe(LectorXlsx $lector, string $nombre): ?string
    {
        foreach ($lector->hojas() as $h) {
            if (mb_strtoupper(trim($h)) === $nombre) {
                return $h;
            }
        }

        return null;
    }

    /** Texto sin acentos, en mayúsculas y con espacios simples. */
    private static function norm(mixed $v): string
    {
        return mb_strtoupper(Str::squish(Str::ascii((string) $v)));
    }

    /** "Día 9" → 9 (null si no es un día del 1 al 31). */
    private static function dia(mixed $v): ?int
    {
        if (is_numeric($v)) {
            $d = (int) $v;
        } elseif (preg_match('/(\d{1,2})/', (string) $v, $m)) {
            $d = (int) $m[1];
        } else {
            return null;
        }

        return $d >= 1 && $d <= 31 ? $d : null;
    }
}

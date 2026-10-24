<?php

namespace App\Services;

use App\Support\Precios;
use Illuminate\Support\Str;

/**
 * Concilia los renglones que la IA leyó de la OC del cliente contra las partidas del pedido.
 * PHP puro y determinista: la IA solo extrae; aquí se empareja y se comparan cantidades, unidades y precios.
 *
 * Emparejamiento: (1) modelo normalizado idéntico; (2) similitud de descripción (Jaccard de palabras)
 * con umbral 0.5 (entre 0.3 y 0.5 se empareja pero queda como `dudoso`). Asignación voraz por mejor
 * puntaje: una partida por renglón y un renglón por partida.
 */
class ConciliadorOc
{
    public const UMBRAL_COINCIDE = 0.5;

    public const UMBRAL_DUDOSO = 0.3;

    /** Orden en que se elige la etiqueta principal de una fila con varias diferencias. */
    public const PRIORIDAD = [
        'falta_en_pedido', 'sobra_en_pedido', 'cantidad_distinta', 'modelo_distinto',
        'unidad_distinta', 'precio_distinto', 'dudoso', 'confianza_baja',
    ];

    private const STOPWORDS = ['de', 'del', 'la', 'el', 'los', 'las', 'para', 'con', 'sin', 'y', 'o', 'e', 'en', 'a', 'al', 'por', 'un', 'una', 'que', 'se'];

    private const UNIDADES = [
        'PZA' => ['PZA', 'PZ', 'PIEZA', 'PIEZAS', 'PZAS'],
        'CJA' => ['CJA', 'CAJA', 'CAJAS', 'CJ'],
        'PAQ' => ['PAQ', 'PAQUETE', 'PAQUETES', 'PQT'],
        'KG' => ['KG', 'KGS', 'KILO', 'KILOS', 'KILOGRAMO', 'KILOGRAMOS'],
        'M' => ['M', 'MT', 'MTS', 'METRO', 'METROS'],
        'LT' => ['LT', 'LTS', 'L', 'LITRO', 'LITROS'],
        'JGO' => ['JGO', 'JUEGO', 'JUEGOS', 'JG'],
        'ROLLO' => ['ROLLO', 'ROLLOS', 'RLL', 'RL'],
    ];

    private const ETIQUETAS_RESUMEN = [
        'coincide' => ['coincide', 'coinciden'],
        'cantidad_distinta' => ['cantidad distinta', 'cantidades distintas'],
        'modelo_distinto' => ['modelo distinto', 'modelos distintos'],
        'unidad_distinta' => ['unidad distinta', 'unidades distintas'],
        'precio_distinto' => ['precio distinto', 'precios distintos'],
        'falta_en_pedido' => ['falta en el pedido', 'faltan en el pedido'],
        'sobra_en_pedido' => ['sobra en el pedido', 'sobran en el pedido'],
        'dudoso' => ['dudoso', 'dudosos'],
        'confianza_baja' => ['de lectura dudosa', 'de lectura dudosa'],
    ];

    /**
     * @param  array  $extraccion  JSON que devolvió la IA (esquema oc_cliente_v1)
     * @param  iterable  $partidas  partidas del pedido (modelos o arrays con id, descripcion, modelo, cantidad, unidad, precio)
     * @param  array  $resoluciones  clave de fila => ['motivo', 'usuario', 'fecha', ...] (filas ignoradas por el usuario)
     * @return array{filas: list<array>, advertencias: list<array{nivel: string, texto: string}>, resumen: string, hay_diferencias: bool, conteos: array<string, int>, incluyen_iva: bool}
     */
    public function conciliar(array $extraccion, iterable $partidas, ?string $numeroOcCapturado = null, array $resoluciones = []): array
    {
        $renglones = array_values(array_filter((array) ($extraccion['renglones'] ?? []), 'is_array'));
        $partidas = $this->normalizarPartidas($partidas);
        $incluyenIva = ($extraccion['precios_incluyen_iva'] ?? null) === true;

        // Parejas que decidió una persona ("es la misma"): mandan sobre lo automático. renglón => id de partida.
        $aMano = [];
        $indicePorId = [];
        foreach ($partidas as $j => $p) {
            $indicePorId[(int) $p['id']] = $j;
        }
        foreach ((array) ($resoluciones['_parejas'] ?? []) as $i => $par) {
            $j = $indicePorId[(int) ($par['partida_id'] ?? 0)] ?? null;
            if ($j !== null && isset($renglones[(int) $i]) && ! in_array($j, array_column($aMano, 0), true)) {
                $aMano[(int) $i] = [$j, 'manual', 1.0];
            }
        }
        $restoR = array_diff_key($renglones, $aMano);
        $restoP = array_diff_key($partidas, array_flip(array_column($aMano, 0)));
        $pares = $aMano + $this->emparejar($restoR, $restoP, $incluyenIva);   // índice de renglón => [índice de partida, método, puntaje]

        $filas = [];
        $usadas = [];
        foreach ($renglones as $i => $r) {
            $par = $pares[$i] ?? null;
            $p = $par ? $partidas[$par[0]] : null;
            if ($par) {
                $usadas[$par[0]] = true;
            }
            $filas[] = $this->fila('r'.$i, $r, $p, $par[1] ?? null, $par[2] ?? 0.0, $incluyenIva);
        }
        foreach ($partidas as $j => $p) {
            if (! isset($usadas[$j])) {
                $filas[] = $this->fila('p'.$p['id'], null, $p, null, 0.0, $incluyenIva);
            }
        }

        $hay = false;
        $conteos = array_fill_keys(array_keys(self::ETIQUETAS_RESUMEN), 0);
        $conteos['ignoradas'] = 0;
        foreach ($filas as &$f) {
            $res = $resoluciones[$f['clave']] ?? null;
            $f['resuelta'] = $f['estado'] !== 'coincide' && is_array($res);
            $f['resolucion'] = $f['resuelta'] ? $res : null;
            if ($f['resuelta']) {
                $conteos['ignoradas']++;
            } else {
                $conteos[$f['estado']]++;
                $hay = $hay || $f['estado'] !== 'coincide';
            }
        }
        unset($f);

        return [
            'filas' => $filas,
            'advertencias' => $this->advertencias($extraccion, $renglones, $numeroOcCapturado, $incluyenIva),
            'resumen' => $this->resumen($conteos, count($renglones)),
            'hay_diferencias' => $hay,
            'conteos' => $conteos,
            'incluyen_iva' => $incluyenIva,
            'parejas' => (array) ($resoluciones['_parejas'] ?? []),
        ];
    }

    // ------------------------------------------------------------------ normalización

    /** Mayúsculas sin espacios, guiones, puntos ni símbolos: "MR16-LED/5.5W" y "MR16 LED 5.5W" son iguales. */
    public static function normalizarModelo(?string $modelo): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(Str::ascii((string) $modelo))) ?? '';
    }

    /**
     * Palabras de una descripción: minúsculas, sin acentos ni palabras vacías; conserva números y
     * medidas (5.5w, 1/2, 3000k).
     *
     * @return list<string>
     */
    public static function tokens(?string $texto): array
    {
        $t = strtolower(Str::ascii((string) $texto));
        $t = preg_replace('/[^a-z0-9.\/]+/', ' ', $t);
        $t = preg_replace('/(?<!\d)\.|\.(?!\d)/', ' ', $t);   // el punto solo vale entre dígitos
        $t = preg_replace('/(?<!\d)\/|\/(?!\d)/', ' ', $t);   // la diagonal solo vale entre dígitos (1/2)
        $salida = [];
        foreach (preg_split('/\s+/', trim((string) $t), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            if (in_array($w, self::STOPWORDS, true)) {
                continue;
            }
            if (strlen($w) === 1 && ! ctype_digit($w)) {
                continue;
            }
            $salida[$w] = $w;
        }

        return array_values($salida);
    }

    public static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $inter / $union : 0.0;
    }

    /** Forma canónica de una unidad (PZA/PZ/PIEZA → PZA). Las desconocidas quedan en mayúsculas sin símbolos. */
    public static function unidadCanonica(?string $unidad): string
    {
        $u = preg_replace('/[^A-Z0-9]/', '', strtoupper(Str::ascii((string) $unidad))) ?? '';
        foreach (self::UNIDADES as $canon => $sinonimos) {
            if (in_array($u, $sinonimos, true)) {
                return $canon;
            }
        }

        return $u;
    }

    /** Número de OC comparable: sin prefijo "OC/PO", sin símbolos, mayúsculas y sin ceros a la izquierda. */
    public static function normalizarNumeroOc(?string $numero): string
    {
        $n = strtoupper(Str::ascii(trim((string) $numero)));
        $n = preg_replace('/^(ORDEN\s+DE\s+COMPRA|O\.C\.|OC|PO)\s*[:#.\-]?\s*/', '', $n);
        $n = preg_replace('/[^A-Z0-9]/', '', (string) $n);

        return ltrim((string) $n, '0');
    }

    // ------------------------------------------------------------------ emparejamiento

    /** @return list<array{id: int, descripcion: string, modelo: ?string, cantidad: float, unidad: ?string, precio: ?float}> */
    private function normalizarPartidas(iterable $partidas): array
    {
        $salida = [];
        foreach ($partidas as $p) {
            $g = fn (string $k) => is_array($p) ? ($p[$k] ?? null) : ($p->$k ?? null);
            $salida[] = [
                'id' => (int) $g('id'),
                'descripcion' => (string) $g('descripcion'),
                'modelo' => filled($g('modelo')) ? (string) $g('modelo') : null,
                'cantidad' => (float) $g('cantidad'),
                'unidad' => filled($g('unidad')) ? (string) $g('unidad') : null,
                'precio' => $g('precio') !== null && $g('precio') !== '' ? (float) $g('precio') : null,
            ];
        }

        return $salida;
    }

    /** Contención: qué parte de la descripción más corta viene en la otra ("ESTOPA" ⊂ "BOLSA … DE ESTOPA …" = 1). */
    public static function contencion(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / min(count($a), count($b));
    }

    private static function mismaCantidad(array $r, array $p): bool
    {
        return abs((float) ($r['cantidad'] ?? 0) - $p['cantidad']) < 0.0005;
    }

    /** Precio de la OC igual al del pedido (con IVA si la OC lo trae incluido), con tolerancia de 1% o un centavo (redondeo): a centavos se nota una pija de $0.28 contra $0.21. */
    private static function mismoPrecio(array $r, array $p, bool $incluyenIva): bool
    {
        $precioR = $r['precio_unitario'] ?? null;
        if ($precioR === null || $p['precio'] === null) {
            return false;
        }
        $esperado = $incluyenIva ? $p['precio'] * (1 + Precios::iva()) : $p['precio'];

        return abs((float) $precioR - $esperado) <= max(0.01 * $esperado, 0.011) + 1e-9;
    }

    /** @return array<int, array{0: int, 1: string, 2: float}> */
    private function emparejar(array $renglones, array $partidas, bool $incluyenIva = false): array
    {
        $tokP = array_map(fn ($p) => self::tokens($p['descripcion']), $partidas);
        $modP = array_map(fn ($p) => self::normalizarModelo($p['modelo']), $partidas);

        $candidatos = [];
        foreach ($renglones as $i => $r) {
            $tokR = self::tokens($r['descripcion'] ?? '');
            $modR = self::normalizarModelo($r['modelo'] ?? null);
            foreach ($partidas as $j => $p) {
                $desc = self::jaccard($tokR, $tokP[$j]);
                if ($modR !== '' && $modR === $modP[$j]) {
                    // Modelo idéntico: gana siempre a la similitud; a igualdad, la de misma cantidad.
                    $misma = abs((float) ($r['cantidad'] ?? 0) - $p['cantidad']) < 0.0005 ? 1 : 0;
                    $candidatos[] = [10 + $misma + $desc, $i, $j, 'modelo', $desc];
                } elseif ($desc >= self::UMBRAL_DUDOSO) {
                    $candidatos[] = [$desc, $i, $j, 'descripcion', $desc];
                }
            }
        }
        usort($candidatos, fn ($a, $b) => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);

        $pares = [];
        $partidaUsada = [];
        foreach ($candidatos as [$score, $i, $j, $metodo, $desc]) {
            if (isset($pares[$i]) || isset($partidaUsada[$j])) {
                continue;
            }
            $pares[$i] = [$j, $metodo, round($desc, 3)];
            $partidaUsada[$j] = true;
        }

        // Segunda vuelta para lo que quedó suelto: el cliente suele escribir corto ("Estopa") lo que nosotros
        // cotizamos largo ("BOLSA DE 1 KG DE ESTOPA COLOR BLANCA…"). Se empareja si la descripción corta viene
        // dentro de la otra y coinciden cantidad o precio; o, sin palabras en común, si coinciden cantidad Y precio.
        $candidatos = [];
        foreach ($renglones as $i => $r) {
            if (isset($pares[$i])) {
                continue;
            }
            $tokR = self::tokens($r['descripcion'] ?? '');
            foreach ($partidas as $j => $p) {
                if (isset($partidaUsada[$j])) {
                    continue;
                }
                // Modelos distintos en los dos lados: solo se proponen como duda si cuadran cantidad Y precio (el cliente
                // usa su propio código: OCR20-00W de Leviton contra Cr20). Si un modelo contiene al otro, no chocan.
                $modR = self::normalizarModelo($r['modelo'] ?? null);
                $chocan = $modR !== '' && $modP[$j] !== '' && $modR !== $modP[$j]
                    && ! (min(strlen($modR), strlen($modP[$j])) >= 3 && (str_contains($modR, $modP[$j]) || str_contains($modP[$j], $modR)));
                $cont = self::contencion($tokR, $tokP[$j]);
                $cant = self::mismaCantidad($r, $p);
                $precio = self::mismoPrecio($r, $p, $incluyenIva);
                if ($chocan) {
                    if ($cant && $precio) {
                        $candidatos[] = [0.4, $i, $j, 'cantidad_precio', self::jaccard($tokR, $tokP[$j])];
                    }
                } elseif ($cont >= 0.5 && ($cant || $precio)) {
                    $candidatos[] = [$cont + ($cant ? 1 : 0) + ($precio ? 1 : 0), $i, $j, 'contenido', self::jaccard($tokR, $tokP[$j])];
                } elseif ($cant && $precio) {
                    $candidatos[] = [0.5, $i, $j, 'cantidad_precio', self::jaccard($tokR, $tokP[$j])];
                }
            }
        }
        usort($candidatos, fn ($a, $b) => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);
        foreach ($candidatos as [$score, $i, $j, $metodo, $desc]) {
            if (isset($pares[$i]) || isset($partidaUsada[$j])) {
                continue;
            }
            $pares[$i] = [$j, $metodo, round($desc, 3)];
            $partidaUsada[$j] = true;
        }

        return $pares;
    }

    // ------------------------------------------------------------------ comparación de una fila

    private function fila(string $clave, ?array $r, ?array $p, ?string $metodo, float $puntaje, bool $incluyenIva): array
    {
        $dif = [];
        $det = [];

        if ($r && ! $p) {
            $dif[] = 'falta_en_pedido';
            $det['falta_en_pedido'] = 'La OC pide '.self::num($r['cantidad'] ?? 0).' '.($r['unidad'] ?? '').' y no hay partida equivalente en el pedido.';
        } elseif ($p && ! $r) {
            $dif[] = 'sobra_en_pedido';
            $det['sobra_en_pedido'] = 'El pedido tiene esta partida y el cliente no la pidió en la OC.';
        } else {
            $cantR = (float) ($r['cantidad'] ?? 0);
            if (abs($cantR - $p['cantidad']) > 0.0005) {
                $dif[] = 'cantidad_distinta';
                $det['cantidad_distinta'] = 'OC '.self::num($cantR).' · Pedido '.self::num($p['cantidad']);
            }

            $modR = self::normalizarModelo($r['modelo'] ?? null);
            $modP = self::normalizarModelo($p['modelo']);
            // Si una persona dijo que es la misma, el código distinto del cliente no es diferencia.
            if ($metodo !== 'manual' && $modR !== '' && $modP !== '' && $modR !== $modP) {
                $dif[] = 'modelo_distinto';
                $det['modelo_distinto'] = 'OC "'.$r['modelo'].'" · Pedido "'.$p['modelo'].'"';
            }

            // Misma cantidad y mismo precio: la unidad es solo cómo la nombra cada quien (5 "Pieza" = 5 "BOLSA").
            $mismoTodo = self::mismaCantidad($r, $p) && self::mismoPrecio($r, $p, $incluyenIva);
            $uR = self::unidadCanonica($r['unidad'] ?? null);
            $uP = self::unidadCanonica($p['unidad']);
            if ($uR !== '' && $uP !== '' && $uR !== $uP && ! $mismoTodo) {
                $dif[] = 'unidad_distinta';
                $det['unidad_distinta'] = 'OC '.$r['unidad'].' · Pedido '.$p['unidad'];
            }

            $precioR = $r['precio_unitario'] ?? null;
            if ($precioR !== null && $p['precio'] !== null) {
                $esperado = $incluyenIva ? $p['precio'] * (1 + Precios::iva()) : $p['precio'];
                $tolerancia = max(0.01 * $esperado, 0.011);
                if (abs((float) $precioR - $esperado) > $tolerancia + 1e-9) {
                    $dif[] = 'precio_distinto';
                    $det['precio_distinto'] = 'OC $'.number_format((float) $precioR, 2).($incluyenIva ? ' (con IVA)' : '')
                        .' · Pedido $'.number_format($esperado, 2).($incluyenIva ? ' (con IVA)' : '');
                }
            }

            if ($metodo === 'contenido' && ! $mismoTodo) {
                $dif[] = 'dudoso';
                $det['dudoso'] = 'La OC lo describe más corto ("'.($r['descripcion'] ?? '').'"); se emparejó porque viene dentro de la descripción del pedido. Revisa que sea lo mismo.';
            } elseif ($metodo === 'cantidad_precio') {
                $dif[] = 'dudoso';
                $det['dudoso'] = 'Las descripciones o los modelos no se parecen, pero tienen la misma cantidad y el mismo precio. Revisa que sea lo mismo.';
            } elseif ($metodo === 'manual') {
                $det['manual'] = 'Emparejada a mano.';
            }

            // Todo lo que dice la OC viene en nuestra descripción y cuadran cantidad y precio: no hay duda.
            $contenidaYCuadra = $mismoTodo && self::contencion(self::tokens($r['descripcion'] ?? ''), self::tokens($p['descripcion'])) >= 1.0;
            if ($metodo === 'descripcion' && ! $contenidaYCuadra) {
                $nums = self::diferenciaNumerica($r['descripcion'] ?? '', $p['descripcion']);
                if ($puntaje < self::UMBRAL_COINCIDE) {
                    $dif[] = 'dudoso';
                    $det['dudoso'] = 'Se parecen solo un '.round($puntaje * 100).'% en la descripción.';
                } elseif ($nums !== null) {
                    $dif[] = 'dudoso';
                    $det['dudoso'] = 'Emparejadas por descripción, pero difieren medidas o números: '.$nums.'.';
                }
            }
        }

        if ($r && ($r['confianza'] ?? 'alta') === 'baja') {
            $dif[] = 'confianza_baja';
            $det['confianza_baja'] = 'La IA marcó este renglón como poco legible: revísalo contra el archivo.';
        }

        $estado = 'coincide';
        foreach (self::PRIORIDAD as $e) {
            if (in_array($e, $dif, true)) {
                $estado = $e;
                break;
            }
        }
        usort($dif, fn ($a, $b) => array_search($a, self::PRIORIDAD, true) <=> array_search($b, self::PRIORIDAD, true));

        return [
            'clave' => $clave,
            'estado' => $estado,
            'diferencias' => $dif,
            'detalles' => $det,
            'metodo' => $p && $r ? $metodo : null,
            'puntaje' => $puntaje,
            'renglon' => $r ? [
                'num_renglon' => $r['num_renglon'] ?? null,
                'codigo_cliente' => $r['codigo_cliente'] ?? null,
                'descripcion' => (string) ($r['descripcion'] ?? ''),
                'marca' => $r['marca'] ?? null,
                'modelo' => $r['modelo'] ?? null,
                'cantidad' => (float) ($r['cantidad'] ?? 0),
                'unidad' => $r['unidad'] ?? null,
                'precio_unitario' => $r['precio_unitario'] ?? null,
                'importe' => $r['importe'] ?? null,
                'origen' => $r['origen'] ?? 'tabla',
                'confianza' => $r['confianza'] ?? 'alta',
                'texto_original' => (string) ($r['texto_original'] ?? ''),
            ] : null,
            'partida' => $p,
            'resuelta' => false,
            'resolucion' => null,
        ];
    }

    /** Números/medidas que aparecen en una descripción y no en la otra, o null si son los mismos. */
    private static function diferenciaNumerica(string $a, string $b): ?string
    {
        $num = fn (array $t) => array_values(array_filter($t, fn ($w) => preg_match('/\d/', $w) === 1));
        $na = $num(self::tokens($a));
        $nb = $num(self::tokens($b));
        $solo = array_merge(array_diff($na, $nb), array_diff($nb, $na));

        return $solo === [] ? null : implode(', ', $solo);
    }

    // ------------------------------------------------------------------ encabezado y resumen

    /** @return list<array{nivel: string, texto: string}> */
    private function advertencias(array $ext, array $renglones, ?string $numeroCapturado, bool $incluyenIva): array
    {
        $a = [];

        if (filled($numeroCapturado)) {
            $leido = $ext['numero_oc'] ?? null;
            if (blank($leido)) {
                $a[] = ['nivel' => 'medio', 'texto' => 'No se pudo leer el número de OC en el archivo; compáralo a ojo con el capturado ('.$numeroCapturado.').'];
            } elseif (self::normalizarNumeroOc($leido) !== self::normalizarNumeroOc($numeroCapturado)) {
                $a[] = ['nivel' => 'alto', 'texto' => 'El número de OC del archivo ('.$leido.') no coincide con el capturado en el pedido ('.$numeroCapturado.'). ¿Es el archivo correcto?'];
            }
        }

        if ($renglones !== []) {
            $suma = 0.0;
            $completo = true;
            $deObservaciones = false;
            foreach ($renglones as $r) {
                if (($r['importe'] ?? null) !== null) {
                    $suma += (float) $r['importe'];
                } elseif (($r['precio_unitario'] ?? null) !== null && ($r['cantidad'] ?? null) !== null) {
                    $suma += (float) $r['precio_unitario'] * (float) $r['cantidad'];
                } elseif (($r['origen'] ?? 'tabla') === 'observaciones') {
                    $deObservaciones = true;   // productos de las observaciones: la OC no los desglosa en precio
                } else {
                    $completo = false;
                }
            }
            $subtotal = $ext['subtotal'] ?? null;
            $total = $ext['total'] ?? null;
            if ($subtotal === null && $total !== null && ($ext['iva'] ?? null) !== null) {
                $subtotal = (float) $total - (float) $ext['iva'];
            }
            $referencias = array_values(array_filter([$subtotal, $incluyenIva ? $total : null], fn ($v) => $v !== null));
            if ($completo && $referencias !== []) {
                $cuadra = false;
                foreach ($referencias as $ref) {
                    if (abs($suma - (float) $ref) <= max(1.0, 0.005 * abs((float) $ref))) {
                        $cuadra = true;
                    }
                }
                if (! $cuadra && $deObservaciones) {
                    $a[] = ['nivel' => 'medio', 'texto' => 'La suma de los importes leídos ($'.number_format($suma, 2).') no cuadra con el subtotal de la OC ($'
                        .number_format((float) $referencias[0], 2).'), pero parte del detalle viene de las observaciones y no trae precio: es normal.'];
                } elseif (! $cuadra) {
                    $a[] = ['nivel' => 'alto', 'texto' => 'La suma de los importes leídos ($'.number_format($suma, 2).') no cuadra con el subtotal de la OC ($'
                        .number_format((float) $referencias[0], 2).'). Puede faltar un renglón o haberse leído mal una cantidad.'];
                }
            }
        }

        foreach ((array) ($ext['advertencias'] ?? []) as $texto) {
            if (is_string($texto) && trim($texto) !== '') {
                $a[] = ['nivel' => 'medio', 'texto' => 'La IA avisó: '.trim($texto)];
            }
        }

        return $a;
    }

    private function resumen(array $conteos, int $nRenglones): string
    {
        if ($nRenglones === 0) {
            return 'No se leyó ningún renglón de la OC.';
        }
        $partes = [];
        foreach (self::ETIQUETAS_RESUMEN as $estado => [$uno, $varios]) {
            $n = $conteos[$estado] ?? 0;
            if ($n > 0) {
                $partes[] = $n.' '.($n === 1 ? $uno : $varios);
            }
        }
        if (($conteos['ignoradas'] ?? 0) > 0) {
            $n = $conteos['ignoradas'];
            $partes[] = $n.' '.($n === 1 ? 'ignorada' : 'ignoradas').' con motivo';
        }

        return $partes === [] ? 'Sin renglones que revisar.' : ucfirst(implode(', ', $partes));
    }

    private static function num(float|int|string|null $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.');
    }
}

<?php

namespace App\Support;

use App\Models\SicarArticulo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Empareja una partida de pedido con el catálogo sincronizado de SICAR (solo artículos activos).
 *
 *  a) la clave normalizada de SICAR es igual al modelo normalizado de la partida  → existe
 *  b) el modelo aparece dentro de la descripción de SICAR (si son varios, el más parecido → posible), o la
 *     clave de SICAR —tipo código, con número— está al inicio de nuestra descripción  → existe
 *  c) descripción parecida por palabras (Jaccard sin acentos ni palabras vacías)   → posible
 *
 * Para los nuevos propone la clave de SICAR; la clave SAT se deja vacía (no se copia de artículos de SICAR).
 * El catálogo se carga y se tokeniza una sola vez por instancia.
 */
class EmparejadorSicar
{
    public const UMBRAL_POSIBLE = 0.6;

    private const MAX_CLAVE = 45;

    private const PALABRAS_VACIAS = ['de', 'del', 'la', 'el', 'los', 'las', 'para', 'con', 'sin', 'y', 'e', 'o', 'en', 'a', 'por', 'un', 'una', 'al', 'x'];

    /** @var Collection<int, SicarArticulo>|null */
    private ?Collection $catalogo = null;

    private bool $clavesCargadas = false;

    /** @var array<int, array<string, true>> art_id → palabras */
    private array $palabras = [];

    /** @var array<string, true> llaves de clave ya usadas (SICAR o propuestas en este lote) */
    private array $clavesUsadas = [];

    /** @var array<string, string> identidad del artículo nuevo → clave propuesta (mismo artículo, misma clave) */
    private array $identidades = [];

    /** @param  Collection<int, SicarArticulo>|null  $catalogo  Solo para pruebas; por omisión, los activos de la base. */
    public function __construct(?Collection $catalogo = null)
    {
        $this->catalogo = $catalogo;
    }

    /** @return Collection<int, SicarArticulo> */
    public function catalogo(): Collection
    {
        if ($this->catalogo === null) {
            $this->catalogo = SicarArticulo::where('status', 1)->get();
        }
        if (! $this->clavesCargadas) {
            $this->clavesCargadas = true;
            // Todas las claves del catálogo (también las inactivas) cuentan como usadas.
            foreach (SicarArticulo::query()->pluck('clave_llave') as $llave) {
                if ($llave) {
                    $this->clavesUsadas[$llave] = true;
                }
            }
            foreach ($this->catalogo as $a) {
                $this->clavesUsadas[$this->llaveDe($a)] = true;
            }
        }

        return $this->catalogo;
    }

    /**
     * @param  array{descripcion?: ?string, modelo?: ?string, unidad?: ?string, costo?: float|string|null}  $partida
     * @return array{estado: string, articulo: ?SicarArticulo, avisos: list<string>, similitud: float}
     */
    /**
     * ¿El modelo viene en la descripción de SICAR como dato propio ("MOD 57052", "CODIGO 57052", "222 00162")? Se compara por
     * palabras: antes se juntaba toda la descripción y el 43713 del pasador (P-176) "aparecía" dentro de
     * "1/2\" 437-130" de una reducción.
     */
    public function modeloEnDescripcion(string $descripcion, string $modelo): bool
    {
        $piezas = array_values(array_filter(array_map(fn ($w) => SicarArticulo::llave($w),
            preg_split('/[\s,;:()\[\]]+/u', $descripcion) ?: []), fn ($l) => $l !== ''));
        foreach ($piezas as $i => $l) {
            if ($l === $modelo || preg_match('/^(MOD|MODELO|COD|CODIGO|CAT|REF|NO|SKU)'.preg_quote($modelo, '/').'$/', $l)) {
                return true;
            }
            // Modelo escrito en dos o tres partes ("222 00162").
            $junto = $l;
            for ($j = $i + 1; $j < min($i + 3, count($piezas)); $j++) {
                $junto .= $piezas[$j];
                if ($junto === $modelo) {
                    return true;
                }
            }
        }

        return false;
    }

    public function emparejar(array $partida): array
    {
        $catalogo = $this->catalogo();
        $descripcion = (string) ($partida['descripcion'] ?? '');
        $modelo = SicarArticulo::llave($partida['modelo'] ?? '');
        $llaveDescripcion = SicarArticulo::llave($descripcion);

        $encontrado = null;
        $estado = 'nuevo';
        $similitud = 0.0;

        // a) clave = modelo
        if (strlen($modelo) >= 2) {
            $encontrado = $catalogo->first(fn ($a) => $this->llaveDe($a) === $modelo)
                ?? $catalogo->first(fn ($a) => $a->clave_alterna && SicarArticulo::llave($a->clave_alterna) === $modelo);
        }
        // b1) el modelo aparece en la descripción de SICAR ("ESCOBA TIPO ABANICO ... MOD 57052"). Va antes que b2: el
        //     modelo es un dato fuerte. Si hay varios artículos con ese modelo, se toma el más parecido y queda para revisar.
        $avisosExtra = [];
        $varios = false;
        if (! $encontrado && strlen($modelo) >= 4) {
            $conModelo = $catalogo->filter(fn ($a) => $this->modeloEnDescripcion((string) $a->descripcion, $modelo))->values();
            if ($conModelo->count() > 1) {
                $mias = $this->tokens($descripcion);
                $encontrado = $conModelo->sortByDesc(function ($a) use ($mias) {
                    $otras = $this->palabras[$a->art_id] ??= $this->tokens((string) $a->descripcion);
                    $inter = count(array_intersect_key($mias, $otras));

                    return $inter / max(1, count($mias) + count($otras) - $inter);
                })->first();
                $varios = true;
                $avisosExtra[] = 'En SICAR hay '.$conModelo->count().' artículos con el modelo '.($partida['modelo'] ?? '').': '
                    .$conModelo->map(fn ($a) => $a->clave)->implode(' / ').'. Se propone el más parecido; confirma cuál es.';
            } else {
                $encontrado = $conModelo->first();
            }
        }
        // b2) la clave de SICAR está al inicio de nuestra descripción. Solo claves tipo código (con algún número):
        //     una clave genérica como "ESCOBA" casaba con cualquier escoba (P-88 eligió "ESCOBA" en vez de la 57052).
        if (! $encontrado && $llaveDescripcion !== '') {
            $encontrado = $catalogo->first(function ($a) use ($llaveDescripcion) {
                $l = $this->llaveDe($a);

                return strlen($l) >= 5 && preg_match('/\d/', $l) && str_starts_with($llaveDescripcion, $l);
            });
        }
        if ($encontrado && ! $varios) {
            $estado = 'existe';
            $similitud = 1.0;
        } elseif ($encontrado) {
            $estado = 'posible';
            $similitud = 0.9;
        } else {
            // c) descripción parecida
            [$mejor, $puntos] = $this->masParecidos($descripcion, 1)[0] ?? [null, 0.0];
            if ($mejor && $puntos >= self::UMBRAL_POSIBLE) {
                $estado = 'posible';
                $encontrado = $mejor;
                $similitud = $puntos;
            }
        }

        return [
            'estado' => $estado,
            'articulo' => $encontrado,
            'avisos' => $encontrado ? array_merge($avisosExtra, $this->avisos($partida, $encontrado)) : [],
            'similitud' => round($similitud, 3),
        ];
    }

    /**
     * Propuesta para un artículo nuevo: clave de SICAR, clave SAT sugerida y los parecidos usados.
     *
     * @param  array{descripcion?: ?string, modelo?: ?string}  $partida
     * @return array{clave: string, clave_sat: string, parecidos: list<SicarArticulo>}
     */
    public function proponer(array $partida): array
    {
        $this->catalogo();
        $descripcion = (string) ($partida['descripcion'] ?? '');
        $parecidos = collect($this->masParecidos($descripcion, 5))->pluck(0)->all();

        // La clave SAT NO se toma de los artículos de SICAR: Daniel dice que tienen errores (29 sep 2026).
        return [
            'clave' => $this->proponerClave($partida),
            'clave_sat' => '',
            'parecidos' => $parecidos,
        ];
    }

    /** Clave nueva: el modelo (si cabe y no choca) o la descripción recortada a 45 sin cortar palabras. */
    public function proponerClave(array $partida): string
    {
        $this->catalogo();
        $modelo = Str::upper(Str::squish((string) ($partida['modelo'] ?? '')));
        $descripcion = Str::upper(Str::squish((string) ($partida['descripcion'] ?? '')));
        $identidad = SicarArticulo::llave($modelo) ?: SicarArticulo::llave($descripcion);
        if ($identidad !== '' && isset($this->identidades[$identidad])) {
            return $this->identidades[$identidad];
        }

        $clave = null;
        if ($modelo !== '' && mb_strlen($modelo) <= self::MAX_CLAVE && ! LinkCompra::esUrl($modelo)
            && ! isset($this->clavesUsadas[SicarArticulo::llave($modelo)])) {
            $clave = $modelo;
        }
        if ($clave === null) {
            $base = $this->recortar($descripcion !== '' ? $descripcion : ($modelo !== '' ? $modelo : 'ARTICULO'), self::MAX_CLAVE);
            $clave = $base;
            $n = 1;
            while (isset($this->clavesUsadas[SicarArticulo::llave($clave)])) {
                $n++;
                $sufijo = ' '.$n;
                $clave = $this->recortar($base, self::MAX_CLAVE - mb_strlen($sufijo)).$sufijo;
            }
        }

        $this->clavesUsadas[SicarArticulo::llave($clave)] = true;
        if ($identidad !== '') {
            $this->identidades[$identidad] = $clave;
        }

        return $clave;
    }

    /** Una clave ya decidida (guardada o escrita a mano): no se vuelve a proponer para otro artículo. */
    public function reservar(string $clave): void
    {
        $this->catalogo();
        $this->clavesUsadas[SicarArticulo::llave($clave)] = true;
    }

    /** Artículo del catálogo (activo) con esa clave, si existe. */
    public function porClave(string $clave): ?SicarArticulo
    {
        $llave = SicarArticulo::llave($clave);

        return $llave === '' ? null : $this->catalogo()->first(fn ($a) => $this->llaveDe($a) === $llave);
    }

    /** Artículo del catálogo por id de SICAR. */
    public function porId(int $artId): ?SicarArticulo
    {
        return $this->catalogo()->firstWhere('art_id', $artId) ?? SicarArticulo::find($artId);
    }

    /**
     * Los N artículos más parecidos por descripción (mayor primero), con puntaje > 0.
     *
     * @return list<array{0: SicarArticulo, 1: float}>
     */
    public function masParecidos(string $descripcion, int $n): array
    {
        $mias = $this->tokens($descripcion);
        if ($mias === []) {
            return [];
        }
        $puntos = [];
        foreach ($this->catalogo() as $a) {
            $otras = $this->palabras[$a->art_id] ??= $this->tokens((string) $a->descripcion);
            if ($otras === []) {
                continue;
            }
            $inter = count(array_intersect_key($mias, $otras));
            if ($inter === 0) {
                continue;
            }
            $puntos[] = [$a, $inter / (count($mias) + count($otras) - $inter)];
        }
        usort($puntos, fn ($x, $y) => $y[1] <=> $x[1]);

        return array_slice($puntos, 0, $n);
    }

    /**
     * Palabras de una descripción: sin acentos ni palabras vacías; conserva números y medidas.
     *
     * @return array<string, true>
     */
    public function tokens(string $texto): array
    {
        $t = Str::lower(Str::ascii($texto));
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? '';
        $out = [];
        foreach (explode(' ', trim($t)) as $w) {
            if ($w === '' || in_array($w, self::PALABRAS_VACIAS, true)) {
                continue;
            }
            if (strlen($w) === 1 && ! ctype_digit($w)) {
                continue;
            }
            $out[$w] = true;
        }

        return $out;
    }

    /** @return list<string> */
    private function avisos(array $partida, SicarArticulo $a): array
    {
        $avisos = [];
        $u1 = self::unidadNormal($partida['unidad'] ?? '');
        $u2 = self::unidadNormal($a->unidad ?? '');
        if ($u1 !== '' && $u2 !== '' && $u1 !== $u2) {
            $avisos[] = 'Unidad distinta: el pedido dice '.Str::upper((string) $partida['unidad']).' y SICAR '.Str::upper((string) $a->unidad);
        }
        $costo = (float) ($partida['costo'] ?? 0);
        $sicar = (float) $a->precio_compra;
        if ($costo > 0 && $sicar > 0 && abs($costo - $sicar) / $sicar > 0.10) {
            $avisos[] = 'Costo distinto: pedido $'.number_format($costo, 2).' y SICAR $'.number_format($sicar, 2);
        }

        return $avisos;
    }

    /** Unidades equivalentes ("PIEZA", "PZ", "PZA" → PZA). */
    public static function unidadNormal(?string $unidad): string
    {
        $u = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii((string) $unidad)) ?? '');

        return match ($u) {
            'PZ', 'PZA', 'PZAS', 'PIEZA', 'PIEZAS', 'PC', 'PCS', 'PZS' => 'PZA',
            'KG', 'KGS', 'KILO', 'KILOS', 'KILOGRAMO' => 'KG',
            'M', 'MT', 'MTS', 'METRO', 'METROS' => 'MTS',
            'LT', 'LTS', 'LITRO', 'LITROS', 'L' => 'LT',
            'JGO', 'JUEGO', 'JUEGOS' => 'JGO',
            default => $u,
        };
    }

    private function llaveDe(SicarArticulo $a): string
    {
        return $a->clave_llave ?: SicarArticulo::llave($a->clave);
    }

    private function recortar(string $texto, int $max): string
    {
        $texto = trim($texto);
        if (mb_strlen($texto) <= $max) {
            return $texto;
        }
        $corte = mb_substr($texto, 0, $max + 1);
        $pos = mb_strrpos($corte, ' ');
        $corte = ($pos !== false && $pos > 0) ? mb_substr($corte, 0, $pos) : mb_substr($texto, 0, $max);

        return rtrim($corte, ' -.,/');
    }
}

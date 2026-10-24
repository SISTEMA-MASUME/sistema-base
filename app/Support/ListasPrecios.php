<?php

namespace App\Support;

use App\Models\Articulo;
use App\Models\ArticuloProveedor;
use App\Models\ListaPrecio;
use App\Models\ListaPrecioRenglon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Listas de precios de las marcas (Truper, Urrea…). Se sube el Excel tal como lo manda la marca: se busca sola la hoja
 * y el renglón de encabezados, y las columnas se reconocen por su nombre. El costo = la columna de precio elegida, sin
 * IVA (si la lista lo trae incluido) y con el descuento que le hacen a MASUME. Una lista por proveedor: la del mes nuevo
 * reemplaza a la anterior.
 *
 * Los renglones NO llenan el catálogo: se ligan solos con los artículos que ya existen (mismo código de barras o la misma
 * clave del proveedor, con la misma marca) y les completan lo que les falte para facturar. Un artículo nuevo nace de la
 * lista solo cuando alguien lo elige ("¿Es este?") o lo agrega a mano.
 */
final class ListasPrecios
{
    /** Encabezados que se reconocen (sin acentos, minúsculas), del más preciso al más general. */
    private const COLUMNAS = [
        'clave' => ['clave'],
        'codigo' => ['codigo', 'lista codigo'],
        'descripcion' => ['descripcion larga', 'descripcion'],
        'marca' => ['marca'],
        'unidad' => ['unidad', 'unidad de medida'],
        'codigo_sat' => ['codigo sat'],
        'clave_sat' => ['clave sat'],
        'codigo_barras' => ['codigo de barra', 'codigo de barras', 'codigo barras', 'ean'],
        'familia' => ['descripcion familia', 'familia de venta', 'familia'],
    ];

    private const MAX_RENGLONES = 60000;

    /** Unidad del SAT → cómo se escribe en MASUME (cuando la lista no trae la unidad). */
    private const UNIDAD_DE_SAT = ['H87' => 'PZA', 'SET' => 'JGO', 'KT' => 'KIT', 'PR' => 'PAR', 'XBX' => 'CAJA', 'XPK' => 'PAQ',
        'MTR' => 'MT', 'KGM' => 'KG', 'LTR' => 'LT', 'XRO' => 'ROLLO', 'GLL' => 'GAL', 'XBG' => 'BOLSA'];

    public static function normalizar(mixed $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii((string) $texto))));
    }

    /**
     * Revisa el Excel sin guardar nada: hoja, encabezados, columnas de precio, cuántos productos y una muestra.
     *
     * @return array{hoja: string, encabezado: int, columnas: array<string, int>, precios: array<int, string>, total: int,
     *               muestra: list<array<string, mixed>>, sugerido: array{precio: ?int, con_iva: bool, marca: ?string}}
     */
    public static function analizar(string $ruta): array
    {
        $lector = new LectorXlsx($ruta);
        $mejor = null;
        foreach ($lector->hojas() as $hoja) {
            $encontrado = null;
            $lector->recorrer($hoja, function (int $i, array $celdas) use (&$encontrado) {
                if ($i > 40) {
                    return false;
                }
                $columnas = self::columnasDe($celdas);
                if (isset($columnas['descripcion']) && (isset($columnas['clave']) || isset($columnas['codigo'])) && self::preciosDe($celdas) !== []) {
                    $encontrado = ['encabezado' => $i, 'columnas' => $columnas, 'precios' => self::preciosDe($celdas)];

                    return false;
                }

                return true;
            });
            if (! $encontrado) {
                continue;
            }
            $total = 0;
            $muestra = [];
            $marcas = [];
            $lector->recorrer($hoja, function (int $i, array $celdas) use ($encontrado, &$total, &$muestra, &$marcas) {
                if ($i <= $encontrado['encabezado']) {
                    return true;
                }
                $r = self::renglon($celdas, $encontrado['columnas']);
                if ($r === null) {
                    return true;
                }
                $total++;
                if (count($muestra) < 5) {
                    $muestra[] = $r + ['_celdas' => $celdas];
                }
                if ($r['marca'] && count($marcas) < 2000) {
                    $marcas[$r['marca']] = ($marcas[$r['marca']] ?? 0) + 1;
                }

                return $total < self::MAX_RENGLONES;
            });
            if (! $mejor || $total > $mejor['total']) {
                arsort($marcas);
                $mejor = ['hoja' => $hoja, 'total' => $total, 'muestra' => $muestra, 'marca' => array_key_first($marcas)] + $encontrado;
            }
        }
        if (! $mejor || $mejor['total'] === 0) {
            throw new InvalidArgumentException('No encontré la lista en el Excel: necesito una hoja con columnas de clave, descripción y precio.');
        }
        $mejor['sugerido'] = self::sugerencia($mejor['precios']) + ['marca' => $mejor['marca']];
        unset($mejor['marca']);

        return $mejor;
    }

    /** Qué precio usar de entrada: Truper = "precio distribuidor" (ya trae IVA); Urrea = "precio de lista" (sin IVA, con descuento). */
    private static function sugerencia(array $precios): array
    {
        foreach (['precio distribuidor' => true, 'precio de lista' => false, 'p.dist' => false] as $nombre => $conIva) {
            if (($i = array_search($nombre, array_map([self::class, 'normalizar'], $precios), true)) !== false) {
                return ['precio' => $i, 'con_iva' => $conIva];
            }
        }
        $primero = array_key_first($precios);

        return ['precio' => $primero, 'con_iva' => str_contains(self::normalizar($precios[$primero] ?? ''), 'iva')];
    }

    /** @return array<string, int> campo => índice de columna */
    private static function columnasDe(array $celdas): array
    {
        $porNombre = [];
        foreach ($celdas as $i => $c) {
            $n = self::normalizar($c);
            if ($n !== '' && ! isset($porNombre[$n])) {
                $porNombre[$n] = $i;
            }
        }
        $columnas = [];
        foreach (self::COLUMNAS as $campo => $nombres) {
            foreach ($nombres as $nombre) {
                if (isset($porNombre[$nombre])) {
                    $columnas[$campo] = $porNombre[$nombre];
                    break;
                }
            }
        }

        return $columnas;
    }

    /** @return array<int, string> índice => encabezado, de las columnas que son precios */
    private static function preciosDe(array $celdas): array
    {
        $precios = [];
        foreach ($celdas as $i => $c) {
            $n = self::normalizar($c);
            if ($n !== '' && (str_contains($n, 'precio') || preg_match('/^p\.?\s?dist/', $n))) {
                $precios[$i] = trim(preg_replace('/\s+/', ' ', (string) $c));
            }
        }

        return $precios;
    }

    /** Un producto de la lista, o null si el renglón no lo es (vacío, subtítulo, sin descripción). */
    private static function renglon(array $celdas, array $columnas): ?array
    {
        $valor = fn (string $campo) => isset($columnas[$campo]) ? ($celdas[$columnas[$campo]] ?? null) : null;
        $descripcion = Nombres::limpiar((string) $valor('descripcion'));
        $clave = self::texto($valor('clave')) ?: self::texto($valor('codigo'));
        if ($descripcion === '' || $clave === '' || ! CatalogoImagenes::llaveModelo($clave)) {
            return null;
        }
        // "CLAVE SAT" en Urrea es la unidad (SET, H87); el código de 8 números es el producto.
        $sat = array_filter([self::texto($valor('codigo_sat')), self::texto($valor('clave_sat'))]);
        $claveSat = collect($sat)->first(fn ($v) => preg_match('/^\d{8}$/', $v));
        $unidadSat = collect($sat)->map(fn ($v) => mb_strtoupper($v))->first(fn ($v) => isset(UnidadesSat::LISTA[$v]));
        $unidad = Nombres::limpiar((string) $valor('unidad'));
        if ($unidad === '' || $unidad === '-') {
            $unidad = self::UNIDAD_DE_SAT[$unidadSat] ?? '';
        }
        $barras = preg_replace('/\D/', '', self::texto($valor('codigo_barras')));

        return [
            'clave' => mb_substr($clave, 0, 60),
            'codigo' => ($c = self::texto($valor('codigo'))) !== '' && $c !== $clave ? mb_substr($c, 0, 60) : null,
            'descripcion' => mb_substr($descripcion, 0, 2000),
            'busqueda' => Articulos::textoBusqueda($descripcion, Nombres::limpiar((string) $valor('marca')), $clave),
            'marca' => ($m = Nombres::limpiar((string) $valor('marca'))) !== '' ? mb_substr($m, 0, 120) : null,
            'unidad' => $unidad !== '' ? mb_substr(mb_strtoupper($unidad), 0, 20) : null,
            'unidad_sat' => $unidadSat ?? UnidadesSat::sugerir($unidad),
            'clave_sat' => $claveSat,
            'codigo_barras' => strlen($barras) >= 8 && strlen($barras) <= 40 ? $barras : null,
            'familia' => ($f = trim((string) $valor('familia'))) !== '' ? mb_substr($f, 0, 120) : null,
        ];
    }

    /** Texto de una celda (los números enteros sin ".0"; el código de barras como número no pierde dígitos). */
    private static function texto(mixed $v): string
    {
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return sprintf('%.0f', $v);
        }

        return trim((string) $v);
    }

    /** "$1,234.50", " $345,227 ", 1234.5 → 1234.5; " - " o vacío → null. */
    public static function numero(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return $v > 0 ? (float) $v : null;
        }
        $limpio = preg_replace('/[^\d.]/', '', str_replace(',', '', (string) $v));
        if ($limpio === '' || ! is_numeric($limpio)) {
            return null;
        }

        return (float) $limpio > 0 ? (float) $limpio : null;
    }

    /**
     * Descuentos encadenados como se escriben: "20 + 5" = 20% y luego 5% sobre lo que queda = 24% (no 25%). Urrea: base 20% +
     * descuento en factura del trimestre (0, 5 o 10%) + 3% si se paga por anticipado. null si no se entiende.
     *
     * @return array{texto: string, total: float}|null
     */
    public static function descuentos(string $texto): ?array
    {
        $partes = preg_split('/\s*[+,;]\s*/', trim(str_replace('%', '', $texto)), -1, PREG_SPLIT_NO_EMPTY);
        if ($partes === [] || count($partes) > 6) {
            return $partes === [] ? ['texto' => '0', 'total' => 0.0] : null;
        }
        $queda = 1.0;
        foreach ($partes as $p) {
            if (! is_numeric($p) || (float) $p < 0 || (float) $p > 90) {
                return null;
            }
            $queda *= 1 - (float) $p / 100;
        }

        return ['texto' => implode(' + ', array_map(fn ($p) => rtrim(rtrim(number_format((float) $p, 2, '.', ''), '0'), '.'), $partes)),
            'total' => round((1 - $queda) * 100, 3)];
    }

    /** Costo SIN IVA y con el descuento, a partir del precio de la columna elegida. */
    public static function costo(?float $precio, bool $conIva, float $descuento): ?float
    {
        if (! $precio) {
            return null;
        }
        $sinIva = $conIva ? $precio / (1 + Precios::iva()) : $precio;

        return round($sinIva * (1 - max(0, min(100, $descuento)) / 100), 4);
    }

    /**
     * Guarda la lista (reemplaza la anterior del mismo proveedor) y la liga con el catálogo.
     *
     * @param  array{proveedor_id: int, nombre: string, precio: int, con_iva: bool, descuento: float|string, archivo?: ?string}  $config
     * @return array{renglones: int, ligados: int, completados: int}
     */
    public static function importar(string $ruta, array $config): array
    {
        $analisis = self::analizar($ruta);
        $columnaPrecio = (int) $config['precio'];
        $descuentos = self::descuentos((string) $config['descuento'])
            ?? throw new InvalidArgumentException('No entendí el descuento: escribe números separados con + (por ejemplo 20 + 5).');
        $config['descuento'] = $descuentos['total'];
        if (! isset($analisis['precios'][$columnaPrecio])) {
            throw new InvalidArgumentException('Esa columna de precio no está en el Excel.');
        }
        $lista = ListaPrecio::updateOrCreate(['proveedor_id' => $config['proveedor_id']], [
            'nombre' => mb_substr(trim($config['nombre']), 0, 120),
            'columna_precio' => $analisis['precios'][$columnaPrecio],
            'precio_con_iva' => (bool) $config['con_iva'],
            'descuentos' => $descuentos['texto'],
            'descuento_pct' => $descuentos['total'],
            'archivo' => isset($config['archivo']) ? mb_substr((string) $config['archivo'], 0, 191) : null,
            'user_id' => auth()->id(),
        ]);
        $vistas = [];
        $lote = [];
        $total = 0;
        $guardar = function () use (&$lote) {
            if ($lote !== []) {
                ListaPrecioRenglon::upsert(array_values($lote), ['lista_precio_id', 'clave_llave'],
                    ['clave', 'codigo', 'descripcion', 'busqueda', 'marca', 'unidad', 'unidad_sat', 'clave_sat', 'codigo_barras', 'precio_lista', 'costo', 'familia', 'updated_at']);
                $lote = [];
            }
        };
        (new LectorXlsx($ruta))->recorrer($analisis['hoja'], function (int $i, array $celdas) use ($analisis, $columnaPrecio, $config, $lista, &$lote, &$vistas, &$total, $guardar) {
            if ($i <= $analisis['encabezado']) {
                return true;
            }
            $r = self::renglon($celdas, $analisis['columnas']);
            if ($r === null) {
                return true;
            }
            $precio = self::numero($celdas[$columnaPrecio] ?? null);
            $llave = CatalogoImagenes::llaveModelo($r['clave']);
            $ahora = now()->toDateTimeString();
            // La misma clave dos veces en el archivo: se queda la última (y el upsert no truena).
            $vistas[$llave] = true;
            $lote[$llave] = $r + [
                'lista_precio_id' => $lista->id, 'clave_llave' => $llave,
                'precio_lista' => $precio, 'costo' => self::costo($precio, (bool) $config['con_iva'], (float) $config['descuento']),
                'created_at' => $ahora, 'updated_at' => $ahora,
            ];
            $total++;
            if (count($lote) >= 500) {
                $guardar();
            }

            return $total < self::MAX_RENGLONES;
        });
        $guardar();
        // Lo que ya no viene en la lista nueva (descontinuado) se quita.
        ListaPrecioRenglon::where('lista_precio_id', $lista->id)->select(['id', 'clave_llave'])->chunkById(2000, function ($renglones) use ($vistas) {
            $sobran = $renglones->reject(fn ($r) => isset($vistas[$r->clave_llave]))->pluck('id');
            if ($sobran->isNotEmpty()) {
                ListaPrecioRenglon::whereIn('id', $sobran)->delete();
            }
        });

        $resultado = self::ligar($lista);
        $lista->update(['renglones' => $lista->renglonesLista()->count(), 'ligados' => $resultado['ligados'], 'importada_at' => now()]);

        return ['renglones' => $lista->renglones] + $resultado;
    }

    /**
     * Cambiar los descuentos (o si el precio trae IVA) de una lista ya cargada, sin volver a subir el Excel: se recalcula
     * el costo de todos sus productos y el de los artículos ligados que venía de la lista. Para cuando cambia el nivel del
     * trimestre (Urrea: 0, 5 o 10% en factura según lo comprado el trimestre anterior).
     */
    public static function recalcular(ListaPrecio $lista, string $descuentos, bool $conIva): ListaPrecio
    {
        $d = self::descuentos($descuentos)
            ?? throw new InvalidArgumentException('No entendí el descuento: escribe números separados con + (por ejemplo 20 + 5).');
        $factor = (1 - $d['total'] / 100) / ($conIva ? 1 + Precios::iva() : 1);
        DB::transaction(function () use ($lista, $d, $conIva, $factor) {
            $lista->update(['descuentos' => $d['texto'], 'descuento_pct' => $d['total'], 'precio_con_iva' => $conIva]);
            // Un solo UPDATE (el factor es un número calculado aquí, no texto del usuario).
            $lista->renglonesLista()->whereNotNull('precio_lista')->update(['costo' => DB::raw('ROUND(precio_lista * '.sprintf('%.10F', $factor).', 4)')]);
        });
        self::ligar($lista->fresh());

        return $lista->fresh();
    }

    /**
     * Liga los renglones con los artículos que ya existen (solo coincidencias exactas) y completa sus datos: código de
     * barras, clave y unidad del SAT, marca, y el costo de la lista con ese proveedor (si no hay una compra más reciente).
     *
     * @return array{ligados: int, completados: int}
     */
    public static function ligar(ListaPrecio $lista): array
    {
        $porBarras = Articulo::whereNotNull('codigo_barras')->pluck('id', 'codigo_barras')->all();
        $porClaveProveedor = ArticuloProveedor::where('proveedor_id', $lista->proveedor_id)->whereNotNull('clave_llave')->pluck('articulo_id', 'clave_llave')->all();
        $porClave = [];
        foreach (ArticuloProveedor::whereNotNull('clave_llave')->get(['articulo_id', 'clave_llave']) as $f) {
            $porClave[$f->clave_llave][] = $f->articulo_id;
        }
        foreach (Articulo::whereNotNull('sicar_clave')->get(['id', 'sicar_clave']) as $a) {
            if ($ll = CatalogoImagenes::llaveModelo($a->sicar_clave)) {
                $porClave[$ll][] = $a->id;
            }
        }
        $marcas = Articulo::whereNotNull('marca')->pluck('marca', 'id')->map(fn ($m) => Nombres::llave($m))->all();
        $vivos = Articulo::pluck('id')->flip()->all();   // sin los unidos/borrados

        $ligados = $completados = 0;
        $lista->renglonesLista()->orderBy('id')->chunkById(500, function ($renglones) use ($lista, $porBarras, $porClaveProveedor, $porClave, $marcas, $vivos, &$ligados, &$completados) {
            DB::transaction(function () use ($renglones, $lista, $porBarras, $porClaveProveedor, $porClave, $marcas, $vivos, &$ligados, &$completados) {
                foreach ($renglones as $r) {
                    $marca = Nombres::llave((string) $r->marca);
                    $sirve = fn ($id) => $id && isset($vivos[$id])
                        && ($marca === '' || ! isset($marcas[$id]) || $marcas[$id] === '' || $marcas[$id] === $marca);
                    // En orden: mismo código de barras, misma clave con este proveedor, misma clave con otro proveedor.
                    $id = collect([
                        $r->codigo_barras ? ($porBarras[$r->codigo_barras] ?? null) : null,
                        $porClaveProveedor[$r->clave_llave] ?? null,
                        ...($porClave[$r->clave_llave] ?? []),
                    ])->first($sirve);
                    if ((int) $r->articulo_id !== (int) $id) {
                        $r->update(['articulo_id' => $id]);
                    }
                    if ($id) {
                        $ligados++;
                        $completados += self::completarArticulo(Articulo::find($id), $r, $lista) ? 1 : 0;
                    }
                }
            });
        });

        return ['ligados' => $ligados, 'completados' => $completados];
    }

    /** Llena lo que le falte al artículo con lo de la lista y anota el costo de la lista con ese proveedor. */
    private static function completarArticulo(Articulo $a, ListaPrecioRenglon $r, ListaPrecio $lista): bool
    {
        if (blank($a->codigo_barras) && $r->codigo_barras && ! Articulo::where('codigo_barras', $r->codigo_barras)->exists()) {
            $a->codigo_barras = $r->codigo_barras;
        }
        foreach (['clave_sat', 'unidad_sat', 'marca'] as $campo) {
            if (blank($a->$campo) && filled($r->$campo)) {
                $a->$campo = $r->$campo;
            }
        }
        $cambio = $a->isDirty();
        if ($cambio) {
            $a->save();
        }
        $fila = ArticuloProveedor::firstOrNew(['articulo_id' => $a->id, 'proveedor_id' => $lista->proveedor_id]);
        if (blank($fila->clave)) {
            $fila->clave = $r->clave;
        }
        // El costo de la lista no pisa una compra real reciente (de los últimos 30 días).
        $compraReciente = $fila->ultimo_costo !== null && ! $fila->lista_precio_id && $fila->costo_fecha && $fila->costo_fecha->gt(now()->subDays(30));
        if ($r->costo !== null && ! $compraReciente) {
            $fila->fill(['ultimo_costo' => $r->costo, 'costo_fecha' => today()->toDateString(), 'pedido_id' => null, 'lista_precio_id' => $lista->id]);
        }
        if ($fila->isDirty()) {
            $fila->save();
        }

        return $cambio;
    }

    /** El artículo de un renglón de la lista: el que ya tiene ligado, o nace ahora del catálogo (origen lista). */
    public static function articuloDe(ListaPrecioRenglon $r): Articulo
    {
        if ($r->articulo_id && ($a = Articulos::vigente(Articulo::withTrashed()->find($r->articulo_id)))) {
            return $a;
        }

        return DB::transaction(function () use ($r) {
            $lista = $r->lista;
            $a = Articulo::create([
                'descripcion' => $r->descripcion,
                'marca' => $r->marca,
                'unidad' => $r->unidad ?: 'PZA',
                'unidad_sat' => $r->unidad_sat ?: UnidadesSat::sugerir($r->unidad),
                'clave_sat' => $r->clave_sat,
                'codigo_barras' => $r->codigo_barras && ! Articulo::where('codigo_barras', $r->codigo_barras)->exists() ? $r->codigo_barras : null,
                'origen' => 'lista',
                'user_id' => auth()->id(),
            ]);
            ArticuloProveedor::create(['articulo_id' => $a->id, 'proveedor_id' => $lista->proveedor_id, 'clave' => $r->clave,
                'ultimo_costo' => $r->costo, 'costo_fecha' => ($lista->importada_at ?? now())->toDateString(), 'lista_precio_id' => $lista->id]);
            $r->update(['articulo_id' => $a->id]);

            return $a;
        });
    }

    /** Búsqueda en las listas: cada palabra en la descripción, o la clave o el código de barras exactos. */
    public static function buscar(string $texto): Builder
    {
        $texto = trim($texto);
        $clave = CatalogoImagenes::llaveModelo($texto);
        $barras = preg_replace('/\D/', '', $texto);
        $palabras = Articulos::palabras($texto);

        return ListaPrecioRenglon::query()->where(function (Builder $w) use ($palabras, $clave, $barras) {
            $w->where(function (Builder $todas) use ($palabras) {
                foreach ($palabras as $p) {
                    $todas->where('busqueda', 'like', '%'.addcslashes($p, '%_\\').'%');
                }
            })
                ->when($clave, fn (Builder $c) => $c->orWhere('clave_llave', $clave))
                ->when(strlen($barras) >= 8, fn (Builder $b) => $b->orWhere('codigo_barras', $barras));
        });
    }

    /** Mes de la lista para su nombre ("octubre 2026"). */
    public static function mes(?Carbon $fecha): string
    {
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $fecha ? $meses[$fecha->month - 1].' '.$fecha->year : '';
    }
}

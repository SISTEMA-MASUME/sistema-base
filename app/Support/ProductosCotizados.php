<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Busca productos que YA cotizamos antes (cotizacion_partidas) para ligar una partida nueva a uno de ellos.
 *
 * - Primero por MODELO (ignora mayúsculas, guiones, espacios y puntos: "SPA-2800" = "spa2800").
 * - Luego por DESCRIPCIÓN, sin exigir todas las palabras: se traen candidatos con cualquiera de ellas (LIKE
 *   portable, tope 300) y se califican en PHP. Las medidas (1/2, 10mm, 220V) pesan doble y si la medida no
 *   coincide el producto no sirve ("broca 1/4" no es "broca 3/8"). Palabras vacías ("de", "para", "pza"…) no cuentan.
 * Todo con Query Builder: igual en SQLite y MariaDB. Los resultados traen costo y proveedor: son internos
 * (nunca van al PDF ni al correo del cliente).
 */
final class ProductosCotizados
{
    public const MIN_PUNTAJE = 0.5;

    private const TOPE_CANDIDATOS = 300;

    private const PALABRAS_VACIAS = ['para', 'con', 'sin', 'del', 'las', 'los', 'una', 'uno', 'unos', 'unas', 'por', 'que', 'pza', 'pzas', 'pieza', 'piezas',
        'marca', 'tipo', 'modelo', 'incluye', 'medida', 'color', 'como', 'cada', 'caja', 'cja', 'jgo', 'juego'];

    /**
     * @return list<array<string, mixed>> mejores coincidencias (más recientes si empatan), cada una con
     *                                    partida_id, descripcion, modelo, marca, unidad, costo, utilidad_pct, precio, proveedor_id, proveedor,
     *                                    proveedor_vendedor, proveedor_telefono, link_compra, tiempo_entrega, entrega_lenta, imagen_path,
     *                                    folio, fecha, cliente, imagen (url del catálogo o null), motivo, puntaje
     */
    public static function buscar(?string $modelo, string $descripcion, int $limite = 5, ?int $clienteId = null): array
    {
        $resultados = [];   // por partida_id

        // ---- 1) Por modelo
        $llaveModelo = Nombres::llave((string) $modelo);
        if (strlen($llaveModelo) >= 3 && ! LinkCompra::esUrl((string) $modelo)) {
            $compacto = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(p.modelo, ''), '-', ''), ' ', ''), '.', ''), '/', ''), '_', '') LIKE ?";
            $filas = self::base()->whereRaw($compacto, ['%'.str_replace(['%', '_'], '', $llaveModelo).'%'])
                ->orderByDesc('c.fecha')->orderByDesc('p.id')->limit(100)->get();
            foreach ($filas as $f) {
                if (Nombres::llave((string) $f->modelo) === $llaveModelo) {
                    $resultados[$f->partida_id] = self::mapear($f, 2.0 + ($clienteId && (int) $f->cliente_id === $clienteId ? 0.1 : 0), 'mismo modelo');
                }
            }
        }

        // ---- 2) Por descripción
        [$palabras, $medidas] = self::fichas($descripcion);
        $fichas = array_merge($palabras, $medidas);
        if ($fichas !== []) {
            $q = self::base();
            // Las 8 fichas más largas son las más distintivas ("dewalt" pesa más que "4").
            $buscadas = collect($fichas)->sortByDesc(fn ($t) => strlen($t))->take(8)->values();
            $q->where(function ($w) use ($buscadas) {
                foreach ($buscadas as $t) {
                    $w->orWhere('p.descripcion', 'like', '%'.str_replace(['%', '_'], '', $t).'%');
                }
            });
            $filas = $q->orderByDesc('c.fecha')->orderByDesc('p.id')->limit(self::TOPE_CANDIDATOS)->get();

            $pesoTotal = count($palabras) + 2 * count($medidas);
            foreach ($filas as $f) {
                if (isset($resultados[$f->partida_id])) {
                    continue;
                }
                [$pc, $mc] = self::fichas($f->descripcion);
                $pCoinciden = count(array_intersect($palabras, $pc));
                $mCoinciden = count(array_intersect($medidas, $mc));
                // Si pidió palabras, al menos la mitad deben estar (que no sea "disco 1/4" por "broca 1/4").
                if ($palabras !== [] && $pCoinciden / count($palabras) < 0.5) {
                    continue;
                }
                // Pidió medida y el producto trae otra distinta (1/4 contra 3/8, 4" contra 7"): no es el mismo.
                if ($medidas !== [] && $mCoinciden === 0 && $mc !== []) {
                    continue;
                }
                $puntaje = ($pCoinciden + 2 * $mCoinciden) / $pesoTotal;
                if ($puntaje < self::MIN_PUNTAJE) {
                    continue;
                }
                if ($clienteId && (int) $f->cliente_id === $clienteId) {
                    $puntaje += 0.1;
                }
                $total = count($palabras) + count($medidas);
                $resultados[$f->partida_id] = self::mapear($f, $puntaje, ($pCoinciden + $mCoinciden).' de '.$total.($total === 1 ? ' palabra' : ' palabras'));
            }
        }

        // Sin duplicados por descripción normalizada: se queda el mejor (y, si empatan, el más reciente).
        usort($resultados, fn ($a, $b) => [$b['puntaje'], $b['_fecha'], $b['partida_id']] <=> [$a['puntaje'], $a['_fecha'], $a['partida_id']]);
        $vistos = [];
        $salida = [];
        foreach ($resultados as $r) {
            $llave = Nombres::llave($r['descripcion']);
            if (isset($vistos[$llave])) {
                continue;
            }
            $vistos[$llave] = true;
            unset($r['_fecha']);
            $r['imagen'] = self::imagen($r);
            $salida[] = $r;
            if (count($salida) >= $limite) {
                break;
            }
        }

        return $salida;
    }

    /**
     * Palabras útiles (3+ letras, sin acentos ni palabras vacías) y medidas (cualquier ficha con un número,
     * comparada por su parte numérica: 4, 1/2, 10mm → 10, 220v → 220).
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function fichas(?string $texto): array
    {
        $texto = Str::lower(Str::ascii((string) $texto));
        $palabras = $medidas = [];
        foreach (preg_split('/[^a-z0-9\/.]+/', $texto) ?: [] as $t) {
            $t = trim($t, './');
            if ($t === '') {
                continue;
            }
            if (preg_match('/\d/', $t)) {
                // "19mm" y "19 mm" son la misma medida: se compara por la parte numérica ("19", "1/2", "220").
                $n = preg_match('/^[\d.\/]+/', $t, $m) ? rtrim($m[0], './') : $t;
                $medidas[$n] = $n;
            } elseif (strlen($t) >= 3 && ! in_array($t, self::PALABRAS_VACIAS, true)) {
                $palabras[$t] = $t;
            }
        }

        return [array_values($palabras), array_values($medidas)];
    }

    private static function base()
    {
        return DB::table('cotizacion_partidas as p')
            ->join('cotizaciones as c', 'c.id', '=', 'p.cotizacion_id')
            ->leftJoin('clientes as cl', 'cl.id', '=', 'c.cliente_id')
            ->leftJoin('proveedores as pr', 'pr.id', '=', 'p.proveedor_id')
            ->whereNull('c.deleted_at')
            ->whereNotNull('p.descripcion')->where('p.descripcion', '!=', '')
            // Una partida sin costo ni precio (producto nuevo sin cotizar todavía) no sirve para reutilizar.
            ->where(fn ($w) => $w->whereNotNull('p.costo')->orWhere('p.precio', '>', 0))
            ->select([
                'p.id as partida_id', 'p.descripcion', 'p.modelo', 'p.marca', 'p.unidad', 'p.costo', 'p.utilidad_pct', 'p.precio',
                'p.proveedor_id', 'p.proveedor_vendedor', 'p.proveedor_telefono', 'p.link_compra', 'p.tiempo_entrega', 'p.entrega_lenta', 'p.imagen_path',
                'c.folio', 'c.fecha', 'c.cliente_id', 'cl.nombre as cliente_nombre', 'pr.nombre as proveedor_nombre',
            ]);
    }

    private static function mapear(object $f, float $puntaje, string $motivo): array
    {
        return [
            'partida_id' => (int) $f->partida_id,
            'descripcion' => (string) $f->descripcion,
            'modelo' => (string) $f->modelo,
            'marca' => (string) $f->marca,
            'unidad' => (string) $f->unidad,
            'costo' => $f->costo !== null ? (float) $f->costo : null,
            'utilidad_pct' => $f->utilidad_pct !== null ? (float) $f->utilidad_pct : null,
            'precio' => (float) $f->precio,
            'proveedor_id' => $f->proveedor_id ? (int) $f->proveedor_id : null,
            'proveedor' => (string) $f->proveedor_nombre,
            'proveedor_vendedor' => (string) $f->proveedor_vendedor,
            'proveedor_telefono' => (string) $f->proveedor_telefono,
            'link_compra' => (string) $f->link_compra,
            'tiempo_entrega' => (string) $f->tiempo_entrega,
            'entrega_lenta' => (bool) $f->entrega_lenta,
            'imagen_path' => $f->imagen_path,
            'folio' => $f->folio,
            'fecha' => $f->fecha ? date('d/m/Y', strtotime((string) $f->fecha)) : '',
            'cliente' => (string) $f->cliente_nombre,
            'motivo' => $motivo,
            'puntaje' => round($puntaje, 3),
            '_fecha' => (string) $f->fecha,
        ];
    }

    /** URL de la imagen: la de la partida o la del catálogo (si el archivo existe). */
    private static function imagen(array $r): ?string
    {
        $disco = Storage::disk('public');
        $ruta = $r['imagen_path'] && $disco->exists($r['imagen_path']) ? $r['imagen_path']
            : CatalogoImagenes::buscarFila($r['modelo'], $r['descripcion'])?->imagen_path;

        return $ruta && $disco->exists($ruta) ? $disco->url($ruta) : null;
    }
}

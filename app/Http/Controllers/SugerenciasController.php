<?php

namespace App\Http\Controllers;

use App\Livewire\Articulos\Index;
use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\PedidoPartida;
use App\Models\ProductoImagen;
use App\Support\CatalogoImagenes;
use App\Support\ListasPrecios;
use App\Support\Precios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SugerenciasController extends Controller
{
    private const MAX_CLIENTE = 2000;

    private const MAX_GLOBAL = 8;

    private const MAX_ARTICULOS = 4;

    /** Partidas distintas (por descripción normalizada, la más reciente) cotizadas a un cliente. */
    public function cliente(Cliente $cliente): JsonResponse
    {
        $filas = $this->base()
            ->where('c.cliente_id', $cliente->id)
            ->orderByDesc('c.fecha')->orderByDesc('p.id')
            ->limit(self::MAX_CLIENTE * 4)
            ->get();

        $vistas = [];
        $salida = [];
        foreach ($filas as $f) {
            $llave = self::normalizar($f->descripcion);
            if ($llave === '' || isset($vistas[$llave])) {
                continue;
            }
            $vistas[$llave] = true;
            $salida[] = self::mapear($f);
            if (count($salida) >= self::MAX_CLIENTE) {
                break;
            }
        }

        return response()->json(self::conImagen($salida))->setPrivate()->setMaxAge(300);
    }

    /** Coincidencias en cotizaciones de cualquier cliente (para "otro cliente"). */
    public function global(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 4) {
            return response()->json([]);
        }

        $consulta = $this->base()->addSelect('cl.nombre as cliente_nombre')
            ->join('clientes as cl', 'cl.id', '=', 'c.cliente_id');

        foreach (array_slice(preg_split('/\s+/u', $q), 0, 6) as $palabra) {
            $palabra = str_replace(['%', '_'], '', $palabra);
            $consulta->where('p.descripcion', 'like', '%'.$palabra.'%');
        }

        $filas = $consulta->orderByDesc('p.id')->limit(60)->get();

        $vistas = [];
        $salida = [];
        foreach ($filas as $f) {
            $llave = self::normalizar($f->descripcion);
            if (isset($vistas[$llave])) {
                continue;
            }
            $vistas[$llave] = true;
            $salida[] = self::mapear($f) + ['cl' => $f->cliente_nombre];
            if (count($salida) >= self::MAX_GLOBAL) {
                break;
            }
        }

        return response()->json(self::conImagen($salida))->setPrivate()->setMaxAge(60);
    }

    /**
     * "¿Es este?": artículos del catálogo que coinciden con lo que se escribe, con sus proveedores y costos. El
     * vendedor elige; nunca se elige solo. Van primero en la lista (con su etiqueta "Catálogo").
     */
    public function articulos(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 3) {
            return response()->json([]);
        }
        $articulos = Index::aplicarBusqueda(Articulo::query(), $q)
            ->with(['proveedores.proveedor:id,nombre'])
            ->orderByRaw('ultimo_pedido_at IS NULL')->orderByDesc('ultimo_pedido_at')->orderBy('descripcion')
            ->limit(self::MAX_ARTICULOS)->get();

        $salida = [];
        foreach ($articulos as $a) {
            $mejor = $a->proveedores->first(fn ($p) => $p->ultimo_costo !== null) ?? $a->proveedores->first();
            $ultima = PedidoPartida::where('articulo_id', $a->id)->whereHas('pedido', fn ($w) => $w->whereNull('cancelado_at'))
                ->orderByDesc('id')->first(['costo', 'precio', 'pedido_id']);
            $costo = $mejor?->ultimo_costo !== null ? (float) $mejor->ultimo_costo : null;
            // La utilidad con la que se vendió la última vez, sobre el mejor costo de hoy.
            $utilidad = $ultima && (float) $ultima->costo > 0 && (float) $ultima->precio > 0
                ? Precios::utilidadDesdePrecio((float) $ultima->costo, (float) $ultima->precio) : null;
            $salida[] = [
                'art' => $a->id,
                'd' => $a->descripcion,
                'u' => (string) $a->unidad,
                'ma' => (string) $a->marca,
                'mo' => (string) ($mejor?->clave ?? ''),
                'pr' => (string) ($mejor?->proveedor?->nombre ?? ''),
                'lk' => (string) ($mejor?->link_compra ?? ''),
                'c' => $costo,
                'ut' => $costo && $utilidad !== null ? round($utilidad, 2) : null,
                'p' => $ultima ? (float) $ultima->precio : 0,
                'provs' => $a->proveedores->filter(fn ($p) => $p->ultimo_costo !== null)->take(3)
                    ->map(fn ($p) => $p->proveedor?->nombre.' $'.number_format((float) $p->ultimo_costo, 2))->implode(' · '),
                'te' => '',
                'f' => null,
                'fe' => '',
            ];
        }

        // Lo que aún no está en el catálogo pero viene en una lista de precios (Truper, Urrea…): al elegirlo nace el artículo.
        $faltan = self::MAX_ARTICULOS - count($salida);
        if ($faltan > 0) {
            foreach (ListasPrecios::buscar($q)->whereNull('articulo_id')->with('lista.proveedor:id,nombre')->orderBy('descripcion')->limit($faltan)->get() as $r) {
                $salida[] = [
                    'lr' => $r->id,
                    'd' => $r->descripcion,
                    'u' => (string) $r->unidad,
                    'ma' => (string) $r->marca,
                    'mo' => $r->clave,
                    'pr' => (string) $r->lista?->proveedor?->nombre,
                    'c' => $r->costo !== null ? (float) $r->costo : null,
                    'ut' => null,
                    'p' => 0,
                    'lista' => (string) $r->lista?->proveedor?->nombre,
                    'te' => '',
                    'f' => null,
                    'fe' => '',
                ];
            }
        }

        return response()->json(self::conImagen($salida))->setPrivate()->setMaxAge(30);
    }

    /**
     * Agrega la imagen del catálogo (im) a las sugerencias que ya tienen una: así se ve que el producto ya
     * existe y se evita darlo de alta otra vez con otro nombre.
     */
    private static function conImagen(array $salida): array
    {
        $llaves = array_map(fn ($s) => [CatalogoImagenes::llaveModelo($s['mo']), CatalogoImagenes::llaveDescripcion($s['d'])], $salida);
        $porModelo = $porDescripcion = [];
        $modelos = array_values(array_unique(array_filter(array_column($llaves, 0))));
        $descripciones = array_values(array_unique(array_filter(array_column($llaves, 1))));
        foreach (array_chunk($modelos, 500) as $lote) {
            foreach (ProductoImagen::whereIn('llave_modelo', $lote)->orderBy('updated_at')->get(['llave_modelo', 'imagen_path']) as $f) {
                $porModelo[$f->llave_modelo] = $f->imagen_path;
            }
        }
        foreach (array_chunk($descripciones, 500) as $lote) {
            foreach (ProductoImagen::whereIn('llave_descripcion', $lote)->whereNull('llave_modelo')->orderBy('updated_at')->get(['llave_descripcion', 'imagen_path']) as $f) {
                $porDescripcion[$f->llave_descripcion] = $f->imagen_path;
            }
        }
        $disco = Storage::disk('public');
        foreach ($salida as $n => $s) {
            [$lm, $ld] = $llaves[$n];
            $ruta = ($lm ? ($porModelo[$lm] ?? null) : null) ?? ($ld ? ($porDescripcion[$ld] ?? null) : null);
            if ($ruta) {
                $salida[$n]['im'] = $disco->url($ruta);
            }
        }

        return $salida;
    }

    private function base()
    {
        return DB::table('cotizacion_partidas as p')
            ->join('cotizaciones as c', 'c.id', '=', 'p.cotizacion_id')
            ->leftJoin('proveedores as pr', 'pr.id', '=', 'p.proveedor_id')
            ->select([
                'p.descripcion', 'p.unidad', 'p.modelo', 'p.marca', 'p.proveedor_vendedor', 'p.proveedor_telefono', 'p.link_compra',
                'p.costo', 'p.utilidad_pct', 'p.precio', 'p.tiempo_entrega',
                'c.folio', 'c.fecha', 'pr.nombre as proveedor_nombre',
            ])
            ->whereNotNull('p.descripcion')->where('p.descripcion', '!=', '');
    }

    private static function normalizar(?string $texto): string
    {
        return trim(preg_replace('/\s+/u', ' ', Str::lower(Str::ascii((string) $texto))));
    }

    private static function mapear(object $f): array
    {
        return [
            'd' => $f->descripcion,
            'u' => (string) $f->unidad,
            'mo' => (string) $f->modelo,
            'ma' => (string) $f->marca,
            'pr' => (string) $f->proveedor_nombre,
            'pv' => (string) $f->proveedor_vendedor,
            'lk' => (string) $f->link_compra,
            'pt' => (string) $f->proveedor_telefono,
            'c' => $f->costo !== null ? (float) $f->costo : null,
            'ut' => $f->utilidad_pct !== null ? (float) $f->utilidad_pct : null,
            'p' => (float) $f->precio,
            'te' => (string) $f->tiempo_entrega,
            'f' => $f->folio,
            'fe' => $f->fecha ? date('d/m/Y', strtotime((string) $f->fecha)) : '',
        ];
    }
}

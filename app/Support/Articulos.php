<?php

namespace App\Support;

use App\Models\Articulo;
use App\Models\ArticuloNombre;
use App\Models\ArticuloProveedor;
use App\Models\PedidoPartida;
use App\Models\SicarArticulo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Catálogo de artículos: cada artículo una sola vez, con los proveedores que lo venden (su clave y su último costo)
 * y sus datos para facturar. Es el camino para dejar SICAR.
 *
 * Un artículo nace: de SICAR (llenado inicial), cuando una partida entra a un pedido (llegó la OC: lo que se cotiza y
 * no se vende no llena el catálogo), de una lista de precios o a mano. Al ligar una partida NUNCA se adivina: solo
 * cuenta lo exacto (su artículo de SICAR, la misma clave con el mismo proveedor o la misma descripción). Si no, nace
 * uno nuevo; los repetidos se unen a mano en la ficha.
 */
final class Articulos
{
    /** Llave de comparación de una descripción (sin mayúsculas, acentos, espacios ni puntuación). */
    public static function llave(?string $texto): ?string
    {
        return CatalogoImagenes::llaveDescripcion($texto);
    }

    /** Texto para buscar sin acentos ni mayúsculas que estorben: "LLAVE PRESIÓN TRUPER" → "LLAVE PRESION TRUPER". */
    public static function textoBusqueda(?string ...$partes): string
    {
        return mb_strtoupper(Str::ascii(trim(implode(' ', array_filter($partes)))));
    }

    /** Las palabras de una búsqueda, listas para comparar con textoBusqueda (máximo 6). */
    public static function palabras(string $texto): array
    {
        return array_slice(array_values(array_filter(preg_split('/\s+/u', self::textoBusqueda($texto)))), 0, 6);
    }

    /** El artículo vigente: si se unió a otro, el principal. */
    public static function vigente(?Articulo $a): ?Articulo
    {
        for ($saltos = 0; $a && $a->trashed() && $saltos < 5; $saltos++) {
            $a = $a->unido_a_id ? Articulo::withTrashed()->find($a->unido_a_id) : null;
        }

        return $a && ! $a->trashed() ? $a : null;
    }

    /**
     * Liga la partida del pedido con su artículo (lo crea si no existe) y anota al proveedor con su clave y costo.
     * Nunca rompe lo que se está guardando: si algo falla, se registra el error y el pedido sigue.
     */
    public static function ligarPartidaSeguro(PedidoPartida $p): void
    {
        try {
            self::ligarPartida($p);
        } catch (Throwable $e) {
            RegistroErrores::registrar($e);
        }
    }

    public static function ligarPartida(PedidoPartida $p, ?Carbon $fecha = null): ?Articulo
    {
        if (trim((string) $p->descripcion) === '') {
            return null;
        }
        $articulo = self::buscarPara($p) ?? self::crearDesdePartida($p);
        if ((int) $p->articulo_id !== $articulo->id) {
            $p->articulo_id = $articulo->id;
            $p->saveQuietly();
        }
        $fecha ??= $p->pedido?->created_at ?? now();
        self::completarDesdePartida($articulo, $p, $fecha);
        if ($p->proveedor_id) {
            self::anotarProveedor($articulo, $p, $fecha);
        }

        return $articulo;
    }

    /** El artículo que ya le corresponde a la partida, solo por coincidencias exactas. */
    private static function buscarPara(PedidoPartida $p): ?Articulo
    {
        if ($p->articulo_id && ($a = self::vigente(Articulo::withTrashed()->find($p->articulo_id)))) {
            return $a;
        }
        if ($p->sicar_art_id && ($a = Articulo::where('sicar_art_id', $p->sicar_art_id)->orderBy('id')->first())) {
            return $a;
        }
        $clave = CatalogoImagenes::llaveModelo($p->modelo);
        if ($clave && $p->proveedor_id) {
            $id = ArticuloProveedor::where('proveedor_id', $p->proveedor_id)->where('clave_llave', $clave)->orderBy('id')->value('articulo_id');
            if ($id && ($a = self::vigente(Articulo::withTrashed()->find($id)))) {
                return $a;
            }
        }

        return self::porDescripcion($p->descripcion, $p->marca);
    }

    /** Misma descripción (o uno de sus otros nombres); si los dos traen marca, tiene que ser la misma. */
    public static function porDescripcion(?string $descripcion, ?string $marca = null): ?Articulo
    {
        $llave = self::llave($descripcion);
        if (! $llave) {
            return null;
        }
        $marcaLlave = Nombres::llave((string) $marca);
        $candidatos = Articulo::where('descripcion_llave', $llave)->orderBy('id')->limit(10)->get();
        if ($candidatos->isEmpty()) {
            $ids = ArticuloNombre::where('llave', $llave)->orderBy('id')->limit(10)->pluck('articulo_id');
            $candidatos = Articulo::whereIn('id', $ids)->orderBy('id')->get();
        }

        return $candidatos->first(fn (Articulo $a) => $marcaLlave === '' || blank($a->marca) || Nombres::llave($a->marca) === $marcaLlave);
    }

    private static function crearDesdePartida(PedidoPartida $p): Articulo
    {
        $sicar = $p->sicar_art_id ? SicarArticulo::find($p->sicar_art_id) : null;

        return Articulo::create([
            'descripcion' => Nombres::limpiar((string) $p->descripcion),
            'marca' => self::marca($p->marca),
            'unidad' => self::unidad($p->unidad),
            'unidad_sat' => UnidadesSat::sugerir($p->unidad),
            'clave_sat' => $sicar?->clave_prod_serv ?: ($p->sicar_clave_sat ?: null),
            'sicar_art_id' => $sicar?->art_id,
            'sicar_clave' => $sicar?->clave,
            'origen' => 'pedido',
        ]);
    }

    /** Llena lo que le falta al artículo con lo que trae la partida (nunca pisa lo que ya tiene). */
    private static function completarDesdePartida(Articulo $a, PedidoPartida $p, Carbon $fecha): void
    {
        if (! $a->ultimo_pedido_at || $fecha->gt($a->ultimo_pedido_at)) {
            $a->ultimo_pedido_at = $fecha;
        }
        if (blank($a->marca) && filled($p->marca)) {
            $a->marca = self::marca($p->marca);
        }
        if (! $a->sicar_art_id && $p->sicar_art_id && ! Articulo::where('sicar_art_id', $p->sicar_art_id)->whereKeyNot($a->id)->exists()) {
            $sicar = SicarArticulo::find($p->sicar_art_id);
            $a->sicar_art_id = $p->sicar_art_id;
            $a->sicar_clave = $a->sicar_clave ?: $sicar?->clave;
            $a->clave_sat = $a->clave_sat ?: ($sicar?->clave_prod_serv ?: null);
        }
        if (blank($a->clave_sat) && filled($p->sicar_clave_sat)) {
            $a->clave_sat = $p->sicar_clave_sat;
        }
        if (blank($a->unidad_sat)) {
            $a->unidad_sat = UnidadesSat::sugerir($a->unidad);
        }
        if ($a->isDirty()) {
            $a->save();
        }
    }

    /** El proveedor que se lo vendió en este pedido: su clave, link y el costo (si es el más reciente). */
    private static function anotarProveedor(Articulo $a, PedidoPartida $p, Carbon $fecha): void
    {
        $fila = ArticuloProveedor::firstOrNew(['articulo_id' => $a->id, 'proveedor_id' => $p->proveedor_id]);
        if (blank($fila->clave) && filled($p->modelo) && ! LinkCompra::esUrl((string) $p->modelo)) {
            $fila->clave = mb_substr(trim((string) $p->modelo), 0, 191);
        }
        if (blank($fila->link_compra) && filled($p->link_compra)) {
            $fila->link_compra = $p->link_compra;
        }
        $costo = (float) $p->costo;
        if ($costo > 0 && (! $fila->costo_fecha || $fecha->toDateString() >= $fila->costo_fecha->toDateString())) {
            $fila->ultimo_costo = $costo;
            $fila->costo_fecha = $fecha->toDateString();
            $fila->pedido_id = $p->pedido_id;
            $fila->lista_precio_id = null;   // ya es una compra real, no el precio de la lista
        }
        if ($fila->isDirty()) {
            $fila->save();
        }
    }

    private static function marca(?string $marca): ?string
    {
        $m = Nombres::limpiar((string) $marca);

        return $m !== '' ? mb_substr($m, 0, 120) : null;
    }

    private static function unidad(?string $unidad): string
    {
        $u = Nombres::limpiar((string) $unidad);

        return $u !== '' ? mb_substr($u, 0, 20) : 'PZA';
    }

    /**
     * Llenado inicial (y se puede repetir sin duplicar): los artículos activos de SICAR y todas las partidas de los
     * pedidos vigentes. Devuelve cuántos se crearon y cuántas partidas se ligaron.
     *
     * @return array{sicar: int, partidas: int}
     */
    public static function llenar(): array
    {
        $creados = 0;
        // Cada bloque en una transacción: miles de altas sin esperar al disco en cada una.
        SicarArticulo::where('status', 1)->orderBy('art_id')->chunkById(500, function ($arts) use (&$creados) {
            DB::transaction(function () use ($arts, &$creados) {
                $ya = Articulo::withTrashed()->whereIn('sicar_art_id', $arts->pluck('art_id'))->pluck('sicar_art_id')->flip();
                foreach ($arts as $s) {
                    if (isset($ya[$s->art_id]) || trim((string) $s->descripcion) === '') {
                        continue;
                    }
                    $mismo = self::porDescripcion($s->descripcion);
                    if ($mismo && ! $mismo->sicar_art_id) {
                        $mismo->update(['sicar_art_id' => $s->art_id, 'sicar_clave' => $s->clave, 'clave_sat' => $mismo->clave_sat ?: ($s->clave_prod_serv ?: null)]);

                        continue;
                    }
                    Articulo::create([
                        'descripcion' => Nombres::limpiar((string) $s->descripcion),
                        'unidad' => self::unidad($s->unidad),
                        'unidad_sat' => UnidadesSat::sugerir($s->unidad),
                        'clave_sat' => $s->clave_prod_serv ?: null,
                        'sicar_art_id' => $s->art_id,
                        'sicar_clave' => $s->clave,
                        'origen' => 'sicar',
                    ]);
                    $creados++;
                }
            });
        }, 'art_id');

        $ligadas = 0;
        PedidoPartida::whereNull('articulo_id')->whereHas('pedido', fn ($q) => $q->whereNull('cancelado_at'))
            ->with('pedido:id,created_at')->chunkById(300, function ($partidas) use (&$ligadas) {
                DB::transaction(function () use ($partidas, &$ligadas) {
                    foreach ($partidas as $p) {
                        if (self::ligarPartida($p)) {
                            $ligadas++;
                        }
                    }
                });
            });

        return ['sicar' => $creados, 'partidas' => $ligadas];
    }

    /**
     * Une dos artículos repetidos: todo lo del que se quita pasa al que se queda (partidas, proveedores, otros
     * nombres) y su descripción queda como otro nombre. Lo que le falte al que se queda se llena con el otro.
     */
    public static function unir(Articulo $queda, Articulo $quita): void
    {
        if ($queda->is($quita)) {
            return;
        }
        DB::transaction(function () use ($queda, $quita) {
            DB::table('pedido_partidas')->where('articulo_id', $quita->id)->update(['articulo_id' => $queda->id]);
            DB::table('cotizacion_partidas')->where('articulo_id', $quita->id)->update(['articulo_id' => $queda->id]);
            foreach ($quita->proveedores()->get() as $fila) {
                self::juntarProveedor($queda->id, $fila);
            }
            ArticuloNombre::where('articulo_id', $quita->id)->update(['articulo_id' => $queda->id]);
            if ($quita->descripcion_llave !== $queda->descripcion_llave
                && ! ArticuloNombre::where('articulo_id', $queda->id)->where('llave', $quita->descripcion_llave)->exists()) {
                ArticuloNombre::create(['articulo_id' => $queda->id, 'nombre' => $quita->descripcion]);
            }
            foreach (['marca', 'unidad_sat', 'clave_sat', 'codigo_barras', 'sicar_clave'] as $campo) {
                if (blank($queda->$campo) && filled($quita->$campo)) {
                    $queda->$campo = $quita->$campo;
                }
            }
            if ($quita->ultimo_pedido_at && (! $queda->ultimo_pedido_at || $quita->ultimo_pedido_at->gt($queda->ultimo_pedido_at))) {
                $queda->ultimo_pedido_at = $quita->ultimo_pedido_at;
            }
            if (! $queda->sicar_art_id && $quita->sicar_art_id) {
                $queda->sicar_art_id = $quita->sicar_art_id;
            }
            $queda->save();
            $quita->forceFill(['unido_a_id' => $queda->id, 'sicar_art_id' => null])->save();
            $quita->delete();
        });
    }

    /** Pasa un renglón de proveedor a otro artículo; si ya tenía a ese proveedor, se queda el costo más reciente. */
    private static function juntarProveedor(int $articuloId, ArticuloProveedor $fila): void
    {
        $ya = ArticuloProveedor::where('articulo_id', $articuloId)->where('proveedor_id', $fila->proveedor_id)->first();
        if (! $ya) {
            $fila->update(['articulo_id' => $articuloId]);

            return;
        }
        $masNuevo = $fila->costo_fecha && (! $ya->costo_fecha || $fila->costo_fecha->gt($ya->costo_fecha));
        $ya->fill([
            'clave' => $ya->clave ?: $fila->clave,
            'link_compra' => $ya->link_compra ?: $fila->link_compra,
        ] + ($masNuevo ? ['ultimo_costo' => $fila->ultimo_costo, 'costo_fecha' => $fila->costo_fecha, 'pedido_id' => $fila->pedido_id] : []))->save();
        $fila->delete();
    }

    /** Al unir dos proveedores repetidos (Duplicados): sus renglones de artículos pasan al que se queda. */
    public static function moverProveedor(int $deId, int $aId): void
    {
        foreach (ArticuloProveedor::where('proveedor_id', $deId)->get() as $fila) {
            $ya = ArticuloProveedor::where('articulo_id', $fila->articulo_id)->where('proveedor_id', $aId)->first();
            if (! $ya) {
                $fila->update(['proveedor_id' => $aId]);

                continue;
            }
            $tmp = new ArticuloProveedor($fila->only(['clave', 'link_compra', 'ultimo_costo', 'costo_fecha', 'pedido_id']));
            $tmp->proveedor_id = $aId;
            $fila->delete();
            self::juntarProveedor($fila->articulo_id, $tmp->forceFill(['articulo_id' => $fila->articulo_id]));
        }
    }
}

<?php

namespace App\Support;

use App\Enums\EstadoLoteSicar;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\SicarArticulo;
use App\Models\SicarLote;
use App\Models\SicarProveedor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Puente SICAR (lado web): valida y arma el snapshot `datos` de un lote (contrato con el programa de la PC).
 * Costo y precio salen de pedido_partidas.costo / .precio, que ya se guardan SIN IVA por unidad.
 */
class PuenteSicar
{
    /**
     * Motivos por los que NO se puede mandar todavía.
     *
     * @param  Collection<int, Pedido>  $pedidos  los marcados, con partidas.proveedor y cliente
     * @param  Collection<int, array<string, mixed>>  $filas  salida de AltaSicar::filas() de esos pedidos
     * @return list<string>
     */
    public function bloqueos(Collection $pedidos, Collection $filas): array
    {
        $b = [];
        if ($pedidos->isEmpty()) {
            return ['Marca al menos una tarjeta.'];
        }
        if ($filas->isEmpty()) {
            return ['Las tarjetas marcadas no tienen partidas.'];
        }
        foreach ($pedidos->filter(fn ($p) => $p->cancelado_at) as $p) {
            $b[] = 'P-'.$p->folio.' está cancelado por el cliente: quítalo de la selección.';
        }

        $enLote = $this->pedidosEnLoteAbierto($pedidos->pluck('id')->all());
        foreach ($pedidos->whereIn('id', array_keys($enLote)) as $p) {
            $b[] = 'P-'.$p->folio.' ya está en el lote '.$enLote[$p->id].' (abierto). Espera a que termine o cancélalo en Lotes SICAR.';
        }

        $posibles = $filas->where('estado', 'posible')->count();
        if ($posibles > 0) {
            $b[] = $posibles === 1 ? 'Hay 1 partida 🟡 por decidir: elige "Es este" o "Es nuevo".'
                : "Hay {$posibles} partidas 🟡 por decidir: elige \"Es este\" o \"Es nuevo\" en cada una.";
        }

        $sinProveedor = $filas->filter(fn ($f) => ! $f['partida']->proveedor)->count();
        if ($sinProveedor > 0) {
            $b[] = $sinProveedor === 1 ? 'Hay 1 partida sin proveedor: asígnalo en la tarjeta o en la cotización.'
                : "Hay {$sinProveedor} partidas sin proveedor: asígnalo en la tarjeta.";
        }
        $sinMapeo = $this->proveedoresSinSicar($filas);
        if ($sinMapeo->isNotEmpty()) {
            $b[] = 'Falta decir a qué proveedor de SICAR corresponde: '.$sinMapeo->pluck('nombre')->implode(', ').'.';
        }

        return $b;
    }

    /**
     * Proveedores de las partidas que aún no tienen proveedor de SICAR.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return Collection<int, Proveedor>
     */
    public function proveedoresSinSicar(Collection $filas): Collection
    {
        return $filas->map(fn ($f) => $f['partida']->proveedor)->filter()
            ->filter(fn ($p) => ! $p->sicar_pro_id)->unique('id')->sortBy('nombre')->values();
    }

    /**
     * Proveedor de SICAR sugerido para un proveedor de la web: nombres iguales o uno contiene al otro (por llave).
     *
     * @param  Collection<int, SicarProveedor>  $catalogo
     */
    public static function sugerido(Proveedor $proveedor, Collection $catalogo): ?SicarProveedor
    {
        $llave = Nombres::llave($proveedor->nombre);
        if ($llave === '') {
            return null;
        }
        $llaves = $catalogo->mapWithKeys(fn ($s) => [$s->pro_id => Nombres::llave($s->nombre)]);

        $id = $llaves->search(fn ($l) => $l === $llave)
            ?: $llaves->search(fn ($l) => $l !== '' && (str_contains($l, $llave) || str_contains($llave, $l)));

        return $id ? $catalogo->firstWhere('pro_id', $id) : null;
    }

    /**
     * Pedidos (de la lista) que están en un lote abierto → [pedido_id => lote_id].
     *
     * @param  list<int>  $pedidoIds
     * @return array<int, int>
     */
    public function pedidosEnLoteAbierto(array $pedidoIds): array
    {
        if ($pedidoIds === []) {
            return [];
        }
        $out = [];
        SicarLote::abiertos()->whereHas('pedidos', fn ($q) => $q->whereIn('pedidos.id', $pedidoIds))
            ->with(['pedidos' => fn ($q) => $q->whereIn('pedidos.id', $pedidoIds)])->get()
            ->each(function ($l) use (&$out) {
                foreach ($l->pedidos as $p) {
                    $out[$p->id] = $l->id;
                }
            });

        return $out;
    }

    /**
     * Arma `datos` (contrato con la PC). Solo llamar cuando `bloqueos()` está vacío.
     *
     * @param  Collection<int, Pedido>  $pedidos
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    public function armarDatos(Collection $pedidos, Collection $filas, AltaSicar $alta): array
    {
        $articulos = [];   // llave de clave → artículo nuevo
        $clavesSat = [];   // art_id → renglón
        $compras = [];     // sicar_pro_id → compra

        foreach ($filas as $f) {
            $p = $f['partida'];
            $pedido = $f['pedido'];
            $nuevo = $f['estado'] === 'nuevo';
            $art = $f['articulo'];
            $clave = (string) $f['clave'];
            $cantidad = (float) $p->cantidad;
            $costo = (float) $p->costo;
            $proId = (int) $p->proveedor->sicar_pro_id;
            $folio = 'P-'.$pedido->folio;

            if ($nuevo) {
                $llave = SicarArticulo::llave($clave);
                $a = $articulos[$llave] ?? [
                    'clave' => $clave,
                    'descripcion' => Str::upper(Str::squish((string) $p->descripcion)),
                    'unidad' => (string) $f['unidad'],
                    'categoria' => (string) $f['categoria'],
                    'clave_sat' => (string) $f['sat'],
                    'costo' => 0.0, 'precio' => round((float) $p->precio, 2),
                    'sicar_pro_id' => $proId,
                    'modelo' => (string) ($p->modelo ?? ''),
                    'partida_ids' => [], '_cant' => 0.0, '_imp' => 0.0,
                ];
                $a['partida_ids'][] = $p->id;
                $a['_cant'] += $cantidad;
                $a['_imp'] += $cantidad * $costo;
                $articulos[$llave] = $a;
            } elseif ($art) {
                // Clave SAT de un artículo que ya existe: la que sugiere la IA cuando la de SICAR falta o está mal
                // (Daniel lo aprobó el 29 sep). `anterior` deja ver el cambio y la PC no pisa una clave que alguien
                // haya cambiado en SICAR después.
                $actual = trim((string) $art->clave_prod_serv);
                $sat = trim((string) ($f['sat_sugerida'] ?? '')) ?: ($actual === '' ? trim((string) $p->sicar_clave_sat) : '');
                if ($sat !== '' && $sat !== $actual) {
                    $clavesSat[$art->art_id] ??= ['art_id' => (int) $art->art_id, 'clave' => (string) $art->clave, 'clave_sat' => $sat, 'anterior' => $actual];
                }
            }

            $c = $compras[$proId] ??= ['sicar_pro_id' => $proId, 'proveedor' => (string) $p->proveedor->nombre, 'renglones' => []];
            $llaveR = $nuevo ? 'n:'.SicarArticulo::llave($clave) : 'e:'.$art->art_id;
            $r = $c['renglones'][$llaveR] ?? [
                'clave' => $nuevo ? $clave : (string) $art->clave,
                'art_id' => $nuevo ? null : (int) $art->art_id,
                'descripcion' => $nuevo ? Str::upper(Str::squish((string) $p->descripcion)) : (string) $art->descripcion,
                'cantidad' => 0.0, 'costo' => 0.0, 'pedidos' => [], '_imp' => 0.0,
            ];
            $r['cantidad'] += $cantidad;
            $r['_imp'] += $cantidad * $costo;
            if (! in_array($folio, $r['pedidos'], true)) {
                $r['pedidos'][] = $folio;
            }
            $c['renglones'][$llaveR] = $r;
            $compras[$proId] = $c;
        }

        $articulos = array_map(function ($a) {
            $a['costo'] = $a['_cant'] > 0 ? round($a['_imp'] / $a['_cant'], 4) : 0.0;
            unset($a['_cant'], $a['_imp']);

            return $a;
        }, array_values($articulos));

        $compras = array_map(function ($c) {
            $c['renglones'] = array_map(function ($r) {
                $r['costo'] = $r['cantidad'] > 0 ? round($r['_imp'] / $r['cantidad'], 4) : 0.0;
                $r['cantidad'] = round($r['cantidad'], 3);
                unset($r['_imp']);

                return $r;
            }, array_values($c['renglones']));

            return $c;
        }, array_values($compras));

        return [
            'pedidos' => $pedidos->map(fn ($p) => [
                'id' => $p->id, 'folio' => 'P-'.$p->folio, 'cliente' => (string) $p->cliente?->nombre, 'oc' => $p->numero_oc_cliente,
            ])->values()->all(),
            'articulos' => $articulos,
            'claves_sat' => array_values($clavesSat),
            'compras' => $compras,
        ];
    }

    /**
     * Crea el lote y liga sus pedidos.
     *
     * @param  array<string, mixed>  $datos
     */
    public function crearLote(array $datos, int $userId, array $firmas = []): SicarLote
    {
        return DB::transaction(function () use ($datos, $userId, $firmas) {
            // Se bloquean los pedidos y se vuelve a revisar adentro: dos personas mandando el mismo pedido al mismo
            // tiempo no lo meten en dos lotes abiertos.
            $ids = collect($datos['pedidos'])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            Pedido::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            if ($enLote = $this->pedidosEnLoteAbierto($ids)) {
                throw new \DomainException('Otro lote abierto ya tiene '.count($enLote).' de estos pedidos. Recarga la página.');
            }
            // `firmas` (partida → cómo iba al mandarse) se guarda aparte: `datos` es el contrato con la PC y no cambia.
            $lote = SicarLote::create(['estado' => EstadoLoteSicar::Pendiente, 'datos' => $datos, 'firmas' => $firmas ?: null, 'creado_por' => $userId]);
            $lote->pedidos()->attach(collect($datos['pedidos'])->pluck('id')->all());

            return $lote;
        });
    }
}

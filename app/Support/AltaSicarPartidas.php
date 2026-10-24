<?php

namespace App\Support;

use App\Enums\EstadoLoteSicar;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\SicarArticulo;
use App\Models\SicarLote;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Alta en SICAR partida por partida. Cada partida guarda cuándo y en qué lote se dio de alta y su "firma" (descripción,
 * modelo, proveedor y cantidad) de ese momento. En un pedido ya dado de alta, una partida sin marca (se agregó después)
 * o con otra firma (se cambió el producto o la cantidad) "falta en SICAR": se avisa en la tarjeta y se puede mandar
 * sola desde Alta en SICAR. Pasó en P-176: la reducción se cambió por un pasador después del alta.
 */
final class AltaSicarPartidas
{
    public static function firma(PedidoPartida $p): string
    {
        $texto = fn ($v) => Str::upper(Str::squish((string) $v));

        return sha1(json_encode([$texto($p->descripcion), $texto($p->modelo), (int) $p->proveedor_id,
            number_format((float) $p->cantidad, 3, '.', '')], JSON_UNESCAPED_UNICODE));
    }

    /** ¿Esta partida de un pedido ya dado de alta falta en SICAR? */
    public static function falta(PedidoPartida $p, ?Pedido $pedido = null): bool
    {
        $pedido ??= $p->pedido;
        if (! $pedido?->alta_sicar) {
            return false;
        }

        return $p->sicar_alta_at === null || $p->sicar_alta_firma !== self::firma($p);
    }

    /** Estado de la partida para la tarjeta: 'en_sicar', 'falta' (pedido ya dado de alta) o 'sin_alta' (pedido sin alta). */
    public static function estado(PedidoPartida $p, Pedido $pedido): string
    {
        if (! $pedido->alta_sicar) {
            return 'sin_alta';
        }

        return self::falta($p, $pedido) ? 'falta' : 'en_sicar';
    }

    /** @return Collection<int, PedidoPartida> partidas del pedido que faltan en SICAR */
    public static function faltantes(Pedido $pedido): Collection
    {
        if (! $pedido->alta_sicar) {
            return collect();
        }
        $partidas = $pedido->relationLoaded('partidas') ? $pedido->partidas : $pedido->partidas()->get();

        return $partidas->filter(fn (PedidoPartida $p) => self::falta($p, $pedido))->values();
    }

    /** @param iterable<PedidoPartida> $partidas  se marcan como dadas de alta tal como están ahora */
    public static function marcar(iterable $partidas, ?int $loteId = null): void
    {
        foreach ($partidas as $p) {
            $p->forceFill(['sicar_alta_at' => now(), 'sicar_alta_lote_id' => $loteId, 'sicar_alta_firma' => self::firma($p)])->saveQuietly();
        }
    }

    /** Firmas de las partidas que van en un lote, tomadas al mandarlo (si cambian mientras tanto, siguen faltando). */
    public static function firmasDe(Collection $filas): array
    {
        return $filas->mapWithKeys(fn ($f) => [(string) $f['partida']->id => self::firma($f['partida'])])->all();
    }

    /** Al guardarse el lote en SICAR: marca sus partidas con la firma que tenían al mandarse. */
    public static function marcarDeLote(SicarLote $lote): void
    {
        $firmas = (array) ($lote->firmas ?? []);
        if ($firmas === []) {
            self::marcarPorRenglones($lote);   // lote mandado antes de existir las firmas

            return;
        }
        $pedidoIds = $lote->pedidos()->pluck('pedidos.id');
        PedidoPartida::whereIn('id', array_map('intval', array_keys($firmas)))->whereIn('pedido_id', $pedidoIds)->get()
            ->each(fn (PedidoPartida $p) => $p->forceFill(['sicar_alta_at' => $lote->aplicado_at ?? now(),
                'sicar_alta_lote_id' => $lote->id, 'sicar_alta_firma' => $firmas[(string) $p->id]])->saveQuietly());
    }

    /**
     * Lotes viejos (sin firmas): una partida se dio de alta si su artículo (el de SICAR o la clave nueva) aparece en una
     * compra del lote con el folio de su pedido. Lo que no aparece (p. ej. la partida se cambió después) queda faltando.
     */
    public static function marcarPorRenglones(SicarLote $lote, ?AltaSicar $alta = null, ?int $soloPedidoId = null): void
    {
        $renglones = collect((array) ($lote->datos['compras'] ?? []))->flatMap(fn ($c) => (array) ($c['renglones'] ?? []));
        $nuevas = collect((array) ($lote->datos['articulos'] ?? []))->flatMap(fn ($a) => (array) ($a['partida_ids'] ?? []))->map(fn ($i) => (int) $i);
        $pedidos = $lote->pedidos()->when($soloPedidoId, fn ($q) => $q->whereKey($soloPedidoId))
            ->with(['cliente:id,nombre', 'partidas.proveedor:id,nombre,sicar_pro_id'])->get();
        foreach (($alta ?? new AltaSicar)->filas($pedidos) as $f) {
            $p = $f['partida'];
            $folio = 'P-'.$f['pedido']->folio;
            $enviada = $nuevas->contains($p->id) || $renglones->contains(function ($r) use ($f, $folio) {
                if (! in_array($folio, (array) ($r['pedidos'] ?? []), true)) {
                    return false;
                }
                if ($f['articulo'] && (int) ($r['art_id'] ?? 0) === (int) $f['articulo']->art_id) {
                    return true;
                }
                $clave = $f['articulo']->clave ?? $f['clave'] ?? $f['partida']->sicar_clave;

                return $clave && SicarArticulo::llave((string) $clave) === SicarArticulo::llave((string) ($r['clave'] ?? ''));
            });
            if ($enviada && $p->sicar_alta_at === null) {
                $p->forceFill(['sicar_alta_at' => $lote->aplicado_at ?? now(), 'sicar_alta_lote_id' => $lote->id,
                    'sicar_alta_firma' => self::firma($p)])->saveQuietly();
            }
        }
    }

    /** Único pedido pasado que se revisa contra su lote al publicar (decisión de Daniel, 24-oct-2026). */
    public const REVISAR_FOLIO = 176;

    /**
     * Respaldo al publicar. Daniel pidió NO revisar los pedidos pasados salvo el P-176: en todos los ya dados de alta,
     * las partidas que tienen hoy quedan como dadas de alta tal cual; solo el P-176 se compara con los renglones de su
     * lote (ahí el pasador que sustituyó a la reducción queda faltando).
     */
    public static function respaldar(): void
    {
        $revisar = Pedido::where('folio', self::REVISAR_FOLIO)->where('alta_sicar', true)->first();
        if ($revisar) {
            $alta = new AltaSicar;
            $revisar->lotesSicar()->where('estado', EstadoLoteSicar::Aplicado->value)->orderBy('sicar_lotes.id')->get()
                ->each(fn (SicarLote $l) => self::marcarPorRenglones($l, $alta, $revisar->id));
        }

        Pedido::where('alta_sicar', true)->when($revisar, fn ($q) => $q->whereKeyNot($revisar->id))
            ->with('partidas')->chunkById(200, function ($pedidos) {
                foreach ($pedidos as $pedido) {
                    self::marcar($pedido->partidas->whereNull('sicar_alta_at'));
                }
            });
    }
}

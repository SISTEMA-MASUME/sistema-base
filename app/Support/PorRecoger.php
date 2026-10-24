<?php

namespace App\Support;

use App\Enums\EstadoPartidaPedido;
use App\Models\PedidoActividad;
use App\Models\PedidoPartida;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Por recoger": lo que ya se pidió al proveedor y todavía no se recoge completo (incluye lo que el proveedor entregó a
 * medias), de pedidos vigentes. Sirve para que quien recoge arme su ruta por proveedor.
 */
final class PorRecoger
{
    private static function base(): Builder
    {
        return PedidoPartida::query()
            ->where('estado', EstadoPartidaPedido::Solicitada->value)
            ->whereHas('pedido', fn ($q) => $q->whereNull('cancelado_at')->whereNull('archivado_at'));
    }

    /** Partidas recogidas a medias (el globito del menú). */
    public static function parciales(): int
    {
        return self::base()->where('cantidad_recogida', '>', 0)->count();
    }

    /**
     * Agrupadas por proveedor: primero los que tienen algo a medias, luego por nombre.
     *
     * @return Collection<int, array{proveedor: string, partidas: Collection<int, PedidoPartida>, parciales: int}>
     */
    public static function porProveedor(?string $buscar = null, bool $soloParciales = false): Collection
    {
        $q = self::base()
            ->with(['pedido:id,folio,cliente_id,numero_oc_cliente', 'pedido.cliente:id,nombre', 'proveedor:id,nombre,contacto,telefono,correo', 'proveedor.contactos', 'ordenProveedor:id,folio'])
            ->when($soloParciales, fn ($q) => $q->where('cantidad_recogida', '>', 0));
        $b = trim((string) $buscar);
        if ($b !== '') {
            $like = '%'.addcslashes($b, '%_\\').'%';
            $q->where(fn ($w) => $w->where('descripcion', 'like', $like)->orWhere('modelo', 'like', $like)
                ->orWhereHas('proveedor', fn ($p) => $p->where('nombre', 'like', $like))
                ->orWhereHas('pedido.cliente', fn ($c) => $c->where('nombre', 'like', $like)));
        }

        return $q->orderBy('pedido_id')->orderBy('orden')->limit(500)->get()
            ->groupBy(fn (PedidoPartida $p) => $p->proveedor->nombre ?? 'Sin proveedor')
            ->map(fn (Collection $ps, string $nombre) => [
                'proveedor' => $nombre,
                // Dentro de cada proveedor: lo que se recogió a medias primero, luego la fecha estimada más próxima.
                'partidas' => $ps->sortBy([
                    fn ($a, $b) => (int) $b->recogidaParcial() <=> (int) $a->recogidaParcial(),
                    fn ($a, $b) => ($a->fecha_entrega?->timestamp ?? PHP_INT_MAX) <=> ($b->fecha_entrega?->timestamp ?? PHP_INT_MAX),
                ])->values(),
                'parciales' => $ps->filter(fn ($p) => $p->recogidaParcial())->count(),
            ])
            ->sortBy([fn ($a, $b) => $b['parciales'] <=> $a['parciales'], fn ($a, $b) => strcmp($a['proveedor'], $b['proveedor'])])
            ->values();
    }

    /**
     * Anota lo recogido de una partida (ya bloqueada con lockForUpdate) y devuelve el aviso para la pantalla. Lo usan la
     * tarjeta del pedido ("¿Cuántas recogiste?") y la pantalla Por recoger: completa pasa a Recogida; a medias, se queda
     * "Pedida al proveedor" con lo recogido.
     */
    public static function anotar(PedidoPartida $p, float $c): string
    {
        $falta = $p->faltaRecoger();
        $nombre = '"'.Str::limit($p->descripcion, 60).'"';
        if ($falta <= 0) {
            return $nombre.' ya estaba recogida completa.';
        }
        $c = min($c, $falta);
        $total = round($p->recogido() + $c, 3);
        $cant = (float) $p->cantidad;
        $u = ' '.mb_strtolower((string) $p->unidad);
        if ($total >= $cant) {
            $p->update(['estado' => EstadoPartidaPedido::Recibida, 'cantidad_recogida' => $cant]);
            PedidoActividad::registrar($p->pedido_id, 'recogida', 'recogió '.self::cantidad($c).$u.' de '.$nombre.': ya está completa ('
                .self::cantidad($cant).' de '.self::cantidad($cant).')');

            return $nombre.' quedó recogida completa.';
        }
        $p->update([
            'estado' => $p->estado->nivel() < EstadoPartidaPedido::Solicitada->nivel() ? EstadoPartidaPedido::Solicitada : $p->estado,
            'cantidad_recogida' => $total,
        ]);
        $faltan = self::cantidad($cant - $total).$u;
        PedidoActividad::registrar($p->pedido_id, 'recogida', 'recogió '.self::cantidad($c).$u.' de '.$nombre.' (lleva '
            .self::cantidad($total).' de '.self::cantidad($cant).'; faltan '.$faltan.')');

        return 'De '.$nombre.' faltan '.$faltan.' por recoger.';
    }

    /** 3 → "3", 2.5 → "2.5" (sin ceros de más). */
    public static function cantidad(mixed $c): string
    {
        return rtrim(rtrim(number_format((float) $c, 3, '.', ','), '0'), '.');
    }
}

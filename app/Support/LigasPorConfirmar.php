<?php

namespace App\Support;

use App\Models\Cliente;
use App\Models\ClienteRazonSocial;
use App\Models\LigaRechazada;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\Venta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Candado de razón social (poka-yoke) para ligar facturas a pedidos por la OC del comentario de SICAR.
 * La factura sale a la RAZÓN SOCIAL ("HOTELES XY") y el pedido está con el nombre comercial ("HOTEL AURORA EJECUTIVO"):
 * solo se liga sola si esa razón social ya está registrada como del cliente del pedido. Si no, queda una PROPUESTA
 * (`pedido_venta.por_confirmar`) y Daniel contesta una vez "¿HOTELES XY factura por HOTEL AURORA EJECUTIVO?":
 * Sí → se registra la razón social (y las siguientes facturas ligan solas); No → se quita y no se vuelve a proponer.
 * El PAR es (cliente del pedido, razón social); una razón social puede serlo de varios clientes.
 */
final class LigasPorConfirmar
{
    /** ¿La razón social de esta venta ya es del cliente? Por nombre/razón social/RFC del cliente o por una razón registrada. */
    public static function razonRegistrada(?int $clienteId, Venta $venta): bool
    {
        $llave = Nombres::llave((string) $venta->cliente_nombre);
        if (! $clienteId || $llave === '') {
            return false;
        }
        $rfc = self::rfcDeVenta($venta);
        $cliente = Cliente::withTrashed()->find($clienteId, ['id', 'nombre_llave', 'razon_social', 'rfc']);
        if ($cliente) {
            if ($cliente->nombre_llave === $llave || ($cliente->razon_social && Nombres::llave($cliente->razon_social) === $llave)) {
                return true;
            }
            if ($rfc && $cliente->rfc && mb_strtoupper($cliente->rfc) === $rfc) {
                return true;
            }
        }

        return ClienteRazonSocial::where('cliente_id', $clienteId)
            ->where(fn ($q) => $q->where('nombre_llave', $llave)->when($rfc, fn ($q) => $q->orWhere('rfc', $rfc)))
            ->exists();
    }

    public static function rechazada(int $clienteId, string $llave): bool
    {
        return LigaRechazada::where('cliente_id', $clienteId)->where('nombre_llave', $llave)->exists();
    }

    private static function rfcDeVenta(Venta $venta): ?string
    {
        $rfc = $venta->cliente_id ? Cliente::withTrashed()->whereKey($venta->cliente_id)->value('rfc') : null;

        return filled($rfc) ? mb_strtoupper(trim($rfc)) : null;
    }

    /** Cuántas facturas esperan confirmación (para el aviso). */
    public static function cuantas(): int
    {
        return DB::table('pedido_venta')->where('por_confirmar', true)->count();
    }

    /**
     * Propuestas agrupadas por par (cliente del pedido, razón social).
     *
     * @return Collection<int, array{clave: string, cliente_id: int, cliente: string, razon: string, llave: string, rfc: ?string, items: Collection<int, array{liga_id: int, venta: Venta, pedido: Pedido}>}>
     */
    public static function grupos(): Collection
    {
        $ligas = DB::table('pedido_venta')->where('por_confirmar', true)->orderBy('id')->get();
        if ($ligas->isEmpty()) {
            return collect();
        }
        $ventas = Venta::with('cliente:id,rfc')->whereIn('id', $ligas->pluck('venta_id'))->get()->keyBy('id');
        $pedidos = Pedido::with('cliente:id,nombre')->whereIn('id', $ligas->pluck('pedido_id'))->get()->keyBy('id');

        return $ligas->map(function ($l) use ($ventas, $pedidos) {
            $v = $ventas[$l->venta_id] ?? null;
            $p = $pedidos[$l->pedido_id] ?? null;

            return $v && $p && $p->cliente_id ? ['liga_id' => $l->id, 'venta' => $v, 'pedido' => $p] : null;
        })->filter()->groupBy(fn ($i) => $i['pedido']->cliente_id.'|'.Nombres::llave((string) $i['venta']->cliente_nombre))
            ->map(function (Collection $items, string $clave) {
                $v = $items->first()['venta'];

                return [
                    'clave' => $clave,
                    'cliente_id' => (int) $items->first()['pedido']->cliente_id,
                    'cliente' => (string) $items->first()['pedido']->cliente?->nombre,
                    'razon' => Nombres::limpiar((string) $v->cliente_nombre),
                    'llave' => Nombres::llave((string) $v->cliente_nombre),
                    'rfc' => self::rfcDeVenta($v),
                    'items' => $items->values(),
                ];
            })->values();
    }

    /** Propuestas de un pedido (para su tarjeta). */
    public static function delPedido(Pedido $pedido): Collection
    {
        return $pedido->ventasPorConfirmar()->orderBy('ventas.fecha')->orderBy('ventas.id')->get();
    }

    /** El texto de la pregunta. */
    public static function pregunta(string $razon, string $cliente): string
    {
        return '¿'.$razon.' factura por '.$cliente.'?';
    }

    /**
     * Dijo "Sí" a la propuesta de ESTA liga: registra la razón social en el cliente del pedido y confirma esa liga y todas las
     * pendientes del mismo par. Regresa cuántas confirmó.
     */
    public static function confirmar(int $pedidoId, int $ventaId, ?int $userId = null): int
    {
        $pedido = Pedido::find($pedidoId);
        $venta = Venta::with('cliente:id,rfc')->find($ventaId);
        if (! $pedido || ! $venta || ! $pedido->cliente_id
            || ! DB::table('pedido_venta')->where('pedido_id', $pedidoId)->where('venta_id', $ventaId)->where('por_confirmar', true)->exists()) {
            return 0;
        }

        return self::registrarRazon(Cliente::findOrFail($pedido->cliente_id), (string) $venta->cliente_nombre, self::rfcDeVenta($venta), $userId);
    }

    /**
     * Registra la razón social como de ese cliente (desde "Sí" o a mano en su ficha), quita el "No" de ese par si lo había y
     * confirma las propuestas pendientes de ese par. Regresa cuántas ligas confirmó.
     */
    public static function registrarRazon(Cliente $cliente, string $nombre, ?string $rfc = null, ?int $userId = null): int
    {
        $nombre = Nombres::limpiar($nombre);
        $llave = Nombres::llave($nombre);
        $rfc = filled($rfc) ? mb_strtoupper(trim($rfc)) : null;
        if ($llave === '') {
            return 0;
        }

        return DB::transaction(function () use ($cliente, $nombre, $llave, $rfc, $userId) {
            $razon = ClienteRazonSocial::firstOrNew(['cliente_id' => $cliente->id, 'nombre_llave' => $llave]);
            $razon->nombre = $nombre;
            if ($rfc && blank($razon->rfc)) {
                $razon->rfc = $rfc;
            }
            $razon->confirmada_por = $razon->confirmada_por ?: $userId;
            $razon->save();
            LigaRechazada::where('cliente_id', $cliente->id)->where('nombre_llave', $llave)->delete();

            $n = 0;
            foreach (self::pendientesDelPar($cliente->id, $llave, $razon->rfc) as [$liga, $pedido, $venta]) {
                DB::table('pedido_venta')->where('id', $liga->id)->update(['por_confirmar' => false, 'user_id' => $userId]);
                $venta->anotar('pedido', null, 'P-'.$pedido->folio);
                PedidoActividad::registrar($pedido, 'factura', ($venta->tipo === 'nota' ? 'Nota ' : 'Factura ').$venta->folio
                    .' ligada: se confirmó que '.$razon->nombre.' factura por '.$cliente->nombre
                    .' (la OC '.$pedido->numero_oc_cliente.' viene en su comentario de SICAR).');
                $n++;
            }

            return $n;
        });
    }

    /** Dijo "No": quita las propuestas de ese par y no se vuelve a proponer. Regresa cuántas quitó. */
    public static function rechazar(int $pedidoId, int $ventaId, ?int $userId = null): int
    {
        $pedido = Pedido::find($pedidoId);
        $venta = Venta::with('cliente:id,rfc')->find($ventaId);
        if (! $pedido || ! $venta || ! $pedido->cliente_id
            || ! DB::table('pedido_venta')->where('pedido_id', $pedidoId)->where('venta_id', $ventaId)->where('por_confirmar', true)->exists()) {
            return 0;
        }
        $llave = Nombres::llave((string) $venta->cliente_nombre);

        return DB::transaction(function () use ($pedido, $venta, $llave, $userId) {
            LigaRechazada::firstOrCreate(['cliente_id' => $pedido->cliente_id, 'nombre_llave' => $llave], ['user_id' => $userId]);
            $n = 0;
            foreach (self::pendientesDelPar($pedido->cliente_id, $llave, self::rfcDeVenta($venta)) as [$liga, $p, $v]) {
                DB::table('pedido_venta')->where('id', $liga->id)->delete();
                PedidoActividad::registrar($p, 'factura', ($v->tipo === 'nota' ? 'Nota ' : 'Factura ').$v->folio
                    .' no se liga: se indicó que '.Nombres::limpiar((string) $venta->cliente_nombre).' no factura por '.($p->cliente?->nombre ?? 'este cliente').'.');
                $n++;
            }

            return $n;
        });
    }

    /** @return list<array{0: object, 1: Pedido, 2: Venta}> */
    private static function pendientesDelPar(int $clienteId, string $llave, ?string $rfc): array
    {
        $salida = [];
        $ligas = DB::table('pedido_venta')->join('pedidos', 'pedidos.id', '=', 'pedido_venta.pedido_id')
            ->where('pedido_venta.por_confirmar', true)->where('pedidos.cliente_id', $clienteId)
            ->get(['pedido_venta.id', 'pedido_venta.pedido_id', 'pedido_venta.venta_id']);
        foreach ($ligas as $l) {
            $venta = Venta::with('cliente:id,rfc')->find($l->venta_id);
            $pedido = Pedido::with('cliente:id,nombre')->find($l->pedido_id);
            if (! $venta || ! $pedido) {
                continue;
            }
            $mismo = Nombres::llave((string) $venta->cliente_nombre) === $llave || ($rfc && self::rfcDeVenta($venta) === $rfc);
            if ($mismo) {
                $salida[] = [$l, $pedido, $venta];
            }
        }

        return $salida;
    }
}

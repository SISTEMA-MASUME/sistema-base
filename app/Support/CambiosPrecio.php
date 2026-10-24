<?php

namespace App\Support;

use App\Models\Ajuste;
use App\Models\CambioPrecio;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cambios de precio de venta al dar de alta un pedido: el vendedor los pide y el admin los autoriza (o los hace directo).
 * Utilidad de una partida = (precio − costo) × cantidad; sin costo se toma 0.
 */
class CambiosPrecio
{
    public const MIN_MOTIVO = 10;

    /**
     * Cambios de precio menores a esto (en pesos, por pieza, hacia arriba o hacia abajo) se aplican sin motivo, sin
     * autorización y sin aviso a Daniel (decisión de Daniel, 9 oct 2026: tornillos que cambian centavos). Quedan anotados.
     */
    public const TOLERANCIA = 10.0;

    public const MOTIVO_MENOR = 'Ajuste menor (menos de $10 por pieza)';

    public static function esMenor(float $anterior, float $nuevo): bool
    {
        return abs(round($nuevo - $anterior, 2)) < self::TOLERANCIA;
    }

    public static function utilidad(float $precio, ?float $costo, float $cantidad): float
    {
        return round(($precio - (float) $costo) * $cantidad, 2);
    }

    /** Utilidad de todas las partidas del pedido como están hoy. */
    public static function utilidadPedido(Pedido $pedido): float
    {
        $u = 0.0;
        foreach ($pedido->partidas()->get(['cantidad', 'precio', 'costo']) as $p) {
            $u += ((float) $p->precio - (float) $p->costo) * (float) $p->cantidad;
        }

        return round($u, 2);
    }

    public static function pendientes(): int
    {
        return CambioPrecio::where('estatus', 'pendiente')->count();
    }

    public static function dinero(float|string|null $n): string
    {
        return '$'.number_format((float) $n, 2);
    }

    /**
    /** Interruptor del admin (Ajuste). Apagado (por ahora, decisión de Daniel 2 oct): el vendedor cambia el precio directo con
     * motivo y avisa por WhatsApp; encendido: necesita la autorización de Daniel. */
    public const REQUIERE_AUTORIZACION = 'precios_requieren_autorizacion';

    public static function requiereAutorizacion(): bool
    {
        return Ajuste::obtener(self::REQUIERE_AUTORIZACION, '0') === '1';
    }

    /**
     * Registra el cambio pedido en el paso 2. Admin o cambio menor a $10: se aplica de una vez ("directo"). Vendedor: según
     * el interruptor, directo con aviso por WhatsApp o pendiente (la partida conserva el precio cotizado).
     *
     * @param  array{precio: float, motivo: string, costo?: ?float, proveedor_id?: ?int}  $d
     */
    public static function registrar(Pedido $pedido, PedidoPartida $partida, array $d, User $usuario): CambioPrecio
    {
        $costoAnt = $partida->costo !== null ? (float) $partida->costo : null;
        $menor = self::esMenor((float) $partida->precio, round((float) $d['precio'], 2));
        $motivo = trim((string) $d['motivo']);
        if ($motivo === '' && $menor) {
            $motivo = self::MOTIVO_MENOR;
        }
        $cambio = CambioPrecio::create([
            'pedido_id' => $pedido->id,
            'pedido_partida_id' => $partida->id,
            'cotizacion_partida_id' => $partida->cotizacion_partida_id,
            'descripcion' => mb_substr((string) $partida->descripcion, 0, 2000),
            'cantidad' => (float) $partida->cantidad,
            'precio_anterior' => (float) $partida->precio,
            'precio_solicitado' => round((float) $d['precio'], 2),
            'costo_anterior' => $costoAnt,
            'costo_nuevo' => $d['costo'] ?? null,
            'proveedor_id' => $d['proveedor_id'] ?? null,
            'motivo' => $motivo,
            'estatus' => 'pendiente',
            'solicitado_por' => $usuario->id,
            'utilidad_antes' => self::utilidad((float) $partida->precio, $costoAnt, (float) $partida->cantidad),
            'utilidad_despues' => self::utilidad(round((float) $d['precio'], 2), $d['costo'] ?? $costoAnt, (float) $partida->cantidad),
        ]);
        $nombre = mb_substr((string) $partida->descripcion, 0, 60);

        if ($menor) {
            // Cambio chico: se aplica y queda anotado, sin pedirle nada a Daniel ni avisarle.
            self::aplicar($cambio, $partida, round((float) $d['precio'], 2), $d['costo'] ?? null, $d['proveedor_id'] ?? null, 'directo', null);
            $cambio->update(['avisado_at' => now()]);
            PedidoActividad::registrar($pedido, 'precio_cambiado', 'ajustó el precio de "'.$nombre.'" de '.self::dinero($cambio->precio_anterior)
                .' a '.self::dinero($cambio->precio_final).' (cambio menor a $10 por pieza'.($motivo !== self::MOTIVO_MENOR ? ': '.$motivo : '').'; no requiere aviso)');
        } elseif ($usuario->esAdmin() || ! self::requiereAutorizacion()) {
            self::aplicar($cambio, $partida, round((float) $d['precio'], 2), $d['costo'] ?? null, $d['proveedor_id'] ?? null, 'directo', null);
            PedidoActividad::registrar($pedido, 'precio_cambiado', 'cambió el precio de "'.$nombre.'" de '.self::dinero($cambio->precio_anterior)
                .' a '.self::dinero($cambio->precio_final).' (motivo: '.$cambio->motivo.')'.($usuario->esAdmin() ? '' : ' — hay que avisar a Daniel'));
        } else {
            PedidoActividad::registrar($pedido, 'precio_pedido', 'pidió cambiar el precio de "'.$nombre.'" de '.self::dinero($cambio->precio_anterior)
                .' a '.self::dinero($cambio->precio_solicitado).' (motivo: '.$cambio->motivo.') — falta autorización de Daniel');
        }

        return $cambio;
    }

    /** Pone precio (y costo/proveedor si vienen) en la partida, recalcula el pedido y deja el cambio resuelto. */
    private static function aplicar(CambioPrecio $cambio, PedidoPartida $partida, float $precio, ?float $costo, ?int $proveedorId, string $estatus, ?string $nota): void
    {
        $costoFinal = $costo ?? ($partida->costo !== null ? (float) $partida->costo : null);
        $cambioCosto = $costo !== null && abs($costo - (float) $partida->costo) > 0.00005;
        $datos = ['precio' => $precio];
        if ($costo !== null) {
            $datos['costo'] = $costo;
        }
        // Con OC a proveedor ya hecha no se cambia de proveedor (primero se cancela esa OC).
        $cambioProv = $proveedorId && ! $partida->orden_proveedor_id && $proveedorId !== $partida->proveedor_id;
        if ($cambioProv) {
            $datos['proveedor_id'] = $proveedorId;
        }
        $utilAntes = self::utilidad((float) $partida->precio, $partida->costo !== null ? (float) $partida->costo : null, (float) $partida->cantidad);
        $partida->update($datos);
        $partida->pedido->recalcularTotales();
        if ($cambioCosto) {
            ReenvioOrden::alCambiarPartida($partida);
        }

        $cambio->update([
            'estatus' => $estatus,
            'precio_final' => $precio,
            'costo_nuevo' => $costo,
            'proveedor_id' => $cambioProv ? $proveedorId : $cambio->proveedor_id,
            'resuelto_por' => auth()->id(),
            'resuelto_at' => now(),
            'nota_resolucion' => $nota,
            'utilidad_antes' => $utilAntes,
            'utilidad_despues' => self::utilidad($precio, $costoFinal, (float) $partida->cantidad),
        ]);
    }

    /** El admin autoriza (con precio/costo/proveedor ajustados si quiere). Devuelve null si ya no se puede. */
    public static function autorizar(CambioPrecio $cambio, float $precio, ?float $costo, ?int $proveedorId, ?string $nota = null): ?string
    {
        return DB::transaction(function () use ($cambio, $precio, $costo, $proveedorId, $nota) {
            $c = CambioPrecio::whereKey($cambio->id)->first();
            if (! $c || ! $c->esPendiente()) {
                return 'Este cambio ya se resolvió.';
            }
            $partida = $c->pedido_partida_id ? PedidoPartida::with('pedido')->find($c->pedido_partida_id) : null;
            if (! $partida || ! $partida->pedido) {
                return 'La partida ya no está en el pedido: solo se puede rechazar.';
            }
            self::aplicar($c, $partida, round($precio, 2), $costo, $proveedorId, 'autorizado', $nota !== null && trim($nota) !== '' ? trim($nota) : null);
            $c->refresh();
            $nombre = mb_substr((string) $partida->descripcion, 0, 60);
            PedidoActividad::registrar($partida->pedido, 'precio_autorizado', 'autorizó el cambio de precio de "'.$nombre.'": de '.self::dinero($c->precio_anterior)
                .' a '.self::dinero($c->precio_final).' (utilidad de la partida de '.self::dinero($c->utilidad_antes).' a '.self::dinero($c->utilidad_despues).')');

            return null;
        });
    }

    public static function rechazar(CambioPrecio $cambio, string $nota): ?string
    {
        return DB::transaction(function () use ($cambio, $nota) {
            $c = CambioPrecio::whereKey($cambio->id)->first();
            if (! $c || ! $c->esPendiente()) {
                return 'Este cambio ya se resolvió.';
            }
            $c->update(['estatus' => 'rechazado', 'resuelto_por' => auth()->id(), 'resuelto_at' => now(), 'nota_resolucion' => trim($nota)]);
            PedidoActividad::registrar($c->pedido_id, 'precio_rechazado', 'rechazó el cambio de precio de "'.mb_substr((string) $c->descripcion, 0, 60).'" a '
                .self::dinero($c->precio_solicitado).': se queda en '.self::dinero($c->precio_anterior).' ('.trim($nota).')');

            return null;
        });
    }

    /** Proveedor por nombre escrito (null si vacío, false si no existe). */
    public static function proveedorPorNombre(?string $nombre): int|false|null
    {
        $llave = Nombres::llave(Nombres::limpiar((string) $nombre));
        if ($llave === '') {
            return null;
        }

        return Proveedor::where('nombre_llave', $llave)->value('id') ?? false;
    }
}

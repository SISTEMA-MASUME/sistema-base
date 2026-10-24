<?php

namespace App\Support;

use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\Venta;
use App\Models\VentaVinculo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Trazabilidad de documentos (pedido de Daniel): varias notas se facturan en una factura, una factura cancelada
 * se rehace en otra, y un pedido se factura en una o varias facturas. Reglas:
 * - Lo reemplazado (nota o factura vieja) queda CANCELADO para no sumar doble; se guarda su estatus anterior para
 *   poder deshacer.
 * - Si TODO lo reemplazado ya estaba cobrado, la factura hereda la fecha de cobro (la más reciente): el dinero ya
 *   llegó y la comisión no se paga dos veces.
 */
final class Trazabilidad
{
    /**
     * @param  Collection<int, Venta>  $origenes
     * @return int cuántas se ligaron
     */
    public static function ligar(Collection $origenes, Venta $destino, string $fuente = 'manual'): int
    {
        if ($destino->tipo !== 'factura' || $destino->estatus === 'cancelada') {
            throw new InvalidArgumentException('Solo se puede ligar a una factura vigente (no cancelada).');
        }
        $origenes = $origenes->reject(fn (Venta $o) => $o->id === $destino->id)->values();
        if ($origenes->isEmpty()) {
            throw new InvalidArgumentException('Elige las notas o facturas que se reemplazan.');
        }

        return DB::transaction(function () use ($origenes, $destino, $fuente) {
            $ids = $origenes->pluck('id')->push($destino->id)->unique()->sort()->values();
            $bloqueadas = Venta::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($bloqueadas->contains(fn ($v) => $v->deposito_cobro_id !== null)) {
                throw new InvalidArgumentException('Hay una factura ligada a un depósito. Revisa su conciliación antes de sustituir documentos.');
            }
            $ligadas = 0;
            foreach ($origenes as $o) {
                // Ya ligado (a esta u otra factura): no se encima; primero hay que deshacer la liga anterior.
                if (VentaVinculo::where('origen_id', $o->id)->exists()) {
                    continue;
                }
                $tipo = $o->tipo === 'nota' ? 'facturada_en' : 'sustituida_por';
                VentaVinculo::create([
                    'origen_id' => $o->id, 'destino_id' => $destino->id, 'tipo' => $tipo, 'fuente' => $fuente,
                    'estatus_anterior' => $o->estatus, 'user_id' => $fuente === 'manual' ? auth()->id() : null, 'created_at' => now(),
                ]);
                $antes = $o->estatus;
                $texto = ($tipo === 'facturada_en' ? 'Se facturó en ' : 'Sustituida por ').$destino->folio;
                $o->update([
                    'estatus' => 'cancelada',   // la fecha de pago se conserva: la hereda la factura
                    'factura_sustituta' => $destino->folio,
                    'notas' => trim(($o->notas ? $o->notas."\n" : '').$texto.'.'),
                ]);
                $o->anotar('estatus', $antes, 'cancelada', $fuente === 'sicar' ? 'sicar' : 'usuario');
                $o->anotar('liga', null, $texto, $fuente === 'sicar' ? 'sicar' : 'usuario');
                $destino->anotar('liga', null, 'Reemplaza a '.$o->folio, $fuente === 'sicar' ? 'sicar' : 'usuario');
                $ligadas++;
            }
            self::heredarCobro($destino->fresh());

            return $ligadas;
        });
    }

    /** Si todo lo que esta factura reemplaza ya estaba cobrado y ella sigue pendiente, toma esa fecha de cobro. */
    public static function heredarCobro(Venta $destino): void
    {
        if ($destino->deposito_cobro_id) {
            return;
        }
        $origenes = Venta::whereIn('id', $destino->reemplaza()->pluck('origen_id'))->get();
        if ($origenes->isEmpty() || $origenes->contains(fn (Venta $o) => ! $o->fecha_pago)) {
            return;
        }
        $fecha = $origenes->max(fn (Venta $o) => $o->fecha_pago->toDateString());
        if ($destino->estatus === 'pendiente' || ($destino->estatus === 'pagada' && $destino->origen === 'sicar'
            && $destino->fecha_pago && $destino->fecha_pago->toDateString() > $fecha)) {
            $antes = [$destino->estatus, $destino->fecha_pago?->toDateString()];
            $destino->update(['estatus' => 'pagada', 'fecha_pago' => $fecha,
                'pago_referencia' => mb_substr('Cobrada como '.$origenes->pluck('folio')->implode(', '), 0, 120)]);
            $destino->anotar('estatus', $antes[0], 'pagada');
            $destino->anotar('fecha_pago', $antes[1], $fecha);
        }
    }

    /** Deshace una liga: lo reemplazado regresa a como estaba y, si la factura tomó el cobro de ahí, lo suelta. */
    public static function desligar(VentaVinculo $v): void
    {
        DB::transaction(function () use ($v) {
            $o = $v->origen;
            $d = $v->destino;
            if ($o?->deposito_cobro_id || $d?->deposito_cobro_id) {
                ComplementosSicar::error('Revisa el depósito antes de deshacer una sustitución de sus facturas.');
            }
            $v->delete();
            if ($o && $o->estatus === 'cancelada') {
                $regresa = $v->estatus_anterior ?: ($o->fecha_pago ? 'pagada' : 'pendiente');
                $o->update(['estatus' => $regresa, 'factura_sustituta' => null]);
                $o->anotar('estatus', 'cancelada', $regresa);
                $o->anotar('liga', 'Reemplazada por '.$d?->folio, null);
            }
            if ($d) {
                $d->anotar('liga', 'Reemplaza a '.$o?->folio, null);
                if ($d->estatus === 'pagada' && str_starts_with((string) $d->pago_referencia, 'Cobrada como')) {
                    $fecha = $d->fecha_pago?->toDateString();
                    $d->update(['estatus' => 'pendiente', 'fecha_pago' => null, 'pago_referencia' => null]);
                    $d->anotar('estatus', 'pagada', 'pendiente');
                    $d->anotar('fecha_pago', $fecha, null);
                    self::heredarCobro($d->fresh());   // si aún reemplaza otras ya cobradas
                }
            }
        });
    }

    /** Ligas que SICAR dejó en factura_sustituta (nota facturada) y cuya factura ya llegó. */
    public static function completarLigasDeSicar(): int
    {
        $n = 0;
        Venta::query()->whereNotNull('factura_sustituta')->where('estatus', 'cancelada')
            ->whereDoesntHave('reemplazadaPor')->get()
            ->each(function (Venta $o) use (&$n) {
                $destino = Venta::where('folio_llave', Venta::llave('factura', $o->factura_sustituta))->where('id', '!=', $o->id)->first();
                if ($destino) {
                    VentaVinculo::create([
                        'origen_id' => $o->id, 'destino_id' => $destino->id,
                        'tipo' => $o->tipo === 'nota' ? 'facturada_en' : 'sustituida_por',
                        'fuente' => 'sicar', 'estatus_anterior' => null, 'user_id' => null, 'created_at' => now(),
                    ]);
                    $n++;
                }
            });

        return $n;
    }

    /**
     * Liga un pedido con una factura/nota. `$porConfirmar` = propuesta (la factura sale a otra razón social y falta que Daniel
     * confirme): no cuenta como ligada ni se anota en la tarjeta. Si ya existe como propuesta y ahora se liga de verdad
     * (a mano o porque la razón social ya se confirmó), se confirma esa misma liga.
     */
    public static function ligarPedido(Pedido $pedido, Venta $venta, string $origen = 'usuario', ?string $motivo = null, bool $porConfirmar = false): bool
    {
        $existente = DB::table('pedido_venta')->where('pedido_id', $pedido->id)->where('venta_id', $venta->id)->first();
        if ($existente && (! $existente->por_confirmar || $porConfirmar)) {
            return false;
        }
        $usuario = $origen === 'usuario' ? auth()->id() : null;
        if ($existente) {
            DB::table('pedido_venta')->where('id', $existente->id)->update(['por_confirmar' => false, 'user_id' => $usuario]);
        } else {
            DB::table('pedido_venta')->insert(['pedido_id' => $pedido->id, 'venta_id' => $venta->id, 'user_id' => $usuario,
                'por_confirmar' => $porConfirmar, 'created_at' => now()]);
        }
        if ($porConfirmar) {
            return true;
        }
        $venta->anotar('pedido', null, 'P-'.$pedido->folio, $origen);
        if ($motivo !== null) {
            PedidoActividad::registrar($pedido, 'factura', ($venta->tipo === 'nota' ? 'Nota ' : 'Factura ').$venta->folio.' '.$motivo);
        } elseif ($origen === 'sicar') {
            $otroNombre = $venta->cliente_id !== $pedido->cliente_id ? ' (sale a nombre de '.$venta->cliente_nombre.')' : '';
            PedidoActividad::registrar($pedido, 'factura', ($venta->tipo === 'nota' ? 'Nota ' : 'Factura ').$venta->folio.$otroNombre
                .' ligada sola: su comentario en SICAR trae la OC '.$pedido->numero_oc_cliente.'.');
        }

        return true;
    }

    /**
     * Posibles números de OC dentro de un texto libre (comentario de SICAR o la OC del pedido): cada palabra y las uniones de
     * hasta 5 palabras seguidas ("PO 4500-123" → PO4500123, 4500123; "OEAD39802/00 LA BARCA" → OEAD3980200…), en forma de llave
     * (sin espacios ni signos). Se piden ≥ 4 caracteres y al menos un dígito para no confundir palabras comunes ni "OC 45".
     *
     * @return list<string>
     */
    public static function ocsEnTexto(string $texto, int $maxPalabras = 5): array
    {
        $palabras = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $texto) ?: [], fn ($p) => $p !== ''));
        $llaves = [];
        foreach ($palabras as $i => $p) {
            for ($n = 1; $n <= $maxPalabras && $i + $n <= count($palabras); $n++) {
                $llave = Nombres::llave(implode('', array_slice($palabras, $i, $n)));
                if (strlen($llave) >= 4 && preg_match('/\d/', $llave)) {
                    $llaves[$llave] = true;
                }
            }
        }

        return array_map('strval', array_keys($llaves));   // las llaves numéricas regresan como int
    }

    /** Mínimo de caracteres de la OC para ligar aunque la factura salga a otra razón social (y solo si la OC es única). */
    public const OC_LARGA = 8;

    /**
     * ¿El comentario menciona la OC de este pedido? Regresa la llave que coincidió o null. Vale la OC completa
     * ("4500098765") o una parte de ≥ 6 caracteres que CONTENGA el número principal de la OC (la corrida de dígitos más
     * larga, mínimo 4): "GE39934/00 ZONA INDUSTRIAL" se encuentra con "GE39934/00" pero NO con "00 ZONA INDUSTRIAL", que
     * traen todas las OC de Kappa (ese error ligó facturas a pedidos ajenos el 2 oct).
     *
     * @param  list<string>  $enComentario
     */
    private static function coincidencia(Pedido $pedido, array $enComentario): ?string
    {
        $set = array_flip($enComentario);
        if (isset($set[$pedido->numero_oc_llave]) && strlen($pedido->numero_oc_llave) >= 4) {
            return $pedido->numero_oc_llave;
        }
        preg_match_all('/\d+/', (string) $pedido->numero_oc_llave, $corridas);
        $nucleo = collect($corridas[0])->sortByDesc(fn ($d) => strlen($d))->first();
        if (! $nucleo || strlen($nucleo) < 4) {
            return null;
        }
        $mejor = null;
        foreach (self::ocsEnTexto((string) $pedido->numero_oc_cliente) as $parte) {
            if (strlen($parte) >= 6 && str_contains($parte, $nucleo) && isset($set[$parte]) && strlen($parte) > strlen((string) $mejor)) {
                $mejor = $parte;
            }
        }

        return $mejor;
    }

    /**
     * ¿Una liga automática (sin usuario) sigue siendo correcta con las reglas actuales? Vale si el XML/PDF de esa factura
     * está en el expediente del pedido, o si el comentario de SICAR trae su OC y la razón social es del cliente (o, con OC
     * larga y única, queda por confirmar).
     */
    public static function ligaAutomaticaValida(Pedido $pedido, Venta $venta, $pedidos = null): bool
    {
        return self::estadoLigaAutomatica($pedido, $venta, $pedidos) !== 'invalida';
    }

    /** @return 'valida'|'por_confirmar'|'invalida' */
    public static function estadoLigaAutomatica(Pedido $pedido, Venta $venta, $pedidos = null): string
    {
        $folio = mb_strtoupper(preg_replace('/\s+/u', '', (string) $venta->folio));
        $porArchivo = $pedido->archivos()->whereIn('tipo', ['factura_xml', 'factura_pdf'])
            ->where(function ($q) use ($venta, $folio) {
                $q->where('cfdi_folio', $folio);
                if ($venta->uuid) {
                    $q->orWhere('cfdi_uuid', mb_strtoupper($venta->uuid));
                }
            })->exists();
        if ($porArchivo) {
            return 'valida';
        }
        if (blank($venta->comentario_sicar)) {
            return 'invalida';
        }
        $enComentario = self::ocsEnTexto($venta->comentario_sicar);
        $llave = self::coincidencia($pedido, $enComentario);
        if (! $llave) {
            return 'invalida';
        }
        $porLlave = [];
        foreach ($pedidos ?? self::pedidosConOc() as $p) {
            if (($k = self::coincidencia($p, $enComentario)) !== null) {
                $porLlave[$k][$p->id] = true;
            }
        }

        return match (self::permitido($pedido, $venta, $llave, $porLlave, false)) {
            'directa' => 'valida',
            'por_confirmar' => 'por_confirmar',
            default => 'invalida',
        };
    }

    /**
     * Candado de la razón social. Mismo cliente, o la razón social de la factura ya registrada como de ese cliente → 'directa'.
     * Otra razón social: solo si la OC es larga (≥ 8) y única → 'por_confirmar' (propuesta; no si Daniel ya dijo "No" a ese par).
     * Cualquier otro caso → null (no se liga ni se propone).
     *
     * @return 'directa'|'por_confirmar'|null
     */
    private static function permitido(Pedido $pedido, Venta $venta, string $llave, array $porLlave, bool $respetarRechazos = true): ?string
    {
        if ($venta->cliente_id && $pedido->cliente_id === $venta->cliente_id) {
            return 'directa';
        }
        if (LigasPorConfirmar::razonRegistrada($pedido->cliente_id, $venta)) {
            // Una razón social que factura por varias plazas (PARQUES → Lumex Mérida, Tuxtla…): si esa misma OC aparece en
            // pedidos de más de una plaza, no se adivina: se pregunta.
            return count($porLlave[$llave] ?? []) > 1 ? 'por_confirmar' : 'directa';
        }
        if (strlen($llave) < self::OC_LARGA || count($porLlave[$llave] ?? []) !== 1) {
            return null;
        }
        $razon = Nombres::llave((string) $venta->cliente_nombre);
        if ($razon === '' || ! $pedido->cliente_id) {
            return null;   // sin a quién preguntarle "¿factura por este cliente?"
        }
        if ($respetarRechazos && LigasPorConfirmar::rechazada($pedido->cliente_id, $razon)) {
            return null;
        }

        return 'por_confirmar';
    }

    /** @return Collection<int, Pedido> */
    private static function pedidosConOc()
    {
        return Pedido::whereNotNull('numero_oc_llave')->whereNull('cancelado_at')
            ->get(['id', 'folio', 'cliente_id', 'numero_oc_cliente', 'numero_oc_llave']);
    }

    /** Liga la venta con los pedidos cuya OC aparece en su comentario de SICAR. Regresa cuántos ligó. */
    public static function ligarPedidosPorOc(Venta $venta, $pedidos = null): int
    {
        if (blank($venta->comentario_sicar) || $venta->estatus === 'cancelada') {
            return 0;
        }
        $enComentario = self::ocsEnTexto($venta->comentario_sicar);
        if (! $enComentario) {
            return 0;
        }
        $coinciden = [];
        foreach ($pedidos ?? self::pedidosConOc() as $p) {
            if ($llave = self::coincidencia($p, $enComentario)) {
                $coinciden[] = [$p, $llave];
            }
        }
        $porLlave = [];
        foreach ($coinciden as [$p, $llave]) {
            $porLlave[$llave][$p->id] = true;
        }
        $n = 0;
        foreach ($coinciden as [$p, $llave]) {
            $estado = self::permitido($p, $venta, $llave, $porLlave);
            if ($estado === 'directa') {
                $n += self::ligarPedido(Pedido::find($p->id), $venta, 'sicar') ? 1 : 0;
            } elseif ($estado === 'por_confirmar') {
                self::ligarPedido(Pedido::find($p->id), $venta, 'sicar', null, true);   // propuesta: no cuenta como ligada
            }
        }

        return $n;
    }

    /** Al capturar (o corregir) la OC de un pedido: busca facturas/notas de SICAR que la mencionen. */
    public static function ligarFacturasDelPedido(Pedido $pedido): int
    {
        if (blank($pedido->numero_oc_llave) || $pedido->cancelado_at) {
            return 0;
        }
        $pedidos = null;
        $n = 0;
        Venta::whereNotNull('comentario_sicar')->where('estatus', '!=', 'cancelada')
            ->where('fecha', '>=', now()->subYears(2)->toDateString())->get()
            ->each(function (Venta $v) use ($pedido, &$pedidos, &$n) {
                if (self::coincidencia($pedido, self::ocsEnTexto($v->comentario_sicar))) {
                    // Se revisa contra todos los pedidos para respetar la regla de "OC única" entre clientes.
                    $pedidos ??= self::pedidosConOc();
                    $n += self::ligarPedidosPorOc($v, $pedidos);
                }
            });

        return $n;
    }

    public static function desligarPedido(Pedido $pedido, Venta $venta): void
    {
        // Directo en la tabla: quita la liga aunque sea una propuesta por confirmar.
        $liga = DB::table('pedido_venta')->where('pedido_id', $pedido->id)->where('venta_id', $venta->id)->first();
        if ($liga) {
            DB::table('pedido_venta')->where('id', $liga->id)->delete();
            if (! $liga->por_confirmar) {
                $venta->anotar('pedido', 'P-'.$pedido->folio, null);
            }
        }
    }
}

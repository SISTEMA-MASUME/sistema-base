<?php

namespace App\Livewire\OrdenesProveedor;

use App\Enums\EstadoOrdenProveedor;
use App\Models\CuentaPorPagar;
use App\Models\OrdenProveedor;
use App\Models\PedidoActividad;
use App\Support\CostoOrden;
use App\Support\CuentasPorPagar;
use App\Support\LinkCompra;
use App\Support\Precios;
use DomainException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Orden de compra a proveedor')]
class Ver extends Component
{
    public OrdenProveedor $orden;

    public string $mensaje = '';

    public bool $confirmandoRecibida = false;

    /** Corregir el costo cuando el proveedor cobró distinto (descuento): total real con IVA y motivo opcional. */
    public bool $corrigiendoCosto = false;

    public string $nuevoTotal = '';

    public string $motivoCosto = '';

    public function mount(OrdenProveedor $orden): void
    {
        $this->orden = $orden;
    }

    /** Se compartió la orden (WhatsApp, correo o descarga): pasa a enviada; se vuelve a leer. */
    #[On('envio-registrado')]
    public function envioRegistrado(): void
    {
        $this->orden->refresh();
    }

    public function marcarEnviada(): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        if ($this->orden->estado !== EstadoOrdenProveedor::Emitida) {
            return;
        }
        $this->orden->update(['estado' => EstadoOrdenProveedor::Enviada, 'requiere_reenvio' => false]);
        $this->mensaje = 'La orden quedó como enviada.';
    }

    /** Ya se le mandó al proveedor la versión corregida (con la cantidad nueva). */
    public function marcarReenviada(): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        if (! $this->orden->requiere_reenvio) {
            return;
        }
        $this->orden->update(['requiere_reenvio' => false]);
        $this->mensaje = 'Listo: quedó registrado que la orden corregida ya se envió.';
    }

    public function pedirRecibida(): void
    {
        if (in_array($this->orden->estado, [EstadoOrdenProveedor::Emitida, EstadoOrdenProveedor::Enviada], true)) {
            $this->confirmandoRecibida = true;
        }
    }

    public function marcarRecibida(bool $conPartidas = true): void
    {
        $this->confirmandoRecibida = false;
        if (! in_array($this->orden->estado, [EstadoOrdenProveedor::Emitida, EstadoOrdenProveedor::Enviada], true)) {
            return;
        }
        DB::transaction(function () use ($conPartidas) {
            $this->orden->update(['estado' => EstadoOrdenProveedor::Recibida]);
            if ($conPartidas) {
                // Nunca se retrocede una partida ya entregada al cliente.
                $this->orden->partidas()->whereIn('estado', ['pendiente', 'solicitada'])->update(['estado' => 'recibida', 'cantidad_recogida' => DB::raw('cantidad')]);
            }
        });
        $this->mensaje = $conPartidas
            ? 'Orden recibida. Sus partidas pasaron a recibidas.'
            : 'Orden marcada como recibida (las partidas no se modificaron).';
    }

    /** Link de compra de una partida (Mercado Libre, Amazon…): el vendedor lo pega aquí para quien compra. */
    public function guardarLink(int $partidaId, ?string $link): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $link = LinkCompra::normalizar($link);
        if ($link !== null && ! LinkCompra::esUrl($link)) {
            $this->addError('link.'.$partidaId, 'Pega un link que empiece con https://');

            return;
        }
        $this->orden->partidas()->whereKey($partidaId)->firstOrFail()->update(['link_compra' => $link]);
        $this->resetErrorBag('link.'.$partidaId);
        $this->mensaje = $link ? 'Link de compra guardado.' : 'Link de compra quitado.';
    }

    /**
     * Texto para pedirle a Daniel que compre por internet (solo él compra en Mercado Libre / Amazon):
     * el vendedor lo copia y se lo pega en WhatsApp.
     */
    public function textoPedirCompra(iterable $partidas): string
    {
        $pedido = $this->orden->pedido;
        $para = ($pedido->cliente->nombre ?? 'el cliente').' de la '.($pedido->numero_oc_cliente ? 'OC '.$pedido->numero_oc_cliente : 'nota de venta (sin OC)');
        $renglones = [];
        foreach ($partidas as $p) {
            [, $link] = LinkCompra::separar($p->modelo, $p->link_compra);
            if (! $link) {
                continue;
            }
            $cant = rtrim(rtrim(number_format((float) $p->cantidad, 3, '.', ''), '0'), '.');
            $renglones[] = "{$cant} ".mb_strtolower((string) ($p->unidad ?: 'PZA'))." de este link ({$p->descripcion}):\n{$link}";
        }
        if ($renglones === []) {
            return '';
        }
        $ref = ' (pedido P-'.$pedido->folio.', '.$this->orden->folioTexto().')';

        return count($renglones) === 1
            ? "¿Me puedes apoyar a pedir {$renglones[0]}\n\nEs para {$para}{$ref}. Gracias."
            : "¿Me puedes apoyar a pedir lo siguiente?\n\n".implode("\n\n", array_map(fn ($r, $i) => ($i + 1).') '.$r, $renglones, array_keys($renglones)))
                ."\n\nEs para {$para}{$ref}. Gracias.";
    }

    /** Se copió el texto para pedir la compra: queda anotado en la tarjeta del pedido. */
    public function registrarPedirCompra(array $partidaIds): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $partidas = $this->orden->partidas()->whereIn('id', $partidaIds)->get();
        if ($partidas->isEmpty()) {
            return;
        }
        PedidoActividad::registrar($this->orden->pedido_id, 'compra_por_link',
            'Pidió compra por link ('.$this->orden->folioTexto().'): '.$partidas->map(fn ($p) => rtrim(rtrim(number_format((float) $p->cantidad, 3, '.', ''), '0'), '.').' '.$p->descripcion)->implode(', '));
        $this->mensaje = 'Texto copiado. Pégalo en el WhatsApp de Daniel. Quedó anotado en la tarjeta del pedido.';
    }

    public function abrirCorregirCosto(): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $this->resetErrorBag('nuevoTotal');
        $this->nuevoTotal = '';
        $this->motivoCosto = '';
        $this->corrigiendoCosto = true;
    }

    public function corregirCosto(): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $total = str_replace([',', '$', ' '], '', $this->nuevoTotal);
        if (! is_numeric($total)) {
            $this->addError('nuevoTotal', 'Escribe el total que se pagó, con IVA (por ejemplo 2900).');

            return;
        }
        try {
            $this->mensaje = CostoOrden::corregirTotal($this->orden, (float) $total, mb_substr($this->motivoCosto, 0, 200));
        } catch (DomainException $e) {
            $this->addError('nuevoTotal', $e->getMessage());

            return;
        }
        $this->corrigiendoCosto = false;
        $this->orden->refresh();
    }

    public function cancelar(): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        if (! in_array($this->orden->estado, [EstadoOrdenProveedor::Emitida, EstadoOrdenProveedor::Enviada], true)) {
            return;
        }
        DB::transaction(function () {
            $this->orden->partidas()->update(['orden_proveedor_id' => null, 'estado' => 'pendiente']);
            $this->orden->update(['estado' => EstadoOrdenProveedor::Cancelada]);
            CuentasPorPagar::sincronizarOrden($this->orden);   // si era a crédito, la deuda se cancela
        });
        $this->mensaje = 'Orden cancelada. Sus partidas quedaron libres para pedirse de nuevo.';
    }

    public function render()
    {
        $this->orden->load(['proveedor', 'pedido.cliente', 'user']);
        $partidas = $this->orden->partidas()->get();
        $partidasLiberadas = $this->orden->estado === EstadoOrdenProveedor::Cancelada;
        $sinIva = $partidas->sum(fn ($p) => (float) $p->costo * (float) $p->cantidad);

        return view('livewire.ordenes-proveedor.ver', [
            'deuda' => auth()->user()->esAdmin() ? CuentaPorPagar::where('orden_proveedor_id', $this->orden->id)->first() : null,
            'partidas' => $partidas,
            'totales' => Precios::totales([$partidas->isEmpty() && $partidasLiberadas ? (float) $this->orden->total_costo : $sinIva]),
            'iva' => Precios::iva(),
            'puedeGestionar' => ! auth()->user()->esCampo(),
            'textosCompra' => $partidas->mapWithKeys(fn ($p) => [$p->id => $this->textoPedirCompra([$p])])->filter()->all(),
            'textoCompraTodas' => $this->textoPedirCompra($partidas),
        ]);
    }
}

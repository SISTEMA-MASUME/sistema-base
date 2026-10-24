<?php

namespace App\Livewire\Facturacion;

use App\Models\Pedido;
use App\Models\PedidoActividad;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pendientes de facturación (T16): lo que cada cliente pide después de entregar. No es para el rol campo
 * (la ruta va con sin_campo).
 */
#[Layout('layouts.app')]
#[Title('Pendientes de facturación')]
class Index extends Component
{
    public const PESTANAS = [
        'portal' => 'Facturas por subir a portal',
        'subidas' => 'Ya subidas',
        'evidencias' => 'Evidencias por enviar',
        'plazas' => 'Facturas por enviar a plaza',
        'oc' => 'Esperando OC',
    ];

    #[Url(as: 'ver')]
    public string $pestana = 'portal';

    public string $mensaje = '';

    public function mount(): void
    {
        if (! isset(self::PESTANAS[$this->pestana])) {
            $this->pestana = 'portal';
        }
    }

    public function elegir(string $pestana): void
    {
        if (isset(self::PESTANAS[$pestana])) {
            $this->pestana = $pestana;
            $this->mensaje = '';
        }
    }

    /** "Ya la subí": misma marca que el botón del pedido. */
    public function marcarSubida(int $pedidoId): void
    {
        $pedido = $this->consulta('portal')->findOrFail($pedidoId);
        $pedido->update(['factura_portal_at' => now(), 'factura_portal_por' => auth()->id()]);
        PedidoActividad::registrar($pedido, 'portal', 'marcó la factura como subida al portal del cliente');
        $this->mensaje = 'Listo: la factura de '.$pedido->titulo().' quedó como subida al portal.';
    }

    private function conFactura(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereHas('archivos', fn ($a) => $a->where('tipo', 'factura_pdf'))
            ->orWhereHas('partidas', fn ($p) => $p->where('facturada', true)));
    }

    private function consulta(string $pestana): Builder
    {
        $q = Pedido::query()->whereNull('cancelado_at');

        return match ($pestana) {
            // Clientes que generan la OC después: siempre. Los demás con portal: cuando ya hay factura.
            'portal' => $q->whereNull('factura_portal_at')->where(fn ($w) => $w
                ->whereHas('cliente', fn ($c) => $c->where('oc_despues_de_facturar', true))
                ->orWhere(fn ($x) => $this->conFactura($x->whereHas('cliente', fn ($c) => $c->where('sube_factura_portal', true))))),
            'subidas' => $q->whereNotNull('factura_portal_at'),
            'evidencias' => $q->whereNull('evidencia_enviada_at')
                ->whereHas('cliente', fn ($c) => $c->where('envia_evidencia_por_correo', true))
                ->whereHas('archivos', fn ($a) => $a->where('tipo', 'evidencia')),
            'plazas' => $this->conFactura($q->whereNotNull('sucursal_id')->whereNull('factura_sucursal_enviada_at')),
            'oc' => $q->whereNull('numero_oc_cliente')->whereNull('archivado_at'),
        };
    }

    public function render()
    {
        $conteos = collect(self::PESTANAS)->map(fn ($t, $clave) => $clave === 'subidas' ? null : $this->consulta($clave)->count());

        $pedidos = $this->consulta($this->pestana)
            ->with([Pedido::CLIENTE_REGLAS, 'responsable:id,name,iniciales', 'sucursal', 'facturaPortalPor:id,name'])
            ->withCount(['archivos as facturas_count' => fn ($a) => $a->where('tipo', 'factura_pdf')])
            ->when($this->pestana === 'subidas', fn ($q) => $q->orderByDesc('factura_portal_at'), fn ($q) => $q->orderBy('fecha_oc')->orderBy('id'))
            ->limit(200)->get();

        return view('livewire.facturacion.index', [
            'pestanas' => self::PESTANAS,
            'conteos' => $conteos,
            'pedidos' => $pedidos,
        ]);
    }
}

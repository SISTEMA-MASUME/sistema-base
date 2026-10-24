<?php

namespace App\Livewire\OrdenesProveedor;

use App\Enums\EstadoOrdenProveedor;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\Proveedor;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Órdenes de compra a proveedores')]
class Index extends Component
{
    use WithPagination;

    /** Folio ("OC-0012" o "12") o nombre de cliente. */
    #[Url(as: 'q')]
    public string $buscar = '';

    #[Url]
    public string $proveedor = '';

    #[Url]
    public string $estado = '';

    #[Url]
    public string $desde = '';

    #[Url]
    public string $hasta = '';

    /** id del pedido (viene del detalle del pedido o de generar). */
    #[Url]
    public string $pedido = '';

    public function updated(string $propiedad): void
    {
        $this->resetPage();
    }

    public function limpiar(): void
    {
        $this->reset(['buscar', 'proveedor', 'estado', 'desde', 'hasta', 'pedido']);
        $this->resetPage();
    }

    public function render()
    {
        $texto = trim($this->buscar);

        $ordenes = OrdenProveedor::query()
            ->with(['proveedor:id,nombre', 'pedido:id,folio,cliente_id', 'pedido.cliente:id,nombre'])
            ->when($texto !== '', function ($q) use ($texto) {
                $q->where(function ($q) use ($texto) {
                    if (preg_match('/^(?:OC[\s-]*)?(\d+)$/i', $texto, $m)) {
                        $q->where('folio', (int) $m[1]);
                    }
                    $q->orWhereHas('pedido.cliente', fn ($c) => $c->where('nombre', 'like', '%'.mb_strtoupper($texto).'%'));
                });
            })
            ->when($this->proveedor !== '', fn ($q) => $q->where('proveedor_id', (int) $this->proveedor))
            ->when($this->estado !== '', fn ($q) => $q->where('estado', $this->estado))
            ->when($this->pedido !== '', fn ($q) => $q->where('pedido_id', (int) $this->pedido))
            ->when($this->desde !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->desde))
            ->when($this->hasta !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->hasta))
            ->orderByDesc('folio')
            ->paginate(25);

        return view('livewire.ordenes-proveedor.index', [
            'ordenes' => $ordenes,
            'proveedores' => Proveedor::orderBy('nombre')->get(['id', 'nombre']),
            'estados' => EstadoOrdenProveedor::cases(),
            'pedidoFiltrado' => $this->pedido !== '' ? Pedido::find((int) $this->pedido, ['id', 'folio']) : null,
        ]);
    }
}

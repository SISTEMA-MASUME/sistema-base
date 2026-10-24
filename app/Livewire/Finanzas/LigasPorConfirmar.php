<?php

namespace App\Livewire\Finanzas;

use App\Support\LigasPorConfirmar as Ligas;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Facturas cuya OC coincide con un pedido pero que salen a otra razón social: Daniel confirma una vez
 * "¿HOTELES XY factura por HOTEL AURORA EJECUTIVO?" y de ahí en adelante esa razón social se liga sola.
 */
#[Layout('layouts.app')]
#[Title('Facturas por confirmar')]
class LigasPorConfirmar extends Component
{
    public string $mensaje = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->puede('ventas'), 403);
    }

    public function si(int $pedidoId, int $ventaId): void
    {
        abort_unless(auth()->user()?->puede('ventas'), 403);
        $n = Ligas::confirmar($pedidoId, $ventaId, auth()->id());
        $this->mensaje = $n > 0 ? 'Listo: '.$n.($n === 1 ? ' factura quedó ligada.' : ' facturas quedaron ligadas.').' La próxima vez se ligan solas.' : '';
    }

    public function no(int $pedidoId, int $ventaId): void
    {
        abort_unless(auth()->user()?->puede('ventas'), 403);
        $n = Ligas::rechazar($pedidoId, $ventaId, auth()->id());
        $this->mensaje = $n > 0 ? 'Listo: no se ligan y no se vuelve a preguntar por ese nombre.' : '';
    }

    public function render()
    {
        return view('livewire.finanzas.ligas-por-confirmar', ['grupos' => Ligas::grupos()]);
    }
}

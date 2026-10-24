<?php

namespace App\Livewire\Pedidos;

use App\Enums\EstadoPartidaPedido;
use App\Models\PedidoPartida;
use App\Support\PorRecoger as Datos;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Lo que falta recoger de los proveedores, agrupado por proveedor (Ernesto, Hugo y vendedores). */
#[Layout('layouts.app')]
#[Title('Por recoger')]
class PorRecoger extends Component
{
    #[Url(as: 'q')]
    public string $buscar = '';

    /** Solo lo que el proveedor entregó a medias. */
    #[Url(as: 'parciales')]
    public bool $soloParciales = false;

    /** Partida abierta en "¿Cuántas recogiste?" (null = ventanita cerrada). */
    #[Locked]
    public ?int $recogiendoId = null;

    public string $cantidad = '';

    public string $mensaje = '';

    /** "✓ Recogí" en la ruta: una pieza suelta se anota de una vez; si son varias, pregunta cuántas (por si llegó incompleto). */
    public function recoger(int $id): void
    {
        $this->mensaje = '';
        $this->resetErrorBag();
        $p = $this->partida($id);
        if (! $p) {
            $this->mensaje = 'Esa partida ya no está por recoger (alguien más la marcó o cambió el pedido).';

            return;
        }
        if ((float) $p->cantidad <= 1) {
            $this->anotar($p->id, $p->faltaRecoger());

            return;
        }
        $this->recogiendoId = $p->id;
        $this->cantidad = Datos::cantidad($p->faltaRecoger());
    }

    public function guardarRecogida(): void
    {
        if (! $this->recogiendoId) {
            return;
        }
        $c = str_replace(',', '', trim($this->cantidad));
        if (! is_numeric($c) || (float) $c <= 0) {
            $this->addError('cantidad', 'Escribe cuántas recogiste (más de 0).');

            return;
        }
        $this->anotar($this->recogiendoId, round((float) $c, 3));
    }

    public function cerrar(): void
    {
        $this->recogiendoId = null;
        $this->resetErrorBag();
    }

    private function anotar(int $id, float $c): void
    {
        // Si alguien más ya la marcó (o se canceló el pedido) mientras tanto, solo se avisa.
        $this->mensaje = DB::transaction(fn () => ($p = $this->partida($id, bloquear: true)) ? Datos::anotar($p, $c)
            : 'Esa partida ya no está por recoger (alguien más la marcó o cambió el pedido).');
        $this->recogiendoId = null;
    }

    /** Solo partidas que de verdad están en la lista (pedidas al proveedor, de pedidos vigentes). */
    private function partida(int $id, bool $bloquear = false): ?PedidoPartida
    {
        return PedidoPartida::whereKey($id)->where('estado', EstadoPartidaPedido::Solicitada->value)
            ->whereHas('pedido', fn ($q) => $q->whereNull('cancelado_at')->whereNull('archivado_at'))
            ->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
    }

    public function render()
    {
        $grupos = Datos::porProveedor($this->buscar, $this->soloParciales);

        return view('livewire.pedidos.por-recoger', [
            'grupos' => $grupos,
            'total' => $grupos->sum(fn ($g) => $g['partidas']->count()),
            'totalParciales' => $grupos->sum('parciales'),
            'verCostos' => (bool) auth()->user()?->puedeVerCostos(),
            'recogiendo' => $this->recogiendoId ? PedidoPartida::find($this->recogiendoId) : null,
        ]);
    }
}

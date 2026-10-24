<?php

namespace App\Livewire\CambiosPrecio;

use App\Models\Ajuste;
use App\Models\CambioPrecio;
use App\Models\Proveedor;
use App\Support\CambiosPrecio;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/** Pantalla del admin: autorizar o rechazar los cambios de precio que pidieron los vendedores al dar de alta pedidos. */
#[Layout('layouts.app')]
#[Title('Precios por autorizar')]
class Index extends Component
{
    use WithPagination;

    public string $tab = 'pendientes';

    /** Filtro del historial: '' = todos, o un estatus. */
    public string $estatus = '';

    /** Cambio abierto directo desde un enlace (/cambios-precio/{id}). */
    public ?int $enfocado = null;

    /** id de cambio => precio final que Daniel va a autorizar. */
    public array $precioFinal = [];

    /** id de cambio => costo final (vacío = el costo que ya tenía la partida). */
    public array $costoFinal = [];

    public array $proveedorFinal = [];

    public array $nota = [];

    public string $mensaje = '';

    public function mount(?int $cambio = null): void
    {
        abort_unless(auth()->user()?->esAdmin(), 403);
        $this->requiereAutorizacion = CambiosPrecio::requiereAutorizacion();
        if ($cambio) {
            $c = CambioPrecio::findOrFail($cambio);
            $this->enfocado = $c->id;
            $this->tab = $c->esPendiente() ? 'pendientes' : 'historial';
        }
    }

    public bool $requiereAutorizacion = false;

    public function cambiarRequisito(): void
    {
        abort_unless(auth()->user()?->esAdmin(), 403);
        $nuevo = ! CambiosPrecio::requiereAutorizacion();
        Ajuste::guardar(CambiosPrecio::REQUIERE_AUTORIZACION, $nuevo ? '1' : '0');
        $this->requiereAutorizacion = $nuevo;
        $this->mensaje = $nuevo ? 'Listo: desde ahora los cambios de precio de los vendedores esperan tu autorización.'
            : 'Listo: los vendedores cambian el precio directo (con motivo) y te avisan por WhatsApp.';
    }

    public function updatedEstatus(): void
    {
        $this->resetPage();
    }

    public function verTodos(): void
    {
        $this->enfocado = null;
        $this->resetPage();
    }

    public function cambiarTab(string $tab): void
    {
        $this->tab = $tab === 'historial' ? 'historial' : 'pendientes';
        $this->resetPage();
    }

    /** Los campos editables arrancan con lo que pidió el vendedor. */
    private function prellenar($cambios): void
    {
        foreach ($cambios as $c) {
            $this->precioFinal[$c->id] ??= (float) $c->precio_solicitado;
            $this->costoFinal[$c->id] ??= $c->costo_nuevo !== null ? (float) $c->costo_nuevo : '';
            $this->proveedorFinal[$c->id] ??= (string) ($c->proveedor->nombre ?? '');
            $this->nota[$c->id] ??= '';
        }
    }

    /** Autoriza con el precio, costo y proveedor que Daniel dejó en pantalla. */
    public function autorizar(int $id): void
    {
        $this->resetErrorBag();
        $c = CambioPrecio::findOrFail($id);
        $precio = $this->precioFinal[$id] ?? null;
        $costo = $this->costoFinal[$id] ?? '';
        if ($precio === null || $precio === '' || ! is_numeric($precio) || (float) $precio < 0) {
            $this->addError('precioFinal.'.$id, 'Escribe un precio de 0 en adelante.');

            return;
        }
        if ($costo !== '' && $costo !== null && (! is_numeric($costo) || (float) $costo < 0)) {
            $this->addError('costoFinal.'.$id, 'El costo debe ser un número de 0 en adelante.');

            return;
        }
        $provId = CambiosPrecio::proveedorPorNombre($this->proveedorFinal[$id] ?? '');
        if ($provId === false) {
            $this->addError('proveedorFinal.'.$id, 'No encontré ese proveedor. Elige uno de la lista.');

            return;
        }
        $error = CambiosPrecio::autorizar($c, (float) $precio, $costo !== '' && $costo !== null ? (float) $costo : null, $provId, $this->nota[$id] ?? null);
        $this->mensaje = $error ?? 'Cambio autorizado: el precio ya quedó en el pedido P-'.$c->pedido->folio.'.';
    }

    public function rechazar(int $id): void
    {
        $this->resetErrorBag();
        $c = CambioPrecio::findOrFail($id);
        $nota = trim((string) ($this->nota[$id] ?? ''));
        if (mb_strlen($nota) < 3) {
            $this->addError('nota.'.$id, 'Escribe por qué lo rechazas.');

            return;
        }
        $error = CambiosPrecio::rechazar($c, $nota);
        $this->mensaje = $error ?? 'Cambio rechazado: la partida se queda con el precio cotizado.';
    }

    /** Antes y después de una partida, calculados con lo que hay en pantalla. */
    private function comparativa(CambioPrecio $c): array
    {
        $partida = $c->partida;
        $cant = (float) ($partida->cantidad ?? $c->cantidad);
        $precioAntes = (float) ($partida->precio ?? $c->precio_anterior);
        $costoAntes = $partida && $partida->costo !== null ? (float) $partida->costo : ($c->costo_anterior !== null ? (float) $c->costo_anterior : null);

        $pf = $this->precioFinal[$c->id] ?? $c->precio_solicitado;
        $precioDesp = is_numeric($pf) ? round((float) $pf, 2) : $precioAntes;
        $cf = $this->costoFinal[$c->id] ?? '';
        $costoDesp = ($cf !== '' && $cf !== null && is_numeric($cf)) ? (float) $cf : $costoAntes;

        $fila = fn (float $precio, ?float $costo) => [
            'precio' => $precio, 'costo' => $costo,
            'pieza' => round($precio - (float) $costo, 4),
            'total' => CambiosPrecio::utilidad($precio, $costo, $cant),
            'margen' => $precio > 0 ? round(($precio - (float) $costo) / $precio * 100, 1) : null,
        ];
        $antes = $fila($precioAntes, $costoAntes);
        $desp = $fila($precioDesp, $costoDesp);
        $utilPedido = $c->pedido ? CambiosPrecio::utilidadPedido($c->pedido) : 0.0;

        return [
            'antes' => $antes, 'despues' => $desp, 'cantidad' => $cant,
            'pedidoAntes' => $utilPedido,
            'pedidoDespues' => round($utilPedido - $antes['total'] + $desp['total'], 2),
        ];
    }

    public function render()
    {
        $pendientes = collect();
        $historial = null;
        $comparativas = [];
        if ($this->tab === 'pendientes') {
            $pendientes = CambioPrecio::with(['pedido.cliente:id,nombre', 'partida', 'solicitante:id,name', 'proveedor:id,nombre'])
                ->where('estatus', 'pendiente')
                ->when($this->enfocado, fn ($q) => $q->whereKey($this->enfocado))
                ->orderBy('created_at')->orderBy('id')->get();
            $this->prellenar($pendientes);
            foreach ($pendientes as $c) {
                $comparativas[$c->id] = $this->comparativa($c);
            }
        } else {
            $historial = CambioPrecio::with(['pedido.cliente:id,nombre', 'solicitante:id,name', 'resolutor:id,name'])
                ->when($this->estatus !== '', fn ($q) => $q->where('estatus', $this->estatus))
                ->when($this->enfocado, fn ($q) => $q->whereKey($this->enfocado))
                ->orderByDesc('id')->paginate(25);
        }

        return view('livewire.cambios-precio.index', [
            'pendientes' => $pendientes,
            'historial' => $historial,
            'comparativas' => $comparativas,
            'totalPendientes' => CambiosPrecio::pendientes(),
            'proveedores' => $pendientes->isNotEmpty() ? Proveedor::orderBy('nombre')->pluck('nombre') : collect(),
        ]);
    }
}

<?php

namespace App\Livewire\Ned;

use App\Models\Ned\NedCliente;
use App\Models\Ned\NedCotizacion;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Cotizaciones Ned Tracking')]
class Index extends Component
{
    use WithPagination;

    public string $busqueda = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->puedeUsarNed(), 403);
    }

    public function updatingBusqueda(): void
    {
        $this->resetPage();
    }

    public function nueva()
    {
        abort_unless(auth()->user()?->puedeUsarNed(), 403);
        $c = NedCotizacion::create([
            'folio' => NedCotizacion::siguienteFolio(),
            'user_id' => auth()->id(),
            'fecha' => now()->toDateString(),
        ] + NedCliente::datosNuevaCotizacion());

        return $this->redirectRoute('ned.editar', $c);
    }

    public function render()
    {
        $b = trim($this->busqueda);

        return view('livewire.ned.index', [
            'cotizaciones' => NedCotizacion::query()
                ->when($b !== '', fn ($q) => $q->where(function ($w) use ($b) {
                    $w->where('cliente_nombre', 'like', '%'.addcslashes($b, '%_\\').'%');
                    if (preg_match('/(\d+)/', $b, $m)) {
                        $w->orWhere('folio', (int) $m[1]);
                    }
                }))
                ->latest('id')->paginate(25),
        ]);
    }
}

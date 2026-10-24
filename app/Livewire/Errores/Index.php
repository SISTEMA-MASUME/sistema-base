<?php

namespace App\Livewire\Errores;

use App\Models\ErrorSistema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Errores')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $buscar = '';

    #[Url]
    public string $filtro = 'pendientes';

    public ?int $detalleId = null;

    public string $nota = '';

    public string $mensaje = '';

    public function updatedBuscar(): void
    {
        $this->resetPage();
    }

    public function updatedFiltro(): void
    {
        $this->resetPage();
    }

    public function ver(int $id): void
    {
        $this->detalleId = $this->detalleId === $id ? null : $id;
        $this->nota = (string) ErrorSistema::find($id)?->notas;
        $this->mensaje = '';
    }

    public function resolver(int $id): void
    {
        $error = ErrorSistema::findOrFail($id);
        $error->update([
            'resuelto' => true,
            'resuelto_por' => auth()->id(),
            'resuelto_en' => now(),
            'notas' => filled($this->nota) ? mb_substr($this->nota, 0, 2000) : $error->notas,
        ]);
        $this->mensaje = "Error {$error->codigo} marcado como resuelto.";
        $this->detalleId = null;
        $this->nota = '';
    }

    public function render()
    {
        $errores = ErrorSistema::query()
            ->with('usuario:id,name')
            ->when($this->filtro === 'pendientes', fn ($q) => $q->where('resuelto', false))
            ->when($this->filtro === 'resueltos', fn ($q) => $q->where('resuelto', true))
            ->when(filled($this->buscar), fn ($q) => $q->where('codigo', 'like', '%'.trim($this->buscar).'%'))
            ->orderByDesc('ultima_vez')
            ->paginate(20);

        return view('errores.index', [
            'errores' => $errores,
            'detalle' => $this->detalleId ? ErrorSistema::with(['usuario:id,name', 'resolvio:id,name'])->find($this->detalleId) : null,
        ]);
    }
}

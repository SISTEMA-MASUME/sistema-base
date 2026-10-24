<?php

namespace App\Livewire\Proveedores;

use App\Models\Proveedor;
use App\Support\Nombres;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Proveedores')]
class Index extends Component
{
    use WithPagination;

    public string $busqueda = '';

    public bool $mostrarFormulario = false;

    public ?int $proveedorId = null;

    public string $nombre = '';

    public string $contacto = '';

    public string $telefono = '';

    public string $correo = '';

    public string $notas = '';

    public string $mensaje = '';

    public function mount(): void
    {
        $id = request()->integer('editar');
        if ($id && Proveedor::whereKey($id)->exists()) {
            $this->editar($id);
        }
    }

    public function updatingBusqueda(): void
    {
        $this->resetPage();
    }

    protected function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'contacto' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            'notas' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre del proveedor.',
            'correo.email' => 'Escribe un correo válido.',
        ];
    }

    public function nuevo(): void
    {
        $this->resetValidation();
        $this->reset(['proveedorId', 'nombre', 'contacto', 'telefono', 'correo', 'notas', 'mensaje']);
        $this->mostrarFormulario = true;
    }

    public function editar(int $id): void
    {
        $p = Proveedor::findOrFail($id);

        $this->resetValidation();
        $this->mensaje = '';
        $this->proveedorId = $p->id;
        $this->nombre = $p->nombre;
        $this->contacto = (string) $p->contacto;
        $this->telefono = (string) $p->telefono;
        $this->correo = (string) $p->correo;
        $this->notas = (string) $p->notas;
        $this->mostrarFormulario = true;
    }

    public function cancelar(): void
    {
        $this->mostrarFormulario = false;
        $this->resetValidation();
    }

    public function guardar(): void
    {
        $d = $this->validate();

        $repetido = Proveedor::withTrashed()
            ->where('nombre_llave', Nombres::llave($d['nombre']))
            ->when($this->proveedorId, fn ($q) => $q->where('id', '!=', $this->proveedorId))
            ->first();

        if ($repetido) {
            $this->addError('nombre', 'Ya existe un proveedor con ese nombre: '.$repetido->nombre.($repetido->trashed() ? ' (fue borrado; pide reactivarlo)' : '').'.');

            return;
        }

        $campos = [
            'nombre' => $d['nombre'],
            'telefono' => filled($d['telefono']) ? $d['telefono'] : null,
            'correo' => filled($d['correo']) ? $d['correo'] : null,
            'notas' => filled($d['notas']) ? $d['notas'] : null,
        ];

        if ($this->proveedorId) {
            Proveedor::findOrFail($this->proveedorId)->update($campos);
            $this->mensaje = 'Proveedor actualizado.';
        } else {
            Proveedor::create($campos);
            $this->mensaje = 'Proveedor creado.';
        }

        $this->mostrarFormulario = false;
        $this->reset('proveedorId');
    }

    public function borrar(int $id): void
    {
        // Borrado suave: las partidas que ya usaron este proveedor conservan su referencia.
        Proveedor::findOrFail($id)->delete();
        $this->mensaje = 'Proveedor borrado.';

        if ($this->proveedorId === $id) {
            $this->mostrarFormulario = false;
            $this->reset('proveedorId');
        }
    }

    public function render()
    {
        $q = trim($this->busqueda);

        $proveedores = Proveedor::query()
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $llave = Nombres::llave($q);
                $query->where(function ($w) use ($like, $llave) {
                    $w->where('nombre', 'like', $like)
                        ->orWhere('contacto', 'like', $like)
                        ->orWhereHas('contactos', fn ($c) => $c->where('nombre', 'like', $like)->orWhere('telefono', 'like', $like)->orWhere('correo', 'like', $like))
                        ->orWhere('correo', 'like', $like)
                        ->orWhere('telefono', 'like', $like);
                    if ($llave !== '') {
                        $w->orWhere('nombre_llave', 'like', '%'.$llave.'%');
                    }
                });
            })
            ->with('contactos')
            ->orderBy('nombre')
            ->paginate(25);

        return view('livewire.proveedores.index', ['proveedores' => $proveedores]);
    }
}

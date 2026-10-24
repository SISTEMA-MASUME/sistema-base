<?php

namespace App\Livewire\Proveedores;

use App\Models\Proveedor;
use App\Models\ProveedorContacto;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Ficha del proveedor con sus contactos (vendedores). El principal es el que recibe las OC. */
#[Layout('layouts.app')]
#[Title('Ficha de proveedor')]
class Ver extends Component
{
    public int $proveedorId;

    public bool $mostrarContacto = false;

    public ?int $contactoId = null;

    public string $c_nombre = '';

    public string $c_puesto = '';

    public string $c_correo = '';

    public string $c_telefono = '';

    public string $mensaje = '';

    public function mount(Proveedor $proveedor): void
    {
        $this->proveedorId = $proveedor->id;
    }

    private function proveedor(): Proveedor
    {
        return Proveedor::findOrFail($this->proveedorId);
    }

    /** Nos vende a crédito: al generar su OC ya sale CRÉDITO marcado y la compra se anota en "A quién le debo". */
    public function alternarCredito(): void
    {
        $p = $this->proveedor();
        $p->update(['vende_a_credito' => ! $p->vende_a_credito]);
        $this->mensaje = $p->vende_a_credito
            ? 'Listo: '.$p->nombre.' queda como proveedor que nos vende a crédito.'
            : $p->nombre.' ya no aparece como proveedor a crédito.';
    }

    public function nuevoContacto(): void
    {
        $this->resetValidation();
        $this->reset(['contactoId', 'c_nombre', 'c_puesto', 'c_correo', 'c_telefono', 'mensaje']);
        $this->mostrarContacto = true;
    }

    public function editarContacto(int $id): void
    {
        $c = $this->proveedor()->contactos()->findOrFail($id);
        $this->resetValidation();
        $this->mensaje = '';
        $this->contactoId = $c->id;
        $this->c_nombre = $c->nombre;
        $this->c_puesto = (string) $c->puesto;
        $this->c_correo = (string) $c->correo;
        $this->c_telefono = (string) $c->telefono;
        $this->mostrarContacto = true;
    }

    public function cancelarContacto(): void
    {
        $this->mostrarContacto = false;
        $this->resetValidation();
    }

    public function guardarContacto(): void
    {
        $d = $this->validate([
            'c_nombre' => ['required', 'string', 'max:255'],
            'c_puesto' => ['nullable', 'string', 'max:255'],
            'c_correo' => ['nullable', 'email', 'max:255'],
            'c_telefono' => ['nullable', 'string', 'max:30'],
        ], ['c_nombre.required' => 'Escribe el nombre del contacto.', 'c_correo.email' => 'Escribe un correo válido.']);

        $campos = [
            'nombre' => trim($d['c_nombre']),
            'puesto' => filled($d['c_puesto']) ? $d['c_puesto'] : null,
            'correo' => filled($d['c_correo']) ? $d['c_correo'] : null,
            'telefono' => filled($d['c_telefono']) ? $d['c_telefono'] : null,
        ];
        $prov = $this->proveedor();
        if ($this->contactoId) {
            $prov->contactos()->findOrFail($this->contactoId)->update($campos);
            $this->mensaje = 'Contacto actualizado.';
        } else {
            // El primero que se agrega queda como principal.
            ProveedorContacto::create($campos + ['proveedor_id' => $prov->id, 'principal' => ! $prov->contactos()->exists()]);
            $this->mensaje = 'Contacto agregado.';
        }
        $this->mostrarContacto = false;
        $this->reset('contactoId');
    }

    public function hacerPrincipal(int $id): void
    {
        $prov = $this->proveedor();
        $c = $prov->contactos()->findOrFail($id);
        DB::transaction(function () use ($prov, $c) {
            ProveedorContacto::where('proveedor_id', $prov->id)->update(['principal' => false]);
            $c->update(['principal' => true]);
        });
        $this->mensaje = $c->nombre.' es ahora el contacto principal: a él se le mandan las OC.';
    }

    public function borrarContacto(int $id): void
    {
        $prov = $this->proveedor();
        $c = $prov->contactos()->findOrFail($id);
        $c->delete();
        if ($c->principal) {
            $prov->contactos()->first()?->update(['principal' => true]);
        }
        $this->mensaje = 'Contacto borrado.';
    }

    public function render()
    {
        $prov = $this->proveedor();

        return view('livewire.proveedores.ver', [
            'proveedor' => $prov,
            'contactos' => $prov->contactos()->get(),
        ]);
    }
}

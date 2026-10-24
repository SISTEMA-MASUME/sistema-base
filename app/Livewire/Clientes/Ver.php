<?php

namespace App\Livewire\Clientes;

use App\Models\Cliente;
use App\Support\LigasPorConfirmar;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Ficha de cliente')]
class Ver extends Component
{
    public Cliente $cliente;

    public bool $mostrarContacto = false;

    public ?int $contactoId = null;

    public string $c_nombre = '';

    public string $c_puesto = '';

    public string $c_correo = '';

    public string $c_telefono = '';

    public string $mensaje = '';

    public string $razonNombre = '';

    public string $razonRfc = '';

    public string $mensajeRazon = '';

    public function mount(Cliente $cliente): void
    {
        $this->cliente = $cliente;
    }

    protected function rules(): array
    {
        return [
            'c_nombre' => ['required', 'string', 'max:255'],
            'c_puesto' => ['nullable', 'string', 'max:255'],
            'c_correo' => ['nullable', 'email', 'max:255'],
            'c_telefono' => ['nullable', 'string', 'max:30'],
        ];
    }

    protected function messages(): array
    {
        return [
            'c_nombre.required' => 'Escribe el nombre del contacto.',
            'c_correo.email' => 'Escribe un correo válido.',
        ];
    }

    public function nuevoContacto(): void
    {
        $this->resetValidation();
        $this->reset(['contactoId', 'c_nombre', 'c_puesto', 'c_correo', 'c_telefono', 'mensaje']);
        $this->mostrarContacto = true;
    }

    public function editarContacto(int $id): void
    {
        $c = $this->cliente->contactos()->findOrFail($id);

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
        $d = $this->validate();

        $campos = [
            'nombre' => trim($d['c_nombre']),
            'puesto' => filled($d['c_puesto']) ? $d['c_puesto'] : null,
            'correo' => filled($d['c_correo']) ? $d['c_correo'] : null,
            'telefono' => filled($d['c_telefono']) ? $d['c_telefono'] : null,
        ];

        if ($this->contactoId) {
            $this->cliente->contactos()->findOrFail($this->contactoId)->update($campos);
            $this->mensaje = 'Contacto actualizado.';
        } else {
            $this->cliente->contactos()->create($campos);
            $this->mensaje = 'Contacto agregado.';
        }

        $this->mostrarContacto = false;
        $this->reset('contactoId');
    }

    public function borrarContacto(int $id): void
    {
        $this->cliente->contactos()->findOrFail($id)->delete();
        $this->mensaje = 'Contacto borrado.';

        if ($this->contactoId === $id) {
            $this->mostrarContacto = false;
            $this->reset('contactoId');
        }
    }

    /** Agrega a mano un nombre (razón social) con el que se le factura a este cliente en SICAR. */
    public function agregarRazon(): void
    {
        abort_unless(auth()->user()?->puede('ventas'), 403);
        $this->validate([
            'razonNombre' => ['required', 'string', 'max:255'],
            'razonRfc' => ['nullable', 'string', 'max:20'],
        ], ['razonNombre.required' => 'Escribe el nombre con el que sale la factura.']);

        $ligadas = LigasPorConfirmar::registrarRazon($this->cliente, $this->razonNombre, $this->razonRfc, auth()->id());
        $this->mensajeRazon = 'Razón social agregada.'.($ligadas > 0 ? ' Se ligaron '.$ligadas.($ligadas === 1 ? ' factura' : ' facturas').' que esperaban confirmación.' : '');
        $this->reset(['razonNombre', 'razonRfc']);
    }

    public function quitarRazon(int $id): void
    {
        abort_unless(auth()->user()?->puede('ventas'), 403);
        $this->cliente->razonesSociales()->whereKey($id)->delete();
        $this->mensajeRazon = 'Razón social quitada. Las facturas ya ligadas se quedan como están.';
    }

    public function render()
    {
        return view('livewire.clientes.ver', [
            'razones' => $this->cliente->razonesSociales()->get(),
            'contactos' => $this->cliente->contactos()->orderBy('nombre')->get(),
            'cotizaciones' => $this->cliente->cotizaciones()->orderByDesc('fecha')->orderByDesc('id')->get(),
        ]);
    }
}

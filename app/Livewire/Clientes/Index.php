<?php

namespace App\Livewire\Clientes;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Venta;
use App\Support\Nombres;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Clientes')]
class Index extends Component
{
    use WithPagination;

    public string $busqueda = '';

    public bool $mostrarFormulario = false;

    public ?int $clienteId = null;

    public string $nombre = '';

    public string $razon_social = '';

    public string $rfc = '';

    public string $direccion = '';

    public string $telefono = '';

    public string $correo = '';

    public int|string $dias_credito = 30;

    public string $notas = '';

    public bool $oc_despues_de_facturar = false;

    public string $portal_url = '';

    public bool $sube_factura_portal = false;

    public bool $envia_evidencia_por_correo = false;

    public string $mensaje = '';

    public string $error = '';

    public function mount(): void
    {
        $id = request()->integer('editar');
        if ($id && Cliente::whereKey($id)->exists()) {
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
            'razon_social' => ['nullable', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            'dias_credito' => ['required', 'integer', 'min:0', 'max:365'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'oc_despues_de_facturar' => ['boolean'],
            'portal_url' => ['nullable', 'url', 'max:255'],
            'sube_factura_portal' => ['boolean'],
            'envia_evidencia_por_correo' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre del cliente.',
            'rfc.max' => 'El RFC tiene máximo 13 caracteres.',
            'portal_url.url' => 'Escribe la dirección completa del portal, empezando con https://',
            'correo.email' => 'Escribe un correo válido.',
            'dias_credito.required' => 'Escribe los días de crédito (0 si es de contado).',
            'dias_credito.integer' => 'Los días de crédito deben ser un número.',
            'dias_credito.min' => 'Los días de crédito no pueden ser negativos.',
            'dias_credito.max' => 'Los días de crédito no pueden pasar de 365.',
        ];
    }

    public function nuevo(): void
    {
        $this->resetValidation();
        $this->reset(['clienteId', 'nombre', 'razon_social', 'rfc', 'direccion', 'telefono', 'correo', 'notas', 'oc_despues_de_facturar', 'portal_url', 'sube_factura_portal', 'envia_evidencia_por_correo', 'mensaje', 'error']);
        $this->dias_credito = 30;
        $this->mostrarFormulario = true;
    }

    public function editar(int $id): void
    {
        $c = Cliente::findOrFail($id);

        $this->resetValidation();
        $this->mensaje = '';
        $this->error = '';
        $this->clienteId = $c->id;
        $this->nombre = $c->nombre;
        $this->razon_social = (string) $c->razon_social;
        $this->rfc = (string) $c->rfc;
        $this->direccion = (string) $c->direccion;
        $this->telefono = (string) $c->telefono;
        $this->correo = (string) $c->correo;
        $this->dias_credito = (int) $c->dias_credito;
        $this->notas = (string) $c->notas;
        $this->oc_despues_de_facturar = (bool) $c->oc_despues_de_facturar;
        $this->portal_url = (string) $c->portal_url;
        $this->sube_factura_portal = (bool) $c->sube_factura_portal;
        $this->envia_evidencia_por_correo = (bool) $c->envia_evidencia_por_correo;
        $this->mostrarFormulario = true;
    }

    public function cancelar(): void
    {
        $this->mostrarFormulario = false;
        $this->resetValidation();
    }

    public function guardar(): void
    {
        $datos = $this->validate();
        $this->error = '';

        $repetido = Cliente::withTrashed()
            ->where('nombre_llave', Nombres::llave($datos['nombre']))
            ->when($this->clienteId, fn ($q) => $q->where('id', '!=', $this->clienteId))
            ->first();

        if ($repetido) {
            $this->addError('nombre', 'Ya existe un cliente con ese nombre: '.$repetido->nombre.($repetido->trashed() ? ' (fue borrado; pide reactivarlo)' : '').'.');

            return;
        }

        $campos = [
            'nombre' => $datos['nombre'],
            'razon_social' => filled($datos['razon_social']) ? $datos['razon_social'] : null,
            'rfc' => filled($datos['rfc']) ? mb_strtoupper(trim($datos['rfc'])) : null,
            'direccion' => filled($datos['direccion']) ? $datos['direccion'] : null,
            'telefono' => filled($datos['telefono']) ? $datos['telefono'] : null,
            'correo' => filled($datos['correo']) ? $datos['correo'] : null,
            'dias_credito' => (int) $datos['dias_credito'],
            'notas' => filled($datos['notas']) ? $datos['notas'] : null,
            'oc_despues_de_facturar' => (bool) $datos['oc_despues_de_facturar'],
            'portal_url' => filled($datos['portal_url']) ? trim($datos['portal_url']) : null,
            // Si genera la OC después de facturar, también se le sube la factura a su portal.
            'sube_factura_portal' => (bool) $datos['sube_factura_portal'] || (bool) $datos['oc_despues_de_facturar'],
            'envia_evidencia_por_correo' => (bool) $datos['envia_evidencia_por_correo'],
        ];

        if ($this->clienteId) {
            Cliente::findOrFail($this->clienteId)->update($campos);
            $this->mensaje = 'Cliente actualizado.';
        } else {
            Cliente::create($campos);
            $this->mensaje = 'Cliente creado.';
        }

        $this->mostrarFormulario = false;
        $this->reset('clienteId');
    }

    public function borrar(int $id): void
    {
        $cliente = Cliente::findOrFail($id);
        $this->mensaje = '';

        $tiene = array_keys(array_filter([
            'cotizaciones' => $cliente->cotizaciones()->exists(),
            'pedidos' => Pedido::withTrashed()->where('cliente_id', $cliente->id)->exists(),
            'ventas' => Venta::where('cliente_id', $cliente->id)->exists(),
        ]));
        if ($tiene) {
            $this->error = 'No se puede borrar a '.$cliente->nombre.' porque tiene '.implode(' y ', $tiene).'. Puedes editarlo si algún dato cambió.';

            return;
        }

        $cliente->delete();
        $this->error = '';
        $this->mensaje = 'Cliente borrado.';

        if ($this->clienteId === $id) {
            $this->mostrarFormulario = false;
            $this->reset('clienteId');
        }
    }

    public function render()
    {
        $q = trim($this->busqueda);

        $clientes = Cliente::query()
            ->withCount('contactos')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $llave = Nombres::llave($q);
                $query->where(function ($w) use ($like, $llave) {
                    $w->where('nombre', 'like', $like)
                        ->orWhere('razon_social', 'like', $like)
                        ->orWhere('rfc', 'like', $like)
                        ->orWhere('correo', 'like', $like)
                        ->orWhere('telefono', 'like', $like);
                    if ($llave !== '') {
                        $w->orWhere('nombre_llave', 'like', '%'.$llave.'%');
                    }
                });
            })
            ->orderBy('nombre')
            ->paginate(25);

        return view('livewire.clientes.index', ['clientes' => $clientes]);
    }
}

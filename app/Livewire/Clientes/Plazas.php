<?php

namespace App\Livewire\Clientes;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Support\CuentaCorreo;
use App\Support\UnirClientes;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Plazas del cliente (PARQUES / LUMEX): alta, edición y unir clientes separados como plazas (T16). */
class Plazas extends Component
{
    #[Locked]
    public int $clienteId;

    public bool $formulario = false;

    public ?int $plazaId = null;

    public string $nombre = '';

    public string $ciudad = '';

    public string $estado = '';

    public string $direccion = '';

    public string $correos = '';

    public string $contacto = '';

    public string $telefono = '';

    public string $notas = '';

    public string $mensaje = '';

    // Unir clientes separados como plazas (solo admin, con vista previa)
    public string $buscarUnir = '';

    /** @var list<int|string> */
    public array $unir = [];

    public bool $vistaPrevia = false;

    public function mount(int $clienteId): void
    {
        $this->clienteId = $clienteId;
    }

    private function cliente(): Cliente
    {
        return Cliente::findOrFail($this->clienteId);
    }

    public function nueva(): void
    {
        $this->resetValidation();
        $this->reset(['plazaId', 'nombre', 'ciudad', 'estado', 'direccion', 'correos', 'contacto', 'telefono', 'notas', 'mensaje']);
        $this->formulario = true;
    }

    public function editar(int $id): void
    {
        $s = ClienteSucursal::where('cliente_id', $this->clienteId)->findOrFail($id);
        $this->resetValidation();
        $this->plazaId = $s->id;
        foreach (['nombre', 'ciudad', 'estado', 'direccion', 'contacto', 'telefono', 'notas'] as $c) {
            $this->$c = (string) $s->$c;
        }
        $this->correos = (string) $s->correos_facturacion;
        $this->formulario = true;
    }

    public function cancelar(): void
    {
        $this->formulario = false;
    }

    public function guardar(): void
    {
        $d = $this->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'string', 'max:100'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'correos' => ['nullable', 'string', 'max:1000'],
            'contacto' => ['nullable', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'notas' => ['nullable', 'string', 'max:5000'],
        ], ['nombre.required' => 'Escribe el nombre de la plaza.']);

        $correos = CuentaCorreo::separar($d['correos']);
        foreach ($correos as $c) {
            if (Validator::make(['c' => $c], ['c' => 'email'])->fails()) {
                $this->addError('correos', "El correo \"$c\" no es válido.");

                return;
            }
        }

        $campos = [
            'cliente_id' => $this->clienteId,
            'nombre' => mb_strtoupper(trim($d['nombre'])),
            'correos_facturacion' => $correos ? implode(', ', $correos) : null,
        ];
        foreach (['ciudad', 'estado', 'direccion', 'contacto', 'telefono', 'notas'] as $c) {
            $campos[$c] = filled($d[$c]) ? trim($d[$c]) : null;
        }

        if ($this->plazaId) {
            ClienteSucursal::where('cliente_id', $this->clienteId)->findOrFail($this->plazaId)->update($campos);
            $this->mensaje = 'Plaza actualizada.';
        } else {
            ClienteSucursal::create($campos);
            $this->mensaje = 'Plaza agregada.';
        }
        $this->formulario = false;
    }

    public function borrar(int $id): void
    {
        ClienteSucursal::where('cliente_id', $this->clienteId)->findOrFail($id)->delete();
        $this->mensaje = 'Plaza borrada (sus pedidos conservan el dato).';
    }

    public function verVistaPrevia(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $this->vistaPrevia = $this->unir !== [];
    }

    public function confirmarUnion(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $ids = array_map('intval', $this->unir);
        $n = UnirClientes::aplicar($this->cliente(), $ids);
        $this->reset(['unir', 'vistaPrevia', 'buscarUnir']);
        $this->mensaje = "Listo: {$n} ".($n === 1 ? 'cliente quedó' : 'clientes quedaron').' como plazas, con sus cotizaciones, pedidos y contactos.';
    }

    public function render()
    {
        $cliente = $this->cliente();
        $candidatos = collect();
        $b = trim($this->buscarUnir);
        if (auth()->user()->esAdmin() && mb_strlen($b) >= 2) {
            $like = '%'.addcslashes($b, '%_\\').'%';
            $candidatos = Cliente::where('id', '!=', $cliente->id)->where('nombre', 'like', $like)->orderBy('nombre')->limit(30)->get(['id', 'nombre']);
        }
        $marcados = Cliente::whereIn('id', array_map('intval', $this->unir))->orderBy('nombre')->get(['id', 'nombre']);

        return view('livewire.clientes.plazas', [
            'cliente' => $cliente,
            'plazas' => ClienteSucursal::where('cliente_id', $cliente->id)->withCount('pedidos')->orderBy('nombre')->get(),
            'candidatos' => $candidatos->concat($marcados)->unique('id')->sortBy('nombre')->values(),
            'previa' => $this->vistaPrevia ? UnirClientes::vistaPrevia($cliente, array_map('intval', $this->unir)) : [],
            'esAdmin' => auth()->user()->esAdmin(),
        ]);
    }
}

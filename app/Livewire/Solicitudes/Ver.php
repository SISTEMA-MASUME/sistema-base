<?php

namespace App\Livewire\Solicitudes;

use App\Models\Cliente;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use App\Support\Solicitudes;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Solicitud por cotizar')]
class Ver extends Component
{
    #[Locked]
    public int $solicitudId = 0;

    public string $mensaje = '';

    public string $error = '';

    public string $motivo = '';

    public bool $descartando = false;

    public string $reasignarA = '';

    public string $buscarCliente = '';

    public function mount(SolicitudCotizacion $solicitud): void
    {
        abort_unless(Solicitudes::puedeUsar(auth()->user()), 403);
        $this->solicitudId = $solicitud->id;
    }

    private function s(): SolicitudCotizacion
    {
        return SolicitudCotizacion::findOrFail($this->solicitudId);
    }

    private function intentar(callable $accion, string $ok)
    {
        $this->mensaje = $this->error = '';
        try {
            $resultado = $accion();
        } catch (DomainException $e) {
            $this->error = $e->getMessage();

            return null;
        }
        $this->mensaje = $ok;

        return $resultado ?? true;
    }

    public function tomar()
    {
        $this->mensaje = $this->error = '';
        try {
            $cot = Solicitudes::tomar($this->s(), auth()->user());
        } catch (DomainException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        return $this->redirectRoute('cotizaciones.editar', $cot);
    }

    public function soltar(): void
    {
        $this->intentar(fn () => Solicitudes::soltar($this->s(), auth()->user()), 'La soltaste: vuelve a pendientes. La cotización en borrador se conserva, pero ya no está ligada a esta solicitud.');
    }

    public function reasignar(): void
    {
        $nuevo = User::where('activo', true)->find((int) $this->reasignarA);
        if (! $nuevo) {
            $this->error = 'Escoge a quién se la das.';

            return;
        }
        $this->intentar(fn () => Solicitudes::reasignar($this->s(), auth()->user(), $nuevo), 'Reasignada a '.$nuevo->nombreReal().'.');
        $this->reasignarA = '';
    }

    public function descartar(): void
    {
        if ($this->intentar(fn () => Solicitudes::descartar($this->s(), auth()->user(), $this->motivo), 'Solicitud descartada.')) {
            $this->reset(['motivo', 'descartando']);
        }
    }

    public function seleccionarCliente(int $id): void
    {
        $s = $this->s();
        abort_unless($s->estaAbierta(), 403);
        $s->update(['cliente_id' => Cliente::findOrFail($id)->id, 'cliente_texto' => null]);
        $this->buscarCliente = '';
        $this->mensaje = 'Cliente asignado.';
    }

    /** Da de alta (o usa el existente con ese nombre) el cliente que vino escrito a mano. */
    public function darDeAltaCliente(): void
    {
        $s = $this->s();
        abort_unless($s->estaAbierta(), 403);
        $this->intentar(function () use ($s) {
            $c = Solicitudes::asegurarCliente((string) $s->cliente_texto);
            $s->update(['cliente_id' => $c->id, 'cliente_texto' => null]);
        }, 'Cliente listo.');
    }

    public function render()
    {
        $s = $this->s()->load(['cliente', 'tomadaPor', 'creadaPor', 'cotizacion', 'archivos', 'eventos.user:id,name']);
        $yo = auth()->user();
        $texto = trim($this->buscarCliente);

        return view('livewire.solicitudes.ver', [
            's' => $s,
            'puedeSoltar' => Solicitudes::puedeSoltar($yo, $s),
            'puedeDescartar' => Solicitudes::puedeDescartar($yo, $s),
            'esAdmin' => $yo->esAdmin(),
            'vendedores' => $yo->esAdmin() ? User::where('activo', true)->whereIn('rol', ['admin', 'vendedor'])->orderBy('name')->get(['id', 'name']) : collect(),
            'resultados' => $texto !== '' && ! $s->cliente_id
                ? Cliente::where('nombre', 'like', '%'.mb_strtoupper(addcslashes($texto, '%_\\')).'%')->orderBy('nombre')->limit(8)->get(['id', 'nombre'])
                : collect(),
            'envio' => $s->enviadaAt(),
        ]);
    }
}

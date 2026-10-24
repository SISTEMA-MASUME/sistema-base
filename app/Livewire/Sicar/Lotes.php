<?php

namespace App\Livewire\Sicar;

use App\Enums\EstadoLoteSicar;
use App\Models\Ajuste;
use App\Models\SicarLote;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Lotes SICAR')]
class Lotes extends Component
{
    /** Lote con el detalle desplegado. */
    public ?int $abierto = null;

    public string $mensaje = '';

    public string $error = '';

    public function mount(): void
    {
        $this->autorizar();
    }

    private function autorizar(): void
    {
        abort_if(! auth()->user() || auth()->user()->esCampo() || auth()->user()->esNed(), 403);
    }

    public function detalle(int $id): void
    {
        $this->autorizar();
        $this->abierto = $this->abierto === $id ? null : $id;
    }

    /** Enciende o apaga el puente (solo Daniel). */
    public function alternarPuente(): void
    {
        abort_unless(auth()->user()?->esAdmin(), 403);
        Ajuste::ponerPuenteActivo(! Ajuste::puenteActivo());
    }

    /** Confirma un lote simulado y sin bloqueos: la PC lo guardará de verdad. */
    public function confirmar(int $id): void
    {
        $this->autorizar();
        $this->mensaje = $this->error = '';
        $lote = SicarLote::findOrFail($id);
        if ($lote->estado !== EstadoLoteSicar::Simulado) {
            $this->error = 'Solo se confirma un lote con simulacro listo.';

            return;
        }
        if ($lote->bloqueos() !== []) {
            $this->error = 'El simulacro tiene bloqueos: no se puede confirmar.';

            return;
        }
        // Atómico: si la PC o alguien más lo movió, no se confirma.
        $n = SicarLote::whereKey($id)->where('estado', EstadoLoteSicar::Simulado->value)->update([
            'estado' => EstadoLoteSicar::Confirmado->value, 'confirmado_por' => auth()->id(), 'confirmado_at' => now(),
        ]);
        $this->mensaje = $n ? "Lote {$id} confirmado. La PC lo guardará en SICAR en unos minutos." : 'El lote cambió de estado; revisa de nuevo.';
    }

    /** Cancela un lote pendiente, simulado o con error: libera sus pedidos. */
    public function cancelar(int $id): void
    {
        $this->autorizar();
        $this->mensaje = $this->error = '';
        $n = SicarLote::whereKey($id)->whereIn('estado', [EstadoLoteSicar::Pendiente->value, EstadoLoteSicar::Simulado->value, EstadoLoteSicar::Error->value])
            ->update(['estado' => EstadoLoteSicar::Cancelado->value]);
        $this->mensaje = $n ? "Lote {$id} cancelado; sus pedidos quedaron libres." : 'Ese lote ya no se puede cancelar.';
    }

    public function render()
    {
        $lotes = SicarLote::with(['creador:id,name', 'confirmador:id,name', 'pedidos:id,folio'])->orderByDesc('id')->limit(50)->get();
        $conexion = Ajuste::ultimaConexionPuente();

        return view('livewire.sicar.lotes', [
            'lotes' => $lotes,
            'activo' => Ajuste::puenteActivo(),
            'conexion' => $conexion,
            'conexionVieja' => ! $conexion || $conexion->lt(now()->subMinutes(5)),
            'hayAbiertos' => $lotes->contains(fn ($l) => in_array($l->estado, [EstadoLoteSicar::Pendiente, EstadoLoteSicar::Confirmado], true)),
        ]);
    }
}

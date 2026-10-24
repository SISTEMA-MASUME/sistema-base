<?php

namespace App\Livewire\Cotizaciones;

use App\Models\Cotizacion;
use App\Models\CotizacionComentario;
use App\Models\CotizacionPartida;
use App\Support\Bitacora;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Revisión interna de la cotización (antes: link del Sheets al grupo de WhatsApp). El vendedor pide
 * revisión, Ernesto / Paty / Daniel corrigen directo en el cotizador o comentan, y la marcan como
 * revisada. Todo queda en la bitácora (historial de cambios). Es opcional: al enviar sin revisión solo
 * se pregunta.
 */
class Revision extends Component
{
    #[Locked]
    public int $cotizacionId;

    public string $comentario = '';

    public string $partidaId = '';

    public string $mensaje = '';

    public function mount(int $cotizacionId): void
    {
        $this->cotizacionId = $cotizacionId;
    }

    private function cot(): Cotizacion
    {
        return Cotizacion::findOrFail($this->cotizacionId);
    }

    private function avisar(): void
    {
        // Compartir y EnviarCorreo vuelven a leer si ya está revisada (para preguntar o no).
        $this->dispatch('revision-actualizada');
    }

    /**
     * Mensaje para el grupo, armado en el momento de pedir la revisión (no al abrir la página: los precios
     * se capturan después y salía "Total: $0.00"). Sin emojis: en algunos celulares llegaban como "�".
     */
    private function urlWhatsapp(): string
    {
        $cot = Cotizacion::with(['cliente:id,nombre', 'vendedor:id,name'])->findOrFail($this->cotizacionId);
        $total = '$'.number_format((float) $cot->total, 2);
        $texto = "*Revisión de cotización {$cot->folioConRevision(' ')}*\nCliente: {$cot->cliente->nombre}\nTotal: {$total}\nVendedor: ".($cot->vendedor->name ?? auth()->user()->name)
            ."\n\nÁbrela aquí para revisar y corregir:\n".route('cotizaciones.revision', $cot);

        return 'https://wa.me/?text='.rawurlencode($texto);
    }

    /** Anota la revisión y regresa el enlace de WhatsApp con el mensaje para el grupo. */
    public function pedirRevision(): string
    {
        $cot = $this->cot();
        // Pedirla otra vez (p. ej. tras nuevos cambios) quita la marca de revisada.
        $cot->forceFill(['revision_pedida_at' => now(), 'revision_pedida_por' => auth()->id(), 'revisada_at' => null, 'revisada_por' => null])->save();
        Bitacora::evento($cot, 'revision_pedida');
        $this->mensaje = 'Se marcó "En revisión". Manda el mensaje al grupo de WhatsApp que se abrió.';
        $this->avisar();

        return $this->urlWhatsapp();
    }

    public function marcarRevisada(): void
    {
        $cot = $this->cot();
        $cot->forceFill(['revisada_at' => now(), 'revisada_por' => auth()->id()])->save();
        Bitacora::evento($cot, 'revisada');
        $this->mensaje = 'Cotización marcada como revisada. Ya se puede mandar al cliente.';
        $this->avisar();
    }

    public function comentar(): void
    {
        $this->validate(['comentario' => ['required', 'string', 'max:3000']], [
            'comentario.required' => 'Escribe el comentario.',
            'comentario.max' => 'El comentario es muy largo.',
        ]);
        $cot = $this->cot();
        $partida = $this->partidaId !== ''
            ? CotizacionPartida::where('cotizacion_id', $cot->id)->find((int) $this->partidaId)
            : null;
        $texto = trim($this->comentario);
        CotizacionComentario::create([
            'cotizacion_id' => $cot->id, 'cotizacion_partida_id' => $partida?->id, 'partida_orden' => $partida?->orden,
            'user_id' => auth()->id(), 'texto' => $texto,
        ]);
        Bitacora::evento($cot, 'comentario', mb_strimwidth($texto, 0, 200, '…'), $partida?->orden);
        $this->reset(['comentario', 'partidaId', 'mensaje']);
        $this->dispatch('revision-actualizada');
    }

    public function resolver(int $id): void
    {
        $c = CotizacionComentario::where('cotizacion_id', $this->cotizacionId)->findOrFail($id);
        if ($c->resuelto_at) {
            return;
        }
        $c->update(['resuelto_at' => now(), 'resuelto_por' => auth()->id()]);
        Bitacora::evento($this->cot(), 'comentario_resuelto', mb_strimwidth($c->texto, 0, 200, '…'), $c->partida_orden);
        $this->dispatch('revision-actualizada');
    }

    /** Se agregaron o borraron partidas en el cotizador: se actualiza la lista para comentar. */
    #[On('partidas-cambiaron')]
    public function refrescar(): void
    {
        // Solo vuelve a renderizar.
    }

    public function render()
    {
        $cot = Cotizacion::with(['cliente:id,nombre', 'vendedor:id,name', 'revisionPedidaPor:id,name', 'revisadaPor:id,name'])->findOrFail($this->cotizacionId);

        return view('livewire.cotizaciones.revision', [
            'cot' => $cot,
            'enRevision' => $cot->revision_pedida_at !== null && ! $cot->estaRevisada(),
            'revisada' => $cot->estaRevisada(),
            'comentarios' => $cot->comentarios()->with('user:id,name', 'resueltoPor:id,name')->get(),
            'partidas' => $cot->partidas()->orderBy('orden')->get(['id', 'orden', 'descripcion']),
        ]);
    }
}

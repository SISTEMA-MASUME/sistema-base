@php
    $fecha = fn ($f) => $f?->timezone('America/Mexico_City')->format('d/m/Y H:i');
@endphp
<div class="mb-4 rounded-xl bg-white p-4 shadow-sm sm:p-5" id="revision-interna">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-marino">Revisión interna</h2>
            @if ($revisada)
                <p class="mt-1 inline-block rounded-full bg-green-100 px-3 py-1 text-sm font-semibold text-green-800">✓ Revisada por {{ $cot->revisadaPor?->name ?? 'alguien' }} · {{ $fecha($cot->revisada_at) }}</p>
            @elseif ($enRevision)
                <p class="mt-1 inline-block rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-900">🔎 En revisión · la pidió {{ $cot->revisionPedidaPor?->name ?? 'alguien' }} el {{ $fecha($cot->revision_pedida_at) }}</p>
            @else
            @endif
        </div>
        <div class="flex flex-col gap-2 sm:flex-row">
            {{-- La ventana se abre en el clic (si no, el celular la bloquea) y luego se le pone el mensaje ya con el total al día. --}}
            <button type="button"
                    x-on:click="const w = window.open('', '_blank'); $wire.pedirRevision().then((url) => { if (w) { w.location = url } else { window.location = url } })"
                    class="btn min-h-12 bg-[#25d366] text-white hover:bg-[#1ebe5b]">{{ $enRevision || $revisada ? 'Volver a pedir revisión' : 'Pedir revisión (WhatsApp)' }}</button>
            @unless ($revisada)
                <button type="button" wire:click="marcarRevisada"
                        class="btn-primario">✓ Marcar revisada</button>
            @endunless
        </div>
    </div>

    @if ($mensaje)
        <div role="status" class="mt-3 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif
</div>

<div>
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <input type="search" wire:model.live.debounce.300ms="busqueda" placeholder="Buscar por cliente o folio" aria-label="Buscar cotización" class="campo sm:max-w-md">
        <div class="flex flex-col gap-2 sm:flex-row">
            @unless (auth()->user()->esNed())
                <a href="{{ route('ned.datos') }}" class="btn-secundario">Datos y logo de Ned</a>
            @endunless
            <button type="button" wire:click="nueva" class="btn-primario w-full sm:w-auto">+ Nueva cotización</button>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl bg-white shadow-sm">
        <ul class="divide-y divide-slate-200">
            @forelse ($cotizaciones as $c)
                <li wire:key="ned-{{ $c->id }}" class="flex flex-col gap-2 p-4 hover:bg-slate-50 sm:flex-row sm:items-center">
                    <a href="{{ route('ned.editar', $c) }}" class="flex min-w-0 flex-1 flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                        <span class="font-semibold text-marino">{{ $c->folioTexto() }} · {{ $c->cliente_nombre }}</span>
                        <span class="text-sm text-slate-600">{{ $c->fecha->format('d/m/Y') }} · ${{ number_format((float) $c->total, 2) }}</span>
                    </a>
                    {{-- Un clic: en el celular abre "Compartir" (WhatsApp, correo…) con el PDF; en la computadora lo descarga. --}}
                    <button type="button" class="btn min-h-12 shrink-0 bg-[#25d366] text-white hover:bg-[#1ebe5b] sm:ml-4"
                            x-data="{ cargando: false, async mandar() {
                                this.cargando = true;
                                try {
                                    const r = await fetch(@js(route('ned.pdf', $c)), { credentials: 'same-origin' });
                                    const f = new File([await r.blob()], @js($c->nombreArchivoPdf()), { type: 'application/pdf' });
                                    if (navigator.canShare && navigator.canShare({ files: [f] })) { try { await navigator.share({ files: [f] }); } catch (e) {} }
                                    else { window.location = @js(route('ned.pdf', ['cotizacion' => $c, 'descargar' => 1])); }
                                } finally { this.cargando = false; }
                            } }" x-on:click="mandar()" x-bind:disabled="cargando">
                        <span x-text="cargando ? 'Preparando…' : 'Mandar PDF'">Mandar PDF</span>
                    </button>
                </li>
            @empty
                <li class="p-6 text-center text-slate-600">Sin cotizaciones</li>
            @endforelse
        </ul>
    </div>
    <div class="mt-4">{{ $cotizaciones->links() }}</div>
</div>

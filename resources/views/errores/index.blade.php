<div>
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <input type="search" wire:model.live.debounce.300ms="buscar" placeholder="Buscar por código (E-XXXXXX)" class="campo sm:col-span-2" aria-label="Buscar por código">
        <select wire:model.live="filtro" class="campo" aria-label="Filtro">
            <option value="pendientes">No resueltos</option>
            <option value="resueltos">Resueltos</option>
            <option value="todos">Todos</option>
        </select>
    </div>

    @if ($mensaje)
        <div role="status" class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    <div class="space-y-3">
        @forelse ($errores as $e)
            <div class="rounded-xl bg-white p-4 shadow-sm" wire:key="err-{{ $e->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-lg font-bold text-marino">{{ $e->codigo }}
                            @if ($e->resuelto) <span class="ml-2 rounded bg-green-100 px-2 py-0.5 text-sm font-medium text-green-800">Resuelto</span> @endif
                        </p>
                        <p class="break-words text-slate-800">{{ \Illuminate\Support\Str::limit($e->mensaje, 120) }}</p>
                        <p class="mt-1 break-all text-sm text-slate-600">
                            {{ $e->ocurrencias }} {{ $e->ocurrencias == 1 ? 'vez' : 'veces' }} ·
                            última: {{ $e->ultima_vez?->format('d/m/Y H:i') }} ·
                            {{ $e->usuario?->name ?? 'sin usuario' }} ·
                            {{ $e->url }}
                        </p>
                    </div>
                    <button type="button" wire:click="ver({{ $e->id }})" class="btn-secundario w-full sm:w-auto">
                        {{ $detalleId === $e->id ? 'Ocultar' : 'Ver detalle' }}
                    </button>
                </div>

                @if ($detalle && $detalle->id === $e->id)
                    <div class="mt-4 space-y-3 border-t border-slate-200 pt-4 text-sm">
                        <p><strong>Clase:</strong> <span class="break-all">{{ $detalle->clase }}</span></p>
                        <p><strong>Mensaje:</strong> <span class="break-words">{{ $detalle->mensaje }}</span></p>
                        <p><strong>Archivo:</strong> <span class="break-all">{{ $detalle->archivo }}:{{ $detalle->linea }}</span></p>
                        <p><strong>Primera vez:</strong> {{ $detalle->primera_vez?->format('d/m/Y H:i') }} · <strong>Petición:</strong> {{ $detalle->metodo }}</p>
                        @if ($detalle->resuelto)
                            <p><strong>Resuelto por:</strong> {{ $detalle->resolvio?->name ?? '—' }} el {{ $detalle->resuelto_en?->format('d/m/Y H:i') }}</p>
                            @if ($detalle->notas) <p><strong>Nota:</strong> {{ $detalle->notas }}</p> @endif
                        @endif
                        @if ($detalle->contexto)
                            <details>
                                <summary class="cursor-pointer font-semibold text-marino">Datos de la petición</summary>
                                <pre class="mt-2 overflow-x-auto rounded bg-slate-100 p-3 text-xs">{{ json_encode($detalle->contexto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </details>
                        @endif
                        <details>
                            <summary class="cursor-pointer font-semibold text-marino">Traza técnica</summary>
                            <pre class="mt-2 max-h-96 overflow-auto rounded bg-slate-100 p-3 text-xs">{{ $detalle->traza }}</pre>
                        </details>
                        @unless ($detalle->resuelto)
                            <div>
                                <label class="etiqueta" for="nota-{{ $e->id }}">Nota (qué se hizo)</label>
                                <textarea id="nota-{{ $e->id }}" wire:model="nota" rows="2" class="campo"></textarea>
                                <button type="button" wire:click="resolver({{ $e->id }})" class="btn-primario mt-2 w-full sm:w-auto">Marcar resuelto</button>
                            </div>
                        @endunless
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-xl bg-white p-6 text-center text-slate-600 shadow-sm">No hay errores en esta lista.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $errores->links() }}</div>
</div>

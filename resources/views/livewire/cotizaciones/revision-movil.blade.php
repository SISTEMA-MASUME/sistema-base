@php
    $dinero = fn ($n) => $n === null ? 'Sin calcular' : '$'.number_format((float) $n, 2);
    $numero = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
    $porcentaje = fn ($n) => $n === null ? 'Sin calcular' : rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.').'%';
@endphp
<div class="mx-auto max-w-3xl space-y-4" x-data="{ pendientes: 0 }"
     x-on:utilidad-edicion="pendientes = Math.max(0, pendientes + $event.detail.cambio)">
    <header class="rounded-xl bg-white p-4 shadow-sm sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-bold text-marino">Revisar cotización {{ $cot->folioConRevision() }}</h1>
            <a href="{{ route('cotizaciones.editar', $cot) }}" class="inline-flex min-h-11 items-center font-semibold text-marino underline decoration-naranja underline-offset-4">Abrir cotizador</a>
        </div>
        <p class="mt-2 break-words text-lg font-semibold text-slate-900">{{ $cot->cliente?->nombre }}</p>
        <p class="mt-1 text-sm text-slate-600">Elaboró {{ $cot->vendedor?->name ?? 'Sin asignar' }} · {{ $cot->fecha?->format('d/m/Y') }} · {{ $cot->moneda }}</p>
        @if ($cot->estaRevisada())
            <p class="mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm font-semibold text-green-800">Revisada por {{ $cot->revisadaPor?->name ?? 'alguien' }} · {{ $cot->revisada_at->timezone('America/Mexico_City')->format('d/m/Y H:i') }}</p>
        @elseif ($cot->revision_pedida_at)
            <p class="mt-3 text-sm text-slate-600">En revisión · la pidió {{ $cot->revisionPedidaPor?->name ?? 'alguien' }}</p>
        @endif
    </header>

    {{-- Debajo de la barra móvil del sistema; en la PC queda al borde superior. --}}
    <section aria-label="Utilidad total de la cotización" class="sticky top-[72px] z-20 rounded-xl bg-marino p-4 text-white shadow-sm lg:top-0">
        <h2 class="text-sm font-medium text-white/90">Utilidad total de la cotización</h2>
        <div class="mt-1 flex flex-wrap items-baseline justify-between gap-2 text-2xl font-bold tabular-nums sm:text-3xl">
            <span>{{ $porcentaje($totales['utilidad_pct']) }}</span><span>{{ $dinero($totales['utilidad']) }}</span>
        </div>
        <p class="mt-1 text-xs text-white/85">Sin IVA · {{ $totales['incluidas'] }} partidas incluidas</p>
        @if ($totales['sin_costo'])
            <p class="mt-2 text-sm text-white">Falta capturar el costo de {{ $totales['sin_costo'] }} {{ $totales['sin_costo'] === 1 ? 'partida' : 'partidas' }}.</p>
        @endif
    </section>

    @if ($mensaje)
        <p role="status" aria-live="polite" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800">{{ $mensaje }}</p>
    @endif

    <div class="space-y-4" aria-label="Partidas de la cotización">
        @forelse ($partidas as $p)
            @php
                $tieneCosto = $p->costo !== null && (float) $p->costo > 0;
                $costoPartida = $tieneCosto ? (float) $p->costo * (float) $p->cantidad : null;
                $utilidadPartida = $tieneCosto ? round((float) $p->importe - $costoPartida, 2) : null;
                $pct = $tieneCosto ? ($p->utilidad_pct ?? \App\Support\Precios::utilidadDesdePrecio((float) $p->costo, (float) $p->precio)) : null;
                $valorPct = $pct === null ? '' : number_format((float) $pct, 2, '.', '');
                $version = \App\Livewire\Cotizaciones\RevisionMovil::version($p);
            @endphp
            <article wire:key="revision-partida-{{ $p->id }}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                @unless ($p->incluir)
                    <p class="mb-3 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-600">Fuera de la cotización</p>
                @endunless
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-slate-600">Partida {{ $p->orden }} · Proveedor</p>
                        <p class="mt-1 break-words text-lg font-bold text-marino">{{ $p->proveedor?->nombre ?? 'Sin proveedor' }}</p>
                    </div>
                    <div class="w-36 shrink-0 rounded-lg bg-naranja/10 p-3 text-right"
                         x-data="utilidadRevision({{ $p->id }}, @js($valorPct), @js($version))">
                        <p class="text-xs font-medium text-slate-700">Utilidad</p>
                        <button type="button" x-show="!editando" x-on:click="iniciar($el)"
                                data-valor="{{ $valorPct }}" data-version="{{ $version }}"
                                aria-label="Cambiar utilidad de la partida {{ $p->orden }}"
                                @disabled(! $tieneCosto)
                                class="min-h-11 text-2xl font-bold tabular-nums text-marino underline decoration-naranja decoration-2 underline-offset-4 disabled:cursor-default disabled:text-base disabled:no-underline">{{ $porcentaje($pct) }}</button>
                        <form x-show="editando" x-cloak x-on:submit.prevent="guardar()" class="mt-2">
                            <label for="utilidad-{{ $p->id }}" class="sr-only">Porcentaje de utilidad de la partida {{ $p->orden }}</label>
                            <div class="flex items-center gap-1">
                                <input id="utilidad-{{ $p->id }}" x-ref="porcentaje" x-model="valor" type="text" inputmode="decimal" enterkeyhint="done"
                                       autocomplete="off" x-bind:disabled="guardando" x-on:blur="guardar()" x-on:keydown.escape.prevent="cancelar()"
                                       class="campo min-w-0 text-right" aria-describedby="error-utilidad-{{ $p->id }}">
                                <span aria-hidden="true">%</span>
                            </div>
                            <button type="submit" x-bind:disabled="guardando" class="mt-2 min-h-11 w-full rounded-lg bg-naranja px-2 py-2 text-sm font-semibold text-white hover:bg-naranja-oscuro disabled:opacity-60" x-text="guardando ? 'Guardando…' : 'Listo'">Listo</button>
                        </form>
                        <p class="mt-1 text-base font-semibold tabular-nums text-marino">{{ $dinero($utilidadPartida) }}</p>
                        <p class="text-xs text-slate-600">En esta partida</p>
                        <p x-show="error" x-cloak x-text="error" role="alert" class="mt-2 text-left text-sm text-red-700"></p>
                    </div>
                </div>
                <div id="error-utilidad-{{ $p->id }}">
                    @error('utilidades.'.$p->id)<p role="alert" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <h2 class="mt-4 whitespace-pre-line break-words text-base font-semibold leading-relaxed text-slate-900">{{ $p->descripcion ?: 'Sin descripción' }}</h2>
                @if ($p->marca || $p->modelo)
                    <p class="mt-1 break-words text-sm text-slate-600">{{ $p->marca }}{{ $p->marca && $p->modelo ? ' · ' : '' }}{{ $p->modelo }}</p>
                @endif
                <p class="mt-2 text-sm text-slate-600">{{ $numero($p->cantidad) }} {{ $p->unidad }}@if ($p->tiempo_entrega) · {{ $p->tiempo_entrega }}@endif</p>
                <div class="mt-3 flex flex-wrap justify-between gap-2 text-sm">
                    <span class="text-slate-600">Venta de esta partida</span><strong class="tabular-nums text-marino">{{ $dinero($p->importe) }}</strong>
                </div>
                <details class="mt-3 border-t border-slate-200">
                    <summary class="flex min-h-11 cursor-pointer items-center py-2 text-sm font-medium text-marino">Ver costo y precio unitario</summary>
                    <dl class="space-y-2 py-2 text-sm">
                        <div class="flex flex-wrap justify-between gap-2"><dt class="text-slate-600">Costo por unidad</dt><dd class="tabular-nums">{{ $dinero($tieneCosto ? $p->costo : null) }}</dd></div>
                        <div class="flex flex-wrap justify-between gap-2"><dt class="text-slate-600">Precio por unidad</dt><dd class="tabular-nums">{{ $dinero($p->precio) }}</dd></div>
                        <div class="flex flex-wrap justify-between gap-2"><dt class="text-slate-600">Costo de esta partida</dt><dd class="tabular-nums">{{ $dinero($costoPartida) }}</dd></div>
                    </dl>
                </details>
            </article>
        @empty
            <p class="rounded-xl bg-white p-5 text-slate-600">Esta cotización todavía no tiene partidas.</p>
        @endforelse
    </div>

    <section class="rounded-xl bg-marino p-4 text-white shadow-sm sm:p-5" aria-label="Resumen completo">
        <h2 class="text-lg font-semibold">Resumen completo</h2>
        <dl class="mt-3 space-y-3 text-sm">
            <div class="flex flex-wrap justify-between gap-2"><dt>Venta sin IVA</dt><dd class="font-semibold tabular-nums">{{ $dinero($totales['subtotal']) }}</dd></div>
            <div class="flex flex-wrap justify-between gap-2"><dt>Costo total sin IVA</dt><dd class="font-semibold tabular-nums">{{ $dinero($totales['costo']) }}</dd></div>
            <div class="flex flex-wrap justify-between gap-2"><dt>Utilidad total</dt><dd class="font-bold tabular-nums">{{ $dinero($totales['utilidad']) }}</dd></div>
            <div class="flex flex-wrap justify-between gap-2"><dt>Utilidad sobre costo</dt><dd class="font-bold tabular-nums">{{ $porcentaje($totales['utilidad_pct']) }}</dd></div>
            <div class="flex flex-wrap justify-between gap-2"><dt>IVA ({{ $numero(\App\Support\Precios::iva() * 100) }}%)</dt><dd class="font-semibold tabular-nums">{{ $dinero($totales['iva']) }}</dd></div>
            <div class="flex flex-wrap justify-between gap-2 border-t border-white/25 pt-3 text-lg"><dt>Total con IVA</dt><dd class="font-bold tabular-nums text-naranja">{{ $dinero($totales['total']) }} {{ $cot->moneda }}</dd></div>
        </dl>
    </section>

    @unless ($cot->estaRevisada())
        <button type="button" wire:click="marcarRevisada" wire:loading.attr="disabled"
                x-bind:disabled="pendientes > 0" class="btn-primario w-full disabled:opacity-60">Marcar cotización revisada</button>
        <p x-show="pendientes > 0" x-cloak class="text-center text-sm text-slate-600">Guardando…</p>
    @endunless
</div>

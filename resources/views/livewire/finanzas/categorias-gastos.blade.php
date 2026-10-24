@php
    $dinero = fn ($n) => '$'.number_format((float) $n, 2);
@endphp
<div class="space-y-4">
    <a href="{{ route('finanzas.gastos') }}" class="text-sm font-medium text-marino underline">&larr; Volver a Gastos</a>

    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif
    @if ($error)
        <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
    @endif

    <section class="space-y-3 rounded-xl bg-white p-4 shadow-sm" aria-labelledby="t-viejas">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="t-viejas" class="text-lg font-semibold text-marino">Categorías que no están en la lista general</h2>
            </div>
            @if ($filas)
                <button type="button" class="btn btn-primario" wire:click="unificarTodas"
                        wire:confirm="¿Unificar todas las categorías con lo que está elegido? Los gastos cambian de categoría.">Unificar todas</button>
            @endif
        </div>

        @forelse ($filas as $f)
            <div wire:key="cat-{{ md5($f['nombre']) }}" class="rounded-lg border border-slate-200 p-3">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-base font-semibold text-slate-900">{{ $f['nombre'] }}</p>
                    <p class="text-sm text-slate-600">{{ number_format($f['n']) }} {{ $f['n'] === 1 ? 'gasto' : 'gastos' }} &middot; {{ $dinero($f['total']) }}</p>
                </div>
                @if ($f['ejemplos'])
                    <ul class="mt-1 text-sm text-slate-500">
                        @foreach ($f['ejemplos'] as $e)
                            <li class="truncate">{{ $e }}</li>
                        @endforeach
                    </ul>
                @endif
                <div class="mt-2 grid gap-2 sm:grid-cols-[1fr_auto]">
                    <div>
                        <label class="etiqueta" for="d-{{ md5($f['nombre']) }}">
                            Pasa a
                            @unless ($f['segura'])
                                <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-800">revisa</span>
                            @endunless
                        </label>
                        <select id="d-{{ md5($f['nombre']) }}" wire:model="destino.{{ md5($f['nombre']) }}" class="campo">
                            @foreach ($general as $c)
                                <option value="{{ $c }}">{{ $c }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" class="btn btn-secundario self-end" wire:click="unificarUna(@js($f['nombre']))">Unificar solo esta</button>
                </div>
            </div>
        @empty
            <p class="rounded-lg bg-green-50 px-4 py-3 text-base text-green-800">Todo está ordenado: todos los gastos usan la lista general.</p>
        @endforelse
    </section>

    @if ($sinCategoria_gastos->isNotEmpty())
        <section class="space-y-3 rounded-xl bg-white p-4 shadow-sm" aria-labelledby="t-sin">
            <div>
                <h2 id="t-sin" class="text-lg font-semibold text-marino">Sin categoría ({{ number_format($totalSin) }})</h2>
                @if ($totalSin > $sinCategoria_gastos->count())<p class="text-sm text-slate-600">Los {{ $sinCategoria_gastos->count() }} más recientes</p>@endif
            </div>
            @foreach ($sinCategoria_gastos as $g)
                <div wire:key="sin-{{ $g->id }}" class="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-[1fr_16rem_auto] sm:items-center">
                    <div class="min-w-0">
                        <p class="text-sm text-slate-600">{{ $g->fecha->format('d/m/Y') }} &middot; {{ $dinero($g->monto) }} &middot; {{ \App\Models\Gasto::DE[$g->de] ?? $g->de }}</p>
                        <p class="truncate text-base text-slate-900">{{ $g->descripcion ?: 'Sin descripción' }}</p>
                    </div>
                    <select wire:model="sinCategoria.{{ $g->id }}" class="campo" aria-label="Categoría del gasto">
                        <option value="">Elige la categoría</option>
                        @foreach ($general as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-secundario" wire:click="asignarSinCategoria({{ $g->id }})">Asignar</button>
                </div>
            @endforeach
        </section>
    @endif
</div>

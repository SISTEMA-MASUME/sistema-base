<div class="space-y-4">
    <div class="flex gap-2" role="tablist">
        @foreach (['clientes' => 'Clientes', 'proveedores' => 'Proveedores'] as $clave => $texto)
            <button type="button" role="tab" aria-selected="{{ $tipo === $clave ? 'true' : 'false' }}" wire:click="elegirTipo('{{ $clave }}')"
                    class="min-h-12 rounded-full px-5 text-sm font-semibold {{ $tipo === $clave ? 'bg-marino text-white' : 'bg-white text-slate-700 shadow-sm' }}">{{ $texto }}</button>
        @endforeach
    </div>

    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    @if ($grupo === [])
        {{-- Unir a mano: Daniel palomea los que son el mismo aunque el sistema no los haya juntado. --}}
        <section class="rounded-xl bg-white p-4 shadow-sm" x-data="{ abierto: @js($manual !== []) }">
            <button type="button" x-on:click="abierto = !abierto" class="flex min-h-12 w-full items-center justify-between text-left text-lg font-semibold text-marino">
                <span>Unir a mano (elegir con palomitas)</span>
                <span x-text="abierto ? '−' : '+'" class="text-2xl"></span>
            </button>
            <div x-show="abierto" x-cloak class="mt-2 space-y-3">
                <input type="search" wire:model.live.debounce.300ms="buscarManual" class="campo" placeholder="Buscar por nombre (p. ej. MAGNO)" aria-label="Buscar para unir a mano" autocomplete="off">
                <ul class="max-h-96 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
                    @forelse ($lista as $item)
                        <li wire:key="manual-{{ $tipo }}-{{ $item->id }}">
                            <label class="flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2 hover:bg-slate-50">
                                <input type="checkbox" wire:model.live="manual" value="{{ $item->id }}" class="h-5 w-5 rounded border-slate-300">
                                <span class="text-sm">{{ $item->nombre }}</span>
                            </label>
                        </li>
                    @empty
                        <li class="px-3 py-4 text-center text-sm text-slate-500">Sin resultados.</li>
                    @endforelse
                </ul>
                @error('manual') <p class="error-campo">{{ $message }}</p> @enderror
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <button type="button" wire:click="unirManual" class="btn-primario" @disabled(count($manual) < 2)>Unir los palomeados ({{ count($manual) }})</button>
                    @if ($manual !== [])
                        <button type="button" wire:click="$set('manual', [])" class="btn-secundario">Quitar palomitas</button>
                    @endif
                </div>
            </div>
        </section>

        <section class="rounded-xl bg-white p-4 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">Posibles duplicados ({{ count($grupos) }} grupos)</h2>
            <ul class="divide-y divide-slate-200">
                @forelse ($grupos as $i => $g)
                    <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center" wire:key="grupo-{{ $tipo }}-{{ implode('-', $g) }}">
                        <p class="min-w-0 flex-1 text-sm">
                            @foreach ($g as $id)
                                <span class="mr-1 inline-block rounded bg-slate-100 px-2 py-0.5 font-medium">{{ $nombres[$id] ?? $id }}</span>
                            @endforeach
                        </p>
                        <button type="button" wire:click="revisar(@js($g))" class="btn-primario">Revisar</button>
                    </li>
                @empty
                    <li class="py-6 text-center text-slate-600">No encontré {{ $tipo }} con nombres parecidos. 🎉</li>
                @endforelse
            </ul>
        </section>
    @else
        <section class="space-y-4 rounded-xl bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-lg font-semibold text-marino">Revisar grupo</h2>
                <button type="button" wire:click="cerrarGrupo" class="btn-secundario">← Volver a la lista</button>
            </div>
            <p class="text-sm text-slate-600">Palomea los que son el mismo · ◉ = el que se queda</p>

            <ul class="space-y-2">
                @foreach ($grupo as $id)
                    <li class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 p-3" wire:key="miembro-{{ $id }}">
                        <label class="flex min-h-10 items-center gap-2">
                            <input type="checkbox" value="{{ $id }}" wire:model.live="marcados" class="h-6 w-6 rounded border-slate-300" aria-label="Es el mismo">
                            <span class="font-semibold">{{ $nombres[$id] ?? $id }}</span>
                        </label>
                        <label class="ml-auto flex min-h-10 items-center gap-2 text-sm">
                            <input type="radio" name="principal" value="{{ $id }}" wire:model.live="principalId" class="h-5 w-5">
                            Se queda
                        </label>
                        <a href="{{ route($rutaFicha, $id) }}" target="_blank" class="text-sm text-marino underline">Ver ficha</a>
                    </li>
                @endforeach
            </ul>
            @error('principalId') <p class="error-campo">{{ $message }}</p> @enderror
            @error('marcados') <p class="error-campo">{{ $message }}</p> @enderror

            <div>
                <label class="etiqueta" for="dup-buscar">¿Falta alguno? Búscalo y agrégalo</label>
                <input id="dup-buscar" type="search" wire:model.live.debounce.400ms="buscar" class="campo" placeholder="Nombre (mínimo 2 letras)">
                @if ($resultados->isNotEmpty())
                    <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200">
                        @foreach ($resultados as $r)
                            <li class="flex items-center justify-between gap-2 px-3 py-2" wire:key="res-{{ $r->id }}">
                                <span>{{ $r->nombre }}</span>
                                <button type="button" wire:click="agregar({{ $r->id }})" class="btn-secundario">Agregar</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <button type="button" wire:click="verVistaPrevia" class="btn-primario">Ver vista previa</button>

            @if ($previa)
                <div class="rounded-lg border border-orange-300 bg-orange-50 p-3">
                    <p class="mb-2 font-semibold text-orange-900">Vista previa: todo pasa a <strong>{{ $nombres[$principalId] ?? '' }}</strong></p>
                    <ul class="space-y-2 text-sm">
                        @foreach ($previa as $f)
                            <li wire:key="previa-{{ $f['id'] }}">
                                <strong>{{ $f['nombre'] }}</strong>: se mueven {{ $f['mueve'] }}.
                                @if ($f['contacto'])
                                    Se crea el contacto <strong>{{ $f['contacto'] }}</strong>{{ $tipo === 'clientes' ? ' y se le ligan sus cotizaciones' : '' }}.
                                @endif
                                @foreach ($f['conflictos'] as $x)
                                    <span class="block text-red-700">⚠ {{ $x }}</span>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                    @if (collect($previa)->contains(fn ($f) => $f['conflictos'] !== []))
                        <p class="mt-2 text-sm font-semibold text-red-700">No se puede unir: hay OC repetidas.</p>
                    @else
                        <button type="button" wire:click="unir" wire:confirm="¿Seguro? Se unen en uno solo y los otros desaparecen de las listas." class="btn-primario mt-3">Sí, unir</button>
                    @endif
                </div>
            @endif
        </section>
    @endif
</div>

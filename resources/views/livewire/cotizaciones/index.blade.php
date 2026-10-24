<div>
    <div class="mb-4 flex justify-end">
        <a href="{{ route('cotizaciones.nueva') }}" class="btn-primario w-full sm:w-auto">+ Nueva cotización</a>
    </div>

    <div class="mb-4 space-y-3 rounded-xl bg-white p-4 shadow-sm">
        <input type="search" wire:model.live.debounce.300ms="buscar" class="campo" placeholder="Buscar por folio, cliente, artículo o modelo" aria-label="Buscar">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <select wire:model.live="vendedor" class="campo" aria-label="Vendedor">
                <option value="">Todos los vendedores</option>
                @foreach ($vendedores as $v)
                    <option value="{{ $v->id }}">{{ $v->name }}</option>
                @endforeach
            </select>
            {{-- Cliente: se busca escribiendo (ya no se carga la lista de todos los clientes en cada búsqueda). --}}
            <div class="relative">
                @if ($clienteElegido)
                    <div class="campo flex items-center justify-between gap-2 !py-1.5" data-cliente-elegido>
                        <span class="truncate font-semibold text-marino">{{ $clienteElegido->nombre }}</span>
                        <button type="button" wire:click="quitarCliente" class="shrink-0 rounded px-2 text-lg leading-none text-slate-500 hover:bg-slate-100" aria-label="Quitar filtro de cliente">✕</button>
                    </div>
                @else
                    <input type="search" wire:model.live.debounce.300ms="clienteTexto" class="campo" placeholder="Todos los clientes" aria-label="Filtrar por cliente" autocomplete="off">
                    @if ($clientesSugeridos->isNotEmpty())
                        <ul class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                            @foreach ($clientesSugeridos as $c)
                                <li wire:key="fcli-{{ $c->id }}"><button type="button" wire:click="elegirCliente({{ $c->id }})" class="block min-h-11 w-full px-3 py-2 text-left text-sm hover:bg-slate-50">{{ $c->nombre }}</button></li>
                            @endforeach
                        </ul>
                    @elseif (mb_strlen(trim($clienteTexto)) >= 2)
                        <p class="absolute inset-x-0 top-full z-20 mt-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-500 shadow-lg">Ningún cliente con ese nombre.</p>
                    @endif
                @endif
            </div>
            <select wire:model.live="estado" class="campo" aria-label="Estado">
                <option value="">Todos los estados</option>
                @foreach ($estados as $e)
                    <option value="{{ $e->value }}">{{ $e->etiqueta() }}</option>
                @endforeach
            </select>
            <input type="date" wire:model.live="desde" class="campo" aria-label="Desde">
            <input type="date" wire:model.live="hasta" class="campo" aria-label="Hasta">
        </div>
        <button type="button" wire:click="limpiar" class="text-sm font-medium text-marino underline">Limpiar filtros</button>
    </div>

    @if ($mensaje)
        <div role="status" class="mb-3 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mb-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
    @endif
    @if ($esAdmin && $seleccion)
        {{-- Solo el admin: eliminar las palomeadas. --}}
        <div class="sticky top-16 z-20 mb-3 flex flex-col gap-2 rounded-xl border border-red-200 bg-red-50 p-3 sm:flex-row sm:items-center lg:top-2">
            <span class="flex-1 font-semibold text-red-800">{{ count($seleccion) }} {{ count($seleccion) === 1 ? 'cotización seleccionada' : 'cotizaciones seleccionadas' }}</span>
            <button type="button" wire:click="eliminarSeleccionadas"
                    wire:confirm="¿Eliminar {{ count($seleccion) }} {{ count($seleccion) === 1 ? 'cotización' : 'cotizaciones' }}? Ya no aparecerán en el sistema."
                    class="btn bg-red-600 text-white hover:bg-red-700">Eliminar seleccionadas</button>
            <button type="button" wire:click="$set('seleccion', [])" class="btn-secundario">Cancelar</button>
        </div>
    @endif

    {{-- Escritorio --}}
    <div class="hidden overflow-hidden rounded-xl bg-white shadow-sm md:block">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                <tr>
                    @if ($esAdmin)
                        <th class="w-10 px-4 py-3"><span class="sr-only">Seleccionar</span></th>
                    @endif
                    <th class="px-4 py-3">Folio</th>
                    <th class="px-4 py-3">Fecha</th>
                    <th class="px-4 py-3">Cliente</th>
                    <th class="px-4 py-3">Vendedor</th>
                    <th class="px-4 py-3 text-right">Partidas</th>
                    <th class="px-4 py-3 text-right">Total</th>
                    <th class="px-4 py-3">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse ($cotizaciones as $c)
                    <tr wire:key="fila-{{ $c->id }}" class="hover:bg-slate-50">
                        @if ($esAdmin)
                            <td class="px-4 py-3"><input type="checkbox" wire:model.live="seleccion" value="{{ $c->id }}" class="h-5 w-5 rounded border-slate-300" aria-label="Seleccionar cotización {{ $c->folio }}"></td>
                        @endif
                        <td class="px-4 py-3 font-semibold"><a href="{{ route('cotizaciones.editar', $c) }}" class="text-marino underline">{{ $c->folio }}</a></td>
                        <td class="px-4 py-3">{{ $c->fecha->format('d/m/Y') }}</td>
                        <td class="px-4 py-3">
                            {{ $c->cliente?->nombre }}
                            @include('livewire.cotizaciones.partials.articulos-encontrados')
                        </td>
                        <td class="px-4 py-3">{{ $c->vendedor?->iniciales ?: $c->vendedor?->name }}</td>
                        <td class="px-4 py-3 text-right">{{ $c->partidas_count }}</td>
                        <td class="px-4 py-3 text-right">${{ number_format($c->total, 2) }}</td>
                        <td class="px-4 py-3"><span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-sm font-semibold {{ $c->estado->colores() }}" data-estado>{{ $c->estado->etiqueta() }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $esAdmin ? 8 : 7 }}" class="px-4 py-8 text-center text-slate-500">No hay cotizaciones con esos filtros.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Celular --}}
    <ul class="space-y-3 md:hidden">
        @forelse ($cotizaciones as $c)
            <li wire:key="tarjeta-{{ $c->id }}" class="flex items-stretch gap-2">
                @if ($esAdmin)
                    <label class="flex items-center rounded-xl bg-white px-3 shadow-sm"><input type="checkbox" wire:model.live="seleccion" value="{{ $c->id }}" class="h-6 w-6 rounded border-slate-300" aria-label="Seleccionar cotización {{ $c->folio }}"></label>
                @endif
                <a href="{{ route('cotizaciones.editar', $c) }}" class="block min-w-0 flex-1 rounded-xl bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-lg font-bold text-marino">#{{ $c->folio }}</span>
                        <span class="text-sm text-slate-500">{{ $c->fecha->format('d/m/Y') }}</span>
                    </div>
                    <p class="mt-1 font-medium">{{ $c->cliente?->nombre }}</p>
                    @include('livewire.cotizaciones.partials.articulos-encontrados')
                    <div class="mt-2 flex items-center justify-between text-sm text-slate-600">
                        <span>{{ $c->vendedor?->iniciales ?: $c->vendedor?->name }} · {{ $c->partidas_count }} partidas · <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold {{ $c->estado->colores() }}">{{ $c->estado->etiqueta() }}</span></span>
                        <span class="text-base font-semibold text-slate-900">${{ number_format($c->total, 2) }}</span>
                    </div>
                </a>
            </li>
        @empty
            <li class="rounded-xl bg-white p-6 text-center text-slate-500">No hay cotizaciones con esos filtros.</li>
        @endforelse
    </ul>

    <div class="mt-4">{{ $cotizaciones->links() }}</div>
</div>

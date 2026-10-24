@php
    $dinero = fn ($n) => '$'.number_format((float) $n, 2);
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
    $fecha = fn ($f) => $f ? $f->day.'-'.$meses[$f->month - 1] : '';
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-end gap-3">
        <a href="{{ route('articulos.listas') }}" class="btn-secundario">Listas de precios (Truper, Urrea…)</a>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-sm text-slate-600">Artículos</p>
            <p class="text-2xl font-bold text-marino">{{ number_format($total) }}</p>
            <p class="text-sm text-slate-600">{{ number_format($deSicar) }} ligados a SICAR</p>
        </div>
        <button type="button" wire:click="filtrar('fiscal')" class="rounded-xl border border-orange-200 bg-orange-50 p-4 text-left shadow-sm hover:bg-orange-100">
            <p class="text-sm text-orange-900">Les falta dato fiscal</p>
            <p class="text-2xl font-bold text-orange-900">{{ number_format($faltaFiscal) }}</p>
            <p class="text-sm text-orange-900">sin clave o unidad del SAT</p>
        </button>
        <button type="button" wire:click="filtrar('semana')" class="rounded-xl bg-white p-4 text-left shadow-sm hover:bg-slate-50">
            <p class="text-sm text-slate-600">Nuevos esta semana</p>
            <p class="text-2xl font-bold text-marino">{{ number_format($nuevos) }}</p>
            <p class="text-sm text-slate-600">nacieron en pedidos o a mano</p>
        </button>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-sm text-slate-600">Para facturar aquí</p>
            <p class="text-2xl font-bold text-marino">{{ $total > 0 ? round(100 * ($total - $faltaFiscal) / $total) : 0 }}%</p>
            <p class="text-sm text-slate-600">tienen sus datos completos</p>
        </div>
    </div>

    <div class="space-y-3 rounded-xl bg-white p-4 shadow-sm">
        <input type="search" wire:model.live.debounce.300ms="buscar" class="campo" placeholder="Descripción, marca, clave del proveedor o código de barras" aria-label="Buscar artículo">
        <div class="flex flex-wrap gap-2">
            @foreach (\App\Livewire\Articulos\Index::FILTROS as $clave => $texto)
                <button type="button" wire:click="filtrar('{{ $clave }}')" wire:key="f-{{ $clave ?: 'todos' }}"
                        class="min-h-9 rounded-full px-4 text-sm font-semibold {{ $filtro === $clave ? 'bg-marino text-white' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}">{{ $texto }}</button>
            @endforeach
        </div>
    </div>

    {{-- Computadora: tabla --}}
    <div class="hidden overflow-x-auto rounded-xl bg-white shadow-sm md:block">
        <table class="w-full min-w-[900px] text-left text-base">
            <thead class="bg-slate-50 text-sm text-slate-600">
                <tr>
                    <th class="px-4 py-3 font-semibold">Artículo</th>
                    <th class="px-3 py-3 font-semibold">Unidad</th>
                    <th class="px-3 py-3 font-semibold">Clave SAT</th>
                    <th class="px-3 py-3 font-semibold">Quién lo vende</th>
                    <th class="px-3 py-3 text-right font-semibold">Mejor costo</th>
                    <th class="px-4 py-3 text-right font-semibold">Pedidos</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse ($articulos as $a)
                    @php $mejor = $a->proveedores->first(fn ($p) => $p->ultimo_costo !== null); @endphp
                    <tr wire:key="art-{{ $a->id }}" class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('articulos.ver', $a) }}" class="font-semibold text-marino hover:underline">{{ $a->descripcion }}</a>
                            <p class="text-sm text-slate-600">
                                {{ $a->marca ?: 'Sin marca' }}@if ($a->origen === 'pedido' && $a->created_at?->gt(now()->subDays(7))) · <span class="font-semibold text-orange-800">Nuevo</span>@endif
                            </p>
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap">{{ $a->unidad }}@if ($a->unidad_sat) <span class="text-sm text-slate-600">· {{ $a->unidad_sat }}</span>@endif</td>
                        <td class="px-3 py-3">
                            @if ($a->faltaDatoFiscal())
                                <span class="rounded-full bg-orange-100 px-2.5 py-0.5 text-sm font-semibold text-orange-900">Falta</span>
                            @else
                                <span class="font-mono text-sm">{{ $a->clave_sat }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex flex-wrap gap-1.5">
                                @forelse ($a->proveedores->take(3) as $p)
                                    <span class="rounded-full px-2.5 py-0.5 text-sm {{ $loop->first && $p->ultimo_costo !== null ? 'bg-indigo-100 font-semibold text-marino' : 'bg-slate-100 text-slate-700' }}">{{ $p->proveedor?->nombre }}</span>
                                @empty
                                    <span class="text-sm text-slate-500">—</span>
                                @endforelse
                                @if ($a->proveedores->count() > 3)
                                    <span class="text-sm text-slate-600">+{{ $a->proveedores->count() - 3 }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-3 py-3 text-right whitespace-nowrap">
                            @if ($mejor)
                                <span class="font-semibold">{{ $dinero($mejor->ultimo_costo) }}</span>
                                <p class="text-xs text-slate-600">{{ $mejor->proveedor?->nombre }} · {{ $fecha($mejor->costo_fecha) }}</p>
                            @else
                                <span class="text-slate-500">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">{{ $vendidos[$a->id] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-600">{{ $buscar !== '' || $filtro !== '' ? 'No hay artículos con ese filtro.' : 'Todavía no hay artículos.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Celular: tarjetas --}}
    <ul class="space-y-3 md:hidden">
        @forelse ($articulos as $a)
            @php $mejor = $a->proveedores->first(fn ($p) => $p->ultimo_costo !== null); @endphp
            <li wire:key="tarjeta-{{ $a->id }}">
                <a href="{{ route('articulos.ver', $a) }}" class="block rounded-xl bg-white p-4 shadow-sm">
                    <p class="font-semibold text-marino">{{ $a->descripcion }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $a->marca ?: 'Sin marca' }} · {{ $a->unidad }}@if ($a->faltaDatoFiscal()) · <span class="font-semibold text-orange-800">Falta dato fiscal</span>@endif</p>
                    @if ($mejor)
                        <p class="mt-1 text-sm">Mejor costo <strong>{{ $dinero($mejor->ultimo_costo) }}</strong> · {{ $mejor->proveedor?->nombre }}</p>
                    @endif
                </a>
            </li>
        @empty
            <li class="rounded-xl bg-white p-6 text-center text-slate-600">{{ $buscar !== '' || $filtro !== '' ? 'No hay artículos con ese filtro.' : 'Todavía no hay artículos.' }}</li>
        @endforelse
    </ul>

    <div>{{ $articulos->links() }}</div>
</div>

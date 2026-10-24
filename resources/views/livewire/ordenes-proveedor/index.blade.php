<div>
    <div class="mb-4 space-y-3 rounded-xl bg-white p-4 shadow-sm">
        <input type="search" wire:model.live.debounce.300ms="buscar" class="campo" placeholder="Buscar por folio (OC-0001 o 1) o cliente" aria-label="Buscar">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <select wire:model.live="proveedor" class="campo" aria-label="Proveedor">
                <option value="">Todos los proveedores</option>
                @foreach ($proveedores as $p)
                    <option value="{{ $p->id }}">{{ $p->nombre }}</option>
                @endforeach
            </select>
            <select wire:model.live="estado" class="campo" aria-label="Estado">
                <option value="">Todos los estados</option>
                @foreach ($estados as $e)
                    <option value="{{ $e->value }}">{{ $e->etiqueta() }}</option>
                @endforeach
            </select>
            <input type="date" wire:model.live="desde" class="campo" aria-label="Desde">
            <input type="date" wire:model.live="hasta" class="campo" aria-label="Hasta">
        </div>
        @if ($pedidoFiltrado)
            <p class="text-sm text-slate-600">Solo órdenes del pedido <a href="{{ url('/pedidos/'.$pedidoFiltrado->id) }}" class="font-semibold text-marino underline">P-{{ $pedidoFiltrado->folio }}</a>.</p>
        @endif
        <button type="button" wire:click="limpiar" class="text-sm font-medium text-marino underline">Limpiar filtros</button>
    </div>

    {{-- Escritorio --}}
    <div class="hidden overflow-hidden rounded-xl bg-white shadow-sm md:block">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">Folio</th>
                    <th class="px-4 py-3">Fecha</th>
                    <th class="px-4 py-3">Proveedor</th>
                    <th class="px-4 py-3">Cliente / pedido</th>
                    <th class="px-4 py-3">Pago</th>
                    <th class="px-4 py-3 text-right">Total s/IVA</th>
                    <th class="px-4 py-3">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse ($ordenes as $o)
                    <tr wire:key="fila-{{ $o->id }}" class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold"><a href="{{ route('ordenes-proveedor.ver', $o) }}" class="text-marino underline">{{ $o->folioTexto() }}</a></td>
                        <td class="px-4 py-3">{{ $o->created_at->format('d/m/Y') }}</td>
                        <td class="px-4 py-3">{{ $o->proveedor?->nombre }}</td>
                        <td class="px-4 py-3">{{ $o->pedido?->cliente?->nombre }} · <a href="{{ url('/pedidos/'.$o->pedido_id) }}" class="text-marino underline">P-{{ $o->pedido?->folio }}</a></td>
                        <td class="px-4 py-3">{{ $o->metodo_pago }}</td>
                        <td class="px-4 py-3 text-right">${{ number_format((float) $o->total_costo, 2) }}</td>
                        <td class="px-4 py-3">{{ $o->estado->etiqueta() }}@if ($o->requiere_reenvio) <span class="ml-1 rounded-full bg-orange-100 px-2 py-0.5 text-xs font-semibold text-orange-800">Reenviar</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No hay órdenes de compra con esos filtros.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Celular --}}
    <ul class="space-y-3 md:hidden">
        @forelse ($ordenes as $o)
            <li wire:key="tarjeta-{{ $o->id }}">
                <a href="{{ route('ordenes-proveedor.ver', $o) }}" class="block rounded-xl bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-lg font-bold text-marino">{{ $o->folioTexto() }}</span>
                        <span class="text-sm text-slate-500">{{ $o->created_at->format('d/m/Y') }}</span>
                    </div>
                    <p class="mt-1 font-medium">{{ $o->proveedor?->nombre }}</p>
                    <p class="text-sm text-slate-600">{{ $o->pedido?->cliente?->nombre }} · P-{{ $o->pedido?->folio }}</p>
                    <div class="mt-2 flex items-center justify-between text-sm text-slate-600">
                        <span>{{ $o->metodo_pago }} · {{ $o->estado->etiqueta() }}@if ($o->requiere_reenvio) <span class="ml-1 rounded-full bg-orange-100 px-2 py-0.5 text-xs font-semibold text-orange-800">Reenviar</span>@endif</span>
                        <span class="text-base font-semibold text-slate-900">${{ number_format((float) $o->total_costo, 2) }}</span>
                    </div>
                </a>
            </li>
        @empty
            <li class="rounded-xl bg-white p-6 text-center text-slate-500">No hay órdenes de compra con esos filtros.</li>
        @endforelse
    </ul>

    <div class="mt-4">{{ $ordenes->links() }}</div>
</div>

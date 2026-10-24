<div class="space-y-5">
    <div class="flex flex-col gap-2 rounded-xl bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <p class="text-base text-slate-700">
            @if ($requiereAutorizacion)
                <strong>Autorización encendida</strong>
            @else
                <strong>Autorización apagada</strong>
            @endif
        </p>
        <button type="button" wire:click="cambiarRequisito" class="btn-secundario shrink-0">{{ $requiereAutorizacion ? 'Apagar autorización' : 'Pedir mi autorización' }}</button>
    </div>
    <div class="flex flex-wrap gap-2" role="tablist">
        <button type="button" role="tab" wire:click="cambiarTab('pendientes')" class="min-h-11 rounded-lg px-4 text-base font-semibold {{ $tab === 'pendientes' ? 'bg-marino text-white' : 'bg-white text-slate-700 shadow-sm' }}">
            Por autorizar @if ($totalPendientes) <span class="ml-1 rounded-full bg-naranja px-2 py-0.5 text-xs text-white">{{ $totalPendientes }}</span> @endif
        </button>
        <button type="button" role="tab" wire:click="cambiarTab('historial')" class="min-h-11 rounded-lg px-4 text-base font-semibold {{ $tab === 'historial' ? 'bg-marino text-white' : 'bg-white text-slate-700 shadow-sm' }}">Historial</button>
    </div>

    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-900">{{ $mensaje }}</div>
    @endif
    @if ($enfocado)
        <p class="text-sm text-slate-600">Mostrando un solo cambio. <button type="button" wire:click="verTodos" class="font-semibold text-marino underline">Ver todos</button></p>
    @endif

    @if ($tab === 'pendientes')
        <datalist id="lista-proveedores-cambio">
            @foreach ($proveedores as $nombre)<option value="{{ $nombre }}"></option>@endforeach
        </datalist>

        @forelse ($pendientes as $c)
            @php
                $cmp = $comparativas[$c->id];
                $a = $cmp['antes'];
                $d = $cmp['despues'];
                $dif = $cmp['pedidoDespues'] - $cmp['pedidoAntes'];
                $m = fn ($n) => '$'.number_format((float) $n, 2);
                $dinero = fn ($n) => ($n < 0 ? '-' : '').'$'.number_format(abs((float) $n), 2);
            @endphp
            <section wire:key="cambio-{{ $c->id }}" class="space-y-4 rounded-xl bg-white p-4 shadow-sm sm:p-5">
                <div class="space-y-1">
                    <p class="text-base font-semibold text-marino">
                        <a href="{{ route('pedidos.index', ['pedido' => $c->pedido_id]) }}" class="underline">Pedido P-{{ $c->pedido->folio }}</a>
                        · {{ $c->pedido->cliente->nombre ?? '' }}
                    </p>
                    <p class="text-base font-semibold">{{ $c->partida->descripcion ?? $c->descripcion }}
                        @unless ($c->partida) <span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">La partida ya no está en el pedido</span> @endunless
                    </p>
                    <p class="text-sm text-slate-600">Cantidad {{ rtrim(rtrim(number_format($cmp['cantidad'], 3, '.', ','), '0'), '.') }}
                        · Lo pidió {{ $c->solicitante->name ?? 'alguien' }} el {{ $c->created_at->format('d/m/Y H:i') }}</p>
                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-base text-amber-900"><strong>Motivo:</strong> {{ $c->motivo }}</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm sm:text-base">
                        <thead class="border-b border-slate-200 text-slate-600">
                            <tr><th class="py-2 pr-2"></th><th class="px-2 py-2 text-right">Antes (cotizado)</th><th class="py-2 pl-2 text-right">Después</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr>
                                <th class="py-2 pr-2 font-medium">Precio de venta</th>
                                <td class="px-2 py-2 text-right">{{ $m($a['precio']) }}</td>
                                <td class="py-2 pl-2 text-right">
                                    <input type="number" step="0.01" min="0" inputmode="decimal" wire:model.live.debounce.400ms="precioFinal.{{ $c->id }}" class="campo !w-28 !py-1 text-right" aria-label="Precio final">
                                    @error('precioFinal.'.$c->id) <p class="error-campo">{{ $message }}</p> @enderror
                                </td>
                            </tr>
                            <tr>
                                <th class="py-2 pr-2 font-medium">Costo</th>
                                <td class="px-2 py-2 text-right">{{ $a['costo'] !== null ? $m($a['costo']) : '—' }}</td>
                                <td class="py-2 pl-2 text-right">
                                    <input type="number" step="any" min="0" inputmode="decimal" wire:model.live.debounce.400ms="costoFinal.{{ $c->id }}" class="campo !w-28 !py-1 text-right" aria-label="Costo final" placeholder="{{ $a['costo'] !== null ? number_format($a['costo'], 2) : '' }}">
                                    @error('costoFinal.'.$c->id) <p class="error-campo">{{ $message }}</p> @enderror
                                </td>
                            </tr>
                            <tr><th class="py-2 pr-2 font-medium">Utilidad por pieza</th><td class="px-2 py-2 text-right">{{ $dinero($a['pieza']) }}</td><td class="py-2 pl-2 text-right font-semibold {{ $d['pieza'] < 0 ? 'text-red-700' : '' }}">{{ $dinero($d['pieza']) }}</td></tr>
                            <tr><th class="py-2 pr-2 font-medium">Utilidad de la partida</th><td class="px-2 py-2 text-right">{{ $dinero($a['total']) }}</td><td class="py-2 pl-2 text-right font-semibold {{ $d['total'] < 0 ? 'text-red-700' : '' }}">{{ $dinero($d['total']) }}</td></tr>
                            <tr><th class="py-2 pr-2 font-medium">Margen</th><td class="px-2 py-2 text-right">{{ $a['margen'] !== null ? $a['margen'].'%' : '—' }}</td><td class="py-2 pl-2 text-right font-semibold">{{ $d['margen'] !== null ? $d['margen'].'%' : '—' }}</td></tr>
                            <tr class="bg-slate-50"><th class="py-2 pr-2 font-medium">Utilidad del pedido completo</th><td class="px-2 py-2 text-right">{{ $dinero($cmp['pedidoAntes']) }}</td>
                                <td class="py-2 pl-2 text-right font-semibold">{{ $dinero($cmp['pedidoDespues']) }}
                                    <span class="block text-xs font-normal {{ $dif < 0 ? 'text-red-700' : 'text-green-700' }}">{{ $dif < 0 ? 'baja' : 'sube' }} {{ $dinero(abs($dif)) }}</span></td></tr>
                        </tbody>
                    </table>
                </div>

                <div>
                    <label class="etiqueta" for="prov-{{ $c->id }}">Proveedor</label>
                    <input id="prov-{{ $c->id }}" type="text" list="lista-proveedores-cambio" wire:model.blur="proveedorFinal.{{ $c->id }}" autocomplete="off" class="campo" placeholder="Si lo dejas vacío se queda el proveedor de la partida">
                    @error('proveedorFinal.'.$c->id) <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="nota-{{ $c->id }}">Nota (obligatoria para rechazar)</label>
                    <input id="nota-{{ $c->id }}" type="text" wire:model="nota.{{ $c->id }}" maxlength="500" class="campo">
                    @error('nota.'.$c->id) <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    @if ($c->partida)
                        <button type="button" wire:click="autorizar({{ $c->id }})" wire:loading.attr="disabled" class="btn-primario">Autorizar</button>
                    @endif
                    <button type="button" wire:click="rechazar({{ $c->id }})" wire:loading.attr="disabled" class="btn-secundario">Rechazar</button>
                </div>
            </section>
        @empty
            <div class="rounded-xl bg-white p-8 text-center text-base text-slate-600 shadow-sm">No hay cambios de precio por autorizar.</div>
        @endforelse
    @else
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div class="sm:w-64">
                <label class="etiqueta" for="f-estatus">Estatus</label>
                <select id="f-estatus" wire:model.live="estatus" class="campo">
                    <option value="">Todos</option>
                    @foreach (\App\Models\CambioPrecio::ESTATUS as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach
                </select>
            </div>
        </div>
        <div class="space-y-3">
            @forelse ($historial as $c)
                @php
                    $color = ['pendiente' => 'bg-amber-100 text-amber-900', 'autorizado' => 'bg-green-100 text-green-800', 'directo' => 'bg-blue-100 text-blue-800', 'rechazado' => 'bg-red-100 text-red-800'][$c->estatus] ?? 'bg-slate-100';
                @endphp
                <article wire:key="hist-{{ $c->id }}" class="space-y-1 rounded-xl bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-3 py-0.5 text-sm font-semibold {{ $color }}">{{ $c->etiquetaEstatus() }}</span>
                        <a href="{{ route('pedidos.index', ['pedido' => $c->pedido_id]) }}" class="text-base font-semibold text-marino underline">P-{{ $c->pedido->folio }}</a>
                        <span class="text-sm text-slate-600">{{ $c->pedido->cliente->nombre ?? '' }} · {{ $c->created_at->format('d/m/Y H:i') }}</span>
                    </div>
                    <p class="text-base font-medium">{{ $c->descripcion }}</p>
                    <p class="text-sm text-slate-700">
                        Precio ${{ number_format((float) $c->precio_anterior, 2) }} → <strong>${{ number_format((float) ($c->precio_final ?? $c->precio_solicitado), 2) }}</strong>
                        @if ($c->estatus === 'rechazado') (pedido ${{ number_format((float) $c->precio_solicitado, 2) }}, no autorizado) @endif
                        · Costo {{ $c->costo_anterior !== null ? '$'.number_format((float) $c->costo_anterior, 2) : '—' }}@if ($c->costo_nuevo !== null) → <strong>${{ number_format((float) $c->costo_nuevo, 2) }}</strong>@endif
                        · Cant. {{ rtrim(rtrim(number_format((float) $c->cantidad, 3, '.', ','), '0'), '.') }}
                    </p>
                    @if ($c->estatus !== 'rechazado' && $c->estatus !== 'pendiente')
                        <p class="text-sm text-slate-700">Utilidad de la partida ${{ number_format((float) $c->utilidad_antes, 2) }} → <strong>${{ number_format((float) $c->utilidad_despues, 2) }}</strong></p>
                    @endif
                    <p class="text-sm text-slate-600"><strong>Motivo:</strong> {{ $c->motivo }}</p>
                    <p class="text-sm text-slate-500">Lo pidió {{ $c->solicitante->name ?? '—' }}@if ($c->resuelto_at) · lo resolvió {{ $c->resolutor->name ?? '—' }} el {{ $c->resuelto_at->format('d/m/Y H:i') }}@endif @if ($c->nota_resolucion) · Nota: {{ $c->nota_resolucion }} @endif</p>
                    @if ($c->esPendiente())<a href="{{ route('cambios-precio.ver', $c->id) }}" class="text-sm font-semibold text-marino underline">Revisar</a>@endif
                </article>
            @empty
                <div class="rounded-xl bg-white p-8 text-center text-base text-slate-600 shadow-sm">No hay cambios de precio con ese filtro.</div>
            @endforelse
        </div>
        <div>{{ $historial->links() }}</div>
    @endif
</div>

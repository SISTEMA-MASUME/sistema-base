<div>
    @php
        $dinero = fn ($v) => '$'.number_format((float) $v, 2);
        $cant = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    @endphp

    <div class="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <p class="text-sm text-slate-500">Pedido P-{{ $pedido->folio }}</p>
        <p class="font-semibold text-marino">{{ $pedido->cliente->nombre }}@if ($pedido->numero_oc_cliente) · OC {{ $pedido->numero_oc_cliente }}@endif</p>
        <a href="{{ url('/pedidos/'.$pedido->id) }}" class="mt-2 inline-block text-sm font-medium text-marino underline">Volver al pedido</a>
        @if ($yaTienenOrden > 0)
            <a href="{{ route('ordenes-proveedor.index', ['pedido' => $pedido->id]) }}" class="ml-4 mt-2 inline-block text-sm font-medium text-marino underline">Ver órdenes ya generadas ({{ $pedido->ordenesProveedor()->count() }})</a>
        @endif
    </div>

    @if ($candado['nivel'] === 'bloqueo')
        <div role="alert" class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">
            <p class="font-semibold">Pedido sin conciliar con la OC del cliente</p>
            <p>{{ $candado['texto'] }}</p>
            <label class="etiqueta mt-2" for="motivo-sin-conciliar">Motivo para generar sin conciliar</label>
            <textarea id="motivo-sin-conciliar" wire:model="motivoSinConciliar" rows="2" class="campo" placeholder="Ej. El cliente confirmó por teléfono que son 2 piezas"></textarea>
            @error('motivoSinConciliar')<p class="error-campo">{{ $message }}</p>@enderror
        </div>
    @elseif ($candado['nivel'] === 'aviso')
        <div role="status" class="mb-4 rounded-lg border border-yellow-300 bg-yellow-50 px-4 py-3 text-base text-yellow-800">{{ $candado['texto'] }}</div>
    @endif

    @if ($error)
        <div role="alert" class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
    @endif

    <datalist id="lista-proveedores">
        @foreach ($proveedores as $pr)
            <option value="{{ $pr->nombre }}"></option>
        @endforeach
    </datalist>

    @forelse ($grupos as $g)
        @php $k = $g['clave']; @endphp
        <section wire:key="grupo-{{ $k }}" class="mb-5 rounded-xl bg-white p-4 shadow-sm {{ $k === 'sin' ? 'ring-2 ring-naranja' : '' }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <label class="flex items-center gap-2 text-lg font-bold text-marino">
                    <input type="checkbox" wire:model.live="config.{{ $k }}.incluir" class="h-5 w-5">
                    {{ $g['nombre'] }}
                </label>
                <span class="text-base font-semibold">Total sin IVA: {{ $dinero($g['total']) }}</span>
            </div>

            @if ($k === 'sin')
                <p class="mt-2 text-sm text-naranja">Falta el proveedor de cada partida.</p>
            @endif
            @error('grupo.'.$k)<p class="error-campo mt-2">{{ $message }}</p>@enderror

            @if ($k !== 'sin')
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="etiqueta" for="metodo-{{ $k }}">¿Cómo se le paga?</label>
                        <select id="metodo-{{ $k }}" wire:model.live="config.{{ $k }}.metodo_pago" class="campo {{ ($config[$k]['metodo_pago'] ?? '') === '' ? 'border-red-400 bg-red-50' : '' }}" data-metodo-pago>
                            <option value="">— Elige cómo se paga —</option>
                            @foreach ($metodos as $m)
                                <option value="{{ $m }}">{{ $m }}</option>
                            @endforeach
                        </select>
                        @if (($config[$k]['metodo_pago'] ?? '') === \App\Enums\EstadoOrdenProveedor::CREDITO)
                            <p class="mt-1 text-sm text-indigo-800">Se anota en "A quién le debo".</p>
                        @endif
                    </div>
                    <div class="sm:col-span-2">
                        <label class="etiqueta" for="coment-{{ $k }}">Comentarios para el proveedor</label>
                        <textarea id="coment-{{ $k }}" wire:model="config.{{ $k }}.comentarios" rows="2" class="campo" placeholder="Opcional"></textarea>
                    </div>
                </div>
            @endif

            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-2 py-2"></th>
                            <th class="px-2 py-2">Descripción</th>
                            <th class="px-2 py-2">Modelo</th>
                            <th class="px-2 py-2 text-right">Cant.</th>
                            <th class="px-2 py-2">Unidad</th>
                            <th class="px-2 py-2 text-right">Costo s/IVA</th>
                            <th class="px-2 py-2 text-right">Costo c/IVA</th>
                            <th class="px-2 py-2 text-right">Total s/IVA</th>
                            <th class="px-2 py-2">Proveedor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($g['partidas'] as $p)
                            <tr wire:key="partida-{{ $p->id }}">
                                <td class="px-2 py-2"><input type="checkbox" wire:model.live="seleccion.{{ $p->id }}" class="h-5 w-5" aria-label="Incluir partida"></td>
                                <td class="px-2 py-2">
                                    {{ $p->descripcion }}
                                    @if ($p->proveedor_vendedor || $p->proveedor_telefono)
                                        <span class="block text-xs text-slate-600">Vendedor: {{ $p->proveedor_vendedor }}
                                            @if ($p->proveedor_telefono)
                                                @if ($wa = \App\Support\Telefono::whatsapp($p->proveedor_telefono))<a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="font-semibold text-green-700 underline">WhatsApp {{ $p->proveedor_telefono }}</a>@else Tel. {{ $p->proveedor_telefono }}@endif
                                            @endif
                                        </span>
                                    @endif
                                    @if (preg_match('#^https?://#i', (string) $p->link_compra))
                                        <a href="{{ $p->link_compra }}" target="_blank" rel="noopener noreferrer" class="block text-xs font-semibold text-marino underline">🛒 Dónde comprar</a>
                                    @elseif ($p->link_compra)
                                        <span class="block text-xs text-slate-600">Dónde comprar: {{ $p->link_compra }}</span>
                                    @endif
                                </td>
                                <td class="px-2 py-2">{{ $p->modelo }}</td>
                                <td class="px-2 py-2 text-right">{{ $cant($p->cantidad) }}</td>
                                <td class="px-2 py-2">{{ $p->unidad }}</td>
                                <td class="px-2 py-2 text-right">{{ $dinero($p->costo) }}</td>
                                <td class="px-2 py-2 text-right">{{ $dinero((float) $p->costo * (1 + $iva)) }}</td>
                                <td class="px-2 py-2 text-right font-medium">{{ $dinero((float) $p->costo * (float) $p->cantidad) }}</td>
                                <td class="px-2 py-2">
                                    <input type="text" list="lista-proveedores" class="campo min-w-40 text-sm" placeholder="{{ $k === 'sin' ? 'Elegir proveedor' : 'Cambiar proveedor' }}"
                                        aria-label="Proveedor de la partida"
                                        wire:change="asignarProveedorNombre({{ $p->id }}, $event.target.value)">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <div class="rounded-xl bg-white p-6 text-center text-slate-500">
            Todas las partidas de este pedido ya tienen orden de compra.
        </div>
    @endforelse

    @if ($grupos->isNotEmpty())
        <div class="sticky bottom-0 -mx-4 flex justify-end gap-3 border-t border-slate-200 bg-white/95 px-4 py-3">
            <button type="button" wire:click="generar" wire:loading.attr="disabled" class="btn-primario w-full sm:w-auto" @disabled($nOrdenes === 0)>
                Generar {{ $nOrdenes }} {{ $nOrdenes === 1 ? 'orden' : 'órdenes' }}
            </button>
        </div>
    @endif
</div>

<div>
    @php
        $dinero = fn ($v) => '$'.number_format((float) $v, 2);
        $cant = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
        $e = $orden->estado;
        $abierta = in_array($e->value, ['emitida', 'enviada'], true);
    @endphp

    @if ($mensaje)
        <div role="status" class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    @if ($orden->requiere_reenvio)
        <div role="alert" class="mb-4 flex flex-col gap-3 rounded-lg border border-orange-300 bg-orange-50 px-4 py-3 text-base text-orange-900 sm:flex-row sm:items-center sm:justify-between">
            <span><strong>Reenviar a {{ $orden->proveedor->nombre }}:</strong> cambió la cantidad después de enviarla.</span>
            @if ($puedeGestionar)
                <button type="button" wire:click="marcarReenviada" class="btn-primario shrink-0">Ya se la reenvié</button>
            @endif
        </div>
    @endif

    <div class="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-2xl font-bold text-marino">{{ $orden->folioTexto() }}</p>
                <span class="mt-1 inline-block rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $e->etiqueta() }}</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('ordenes-proveedor.pdf', $orden) }}" target="_blank" class="btn-secundario">Ver PDF</a>
                <a href="{{ route('ordenes-proveedor.pdf', ['orden' => $orden, 'descargar' => 1]) }}" class="btn-secundario">Descargar PDF</a>
                <a href="{{ route('ordenes-proveedor.imagen', ['orden' => $orden, 'descargar' => 1]) }}" class="btn-secundario">Descargar imagen</a>
            </div>
        </div>

        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-xs uppercase text-slate-500">Proveedor</dt>
                <dd class="font-semibold">{{ $orden->proveedor->nombre }}</dd>
                @php $datosProv = $orden->proveedor->datosContacto(); @endphp
                @if ($datosProv['nombre'])<dd>{{ $datosProv['nombre'] }}</dd>@endif
                @if ($datosProv['telefono'])<dd>Tel. {{ $datosProv['telefono'] }}</dd>@endif
                @if ($datosProv['correo'])<dd>{{ $datosProv['correo'] }}</dd>@endif
            </div>
            <div>
                <dt class="text-xs uppercase text-slate-500">Pedido de origen</dt>
                <dd class="font-semibold"><a href="{{ url('/pedidos/'.$orden->pedido_id) }}" class="text-marino underline">P-{{ $orden->pedido->folio }}</a> · {{ $orden->pedido->cliente->nombre }}</dd>
                @if ($orden->pedido->numero_oc_cliente)<dd>OC del cliente: {{ $orden->pedido->numero_oc_cliente }}</dd>@endif
            </div>
            <div>
                <dt class="text-xs uppercase text-slate-500">Método de pago</dt>
                <dd class="font-semibold">{{ $orden->metodo_pago }}</dd>
                @if ($deuda)
                    <dd class="mt-1">
                        <a href="{{ route('finanzas.por-pagar') }}" class="inline-block rounded-full px-3 py-1 text-xs font-semibold {{ $deuda->estatus === 'pagada' ? 'bg-green-100 text-green-800' : ($deuda->estatus === 'cancelada' ? 'bg-slate-100 text-slate-600' : 'bg-indigo-100 text-indigo-800') }}">
                            {{ $deuda->estatus === 'pagada' ? 'Ya se le pagó' : ($deuda->estatus === 'cancelada' ? 'Deuda cancelada' : 'Se le debe $'.number_format((float) $deuda->importe, 2).' · A quién le debo') }}
                        </a>
                    </dd>
                @endif
            </div>
            <div>
                <dt class="text-xs uppercase text-slate-500">Generada por</dt>
                <dd>{{ $orden->user?->name }} · {{ $orden->created_at->format('d/m/Y H:i') }}</dd>
            </div>
            @if ($orden->comentarios)
                <div class="sm:col-span-2">
                    <dt class="text-xs uppercase text-slate-500">Comentarios</dt>
                    <dd class="whitespace-pre-line">{{ $orden->comentarios }}</dd>
                </div>
            @endif
        </dl>
    </div>

    @if ($abierta || $confirmandoRecibida)
        <div class="mb-4 rounded-xl bg-white p-4 shadow-sm">
            @if ($confirmandoRecibida)
                <p class="mb-3 font-semibold">Llegó el material. ¿Pasar también sus partidas a "recibida"?</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="marcarRecibida(true)" class="btn-primario">Sí, marcar orden y partidas</button>
                    <button type="button" wire:click="marcarRecibida(false)" class="btn-secundario">Solo la orden</button>
                    <button type="button" wire:click="$set('confirmandoRecibida', false)" class="btn-secundario">Volver</button>
                </div>
            @else
                <div class="flex flex-wrap gap-2">
                    @if ($puedeGestionar && $e->value === 'emitida')
                        <button type="button" wire:click="marcarEnviada" class="btn-primario">Marcar como enviada</button>
                    @endif
                    <button type="button" wire:click="pedirRecibida" class="btn-primario">Marcar como recibida</button>
                    @if ($puedeGestionar)
                    <button type="button" wire:click="cancelar" wire:confirm="¿Cancelar esta orden? Sus partidas quedarán libres para pedirse de nuevo." class="btn-secundario text-red-700">Cancelar orden</button>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <livewire:compartir tipo="orden_proveedor" :documento-id="$orden->id" :key="'compartir-oc-'.$orden->id" />

    @if ($puedeGestionar && count($textosCompra) > 1)
        {{-- Varias partidas se compran por internet: un solo mensaje para Daniel con todas. --}}
        <div class="mb-4 flex flex-col gap-2 rounded-xl bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-slate-700">{{ count($textosCompra) }} partidas por link</p>
            <button type="button" class="btn-primario shrink-0"
                    x-data="{ copiado: false }"
                    x-on:click="const t = @js($textoCompraTodas);
                        navigator.clipboard.writeText(t).then(() => { copiado = true; setTimeout(() => copiado = false, 3000) }).catch(() => window.prompt('Copia el texto:', t));
                        $wire.registrarPedirCompra(@js(array_keys($textosCompra)))"
                    x-text="copiado ? '✓ Copiado' : '📋 Copiar todas para pedir compra'">📋 Copiar todas para pedir compra</button>
        </div>
    @endif

    <div class="mb-4 overflow-x-auto rounded-xl bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-3 py-3">#</th>
                    <th class="px-3 py-3">Descripción</th>
                    <th class="px-3 py-3">Modelo</th>
                    <th class="px-3 py-3">Dónde comprar</th>
                    <th class="px-3 py-3 text-right">Cant.</th>
                    <th class="px-3 py-3">Unidad</th>
                    <th class="px-3 py-3 text-right">Costo s/IVA</th>
                    <th class="px-3 py-3 text-right">Costo c/IVA</th>
                    <th class="px-3 py-3 text-right">Importe s/IVA</th>
                    <th class="px-3 py-3">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse ($partidas as $i => $p)
                    <tr wire:key="p-{{ $p->id }}">
                        <td class="px-3 py-2">{{ $i + 1 }}</td>
                        <td class="px-3 py-2">{{ $p->descripcion }}</td>
                        @php
                            [$modelo, $link] = \App\Support\LinkCompra::separar($p->modelo, $p->link_compra);
                        @endphp
                        <td class="px-3 py-2">{{ $modelo }}</td>
                        <td class="min-w-56 px-3 py-2" x-data="{ editando: false, link: @js((string) $link) }">
                            <div x-show="!editando" class="flex flex-wrap items-center gap-2">
                                @if ($link)
                                    @if ($puedeGestionar && isset($textosCompra[$p->id]))
                                        <button type="button" class="btn-secundario !min-h-10 text-sm"
                                                x-data="{ copiado: false }"
                                                x-on:click="const t = @js($textosCompra[$p->id]);
                                                    navigator.clipboard.writeText(t).then(() => { copiado = true; setTimeout(() => copiado = false, 3000) }).catch(() => window.prompt('Copia el texto:', t));
                                                    $wire.registrarPedirCompra([{{ $p->id }}])"
                                                x-text="copiado ? '✓ Copiado' : '📋 Copiar para pedir compra'">📋 Copiar para pedir compra</button>
                                    @endif
                                    <a href="{{ $link }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-marino underline">Ver link</a>
                                @endif
                                @if ($puedeGestionar)
                                    <button type="button" x-on:click="editando = true" class="text-xs text-slate-500 underline">{{ $link ? 'Cambiar' : '+ Poner link de compra' }}</button>
                                @elseif (! $link)
                                    <span class="text-slate-400">—</span>
                                @endif
                            </div>
                            @if ($puedeGestionar)
                                <div x-show="editando" x-cloak class="flex gap-2">
                                    <input type="url" inputmode="url" x-model="link" class="campo !min-h-10 text-sm" placeholder="https://…" aria-label="Link de compra">
                                    <button type="button" class="btn-secundario !min-h-10 shrink-0"
                                            x-on:click="$wire.guardarLink({{ $p->id }}, link).then(() => { editando = false })">Guardar</button>
                                </div>
                                @error('link.'.$p->id) <p class="error-campo">{{ $message }}</p> @enderror
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right">{{ $cant($p->cantidad) }}</td>
                        <td class="px-3 py-2">{{ $p->unidad }}</td>
                        <td class="px-3 py-2 text-right">{{ $dinero($p->costo) }}</td>
                        <td class="px-3 py-2 text-right">{{ $dinero((float) $p->costo * (1 + $iva)) }}</td>
                        <td class="px-3 py-2 text-right font-medium">{{ $dinero((float) $p->costo * (float) $p->cantidad) }}</td>
                        <td class="px-3 py-2">{{ $p->estado->etiqueta() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-6 text-center text-slate-500">
                        {{ $e->value === 'cancelada' ? 'La orden está cancelada: sus partidas volvieron a quedar libres.' : 'Sin partidas.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="ml-auto max-w-xs rounded-xl bg-white p-4 text-sm shadow-sm">
        <div class="flex justify-between py-1"><span>Total sin IVA</span><span>{{ $dinero($totales['subtotal']) }}</span></div>
        <div class="flex justify-between py-1"><span>IVA</span><span>{{ $dinero($totales['iva']) }}</span></div>
        <div class="flex justify-between border-t border-slate-200 py-1 text-base font-bold text-marino"><span>Total con IVA</span><span>{{ $dinero($totales['total']) }}</span></div>
        @if ($puedeGestionar && $e->value !== 'cancelada' && $partidas->isNotEmpty() && ! $corrigiendoCosto)
            <button type="button" wire:click="abrirCorregirCosto" class="btn-secundario mt-3 w-full">Corregir total (descuento del proveedor)</button>
        @endif
    </div>

    @if ($corrigiendoCosto)
        <form wire:submit="corregirCosto" class="ml-auto mt-3 max-w-md rounded-xl border border-orange-300 bg-orange-50 p-4 shadow-sm">
            <label class="etiqueta" for="nuevoTotal">Total pagado con IVA (ahora {{ $dinero($totales['total']) }})</label>
            <input id="nuevoTotal" type="text" inputmode="decimal" wire:model="nuevoTotal" class="campo" placeholder="Ej. 2900" autofocus>
            @error('nuevoTotal') <p class="error-campo">{{ $message }}</p> @enderror
            <label class="etiqueta mt-3" for="motivoCosto">Motivo (opcional)</label>
            <input id="motivoCosto" type="text" wire:model="motivoCosto" maxlength="200" class="campo" placeholder="Ej. descuento del proveedor">
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <button type="submit" wire:loading.attr="disabled" class="btn-primario">Guardar total nuevo</button>
                <button type="button" wire:click="$set('corrigiendoCosto', false)" class="btn-secundario">Cancelar</button>
            </div>
        </form>
    @endif
</div>

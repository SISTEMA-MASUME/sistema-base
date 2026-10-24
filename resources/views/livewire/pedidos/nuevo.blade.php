<div class="space-y-6">
    {{-- ===== Pedido sin terminar de antes: se retoma en el mismo paso ===== --}}
    @if ($ofrecerBorrador && $borrador)
        <div role="status" class="rounded-xl border-2 border-naranja bg-orange-50 p-4 sm:p-5">
            <p class="text-base font-semibold text-marino">Tienes un pedido sin terminar</p>
            <p class="mt-1 text-base text-slate-700">
                {{ $borrador->cliente->nombre ?? 'Sin cliente' }} ·
                {{ $borrador->partidas }} {{ $borrador->partidas === 1 ? 'partida' : 'partidas' }} ·
                te quedaste en el paso {{ $borrador->paso }}
                ({{ [1 => 'Cliente', 2 => 'Material', 3 => 'OC del cliente', 4 => 'Datos y crear'][$borrador->paso] ?? '' }}) ·
                guardado el {{ $borrador->updated_at->format('d/m/Y') }} a las {{ $borrador->updated_at->format('H:i') }}
            </p>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <button type="button" wire:click="retomarBorrador" class="btn-primario">Continuar donde me quedé</button>
                <button type="button" wire:click="descartarBorrador" wire:confirm="¿Tirar el pedido sin terminar y empezar uno nuevo?" class="btn-secundario">Empezar de nuevo</button>
            </div>
        </div>
    @endif

    {{-- ===== Guía de pasos: el trabajo va en orden ===== --}}
    <ol class="grid grid-cols-2 gap-2 sm:grid-cols-4" aria-label="Pasos">
        @foreach ([1 => 'Cliente', 2 => 'Material', 3 => 'OC del cliente', 4 => 'Datos y crear'] as $n => $t)
            <li>
                <button type="button" wire:click="irAPaso({{ $n }})" @disabled($n > $paso + 1)
                        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-semibold
                            {{ $paso === $n ? 'bg-marino text-white' : ($n < $paso ? 'bg-green-100 text-green-900' : 'bg-white text-slate-500 shadow-sm') }}">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full {{ $paso === $n ? 'bg-white text-marino' : ($n < $paso ? 'bg-green-600 text-white' : 'bg-slate-200') }}">{{ $n < $paso ? '✓' : $n }}</span>
                    {{ $t }}
                </button>
            </li>
        @endforeach
    </ol>
    @error('paso') <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $message }}</div> @enderror

    {{-- ===== Paso 1: cliente ===== --}}
    <div class="rounded-xl bg-white p-5 shadow-sm">
        <h2 class="mb-3 text-lg font-semibold text-marino">1. Cliente</h2>
        @if ($cliente)
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($clientesBusqueda as $cb)
                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-base font-semibold" wire:key="cb-{{ $cb->id }}">
                            {{ $cb->nombre }}
                            @if ($clientesBusqueda->count() > 1)
                                <button type="button" wire:click="quitarCliente({{ $cb->id }})" class="ml-1 text-slate-500 hover:text-red-600" aria-label="Quitar {{ $cb->nombre }} de la búsqueda">✕</button>
                            @endif
                        </span>
                    @endforeach
                </div>
                <button type="button" wire:click="otroCliente" class="btn-secundario">Cambiar cliente</button>
            </div>
            @if ($paso === 2)
            <div class="mt-3">
                <label class="etiqueta" for="agregar-cliente">Buscar también en otro cliente</label>
                <input id="agregar-cliente" type="search" wire:model.live.debounce.250ms="buscarCliente" class="campo" placeholder="Ej. KAPPA" autocomplete="off">
                @if ($resultados->isNotEmpty())
                    <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200">
                        @foreach ($resultados as $r)
                            <li wire:key="add-{{ $r->id }}">
                                <button type="button" wire:click="agregarCliente({{ $r->id }})" class="block min-h-12 w-full px-4 py-3 text-left text-base hover:bg-slate-50">+ {{ $r->nombre }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            @endif
        @else
            <label class="etiqueta" for="buscar-cliente">Buscar cliente</label>
            <input id="buscar-cliente" type="search" wire:model.live.debounce.250ms="buscarCliente" class="campo" placeholder="Escribe parte del nombre" autocomplete="off">
            @if ($resultados->isNotEmpty())
                <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200">
                    @foreach ($resultados as $r)
                        <li wire:key="cli-{{ $r->id }}">
                            <button type="button" wire:click="seleccionarCliente({{ $r->id }})" class="block min-h-12 w-full px-4 py-3 text-left text-base hover:bg-slate-50">{{ $r->nombre }}</button>
                        </li>
                    @endforeach
                </ul>
            @elseif (trim($buscarCliente) !== '')
                <p class="mt-2 text-sm text-slate-500">No hay clientes con ese nombre.</p>
            @endif
            @error('clienteId') <p class="error-campo">{{ $message }}</p> @enderror
        @endif
    </div>

    @if ($cliente && $paso > 2)
        <div class="flex items-center justify-between gap-2 rounded-xl bg-white px-5 py-3 shadow-sm">
            <p class="text-base"><strong>2. Material:</strong> {{ count($seleccion) + count($extras) }} {{ count($seleccion) + count($extras) === 1 ? 'partida' : 'partidas' }}</p>
            <button type="button" wire:click="irAPaso(2)" class="btn-secundario">Cambiar</button>
        </div>
    @endif

    @if ($cliente && $paso === 2)
        {{-- ===== Paso 2: partidas ===== --}}
        <div class="rounded-xl bg-white p-5 shadow-sm">
            <h2 class="mb-3 text-lg font-semibold text-marino">2. Material</h2>
            @if ($resultadoOc && $diferencias !== [])
                {{-- Regresaste a corregir: aquí siguen las diferencias que encontró la revisión de la OC. --}}
                <div class="mb-4 space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p class="font-semibold text-amber-900">Diferencias con la OC del cliente ({{ count($diferencias) }}):</p>
                        <button type="button" wire:click="irAPaso(3)" class="btn-primario">Volver a la revisión de la OC →</button>
                    </div>
                    <ul class="list-disc space-y-1 pl-5 text-sm text-amber-900">
                        @foreach ($diferencias as $d)<li>{{ $d['texto'] }}</li>@endforeach
                    </ul>
                </div>
            @elseif ($resultadoOc)
                <p class="mb-4 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-900">✓ Con lo que tienes marcado, la OC del cliente coincide. <button type="button" wire:click="irAPaso(3)" class="underline">Volver a la revisión</button></p>
            @endif

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label class="etiqueta" for="f-folios">Folio(s) de cotización</label>
                    <input id="f-folios" type="text" inputmode="numeric" wire:model.live.debounce.400ms="fFolios" class="campo" placeholder="Ej. 90001, 90002">
                </div>
                <div>
                    <label class="etiqueta" for="f-texto">Texto en la descripción</label>
                    <input id="f-texto" type="search" wire:model.live.debounce.400ms="fTexto" class="campo" placeholder="Todas las palabras">
                </div>
                <div>
                    <label class="etiqueta" for="f-prov">Proveedor</label>
                    <select id="f-prov" wire:model.live="fProveedor" class="campo">
                        <option value="">Todos</option>
                        @foreach ($proveedores as $pv)
                            <option value="{{ $pv->id }}">{{ $pv->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="etiqueta" for="f-vend">Vendedor</label>
                    <select id="f-vend" wire:model.live="fVendedor" class="campo">
                        <option value="">Todos</option>
                        @foreach ($usuarios as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="etiqueta" for="f-desde">Desde</label>
                    <input id="f-desde" type="date" wire:model.live="fDesde" class="campo">
                </div>
                <div>
                    <label class="etiqueta" for="f-hasta">Hasta</label>
                    <input id="f-hasta" type="date" wire:model.live="fHasta" class="campo">
                </div>
            </div>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <button type="button" wire:click="limpiarFiltros" class="btn-secundario">Ver últimos 60 días</button>
                <button type="button" wire:click="seleccionarVisibles" class="btn-secundario">Marcar las de esta página</button>
                @if (count($seleccion))
                    <button type="button" wire:click="quitarSeleccion" class="btn-secundario">Quitar marcas ({{ count($seleccion) }})</button>
                @endif
            </div>
            @error('seleccion') <p class="error-campo">{{ $message }}</p> @enderror

            @php
                $conMotivo = collect($preciosUsados)->filter(fn ($x) => $x['cambio'] && ! $x['menor'])->count();
                $menores = collect($preciosUsados)->filter(fn ($x) => $x['cambio'] && $x['menor'])->count();
            @endphp
            @if ($conMotivo)
                <div class="mt-4 rounded-lg border border-orange-300 bg-orange-50 p-3">
                    <label class="etiqueta !text-orange-900" for="motivo-general">
                        Motivo del cambio de precio · {{ $conMotivo }} {{ $conMotivo === 1 ? 'partida' : 'partidas' }} con cambio de $10 o más
                    </label>
                    <input id="motivo-general" type="text" wire:model.blur="motivoGeneral" maxlength="500" class="campo" placeholder="Ej.: el cliente negoció precio en toda la OC">
                    @error('motivoGeneral') <p class="error-campo">{{ $message }}</p> @enderror
                    @unless ($esAdmin)
                        <p class="mt-1 text-xs text-orange-900">{{ $precioConAutorizacion ? 'Lo autoriza Daniel después de crear el pedido.' : 'Avísale a Daniel por WhatsApp desde la tarjeta.' }}</p>
                    @endunless
                </div>
            @endif

            <div class="mt-4 overflow-x-auto">
                @if ($partidas->isEmpty())
                    <p class="p-4 text-center text-slate-600">No hay partidas con esos filtros para este cliente.</p>
                @else
                    <table class="w-full min-w-[56rem] text-left text-sm">
                        <thead class="border-b border-slate-200 text-slate-600">
                            <tr>
                                <th class="w-10 px-2 py-2"></th>
                                @if ($clientesBusqueda->count() > 1)<th class="px-2 py-2">Cliente</th>@endif
                                <th class="px-2 py-2">Folio</th>
                                <th class="px-2 py-2">Fecha</th>
                                <th class="px-2 py-2">Descripción</th>
                                <th class="px-2 py-2">Cantidad</th>
                                <th class="px-2 py-2">Unidad</th>
                                <th class="px-2 py-2">Proveedor</th>
                                <th class="px-2 py-2 text-right">Costo</th>
                                <th class="px-2 py-2 text-right">Precio</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($partidas as $p)
                                @php($marcada = in_array((string) $p->id, array_map('strval', $seleccion), true))
                                <tr wire:key="par-{{ $p->id }}" class="{{ $marcada ? 'bg-orange-50' : '' }}">
                                    <td class="px-2 py-2"><input type="checkbox" value="{{ $p->id }}" wire:model.live="seleccion" class="h-5 w-5 rounded border-slate-300" aria-label="Marcar partida"></td>
                                    @if ($clientesBusqueda->count() > 1)<td class="px-2 py-2 text-xs font-semibold">{{ $p->cotizacion->cliente->nombre ?? '' }}</td>@endif
                                    <td class="px-2 py-2 font-medium">{{ $p->cotizacion->folio }}</td>
                                    <td class="whitespace-nowrap px-2 py-2">{{ $p->cotizacion->fecha->format('d/m/Y') }}</td>
                                    <td class="px-2 py-2">
                                        {{ $p->descripcion }}
                                        @if ($enPedido->has($p->id))
                                            <span class="ml-1 rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">ya en pedido {{ $enPedido[$p->id]->map(fn ($x) => 'P-'.$x->pedido->folio)->implode(', ') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-2">
                                        @if ($marcada)
                                            <input type="number" step="any" min="0" inputmode="decimal" wire:model.blur="cantidades.{{ $p->id }}" class="campo !w-24 !py-1" aria-label="Cantidad">
                                        @else
                                            {{ rtrim(rtrim(number_format((float) $p->cantidad, 3, '.', ''), '0'), '.') }}
                                        @endif
                                    </td>
                                    <td class="px-2 py-2">{{ $p->unidad }}</td>
                                    <td class="px-2 py-2">{{ $p->proveedor->nombre ?? '' }}</td>
                                    <td class="px-2 py-2 text-right">${{ number_format((float) $p->costo, 2) }}</td>
                                    <td class="px-2 py-2 text-right">
                                        @if ($marcada)
                                            <input type="number" step="0.01" min="0" inputmode="decimal" wire:model.blur="precios.{{ $p->id }}" class="campo !w-24 !py-1 text-right {{ ($preciosUsados[$p->id]['cambio'] ?? false) ? '!border-naranja !bg-orange-100 font-semibold' : '' }}" aria-label="Precio de venta sin IVA">
                                            @if ($preciosUsados[$p->id]['cambio'] ?? false)
                                                <span class="mt-0.5 block text-xs text-slate-500">cotizado ${{ number_format((float) $p->precio, 2) }}</span>
                                            @endif
                                            @error('precios.'.$p->id) <p class="error-campo">{{ $message }}</p> @enderror
                                        @else
                                            ${{ number_format((float) $p->precio, 2) }}
                                        @endif
                                    </td>
                                </tr>
                                @if ($marcada && ($preciosUsados[$p->id]['cambio'] ?? false))
                                    @php($menor = $preciosUsados[$p->id]['menor'] ?? false)
                                    <tr wire:key="par-precio-{{ $p->id }}" class="{{ $menor ? 'bg-slate-50' : 'bg-orange-100/60' }}">
                                        <td colspan="{{ $clientesBusqueda->count() > 1 ? 10 : 9 }}" class="px-2 py-3">
                                            <div class="sticky left-0 grid w-[calc(100vw-4.5rem)] max-w-full gap-3 sm:w-auto sm:grid-cols-[2fr_1fr_1.2fr]">
                                                <div class="sm:col-span-3 text-sm font-semibold {{ $menor ? 'text-slate-700' : 'text-orange-900' }}">
                                                    {{ $menor ? 'Ajuste menor' : 'Cambio de precio' }}: ${{ number_format((float) $p->precio, 2) }} → ${{ number_format($preciosUsados[$p->id]['usado'], 2) }}
                                                    
                                                </div>
                                                @if ($menor)
                                                <div></div>
                                                @else
                                                <div>
                                                    <label class="etiqueta" for="mp-{{ $p->id }}">Motivo solo de esta partida (opcional)</label>
                                                    <input id="mp-{{ $p->id }}" type="text" wire:model.blur="motivosPrecio.{{ $p->id }}" maxlength="500" class="campo" placeholder="Déjalo vacío si es el mismo motivo">
                                                    @error('motivosPrecio.'.$p->id) <p class="error-campo">{{ $message }}</p> @enderror
                                                </div>
                                                @endif
                                                <div>
                                                    <label class="etiqueta" for="cp-{{ $p->id }}">Costo del sustituto (opcional)</label>
                                                    <input id="cp-{{ $p->id }}" type="number" step="any" min="0" inputmode="decimal" wire:model.blur="costosPrecio.{{ $p->id }}" class="campo">
                                                    @error('costosPrecio.'.$p->id) <p class="error-campo">{{ $message }}</p> @enderror
                                                </div>
                                                <div>
                                                    <label class="etiqueta" for="pp-{{ $p->id }}">Proveedor del sustituto (opcional)</label>
                                                    <input id="pp-{{ $p->id }}" type="text" list="lista-proveedores-precio" wire:model.blur="proveedoresPrecio.{{ $p->id }}" autocomplete="off" class="campo" placeholder="Escribe y elige">
                                                    @error('proveedoresPrecio.'.$p->id) <p class="error-campo">{{ $message }}</p> @enderror
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                    <datalist id="lista-proveedores-precio">
                        @foreach ($proveedores as $pv)<option value="{{ $pv->nombre }}"></option>@endforeach
                    </datalist>
                    <div class="mt-3">{{ $partidas->links() }}</div>
                @endif
            </div>
            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                {{-- Para cuadrar contra la OC: cuenta todas las marcadas (de todas las páginas) con las cantidades escritas. --}}
                <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1 rounded-lg bg-slate-50 px-4 py-2 text-sm text-slate-700" aria-live="polite">
                    <span><strong class="text-base text-marino">{{ $sumaMarcadas['partidas'] }}</strong> {{ $sumaMarcadas['partidas'] === 1 ? 'partida marcada' : 'partidas marcadas' }}</span>
                    <span>Subtotal <strong class="text-slate-900">${{ number_format($sumaMarcadas['subtotal'], 2) }}</strong></span>
                    <span>IVA ${{ number_format($sumaMarcadas['iva'], 2) }}</span>
                    <span>Total <strong class="text-base text-marino">${{ number_format($sumaMarcadas['total'], 2) }}</strong></span>
                </div>
                <button type="button" wire:click="irAPaso(3)" class="btn-primario">Siguiente: OC del cliente → ({{ count($seleccion) }})</button>
            </div>
        </div>
    @endif

    @if ($cliente && $paso === 3)
        {{-- ===== Paso 3: OC del cliente (se revisa con IA contra lo marcado) ===== --}}
        <div class="space-y-4 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">3. OC del cliente</h2>

            @unless ($sinOc)
                <div class="space-y-3"
                     x-data="{ subiendo: false, aviso: '', activo: false,
                        subir(file) {
                            if (!file) { this.aviso = 'No se encontró un archivo en el portapapeles.'; setTimeout(() => this.aviso = '', 6000); return; }
                            this.aviso = ''; this.subiendo = true;
                            $wire.upload('archivo', file, () => this.subiendo = false, () => { this.subiendo = false; this.aviso = 'No se pudo subir el archivo.'; }, () => {});
                        },
                        async pegar() {
                            // En celular no hay Ctrl+V: se lee el portapapeles con permiso del navegador (imágenes y, si el teléfono lo permite, PDF).
                            try {
                                if (!navigator.clipboard?.read) throw new Error();
                                for (const item of await navigator.clipboard.read()) {
                                    const tipo = item.types.find(t => t === 'application/pdf' || t.startsWith('image/'));
                                    if (tipo) {
                                        const blob = await item.getType(tipo);
                                        const ext = tipo === 'application/pdf' ? 'pdf' : (tipo.split('/')[1] || 'png');
                                        return this.subir(new File([blob], 'oc-pegada.' + ext, { type: tipo }));
                                    }
                                }
                                this.subir(null);
                            } catch (e) {
                                this.aviso = 'Tu celular no deja pegar este archivo. Usa «Elegir PDF o foto» (en WhatsApp: abre el PDF → Compartir/Guardar, y luego elígelo aquí).';
                            }
                        } }">
                    {{-- Botones grandes (sirven en celular). --}}
                    <div class="grid gap-2 sm:grid-cols-3">
                        <button type="button" x-on:click="$refs.elegirOc.click()" class="btn-primario py-3 text-base">📎 Elegir PDF o foto</button>
                        <button type="button" x-on:click="$refs.camaraOc.click()" class="btn-secundario py-3 text-base">📷 Tomar foto</button>
                        <button type="button" x-on:click="pegar()" class="btn-secundario py-3 text-base">📋 Pegar lo copiado</button>
                    </div>
                    <input x-ref="elegirOc" type="file" wire:model="archivo" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" class="hidden">
                    <input x-ref="camaraOc" type="file" wire:model="archivo" accept="image/*" capture="environment" class="hidden">

                    {{-- En computadora además se puede pegar con Ctrl+V o arrastrar. --}}
                    <div tabindex="0" role="button" aria-label="Recuadro: pega con Ctrl+V o arrastra la OC"
                         x-on:click="$el.focus()"
                         x-on:focus="activo = true" x-on:blur="activo = false"
                         x-on:paste.prevent="const it = [...($event.clipboardData?.items || [])].find(t => t.kind === 'file'); subir(it ? it.getAsFile() : null)"
                         x-on:dragover.prevent="activo = true" x-on:dragleave="activo = false"
                         x-on:drop.prevent="activo = false; subir([...($event.dataTransfer?.files || [])][0] || null)"
                         x-bind:class="activo ? 'border-orange-500 ring-2 ring-orange-300 bg-orange-50' : 'border-slate-300'"
                         class="hidden h-20 cursor-pointer items-center justify-center rounded border-2 border-dashed p-2 text-center text-sm text-slate-500 outline-none sm:flex">
                        <span>Pega (Ctrl+V) o arrastra aquí</span>
                    </div>

                    <p class="text-base" x-show="subiendo">Subiendo…</p>
                    @if ($ocNombre)
                        <p class="flex flex-wrap items-center gap-3 rounded-lg bg-green-50 px-3 py-2 text-base text-green-900">
                            <span>✓ OC: {{ $ocNombre }}</span>
                            @if ($ocRuta)
                                <button type="button" wire:click="quitarOc" class="text-sm text-red-600 underline">Quitar este archivo</button>
                            @endif
                        </p>
                    @endif
                    <p x-show="aviso" x-text="aviso" class="text-sm text-red-600" role="alert"></p>
                </div>
                @error('archivo') <p class="error-campo">{{ $message }}</p> @enderror
                <div wire:loading wire:target="archivo" class="text-sm text-slate-600">Subiendo la OC…</div>

                {{-- Resultado de la revisión --}}
                @if ($conciliacion && $conciliacion->estado === 'procesando')
                    <div wire:poll.3s="consultarOc" class="flex items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 text-base text-blue-900">
                        <span class="inline-block h-5 w-5 animate-spin rounded-full border-2 border-blue-300 border-t-blue-700" aria-hidden="true"></span>
                        Revisando la OC…
                    </div>
                @elseif ($conciliacion && $conciliacion->estado === 'error')
                    <div class="space-y-2 rounded-lg border border-red-300 bg-red-50 p-4 text-base text-red-800">
                        <p>{{ $conciliacion->error }}</p>
                        <button type="button" wire:click="reintentarLectura" class="btn-secundario">Reintentar la revisión</button>
                    </div>
                @elseif ($resultadoOc)
                    @if (! $resultadoOc['hay_diferencias'])
                        <div class="rounded-lg border border-green-300 bg-green-50 p-4 text-base text-green-900">
                            <p class="text-lg font-bold">✓ Todo coincide</p>
                            <p>La OC pide lo mismo que marcaste: {{ $resultadoOc['conteos']['coincide'] }} {{ $resultadoOc['conteos']['coincide'] === 1 ? 'partida' : 'partidas' }}, mismas cantidades.</p>
                        </div>
                    @else
                        <div class="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4">
                            <p class="text-lg font-bold text-amber-900">⚠️ Hay {{ count($diferencias) }} {{ count($diferencias) === 1 ? 'diferencia' : 'diferencias' }} entre la OC y lo que marcaste</p>
                            @if ($resultadoOc['conteos']['coincide'] > 0)
                                <p class="text-sm text-green-800">✓ {{ $resultadoOc['conteos']['coincide'] }} {{ $resultadoOc['conteos']['coincide'] === 1 ? 'partida coincide' : 'partidas coinciden' }}.</p>
                            @endif
                            <ul class="space-y-2">
                                @foreach ($diferencias as $d)
                                    <li class="flex flex-col gap-2 rounded bg-white p-3 text-base sm:flex-row sm:items-center sm:justify-between" wire:key="dif-{{ $loop->index }}">
                                        <span>{{ $d['texto'] }}</span>
                                        @if ($d['tipo'] === 'cantidad_distinta' && $d['partida_id'])
                                            <button type="button" wire:click="usarCantidadOc({{ $d['partida_id'] }}, {{ $d['cantidad_oc'] }})" class="btn-secundario shrink-0">Usar la de la OC</button>
                                        @elseif ($d['tipo'] === 'sobra_en_pedido' && $d['partida_id'])
                                            <button type="button" wire:click="desmarcar({{ $d['partida_id'] }})" class="btn-secundario shrink-0">Quitarla</button>
                                        @endif
                                    </li>
                                    @if ($d['tipo'] === 'falta_en_pedido')
                                        {{-- Arreglarlo aquí mismo: usar una partida cotizada parecida o agregarla como viene en la OC. --}}
                                        <li class="-mt-1 space-y-2 rounded-b bg-white px-3 pb-3 text-sm" wire:key="falta-{{ $d['clave'] }}">
                                            @forelse ($parecidas[$d['clave']] ?? [] as $cp)
                                                <div class="flex flex-col gap-2 rounded border border-slate-200 p-2 sm:flex-row sm:items-center sm:justify-between" wire:key="par-{{ $d['clave'] }}-{{ $cp->id }}">
                                                    <span>Cotizado en {{ $cp->cotizacion->folio }}: <strong>{{ $cp->descripcion }}</strong> · ${{ number_format((float) $cp->precio, 2) }}</span>
                                                    <button type="button" wire:click="usarCotizada({{ $cp->id }}, {{ (float) $d['cantidad_oc'] }})" class="btn-secundario shrink-0">Usar esta</button>
                                                </div>
                                            @empty
                                                <p class="text-slate-500">No encontré algo parecido en sus cotizaciones del último año.</p>
                                            @endforelse
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" wire:click="agregarDeLaOc(@js($d['clave']))" class="btn-secundario">Agregar tal como viene en la OC</button>
                                                <button type="button" wire:click="irAPaso(2)" class="text-sm text-slate-600 underline">Buscarlo en el material</button>
                                            </div>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @foreach ($resultadoOc['advertencias'] as $a)
                        <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">ℹ️ {{ $a['texto'] }}</p>
                    @endforeach

                    {{-- Lo que va en el pedido: se corrige aquí mismo y la revisión se actualiza al instante. --}}
                    <div class="space-y-2">
                        <h3 class="text-base font-semibold text-marino">Lo que va en el pedido</h3>
                        <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                            @foreach ($marcadas as $m)
                                <li class="flex flex-col gap-2 p-3 sm:flex-row sm:items-center" wire:key="va-{{ $m->id }}">
                                    <span class="flex-1 text-sm"><span class="text-slate-500">{{ $m->cotizacion->folio }} ·</span> {{ $m->descripcion }} <span class="text-slate-500">· ${{ number_format((float) ($preciosUsados[$m->id]['usado'] ?? $m->precio), 2) }}</span>@if ($preciosUsados[$m->id]['cambio'] ?? false) <span class="rounded bg-orange-100 px-1.5 py-0.5 text-xs font-semibold text-orange-800">precio cambiado (cotizado ${{ number_format((float) $m->precio, 2) }})</span>@endif</span>
                                    <label class="flex items-center gap-2 text-sm">Cant.
                                        <input type="number" step="any" min="0" inputmode="decimal" wire:model.live.debounce.500ms="cantidades.{{ $m->id }}" class="campo !w-24 !py-1" aria-label="Cantidad">
                                        {{ $m->unidad }}
                                    </label>
                                    <button type="button" wire:click="desmarcar({{ $m->id }})" class="text-sm text-red-600 underline">Quitar</button>
                                </li>
                            @endforeach
                            @foreach ($extras as $i => $e)
                                <li class="flex flex-col gap-2 bg-orange-50/50 p-3 sm:flex-row sm:items-center" wire:key="extra-{{ $i }}">
                                    <span class="flex-1 text-sm"><span class="rounded bg-orange-100 px-1.5 py-0.5 text-xs font-semibold text-orange-800">De la OC, sin cotizar</span> {{ $e['descripcion'] }} <span class="text-slate-500">· ${{ number_format((float) $e['precio'], 2) }}</span></span>
                                    <label class="flex items-center gap-2 text-sm">Cant.
                                        <input type="number" step="any" min="0" inputmode="decimal" wire:model.live.debounce.500ms="extras.{{ $i }}.cantidad" class="campo !w-24 !py-1" aria-label="Cantidad">
                                        {{ $e['unidad'] }}
                                    </label>
                                    <button type="button" wire:click="desmarcar({{ -($i + 1) }})" class="text-sm text-red-600 underline">Quitar</button>
                                </li>
                            @endforeach
                        </ul>
                        @if ($extras !== [])
                            <p class="text-xs text-slate-500">Sin proveedor ni costo: captúralos en la tarjeta.</p>
                        @endif
                    </div>
                @endif
            @endunless

            <label class="flex items-center gap-3 text-base">
                <input type="checkbox" wire:model.live="sinOc" class="h-5 w-5 rounded border-slate-300"> No tengo OC del cliente
            </label>
            @if ($sinOc || ($conciliacion && $conciliacion->estado === 'error'))
                <div>
                    <label class="etiqueta" for="p-just">{{ $sinOc ? '¿Por qué no hay OC? (obligatorio)' : '¿Por qué sigues sin revisión? (obligatorio)' }}</label>
                    {{-- Contador en vivo: antes el botón solo se apagaba y no se entendía por qué no avanzaba. --}}
                    <div x-data="{ n: @js(mb_strlen(trim($justificacion))) }">
                        <textarea id="p-just" rows="2" wire:model.live.debounce.400ms="justificacion" x-on:input="n = $event.target.value.trim().length" class="campo" placeholder="Ej. Se surte con nota de venta; el cliente manda la OC la próxima semana"></textarea>
                        <p class="mt-1 text-sm" x-bind:class="n >= {{ $minJustificacion }} ? 'text-green-700' : 'font-semibold text-red-700'">
                            <span x-show="n < {{ $minJustificacion }}">Faltan <span x-text="{{ $minJustificacion }} - n"></span> letras</span>
                            <span x-show="n >= {{ $minJustificacion }}" x-cloak>✓</span>
                        </p>
                    </div>
                </div>
            @endif

            <div class="flex flex-col gap-2 sm:flex-row sm:justify-between">
                <button type="button" wire:click="irAPaso(2)" class="btn-secundario">← Regresar al material</button>
                {{-- No se apaga: si falta algo, al picarlo sale el aviso en rojo arriba con la razón. --}}
                <button type="button" wire:click="irAPaso(4)" class="btn-primario {{ $faltaParaDatos ? 'opacity-60' : '' }}">Siguiente: datos del pedido →</button>
            </div>
            @if ($faltaParaDatos)<p class="text-right text-sm font-semibold text-red-700" role="status">No puedes avanzar todavía: {{ $faltaParaDatos }}</p>@endif
            @error('paso') <p class="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-right text-sm text-red-800" role="alert">{{ $message }}</p> @enderror
        </div>
    @endif

    @if ($cliente && $paso === 4)

        {{-- ===== Paso 3: datos del pedido ===== --}}
        <form wire:submit="crear" class="space-y-4 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">4. Datos del pedido</h2>
            @if ($resultadoOc)
                <p class="rounded-lg px-3 py-2 text-base {{ $resultadoOc['hay_diferencias'] ? 'bg-amber-50 text-amber-900' : 'bg-green-50 text-green-900' }}">
                    {{ $resultadoOc['hay_diferencias'] ? '⚠️ OC revisada con diferencias: '.$resultadoOc['resumen'] : '✓ OC revisada: todo coincide.' }}
                </p>
            @elseif ($sinOc)
                <p class="rounded-lg bg-slate-50 px-3 py-2 text-base text-slate-700">Sin OC del cliente: nota de venta</p>
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($clientesBusqueda->count() > 1)
                    <div class="sm:col-span-2">
                        <label class="etiqueta" for="p-cliente">El pedido es del cliente</label>
                        <select id="p-cliente" wire:model.live="clienteId" class="campo">
                            @foreach ($clientesBusqueda as $cb)
                                <option value="{{ $cb->id }}">{{ $cb->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label class="etiqueta" for="p-oc">Número de OC del cliente</label>
                    <input id="p-oc" type="text" wire:model.live.debounce.400ms="numeroOc" class="campo" maxlength="80" {{ $ocRuta ? 'required' : '' }} placeholder="{{ $ocRuta ? '' : 'Vacío = nota de venta' }}">
                    @error('numeroOc') <p class="error-campo">{{ $message }}</p> @enderror
                    @if ($duplicado)
                        <p class="error-campo">Ya existe el pedido de la OC {{ $duplicado->numero_oc_cliente }} de este cliente.
                            @unless ($duplicado->trashed())
                                <a href="{{ route('pedidos.ver', $duplicado) }}" class="font-semibold underline">Ver P-{{ $duplicado->folio }}</a>
                            @endunless
                        </p>
                    @endif
                </div>
                <div>
                    <label class="etiqueta" for="p-resp">Responsable</label>
                    <select id="p-resp" wire:model="responsableId" class="campo">
                        @foreach ($usuarios as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                    @error('responsableId') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="p-fecha">Fecha de la OC</label>
                    <input id="p-fecha" type="date" wire:model="fechaOc" class="campo">
                    @error('fechaOc') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="p-comp">Fecha compromiso (opcional)</label>
                    <input id="p-comp" type="date" wire:model="fechaCompromiso" class="campo">
                    @error('fechaCompromiso') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="p-columna">Lista del tablero</label>
                    <select id="p-columna" wire:model="columna" class="campo">
                        @foreach (\App\Enums\ColumnaPedido::cases() as $c)
                            <option value="{{ $c->value }}">{{ $c->etiqueta() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="etiqueta" for="p-notas">Notas</label>
                    <textarea id="p-notas" rows="3" wire:model="notas" class="campo"></textarea>
                </div>
            </div>

            {{-- Fecha estimada de entrega por partida (como en Trello); quien recoge y entrega la ve en la tarjeta. --}}
            @if ($marcadas->isNotEmpty() || $extras)
                <section class="space-y-3 rounded-lg border border-slate-200 p-4" data-fechas-entrega>
                    <div>
                        <h3 class="text-base font-semibold text-marino">Fecha estimada de entrega</h3>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                        <div>
                            <label class="etiqueta" for="p-fecha-todas">La misma fecha para todas</label>
                            <input id="p-fecha-todas" type="date" wire:model="fechaTodas" class="campo">
                            @error('fechaTodas') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                        <button type="button" wire:click="aplicarFechaTodas" class="btn-secundario">Poner a todas</button>
                    </div>
                    <ul class="divide-y divide-slate-200">
                        @foreach ($marcadas as $m)
                            <li wire:key="fe-c{{ $m->id }}" class="flex flex-col gap-2 py-2 sm:flex-row sm:items-center sm:justify-between">
                                <label for="fe-c{{ $m->id }}" class="min-w-0 text-sm">{{ \Illuminate\Support\Str::limit($m->descripcion, 90) }}</label>
                                <input id="fe-c{{ $m->id }}" type="date" wire:model="fechasEntrega.c{{ $m->id }}" class="campo sm:max-w-48">
                            </li>
                        @endforeach
                        @foreach ($extras as $i => $e)
                            <li wire:key="fe-e{{ $i }}" class="flex flex-col gap-2 py-2 sm:flex-row sm:items-center sm:justify-between">
                                <label for="fe-e{{ $i }}" class="min-w-0 text-sm">{{ \Illuminate\Support\Str::limit($e['descripcion'], 90) }} <span class="text-xs text-slate-500">(de la OC)</span></label>
                                <input id="fe-e{{ $i }}" type="date" wire:model="fechasEntrega.e{{ $i }}" class="campo sm:max-w-48">
                            </li>
                        @endforeach
                    </ul>
                    @if ($errors->has('fechasEntrega.*')) <p class="error-campo">{{ $errors->first('fechasEntrega.*') }}</p> @endif
                </section>
            @endif
            @if ($resultadoOc && $resultadoOc['hay_diferencias'])
                <label class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-base">
                    <input type="checkbox" wire:model="aceptoDiferencias" class="mt-1 h-5 w-5 rounded border-slate-300">
                    <span>Revisé las diferencias con la OC y quiero crear el pedido así.</span>
                </label>
                @error('aceptoDiferencias') <p class="error-campo">{{ $message }}</p> @enderror
            @endif
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <button type="button" wire:click="irAPaso(3)" class="btn-secundario">← Regresar a la OC</button>
                <button type="submit" class="btn-primario" wire:loading.attr="disabled" wire:target="crear">Crear pedido ({{ count($seleccion) + count($extras) }} partidas)</button>
                <a href="{{ url('/pedidos') }}" class="btn-secundario">Cancelar</a>
            </div>
        </form>
    @endif
</div>

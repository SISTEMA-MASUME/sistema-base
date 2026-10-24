{{-- Si al guardar falta algo, la pantalla sube sola al primer aviso (en pantallas chicas quedaba fuera de vista y parecía trabado). --}}
<div x-data x-on:mostrar-error.window="$nextTick(() => document.querySelector('[data-error-solicitud], .error-campo')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))">
    @if ($mensaje)
        <div role="status" class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif
    @if ($error)
        <div role="alert" data-error-solicitud class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
    @endif

    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
        <a href="{{ $solicitudId ? route('solicitudes.ver', $solicitudId) : route('solicitudes.index') }}" class="btn-secundario">← {{ $solicitudId ? 'Volver a la solicitud' : 'Por cotizar' }}</a>
        @if ($folio)<span class="text-lg font-semibold text-marino">{{ $folio }}</span>@endif
    </div>

    <form wire:submit="guardar" class="space-y-5">
        {{-- Cliente y datos de llegada --}}
        <div class="space-y-4 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">¿Quién lo pidió y cuándo llegó?</h2>

            <div>
                <label class="etiqueta" for="s-cliente">Cliente</label>
                @if ($cliente)
                    <div class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                        <span class="text-base font-semibold">{{ $cliente->nombre }}</span>
                        <button type="button" wire:click="quitarCliente" class="text-sm text-marino underline">Cambiar</button>
                    </div>
                @else
                    <input id="s-cliente" type="search" wire:model.live.debounce.250ms="buscarCliente" class="campo" placeholder="Buscar cliente dado de alta" autocomplete="off">
                    @if ($resultados->isNotEmpty())
                        <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200">
                            @foreach ($resultados as $r)
                                <li wire:key="cli-{{ $r->id }}"><button type="button" wire:click="seleccionarCliente({{ $r->id }})" class="block min-h-12 w-full px-4 py-3 text-left text-base hover:bg-slate-50">{{ $r->nombre }}</button></li>
                            @endforeach
                        </ul>
                    @endif
                    <label class="etiqueta mt-3" for="s-cliente-texto">…o escribe el nombre (cliente nuevo)</label>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <input id="s-cliente-texto" type="text" wire:model="clienteTexto" class="campo" maxlength="250" placeholder="Nombre tal como lo dieron">
                        <button type="button" wire:click="darDeAltaCliente" class="btn-secundario shrink-0">Dar de alta como cliente</button>
                    </div>
                    @error('clienteTexto') <p class="error-campo">{{ $message }}</p> @enderror
                @endif
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="etiqueta" for="s-contacto">Contacto</label>
                    <input id="s-contacto" type="text" wire:model="contactoTexto" class="campo" maxlength="250" placeholder="Quién lo pidió">
                </div>
                <div>
                    <label class="etiqueta" for="s-canal">Por dónde llegó</label>
                    <select id="s-canal" wire:model="canal" class="campo">
                        @foreach (\App\Models\SolicitudCotizacion::CANALES as $k => $t)
                            <option value="{{ $k }}">{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="etiqueta" for="s-recibida">Fecha y hora en que llegó</label>
                    <input id="s-recibida" type="datetime-local" wire:model="recibida" class="campo">
                    @error('recibida') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Detalle --}}
        <div class="rounded-xl bg-white p-5 shadow-sm">
            <label class="etiqueta" for="s-detalle">Qué pidió (detalle)</label>
            <textarea id="s-detalle" rows="4" wire:model="detalle" class="campo" maxlength="5000" placeholder="Pega aquí el mensaje del cliente o anota lo que pidió"></textarea>
            @error('detalle') <p class="error-campo">{{ $message }}</p> @enderror
            @if ($iaEstado !== 'leyendo')
                <button type="button" wire:click="acomodarTexto" wire:loading.attr="disabled" class="btn-secundario mt-3 w-full sm:w-auto">✨ Acomodar este texto en partidas con IA</button>
            @endif
        </div>

        {{-- Archivos + IA --}}
        <div class="space-y-3 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">Archivos (PDF, foto o Excel)</h2>
            <div class="space-y-2"
                 x-data="{ aviso: '', encima: false,
                    soltar(lista) {
                        const fs = [...(lista || [])];
                        if (!fs.length) { return; }
                        this.aviso = '';
                        $wire.uploadMultiple('archivos', fs);
                    },
                    async pegar() {
                        // En celular no hay Ctrl+V: se lee el portapapeles con permiso del navegador.
                        try {
                            if (!navigator.clipboard?.read) throw new Error();
                            for (const item of await navigator.clipboard.read()) {
                                const tipo = item.types.find(t => t === 'application/pdf' || t.startsWith('image/'));
                                if (tipo) {
                                    const blob = await item.getType(tipo);
                                    const ext = tipo === 'application/pdf' ? 'pdf' : (tipo.split('/')[1] || 'png');
                                    this.aviso = '';
                                    return $wire.uploadMultiple('archivos', [new File([blob], 'pegado.' + ext, { type: tipo })]);
                                }
                            }
                            this.aviso = 'No hay una imagen o PDF copiado.';
                        } catch (e) {
                            this.aviso = 'Tu celular no deja pegar este archivo. Usa «Elegir PDF, foto o Excel» (en WhatsApp: abre el archivo → Compartir/Guardar, y luego elígelo aquí).';
                        }
                    } }"
                 x-on:paste="const fs = [...($event.clipboardData?.files || [])]; if (fs.length) { $event.preventDefault(); $wire.uploadMultiple('archivos', fs); }">
                <div class="grid gap-2 sm:grid-cols-2">
                    <button type="button" x-on:click="$refs.elegir.click()" class="btn-primario py-3 text-base">📎 Cargar archivo (PDF, imagen o Excel)</button>
                    <button type="button" x-on:click="$refs.camara.click()" class="btn-secundario py-3 text-base">📷 Tomar foto</button>
                </div>
                {{-- Recuadro: tocarlo abre los archivos; también se puede arrastrar ahí o pegar con Ctrl+V. --}}
                <div role="button" tabindex="0" aria-label="Recuadro para subir archivos: toca para elegir, o arrastra aquí el PDF, imagen o Excel"
                     x-on:click="$refs.elegir.click()" x-on:keydown.enter.prevent="$refs.elegir.click()" x-on:keydown.space.prevent="$refs.elegir.click()"
                     x-on:dragover.prevent="encima = true" x-on:dragenter.prevent="encima = true" x-on:dragleave.prevent="encima = false"
                     x-on:drop.prevent="encima = false; soltar($event.dataTransfer?.files)"
                     x-bind:class="encima ? 'border-naranja bg-orange-50 ring-2 ring-orange-300' : 'border-slate-300 bg-slate-50 hover:bg-slate-100'"
                     class="flex min-h-28 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed p-4 text-center outline-none focus:ring-2 focus:ring-orange-300">
                    <span class="text-3xl" aria-hidden="true">⬆️</span>
                    <span class="text-base font-medium text-slate-700">Toca aquí o arrastra el archivo</span>
                    <span class="text-sm text-slate-500">o pega con Ctrl+V</span>
                </div>
                <button type="button" x-on:click="pegar()" class="text-sm text-marino underline">📋 Pegar una imagen copiada (celular)</button>
                <input x-ref="elegir" type="file" wire:model="archivos" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.csv,application/pdf,image/jpeg,image/png,image/webp,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" class="hidden">
                <input x-ref="camara" type="file" wire:model="archivos" accept="image/*" capture="environment" class="hidden">
                <p x-show="aviso" x-text="aviso" class="text-sm text-red-600" role="alert"></p>
            </div>
            <div wire:loading wire:target="archivos" class="text-sm text-slate-600">Subiendo…</div>
            @error('archivos.*') <p class="error-campo">{{ $message }}</p> @enderror

            @if ($guardados->isNotEmpty() || $pendientes)
                <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200">
                    @foreach ($guardados as $a)
                        <li wire:key="ar-{{ $a->id }}" class="flex items-center justify-between gap-3 px-3 py-2">
                            <a href="{{ route('solicitudes.archivo', $a) }}" target="_blank" class="min-w-0 break-all text-base text-marino underline">{{ $a->nombre }}</a>
                            <span class="flex shrink-0 items-center gap-3 text-sm">
                                @if ($a->leido_at)<span class="text-green-700">leído</span>@endif
                                <button type="button" wire:click="quitarArchivo({{ $a->id }})" wire:confirm="¿Quitar este archivo?" class="text-red-600 underline">Quitar</button>
                            </span>
                        </li>
                    @endforeach
                    @foreach ($pendientes as $i => $a)
                        <li wire:key="pe-{{ $i }}" class="flex items-center justify-between gap-3 px-3 py-2">
                            <span class="min-w-0 break-all text-base">{{ $a['nombre'] }}</span>
                            <button type="button" wire:click="quitarPendiente({{ $i }})" class="shrink-0 text-sm text-red-600 underline">Quitar</button>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($iaEstado === 'leyendo')
                <div wire:poll.3s="consultarIa" class="flex items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 text-base text-blue-900">
                    <span class="inline-block h-5 w-5 animate-spin rounded-full border-2 border-blue-300 border-t-blue-700" aria-hidden="true"></span>
                    Leyendo el archivo…
                </div>
            @elseif ($iaEstado === 'error')
                <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-base text-red-800">
                    {{ $iaMensaje }}
                </div>
            @elseif ($iaEstado === 'lista')
                <div class="rounded-lg border border-green-300 bg-green-50 p-4 text-base text-green-900">✓ Partidas agregadas abajo</div>
            @endif

            @if ($tieneArchivos && $iaEstado !== 'leyendo')
                <button type="button" wire:click="leerConIa" wire:loading.attr="disabled" class="btn-secundario">✨ Leer con IA</button>
            @endif
        </div>

        {{-- Partidas --}}
        <div class="space-y-3 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">Partidas</h2>
            <div class="space-y-3">
                @foreach ($partidas as $i => $p)
                    <div wire:key="par-{{ $i }}" class="rounded-lg border border-slate-200 p-3">
                    <div class="grid grid-cols-[5rem_1fr] gap-2 sm:grid-cols-[6rem_6rem_1fr_12rem_auto]">
                        <div>
                            <label class="etiqueta sm:sr-only">Cant.</label>
                            <input type="text" inputmode="decimal" wire:model="partidas.{{ $i }}.cantidad" class="campo" aria-label="Cantidad">
                        </div>
                        <div>
                            <label class="etiqueta sm:sr-only">Unidad</label>
                            <input type="text" wire:model="partidas.{{ $i }}.unidad" class="campo" maxlength="10" placeholder="PZA" aria-label="Unidad">
                        </div>
                        <div class="col-span-2 min-w-0 sm:col-span-1">
                            <label class="etiqueta sm:sr-only">Descripción</label>
                            <input type="text" wire:model="partidas.{{ $i }}.descripcion" class="campo" maxlength="500" placeholder="Descripción" aria-label="Descripción">
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <input type="text" wire:model="partidas.{{ $i }}.modelo" class="campo" maxlength="250" placeholder="Modelo (opcional)" aria-label="Modelo">
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <button type="button" wire:click="quitarRenglon({{ $i }})" class="btn-secundario w-full text-red-700 sm:w-auto" aria-label="Quitar renglón">Quitar</button>
                        </div>
                    </div>

                    {{-- Producto ya cotizado --}}
                    @if (! empty($p['ligada']))
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-900">
                            <span class="min-w-0 break-words">✓ Ya cotizado: folio {{ $p['ligada']['folio'] ?? '' }}{{ ! empty($p['ligada']['cliente']) ? ' · '.$p['ligada']['cliente'] : '' }}</span>
                            <button type="button" wire:click="quitarLiga({{ $i }})" class="text-red-700 underline">Quitar liga</button>
                        </div>
                    @elseif (! empty($sugerencias[$i]))
                        <div class="mt-2 space-y-2 rounded-lg bg-blue-50 p-3" x-data="{ todas: false }">
                            @foreach ($sugerencias[$i] as $j => $sg)
                                <div wire:key="sg-{{ $i }}-{{ $j }}" x-show="todas || {{ $j }} === 0" class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0 text-sm text-blue-950">
                                        <p class="font-semibold">¿Es este? · {{ $sg['descripcion'] }}@if ($sg['modelo']) · modelo {{ $sg['modelo'] }}@endif</p>
                                        <p class="text-blue-900/80">
                                            Cotizado a {{ $sg['cliente'] ?: 'otro cliente' }} el {{ $sg['fecha'] }} (folio {{ $sg['folio'] }})
                                            @if ($sg['costo'] !== null) · costo ${{ number_format($sg['costo'], 2) }}@endif
                                            @if ($sg['proveedor']) · {{ $sg['proveedor'] }}@endif
                                            · <span class="italic">{{ $sg['motivo'] }}</span>
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        @if ($sg['imagen'])<img src="{{ $sg['imagen'] }}" alt="" class="h-10 w-10 rounded object-cover">@endif
                                        <button type="button" wire:click="elegirSugerencia({{ $i }}, {{ $j }})" class="btn-primario">Sí, es este</button>
                                    </div>
                                </div>
                            @endforeach
                            <div class="flex flex-wrap gap-4 text-sm">
                                @if (count($sugerencias[$i]) > 1)
                                    <button type="button" x-on:click="todas = !todas" class="text-marino underline" x-text="todas ? 'Ver menos' : 'Ver más ({{ count($sugerencias[$i]) - 1 }})'"></button>
                                @endif
                                <button type="button" wire:click="quitarSugerencias({{ $i }})" class="text-slate-600 underline">Es producto nuevo</button>
                            </div>
                        </div>
                    @endif
                    @if (empty($p['ligada']))
                        <div class="mt-2 flex items-center gap-2" x-data="{ abierto: false }">
                            <button type="button" x-show="!abierto" x-on:click="abierto = true" class="text-sm text-marino underline">Buscar otro producto ya cotizado</button>
                            <div x-show="abierto" x-cloak class="flex w-full flex-col gap-2 sm:flex-row">
                                <input type="text" wire:model="consultas.{{ $i }}" wire:keydown.enter.prevent="buscarOtro({{ $i }})" class="campo" placeholder="Escribe qué buscar (modelo o descripción)" aria-label="Buscar otro">
                                <button type="button" wire:click="buscarOtro({{ $i }})" class="btn-secundario shrink-0">Buscar</button>
                            </div>
                        </div>
                    @endif
                    </div>
                @endforeach
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="button" wire:click="agregarRenglon" class="btn-secundario">+ Agregar renglón</button>
                <button type="button" wire:click="sugerir" class="btn-secundario" wire:loading.attr="disabled">Buscar productos ya cotizados</button>
            </div>
        </div>

        @if ($error || $errors->any())
            <p class="rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-base font-semibold text-red-800" data-falta-algo>No se guardó: {{ $error ?: $errors->first() }}</p>
        @endif
        <div class="flex flex-col gap-2 sm:flex-row">
            <button type="submit" class="btn-primario" wire:loading.attr="disabled">Guardar solicitud</button>
            <a href="{{ $solicitudId ? route('solicitudes.ver', $solicitudId) : route('solicitudes.index') }}" class="btn-secundario">Cancelar</a>
        </div>
    </form>
</div>

@php
    $dinero = fn ($n) => $n === null ? '—' : '$'.number_format((float) $n, 2);
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('articulos.index') }}" class="inline-block text-base font-semibold text-marino underline">← Artículos</a>
        @if ($esAdmin)
            <a href="{{ route('programas') }}" class="btn-secundario">Metas con proveedores (programa Urrea)</a>
        @endif
    </div>


    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    {{-- ===== Las listas que hay ===== --}}
    <section class="rounded-xl bg-white p-4 shadow-sm">
        <h2 class="mb-2 text-lg font-semibold text-marino">Listas cargadas</h2>
        @forelse ($listas as $l)
            <div wire:key="lista-{{ $l->id }}" class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-t border-slate-100 py-2 first:border-0">
                <p><strong>{{ $l->nombre }}</strong> <span class="text-sm text-slate-600">· {{ $l->proveedor?->nombre }}</span></p>
                <p class="text-sm text-slate-600">
                    {{ number_format($l->renglones) }} productos · {{ number_format($l->ligados) }} en tu catálogo ·
                    costo = {{ $l->columna_precio }}{{ $l->precio_con_iva ? ' sin IVA' : '' }}{{ (float) $l->descuento_pct > 0 ? ' − '.$l->descuentos.'%' : '' }}
                    · {{ $l->importada_at?->timezone('America/Mexico_City')->format('d/m/Y') }}
                    @if ($esAdmin && $editandoLista !== $l->id)
                        · <button type="button" wire:click="editarLista({{ $l->id }})" class="font-semibold text-marino underline">Cambiar descuentos</button>
                    @endif
                </p>
                @if ($editandoLista === $l->id)
                    <form wire:submit="guardarDescuentos" class="mt-2 w-full space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <label class="etiqueta" for="e-desc-{{ $l->id }}">Descuentos (%)</label>
                        <input id="e-desc-{{ $l->id }}" type="text" wire:model="editDescuentos" class="campo" placeholder="20 + 5">
                        @error('editDescuentos') <p class="error-campo">{{ $message }}</p> @enderror
                        <label class="flex items-center gap-2 text-base"><input type="checkbox" wire:model="editConIva" class="h-5 w-5 rounded border-slate-300"> El precio de la lista ya incluye IVA</label>
                        <div class="flex gap-2">
                            <button type="submit" class="btn-primario">Recalcular costos</button>
                            <button type="button" wire:click="cerrarLista" class="btn-secundario">Cancelar</button>
                        </div>
                    </form>
                @endif
            </div>
        @empty
            <p class="text-base text-slate-600">Sin listas</p>
        @endforelse
    </section>

    {{-- ===== Subir una lista (solo Daniel) ===== --}}
    @if ($esAdmin)
        <section class="space-y-4 rounded-xl bg-white p-4 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">Subir lista del mes</h2>
            @unless ($analisis)
                <label class="flex min-h-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-4 text-center hover:bg-slate-100">
                    <span class="text-base font-semibold text-marino">Elegir el Excel de la lista (.xlsx)</span>
                    <input type="file" wire:model="archivo" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="sr-only">
                </label>
                <p wire:loading wire:target="archivo" class="text-base text-slate-600">Leyendo el Excel…</p>
            @endunless
            @error('archivo') <p class="error-campo">{{ $message }}</p> @enderror

            @if ($analisis)
                <p class="text-base">Encontré <strong>{{ number_format($analisis['total']) }} productos</strong> en la hoja "{{ $analisis['hoja'] }}".</p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="relative">
                        <label class="etiqueta" for="l-prov">¿De qué proveedor es la lista?</label>
                        <input id="l-prov" type="search" wire:model.live.debounce.300ms="proveedorTexto" class="campo" autocomplete="off">
                        @if ($proveedoresSugeridos->isNotEmpty())
                            <ul class="absolute inset-x-0 top-full z-20 mt-1 max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                                @foreach ($proveedoresSugeridos as $s)
                                    <li wire:key="lp-{{ $s->id }}"><button type="button" wire:click="elegirProveedor({{ $s->id }})" class="block min-h-11 w-full px-3 py-2 text-left text-sm hover:bg-slate-50">{{ $s->nombre }}</button></li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($proveedorId)
                            <p class="mt-1 text-sm font-semibold text-green-800">✓ Elegido</p>
                        @elseif (trim($proveedorTexto) !== '')
                            <button type="button" wire:click="crearProveedor" class="mt-1 text-sm font-semibold text-marino underline">Dar de alta "{{ \App\Support\Nombres::limpiar($proveedorTexto) }}" como proveedor</button>
                        @endif
                        @error('proveedorId') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="etiqueta" for="l-nombre">Nombre de la lista</label>
                        <input id="l-nombre" type="text" wire:model="nombre" class="campo" maxlength="120">
                        @error('nombre') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="etiqueta" for="l-precio">¿Qué precio es tu costo?</label>
                        <select id="l-precio" wire:model.live="precio" class="campo">
                            @foreach ($analisis['precios'] as $i => $encabezado)
                                <option value="{{ $i }}">{{ $encabezado }}</option>
                            @endforeach
                        </select>
                        @error('precio') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="etiqueta" for="l-desc">Descuentos (%)</label>
                        <input id="l-desc" type="text" wire:model.live.debounce.400ms="descuento" class="campo" placeholder="20 + 5">
                        @error('descuento') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                </div>
                <label class="flex items-center gap-2 text-base"><input type="checkbox" wire:model.live="conIva" class="h-5 w-5 rounded border-slate-300"> El precio ya incluye IVA</label>

                {{-- Celular: tarjetas (la tabla no cabe) --}}
                <ul class="space-y-2 md:hidden">
                    @foreach ($muestra as $m)
                        <li class="rounded-lg border border-slate-200 p-3">
                            <p class="text-sm font-semibold text-marino">{{ $m['descripcion'] }}</p>
                            <p class="text-xs text-slate-600">Clave <span class="font-mono">{{ $m['clave'] }}</span> · {{ $m['unidad'] ?? '—' }} · SAT {{ $m['clave_sat'] ?? 'sin clave' }}</p>
                            <p class="mt-1 text-sm">Precio {{ $dinero($m['precio']) }} → <strong>tu costo {{ $dinero($m['costo']) }}</strong> <span class="text-xs text-slate-600">sin IVA</span></p>
                        </li>
                    @endforeach
                </ul>
                <div class="hidden overflow-x-auto rounded-lg border border-slate-200 md:block">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr><th class="px-3 py-2">Clave</th><th class="px-3 py-2">Descripción</th><th class="px-3 py-2">Unidad · SAT</th><th class="px-3 py-2">Código de barras</th><th class="px-3 py-2 text-right">Precio</th><th class="px-3 py-2 text-right">Tu costo sin IVA</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($muestra as $m)
                                <tr>
                                    <td class="px-3 py-2 font-mono">{{ $m['clave'] }}</td>
                                    <td class="px-3 py-2">{{ $m['descripcion'] }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $m['unidad'] ?? '—' }} · {{ $m['unidad_sat'] ?? '—' }} · {{ $m['clave_sat'] ?? 'sin clave' }}</td>
                                    <td class="px-3 py-2 font-mono">{{ $m['codigo_barras'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right">{{ $dinero($m['precio']) }}</td>
                                    <td class="px-3 py-2 text-right font-bold">{{ $dinero($m['costo']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="importar" wire:loading.attr="disabled" wire:target="importar" class="btn-primario">
                        <span wire:loading.remove wire:target="importar">Importar {{ number_format($analisis['total']) }} productos</span>
                        <span wire:loading wire:target="importar">Importando… no cierres la página</span>
                    </button>
                    <button type="button" wire:click="cancelar" class="btn-secundario">Cancelar</button>
                </div>
            @endif
        </section>
    @endif

    {{-- ===== Buscar en las listas ===== --}}
    <section class="space-y-3 rounded-xl bg-white p-4 shadow-sm">
        <h2 class="text-lg font-semibold text-marino">Buscar en las listas</h2>
        <input type="search" wire:model.live.debounce.300ms="buscar" class="campo" placeholder="Descripción, clave o código de barras" aria-label="Buscar en las listas">
        @if ($resultados->isNotEmpty())
            <ul class="divide-y divide-slate-200">
                @foreach ($resultados as $r)
                    <li wire:key="rl-{{ $r->id }}" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3">
                        <div class="min-w-[220px] flex-1">
                            <p class="font-semibold text-marino">{{ $r->descripcion }}</p>
                            <p class="text-sm text-slate-600">{{ $r->lista?->proveedor?->nombre }} · clave <span class="font-mono">{{ $r->clave }}</span>{{ $r->codigo_barras ? ' · '.$r->codigo_barras : '' }}{{ $r->clave_sat ? ' · SAT '.$r->clave_sat : '' }}</p>
                        </div>
                        <p class="whitespace-nowrap text-base"><strong>{{ $dinero($r->costo) }}</strong> <span class="text-xs text-slate-600">tu costo sin IVA</span></p>
                        @if ($r->articulo)
                            <a href="{{ route('articulos.ver', $r->articulo_id) }}" class="btn-secundario !min-h-10 text-sm">✓ En tu catálogo</a>
                        @else
                            <button type="button" wire:click="agregarAlCatalogo({{ $r->id }})" class="btn-secundario !min-h-10 text-sm">Agregar al catálogo</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @elseif (mb_strlen(trim($buscar)) >= 3)
            <p class="text-base text-slate-600">No está en ninguna lista.</p>
        @endif
    </section>
</div>

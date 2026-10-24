@php
    $dinero = fn ($n) => '$'.number_format((float) $n, 2);
    $cant = fn ($c) => rtrim(rtrim(number_format((float) $c, 3, '.', ','), '0'), '.');
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
    $fecha = fn ($f) => $f ? $f->day.'-'.$meses[$f->month - 1].($f->year !== now()->year ? '-'.$f->year : '') : '';
    $mejorId = $a->proveedores->first(fn ($p) => $p->ultimo_costo !== null)?->id;
@endphp
<div class="space-y-4">
    <a href="{{ route('articulos.index') }}" class="inline-block text-base font-semibold text-marino underline">← Artículos</a>

    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    <div class="flex flex-wrap items-start gap-4">
        {{-- ===== Izquierda: el artículo y sus datos para facturar ===== --}}
        <div class="min-w-0 flex-[1_1_340px] space-y-4">
            <section class="space-y-3 rounded-xl bg-white p-5 shadow-sm">
                @if ($foto)
                    <div class="flex h-52 items-center justify-center overflow-hidden rounded-lg bg-slate-50 p-2">
                        <img src="{{ Storage::disk('public')->url($foto) }}" alt="" class="max-h-full max-w-full object-contain">
                    </div>
                @endif
                <h2 class="text-2xl font-bold leading-tight text-marino">{{ $a->descripcion }}</h2>
                <div class="flex flex-wrap gap-2 text-sm">
                    @if ($a->marca)<span class="rounded-full bg-indigo-100 px-3 py-0.5 font-semibold text-marino">{{ $a->marca }}</span>@endif
                    <span class="rounded-full bg-slate-100 px-3 py-0.5 text-slate-700">{{ \App\Models\Articulo::ORIGENES[$a->origen] ?? $a->origen }}</span>
                    @if ($a->sicar_art_id)<span class="rounded-full bg-slate-100 px-3 py-0.5 text-slate-700">En SICAR{{ $a->sicar_clave ? ': '.$a->sicar_clave : '' }}</span>@endif
                </div>

                <div class="space-y-2 border-t border-slate-100 pt-3">
                    <p class="text-sm font-semibold text-slate-700">También se conoce como</p>
                    @forelse ($a->nombres as $n)
                        <div wire:key="nom-{{ $n->id }}" class="flex items-start justify-between gap-2 text-sm">
                            <span>{{ $n->nombre }}</span>
                            <button type="button" wire:click="quitarNombre({{ $n->id }})" class="shrink-0 text-red-700 underline">Quitar</button>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Ningún otro nombre.</p>
                    @endforelse
                    <form wire:submit="agregarNombre" class="flex gap-2">
                        <label class="sr-only" for="otro-nombre">Otro nombre</label>
                        <input id="otro-nombre" type="text" wire:model="nombreNuevo" class="campo" placeholder="Ej.: STILLSON 14 PULG">
                        <button type="submit" class="btn-secundario shrink-0">Agregar</button>
                    </form>
                    @error('nombreNuevo') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
            </section>

            <form wire:submit="guardar" class="space-y-3 rounded-xl bg-white p-5 shadow-sm">
                <h3 class="text-lg font-semibold text-marino">Datos del artículo y para facturar</h3>
                <div>
                    <label class="etiqueta" for="a-desc">Descripción</label>
                    <textarea id="a-desc" wire:model="descripcion" rows="2" class="campo"></textarea>
                    @error('descripcion') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="etiqueta" for="a-marca">Marca</label>
                        <input id="a-marca" type="text" wire:model="marca" class="campo">
                    </div>
                    <div>
                        <label class="etiqueta" for="a-unidad">Unidad</label>
                        <input id="a-unidad" type="text" wire:model.blur="unidad" class="campo" maxlength="20">
                        @error('unidad') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="etiqueta" for="a-usat">Unidad del SAT</label>
                        <select id="a-usat" wire:model="unidadSat" class="campo">
                            <option value="">Falta elegir</option>
                            @foreach (\App\Support\UnidadesSat::LISTA as $clave => $nombre)
                                <option value="{{ $clave }}">{{ $nombre }} ({{ $clave }})</option>
                            @endforeach
                        </select>
                        @error('unidadSat') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="etiqueta" for="a-csat">Clave SAT del producto</label>
                        <input id="a-csat" type="text" wire:model="claveSat" inputmode="numeric" maxlength="10" class="campo font-mono" placeholder="8 números">
                        @error('claveSat') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                </div>
                @if ($satDescripcion)
                    <p class="text-sm text-slate-600">SAT: {{ $satDescripcion }}</p>
                @endif
                <div>
                    <label class="etiqueta" for="a-cb">Código de barras</label>
                    <input id="a-cb" type="text" wire:model="codigoBarras" inputmode="numeric" class="campo font-mono">
                    @error('codigoBarras') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn-primario w-full sm:w-auto">Guardar</button>
            </form>
        </div>

        {{-- ===== Derecha: quién lo vende y ventas ===== --}}
        <div class="min-w-0 flex-[999_1_560px] space-y-4">
            <section class="space-y-3 rounded-xl bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-xl font-semibold text-marino">Quién te lo vende</h3>
                    @unless ($editandoProveedor)
                        <button type="button" wire:click="nuevoProveedor" class="btn-secundario">+ Agregar proveedor</button>
                    @endunless
                </div>

                @if ($a->proveedores->isEmpty() && ! $editandoProveedor)
                    <p class="text-base text-slate-600">Sin proveedores</p>
                @endif

                <ul class="divide-y divide-slate-200">
                    @foreach ($a->proveedores as $p)
                        @php $wa = \App\Support\Telefono::whatsapp($p->proveedor?->datosContacto()['telefono'] ?? null); @endphp
                        <li wire:key="prov-{{ $p->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3 {{ $p->id === $mejorId && $a->proveedores->count() > 1 ? 'rounded-lg bg-green-50 px-2' : '' }}">
                            <div class="min-w-[200px] flex-1">
                                <p class="font-semibold">{{ $p->proveedor?->nombre }}
                                    @if ($p->id === $mejorId && $a->proveedores->count() > 1)<span class="ml-1 whitespace-nowrap rounded-full bg-green-100 px-2 py-0.5 text-xs font-bold text-green-800">Más barato</span>@endif
                                </p>
                                <p class="text-sm text-slate-600">
                                    {{ $p->clave ? 'Su clave: '.$p->clave : 'Sin clave' }}@if ($p->costo_fecha) · {{ $fecha($p->costo_fecha) }}@endif
                                    @if ($p->pedido) · <span class="whitespace-nowrap">pedido <a href="{{ route('pedidos.index', ['pedido' => $p->pedido_id]) }}" class="underline">P-{{ $p->pedido->folio }}</a></span>@endif
                                </p>
                            </div>
                            <p class="whitespace-nowrap text-lg font-bold">{{ $p->ultimo_costo !== null ? $dinero($p->ultimo_costo) : '—' }} <span class="text-xs font-normal text-slate-600">sin IVA</span></p>
                            <div class="flex flex-wrap gap-2">
                                @if ($wa)<a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="btn-secundario !min-h-10 text-sm">WhatsApp</a>@endif
                                @if ($p->link_compra)<a href="{{ $p->link_compra }}" target="_blank" rel="noopener" class="btn-secundario !min-h-10 text-sm">Link de compra</a>@endif
                                <button type="button" wire:click="editarProveedor({{ $p->id }})" class="btn-secundario !min-h-10 text-sm">Cambiar</button>
                                <button type="button" wire:click="quitarProveedor({{ $p->id }})" wire:confirm="¿Quitar a {{ $p->proveedor?->nombre }} de este artículo?" class="btn-secundario !min-h-10 text-sm !text-red-700">Quitar</button>
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($editandoProveedor)
                    <form wire:submit="guardarProveedor" class="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <p class="font-semibold text-marino">{{ $filaProveedorId ? 'Cambiar proveedor' : 'Agregar proveedor' }}</p>
                        <div class="relative">
                            <label class="etiqueta" for="p-prov">Proveedor</label>
                            <input id="p-prov" type="search" wire:model.live.debounce.300ms="proveedorTexto" class="campo" autocomplete="off" placeholder="Escribe parte del nombre">
                            @if ($proveedoresSugeridos->isNotEmpty())
                                <ul class="absolute inset-x-0 top-full z-20 mt-1 max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                                    @foreach ($proveedoresSugeridos as $s)
                                        <li wire:key="sp-{{ $s->id }}"><button type="button" wire:click="elegirProveedor({{ $s->id }})" class="block min-h-11 w-full px-3 py-2 text-left text-sm hover:bg-slate-50">{{ $s->nombre }}</button></li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($proveedorId)<p class="mt-1 text-sm font-semibold text-green-800">✓ Elegido</p>@endif
                            @error('proveedorId') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="etiqueta" for="p-clave">Su clave (SKU)</label>
                                <input id="p-clave" type="text" wire:model="clave" class="campo font-mono">
                            </div>
                            <div>
                                <label class="etiqueta" for="p-costo">Costo sin IVA</label>
                                <input id="p-costo" type="text" inputmode="decimal" wire:model="costo" class="campo" placeholder="0.00">
                                @error('costo') <p class="error-campo">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div>
                            <label class="etiqueta" for="p-link">Link de compra (opcional)</label>
                            <input id="p-link" type="url" wire:model="link" class="campo" placeholder="https://…">
                            @error('link') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="btn-primario">Guardar</button>
                            <button type="button" wire:click="cerrarProveedor" class="btn-secundario">Cancelar</button>
                        </div>
                    </form>
                @endif
            </section>

            <section class="space-y-3 rounded-xl bg-white p-5 shadow-sm">
                <h3 class="text-xl font-semibold text-marino">Ventas</h3>
                <div class="grid grid-cols-2 gap-3 text-base">
                    <p><span class="block text-sm text-slate-600">Vendido en</span><strong>{{ $totalPedidos }} {{ $totalPedidos === 1 ? 'pedido' : 'pedidos' }}</strong></p>
                    <p><span class="block text-sm text-slate-600">Piezas vendidas</span><strong>{{ $cant($piezas) }} {{ $a->unidad }}</strong></p>
                </div>
                @if ($ventas->isNotEmpty())
                    <ul class="divide-y divide-slate-200 text-base">
                        @foreach ($ventas as $v)
                            <li wire:key="v-{{ $v->id }}" class="flex flex-wrap justify-between gap-x-3 py-2">
                                <span><a href="{{ route('pedidos.index', ['pedido' => $v->pedido_id]) }}" class="whitespace-nowrap font-semibold text-marino underline">P-{{ $v->pedido?->folio }}</a> · {{ $v->pedido?->cliente?->nombre }} · {{ $fecha($v->pedido?->created_at) }}</span>
                                <span>{{ $cant($v->cantidad) }} × <strong>{{ $dinero($v->precio) }}</strong></span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-base text-slate-600">Sin ventas</p>
                @endif
            </section>

            <section class="space-y-3 rounded-xl bg-white p-5 shadow-sm">
                <h3 class="text-xl font-semibold text-marino">Unir un artículo repetido</h3>
                <div class="relative">
                    <label class="sr-only" for="unir">Buscar el repetido</label>
                    <input id="unir" type="search" wire:model.live.debounce.300ms="unirTexto" class="campo" placeholder="Buscar el artículo repetido" autocomplete="off">
                </div>
                @if ($paraUnir->isNotEmpty())
                    <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200">
                        @foreach ($paraUnir as $o)
                            <li wire:key="unir-{{ $o->id }}" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                <span class="min-w-0 text-sm"><strong>{{ $o->descripcion }}</strong>{{ $o->marca ? ' · '.$o->marca : '' }} · {{ $o->unidad }}</span>
                                <button type="button" wire:click="unirCon({{ $o->id }})" wire:confirm="¿Unir &quot;{{ $o->descripcion }}&quot; dentro de este artículo? Ya no aparecerá por separado." class="btn-secundario !min-h-10 text-sm">Unir aquí</button>
                            </li>
                        @endforeach
                    </ul>
                @elseif (mb_strlen(trim($unirTexto)) >= 3)
                    <p class="text-sm text-slate-600">Sin resultados</p>
                @endif
            </section>
        </div>
    </div>
</div>

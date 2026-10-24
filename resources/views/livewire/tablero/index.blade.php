@php
    // Color de fondo de cada lista, como en el Trello "ORDENES DE COMPRA" (claro y oscuro según el tema del teléfono).
    $coloresColumna = [
        'oc_recibida' => 'bg-purple-200 dark:bg-purple-950',
        'en_tramite' => 'bg-blue-200 dark:bg-blue-950',
        'con_tiempo_entrega' => 'bg-yellow-200 dark:bg-yellow-900',
        'checar_tema' => 'bg-red-200 dark:bg-red-950',
        'por_facturar' => 'bg-teal-200 dark:bg-teal-950',
        'facturadas_pendientes_entregar' => 'bg-orange-200 dark:bg-orange-950',
        'envios_embarcados' => 'bg-sky-200 dark:bg-sky-950',
        'entregados_por_facturar' => 'bg-pink-200 dark:bg-pink-950',
        'entregadas_completas' => 'bg-green-200 dark:bg-green-950',
    ];
@endphp
<div data-tablero
     x-data="{
        filtros: false,
        etqAbiertas: (() => { try { return localStorage.getItem('tablero-etiquetas') === '1' } catch (e) { return false } })(),
        alternarEtiquetas() { this.etqAbiertas = !this.etqAbiertas; try { localStorage.setItem('tablero-etiquetas', this.etqAbiertas ? '1' : '0') } catch (e) {} },
        // Historial del navegador: abrir una tarjeta agrega UNA entrada (?pedido=ID); el botón atrás del celular o la ✕
        // la cierran y regresan al tablero sin dejar entradas de más (antes había que picar atrás 3-4 veces).
        conPedido(id) { const u = new URL(location.href); id ? u.searchParams.set('pedido', id) : u.searchParams.delete('pedido'); return u.toString() },
        abrirTarjeta(id) {
            if (Date.now() - (window.__finArrastre || 0) < 400) return;
            $wire.abrir(id);
            if (history.state && history.state.tarjeta) history.replaceState({ ...history.state, tarjeta: id }, '', this.conPedido(id));
            else history.pushState({ ...(history.state || {}), tarjeta: id }, '', this.conPedido(id));
        },
        cerrarTarjeta() {
            if (history.state && history.state.tarjeta) { history.back(); return; }   // el popstate la cierra
            $wire.cerrar();
            history.replaceState({ ...(history.state || {}), tarjeta: null }, '', this.conPedido(null));
        },
        alRegresar(e) {
            const id = e.state && e.state.tarjeta;
            if (id) { $wire.abrir(id) } else if ($wire.abierto) { $wire.cerrar() }
        },
     }"
     x-on:popstate.window="alRegresar($event)"
     x-on:cerrar-tarjeta-ui.window="cerrarTarjeta()">
    {{-- ===== Barra superior: buscar, filtro y nuevo pedido ===== --}}
    <div class="mb-3 flex flex-wrap items-center gap-2">
        <input type="search" wire:model.live.debounce.400ms="busqueda" placeholder="Buscar cliente, OC, folio P- o partidas" aria-label="Buscar pedido" class="campo min-w-0 flex-1 lg:max-w-sm">
        <button type="button" x-on:click="filtros = !filtros" x-bind:aria-expanded="filtros" class="btn-secundario relative shrink-0" aria-label="Filtro">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M7 12h10M10 18h4"/></svg>
            <span class="hidden sm:inline">Filtro</span>
            @if ($filtrosActivos)
                <span class="absolute -right-1 -top-1 rounded-full bg-naranja px-1.5 text-xs font-bold text-white">{{ $filtrosActivos }}</span>
            @endif
        </button>
        <a href="{{ url('/por-recoger') }}" class="btn shrink-0 border border-orange-300 bg-orange-50 text-orange-900 hover:bg-orange-100" data-boton-por-recoger>
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h13v10H3zM16 10h4l1 3v4h-5"/><circle cx="7" cy="18" r="1.6"/><circle cx="18" cy="18" r="1.6"/></svg>
            <span class="hidden sm:inline">Por recoger</span>@if ($parcialesRecoger) <span class="rounded-full bg-naranja px-1.5 text-xs font-bold text-white">{{ $parcialesRecoger }}</span>@endif
        </a>
        @if ($verVenta)
            <a href="{{ url('/pedidos/nuevo') }}" class="btn-primario shrink-0 lg:ml-auto" aria-label="Nuevo pedido">+<span class="hidden sm:inline"> Nuevo pedido</span><span class="sm:hidden"> Nuevo</span></a>
        @endif
    </div>

    <div x-show="filtros" x-cloak x-transition class="mb-3 grid gap-3 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label class="etiqueta" for="filtro-etiqueta">Etiqueta</label>
            <select id="filtro-etiqueta" wire:model.live="etiqueta" class="campo">
                <option value="">Todas las etiquetas</option>
                @foreach ($etiquetasDisponibles as $e)
                    <option value="{{ $e }}">{{ $e }}</option>
                @endforeach
                @if ($etiqueta !== '' && ! in_array($etiqueta, $etiquetasDisponibles, true))
                    <option value="{{ $etiqueta }}">{{ $etiqueta }}</option>
                @endif
            </select>
        </div>
        <div>
            <label class="etiqueta" for="filtro-responsable">Responsable</label>
            <select id="filtro-responsable" wire:model.live="responsableId" class="campo">
                <option value="">Todos los responsables</option>
                @foreach ($responsables as $r)
                    <option value="{{ $r->id }}">{{ $r->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1 text-sm text-slate-700 sm:col-span-2">
            <label class="flex min-h-10 items-center gap-2"><input type="checkbox" wire:model.live="soloMios" class="h-5 w-5 rounded"> Mis tarjetas</label>
            <label class="flex min-h-10 items-center gap-2"><input type="checkbox" wire:model.live="mostrarCancelados" class="h-5 w-5 rounded"> Mostrar cancelados</label>
            <label class="flex min-h-10 items-center gap-2"><input type="checkbox" wire:model.live="verArchivados" class="h-5 w-5 rounded"> Ver archivados</label>
            <label class="flex min-h-10 items-center gap-2"><input type="checkbox" wire:model.live="verTodasEntregadas" class="h-5 w-5 rounded"> Todas las entregadas</label>
        </div>
    </div>

    @if ($verArchivados)
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <h2 class="mb-3 text-lg font-semibold text-marino">Pedidos archivados ({{ $archivados->count() }})</h2>
            <ul class="divide-y divide-slate-200">
                @forelse ($archivados as $p)
                    <li wire:key="arch-{{ $p->id }}" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <a href="{{ url('/pedidos/'.$p->id) }}" class="min-w-0">
                            <span class="text-xs font-semibold text-slate-500">P-{{ $p->folio }} · Archivado {{ $p->archivado_at?->format('d/m/Y') }}</span>
                            <span class="block font-semibold text-marino">{{ $p->titulo() }}</span>
                        </a>
                        @if ($puedeArchivar)
                            <button type="button" wire:click="desarchivar({{ $p->id }})" class="btn-secundario">Desarchivar</button>
                        @endif
                    </li>
                @empty
                    <li class="p-6 text-center text-slate-600">No hay pedidos archivados.</li>
                @endforelse
            </ul>
        </div>
    @else
        {{-- ===== Columnas una junto a otra (celular: ~85% del ancho, con snap; arrastrar con pulsación larga) ===== --}}
        {{-- PC: el tablero ocupa el alto de la ventana para que la barra de abajo (mover a los lados) siempre se vea. --}}
        <div class="tablero-scroll -mx-4 flex items-start gap-3 overflow-x-auto px-4 pb-4 sm:-mx-6 sm:px-6 lg:mx-0 lg:h-[calc(100vh-12.5rem)] lg:px-0 dark:rounded-xl dark:bg-slate-900 dark:p-3" style="min-height: 60vh">
            @foreach ($columnas as $c)
                <section class="flex max-h-[calc(100vh-11rem)] w-[85vw] lg:max-h-full max-w-sm shrink-0 flex-col rounded-xl sm:w-72 {{ $coloresColumna[$c['valor']] ?? 'bg-slate-200' }}" wire:key="col-{{ $c['valor'] }}">
                    <header class="flex items-start gap-2 px-3 pb-1 pt-3" x-data="{ menu: false }">
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-bold uppercase leading-tight text-slate-900 dark:text-slate-100">{{ $c['titulo'] }}</h2>
                            <p class="text-xs text-slate-700 dark:text-slate-300">{{ $verVenta ? "" : "Costo " }}${{ number_format($c["total"], 2) }}</p>
                        </div>
                        @if ($puedeArchivar)
                            <a href="{{ route('sicar.alta', ['columna' => $c['valor']]) }}" class="mt-0.5 shrink-0 rounded bg-white/70 px-2 py-0.5 text-[11px] font-bold text-marino hover:bg-white dark:bg-slate-800 dark:text-slate-100" title="Dar de alta en SICAR los pedidos de esta lista">Alta en SICAR</a>
                        @endif
                        <span class="pt-0.5 text-sm font-semibold text-slate-700 dark:text-slate-300">{{ $c['pedidos']->count() }}</span>
                        <div class="relative">
                            <button type="button" x-on:click="menu = !menu" class="-mr-1 flex h-8 w-8 items-center justify-center rounded text-lg font-bold text-slate-700 hover:bg-black/10 dark:text-slate-200" aria-label="Menú de la lista {{ $c['titulo'] }}">⋮</button>
                            <div x-show="menu" x-cloak x-on:click.outside="menu = false" class="absolute right-0 z-20 mt-1 w-56 rounded-lg bg-white p-1 text-sm shadow-lg dark:bg-slate-800 dark:text-slate-100">
                                @if ($verVenta)
                                    <a href="{{ url('/pedidos/nuevo?columna='.$c['valor']) }}" class="block rounded px-3 py-2 hover:bg-slate-100 dark:hover:bg-slate-700">+ Añadir tarjeta en esta lista</a>
                                @endif
                                <p class="px-3 py-2 text-slate-600 dark:text-slate-300">{{ $c['pedidos']->count() }} {{ $c['pedidos']->count() === 1 ? 'tarjeta' : 'tarjetas' }} · {{ $verVenta ? 'Venta' : 'Costo' }} ${{ number_format($c['total'], 2) }}</p>
                            </div>
                        </div>
                    </header>
                    <div class="min-h-16 flex-1 space-y-2 overflow-y-auto px-2 pb-2 pt-1" data-columna="{{ $c['valor'] }}">
                        @foreach ($c['pedidos'] as $p)
                            <div class="cursor-pointer select-none" data-id="{{ $p->id }}" wire:key="d-{{ $p->id }}">
                                @include('livewire.tablero.tarjeta', ['p' => $p])
                            </div>
                        @endforeach
                    </div>
                    @if ($verVenta)
                        <a href="{{ url('/pedidos/nuevo?columna='.$c['valor']) }}" class="m-2 mt-0 flex min-h-10 items-center rounded-lg px-2 text-sm font-semibold text-slate-800 hover:bg-black/10 dark:text-slate-200">+ Añadir</a>
                    @endif
                </section>
            @endforeach
        </div>
    @endif

    {{-- ===== Tarjeta abierta en ventana flotante (en celular ocupa toda la pantalla) ===== --}}
    @if ($pedidoAbierto)
        <div class="modal-tarjeta fixed inset-0 z-50 overflow-y-auto bg-black/60 sm:p-6" role="dialog" aria-modal="true" aria-label="Tarjeta del pedido"
             x-on:keydown.escape.window="cerrarTarjeta()" x-on:click.self="cerrarTarjeta()" wire:key="modal-{{ $pedidoAbierto->id }}">
            <div class="mx-auto min-h-full w-full bg-white sm:min-h-0 sm:max-w-5xl sm:rounded-xl sm:shadow-2xl">
                <livewire:pedidos.ver :pedido="$pedidoAbierto" :en-modal="true" :key="'tarjeta-'.$pedidoAbierto->id" />
            </div>
        </div>
    @endif
</div>

@php
    $chips = [
        'rojo' => 'bg-red-100 text-red-800',
        'verde' => 'bg-green-100 text-green-800',
        'azul' => 'bg-blue-100 text-blue-800',
        'rosa' => 'bg-pink-100 text-pink-800',
        'naranja' => 'bg-orange-100 text-orange-800',
        'amarillo' => 'bg-yellow-100 text-yellow-800',
        'morado' => 'bg-purple-100 text-purple-800',
        'gris' => 'bg-slate-200 text-slate-700',
    ];
    $cantidadTexto = fn ($c) => rtrim(rtrim(number_format((float) $c, 3, '.', ','), '0'), '.');
    $estadoColor = [
        'pendiente' => 'bg-slate-200 text-slate-700 hover:bg-slate-300',
        'solicitada' => 'bg-pink-100 text-pink-800 hover:bg-pink-200',
        'recibida' => 'bg-blue-100 text-blue-800 hover:bg-blue-200',
        'entregada' => 'bg-green-100 text-green-800 hover:bg-green-200',
    ];
    $yaEsta = fn ($id) => in_array($id, $yaEnPedido);
    $esCampo = auth()->user()->esCampo();
    $n = $partidas->count();
    $pct = fn ($x) => $n ? (int) round($x * 100 / $n) : 0;
    $fc = $pedido->fecha_compromiso;
    $todoEntregado = $n > 0 && $entregadas === $n;
    $fechaClase = ! $fc || $todoEntregado ? 'bg-slate-100 text-slate-700'
        : ($fc->isPast() && ! $fc->isToday() ? 'bg-red-600 text-white'
        : ($fc->lte(now()->addDays(2)->endOfDay()) ? 'bg-yellow-300 text-yellow-950' : 'bg-slate-100 text-slate-700'));
    $iniciales = fn ($u) => $u?->iniciales ?: mb_strtoupper(mb_substr((string) $u?->name, 0, 2));
    $enlaceTarjeta = route('pedidos.index', ['pedido' => $pedido->id]);
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
    $diaMes = fn ($f) => $f->day.' '.$meses[$f->month - 1];
    $plural = fn ($x, $uno, $varios) => $x.' '.($x === 1 ? $uno : $varios);
    // Pasos del pedido (como lo trabaja MASUME) y el siguiente que falta, para que cualquiera entienda en qué va.
    $pasos = [
        ['OC recibida', $pedido->numero_oc_cliente ? 'OC '.$pedido->numero_oc_cliente : 'Sin OC: nota de venta', 1, 1],
        ['Pedir a proveedores', $pedidas.'/'.$n.' pedidas', $pedidas, $n],
        ['Recoger', $recogidas.'/'.$n.' recogidas', $recogidas, $n],
        ['Entregar + foto', $entregadas.'/'.$n.' entregadas · '.($evidencias ? $plural($evidencias, 'foto', 'fotos') : 'sin foto'), $entregadas, $n],
        ['Facturar', $facturadas.'/'.$n.' facturadas', $facturadas, $n],
    ];
    $actual = null;
    foreach ($pasos as $i => $p) {
        if ($n && $p[2] < $p[3]) { $actual = $i; break; }
    }
    $siguiente = match (true) {
        $n === 0 => 'agrega el material del pedido',
        $pedidas < $n => 'pedir '.$plural($n - $pedidas, 'partida', 'partidas').' al proveedor',
        $recogidas < $n => 'recoger '.$plural($n - $recogidas, 'partida', 'partidas'),
        $entregadas < $n => 'entregar '.$plural($n - $entregadas, 'partida', 'partidas'),
        $evidencias === 0 => 'subir la foto de entrega',
        $facturadas < $n => $verVenta ? 'facturar '.$plural($n - $facturadas, 'partida', 'partidas') : null,
        default => null,
    };
    $hayEntrega = ($recogidas > 0 || $entregadas > 0) && ! $pedido->cancelado_at;
    // Abre el expediente y la cámara (en computadora, el selector de archivos) para la foto de entrega.
    $abrirFoto = "const d = document.getElementById('expediente'); if (d) { d.open = true; const s = d.querySelector('[data-seccion=evidencia]'); s?.scrollIntoView({ behavior: 'smooth', block: 'center' }); s?.querySelector('input[capture]')?.click(); }";
    $permitidos = $estados;
@endphp
<div class="{{ $enModal ? '' : 'rounded-xl bg-white shadow-sm' }}">
    {{-- ===== Encabezado: título editable y lista ===== --}}
    <div class="flex items-start gap-3 border-b border-slate-200 p-4 sm:p-5">
        <svg class="mt-1 h-6 w-6 shrink-0 text-slate-500" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h6"/></svg>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-naranja">P-{{ $pedido->folio }}</p>
            @if ($esCampo)
                <h2 class="text-xl font-bold leading-snug text-marino">{{ $pedido->titulo() }}</h2>
            @else
                <div x-data="{ editando: false }">
                    <h2 x-show="!editando" x-on:click="editando = true; $nextTick(() => $refs.titulo.focus())"
                        class="cursor-text rounded text-xl font-bold leading-snug text-marino hover:bg-slate-100" title="Clic para editar el título">{{ $pedido->titulo() }}</h2>
                    <form x-show="editando" x-cloak wire:submit="guardarTitulo" x-on:submit="editando = false">
                        <textarea x-ref="titulo" wire:model="titulo" rows="2" maxlength="255" aria-label="Título de la tarjeta"
                                  x-on:keydown.enter.prevent="$el.form.requestSubmit()" x-on:keydown.escape.stop="editando = false"
                                  class="campo text-lg font-bold"></textarea>
                    </form>
                    @error('titulo') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
            @endif
            <label class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                en la lista
                <select wire:change="mover($event.target.value)" class="min-w-0 max-w-full rounded border border-slate-300 bg-white px-2 py-1 text-sm font-semibold text-marino" aria-label="Mover a">
                    @foreach (\App\Enums\ColumnaPedido::cases() as $c)
                        <option value="{{ $c->value }}" @selected($pedido->columna === $c)>{{ $c->etiqueta() }}</option>
                    @endforeach
                </select>
                <span class="text-xs text-slate-500">(Mover a…)</span>
            </label>
            <p class="mt-1 text-sm text-slate-600">
                OC del {{ $pedido->fecha_oc->format('d/m/Y') }} · {{ $plural($n, 'partida', 'partidas') }}
                @if ($verVenta && $n) · venta ${{ number_format($totalVenta, 2) }} sin IVA @endif
            </p>
        </div>
        @if ($enModal)
            <button type="button" x-data x-on:click="window.dispatchEvent(new CustomEvent('cerrar-tarjeta-ui'))" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-2xl text-slate-600 hover:bg-slate-100" aria-label="Cerrar tarjeta">✕</button>
        @else
            <a href="{{ url('/pedidos') }}" class="btn-secundario shrink-0">← Tablero</a>
        @endif
    </div>

    @if ($mensaje)
        <div role="status" class="mx-4 mt-4 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800 sm:mx-5">{{ $mensaje }}</div>
    @endif

    {{-- Recordatorio al entregar: invita a tomar la foto (no es obligatoria). --}}
    @if ($pedirFoto && $evidencias === 0)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="titulo-foto-{{ $pedido->id }}">
            <div class="w-full max-w-md space-y-4 rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl" data-aviso-foto>
                <p class="flex items-center gap-2 text-sm font-semibold text-green-800"><span class="flex h-7 w-7 items-center justify-center rounded-full bg-green-100" aria-hidden="true">✓</span> Quedó como entregada</p>
                <h2 id="titulo-foto-{{ $pedido->id }}" class="text-2xl font-bold text-marino">Ahora la foto de entrega</h2>
                <div class="grid grid-cols-3 gap-2 text-center text-xs text-purple-900">
                    <span class="rounded-lg bg-purple-50 px-2 py-2">Material completo</span>
                    <span class="rounded-lg bg-purple-50 px-2 py-2">Que se vea el lugar</span>
                    <span class="rounded-lg bg-purple-50 px-2 py-2">Firma de recibido</span>
                </div>
                <button type="button" x-data x-on:click="{{ $abrirFoto }} $wire.cerrarAvisoFoto()" class="btn !min-h-14 w-full bg-purple-700 text-lg font-bold text-white hover:bg-purple-800">📷 Tomar foto ahora</button>
                <button type="button" wire:click="cerrarAvisoFoto" class="btn-secundario w-full">La subo después</button>
            </div>
        </div>
    @endif

    <div class="grid gap-6 p-4 sm:p-5 md:grid-cols-[minmax(0,1fr)_13rem]">
        {{-- ================= Columna principal ================= --}}
        <div class="min-w-0 space-y-6">


            {{-- ¿En qué va este pedido? Los pasos y lo que sigue, para cualquiera que abra la tarjeta. --}}
            <section class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-3 sm:p-4" aria-label="En qué va el pedido" data-pasos-pedido>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h3 class="text-base font-bold text-marino">¿En qué va este pedido?</h3>
                    @if ($siguiente)
                        @if ($pedidas < $n && $verVenta)
                            <a href="{{ url('/pedidos/'.$pedido->id.'/ordenes-proveedor/nueva') }}" class="btn-primario text-sm">Siguiente paso: {{ $siguiente }} ›</a>
                        @elseif ($n && $entregadas >= $n && $evidencias === 0)
                            <button type="button" x-data x-on:click="{{ $abrirFoto }}" class="btn bg-purple-700 text-sm text-white hover:bg-purple-800">Siguiente paso: {{ $siguiente }} ›</button>
                        @else
                            <p class="rounded-lg bg-orange-100 px-3 py-2 text-sm font-semibold text-orange-900">Siguiente paso: {{ $siguiente }}</p>
                        @endif
                    @elseif ($n)
                        <p class="rounded-lg bg-green-100 px-3 py-2 text-sm font-semibold text-green-800">✓ {{ $verVenta ? 'Todo entregado y facturado' : 'Todo entregado' }}</p>
                    @endif
                </div>
                <ol class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                    @foreach ($pasos as $i => [$nombre, $detalle, $hecho, $total])
                        @php
                            $completo = $total > 0 && $hecho >= $total;
                            $esActual = $i === $actual;
                            $ancho = $total ? (int) round(min($hecho, $total) * 100 / $total) : 0;
                        @endphp
                        <li class="flex flex-col gap-1.5 rounded-lg border bg-white p-2.5 {{ $esActual ? 'border-orange-300 ring-1 ring-orange-200' : 'border-slate-200' }}" @if ($esActual) aria-current="step" @endif>
                            <span class="flex items-center gap-2 text-sm font-semibold {{ $esActual ? 'text-orange-900' : 'text-slate-800' }}">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white {{ $completo ? 'bg-green-600' : ($esActual ? 'bg-orange-600' : 'bg-slate-400') }}">{{ $completo ? '✓' : $i + 1 }}</span>
                                {{ $nombre }}
                            </span>
                            <span class="h-1.5 overflow-hidden rounded-full bg-slate-200"><span class="block h-full rounded-full {{ $completo ? 'bg-green-600' : ($esActual ? 'bg-orange-600' : 'bg-slate-400') }}" style="width: {{ $ancho }}%"></span></span>
                            <span class="text-xs text-slate-600">{{ $detalle }}</span>
                        </li>
                    @endforeach
                </ol>
            </section>

            {{-- Recogidas parciales: el proveedor entregó incompleto. --}}
            @if ($parciales->isNotEmpty())
                <section class="space-y-1 rounded-xl border border-orange-300 bg-orange-50 p-3 sm:p-4" data-falta-recoger>
                    <p class="flex items-center gap-2 text-base font-bold text-orange-900">
                        <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h13v10H3zM16 10h4l1 3v4h-5"/><circle cx="7" cy="18" r="1.6"/><circle cx="18" cy="18" r="1.6"/></svg>
                        Falta recoger
                    </p>
                    <ul class="space-y-0.5 text-sm text-orange-900">
                        @foreach ($parciales as $pp)
                            <li>{{ $cantidadTexto($pp->faltaRecoger()) }} {{ $pp->unidad }} de {{ \Illuminate\Support\Str::limit($pp->descripcion, 70) }} <span class="text-orange-800">(van {{ $cantidadTexto($pp->recogido()) }} de {{ $cantidadTexto($pp->cantidad) }})</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- ¿Cuántas recogiste? Una o varias partidas (el proveedor a veces entrega solo una parte). --}}
            @if ($recogiendo->isNotEmpty())
                @php
                    $faltas = $recogiendo->mapWithKeys(fn ($rp) => [$rp->id => $rp->faltaRecoger()])->all();
                    $varias = $recogiendo->count() > 1;
                @endphp
                <div class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="titulo-recoger"
                     x-data="{ cant: @js((object) $faltas), falta: @js((object) $faltas),
                               paso(id) { return this.falta[id] >= 1 ? 1 : this.falta[id] },
                               menos(id) { this.cant[id] = Math.max(0, Math.round((Number(this.cant[id]) - this.paso(id)) * 1000) / 1000) },
                               mas(id) { this.cant[id] = Math.min(this.falta[id], Math.round((Number(this.cant[id]) + this.paso(id)) * 1000) / 1000) },
                               quedan(id) { return Math.round((this.falta[id] - Number(this.cant[id] || 0)) * 1000) / 1000 } }"
                     x-on:keydown.escape.window="$wire.cerrarRecoger()" data-recoger>
                    <div class="flex max-h-[92vh] w-full max-w-md flex-col gap-4 overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                        <div>
                            <h2 id="titulo-recoger" class="text-2xl font-bold text-marino">¿Cuántas recogiste?</h2>
                            <p class="text-sm text-slate-600">{{ $varias ? 'Viene marcado todo lo que falta. Si el proveedor no entregó completo, cambia la cantidad (0 = no se recogió nada de esa).' : 'Si el proveedor no entregó completo, pon solo lo que te dio.' }}</p>
                        </div>
                        <ul class="space-y-3">
                            @foreach ($recogiendo as $rp)
                                <li wire:key="rec-{{ $rp->id }}" class="space-y-2 rounded-xl border border-slate-200 p-3">
                                    <p class="text-base font-semibold text-slate-900">{{ $rp->descripcion }}</p>
                                    <p class="text-sm text-slate-600">Se pidieron {{ $cantidadTexto($rp->cantidad) }} {{ $rp->unidad }}@if ($rp->recogido() > 0) · ya van {{ $cantidadTexto($rp->recogido()) }}@endif</p>
                                    <div class="flex items-center gap-2">
                                        <button type="button" x-on:click="menos({{ $rp->id }})" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-slate-300 text-2xl font-bold text-marino" aria-label="Menos">−</button>
                                        <label class="min-w-0 flex-1">
                                            <span class="sr-only">Cantidad recogida de {{ $rp->descripcion }}</span>
                                            <input type="number" inputmode="decimal" step="any" min="0" max="{{ $faltas[$rp->id] }}" x-model="cant[{{ $rp->id }}]" class="campo !py-2 text-center text-2xl font-extrabold text-marino">
                                        </label>
                                        <button type="button" x-on:click="mas({{ $rp->id }})" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-slate-300 text-2xl font-bold text-marino" aria-label="Más">+</button>
                                        <button type="button" x-on:click="cant[{{ $rp->id }}] = falta[{{ $rp->id }}]" x-bind:class="Number(cant[{{ $rp->id }}]) >= falta[{{ $rp->id }}] ? 'border-marino bg-indigo-50' : 'border-slate-300'"
                                                class="min-h-12 w-28 shrink-0 rounded-xl border-2 px-2 text-sm font-bold leading-tight text-slate-900">{{ $rp->recogido() > 0 ? 'Lo que faltaba' : 'Todas' }}<span class="block text-xs font-semibold">{{ $cantidadTexto($faltas[$rp->id]) }} {{ $rp->unidad }}</span></button>
                                    </div>
                                    <p x-show="Number(cant[{{ $rp->id }}]) > 0 && quedan({{ $rp->id }}) > 0" x-cloak class="rounded-lg bg-orange-50 px-3 py-1.5 text-sm font-semibold text-orange-900">
                                        Quedan <span x-text="quedan({{ $rp->id }})"></span> {{ $rp->unidad }} por recoger.
                                    </p>
                                    <p x-show="!(Number(cant[{{ $rp->id }}]) > 0)" x-cloak class="text-sm text-slate-500">De esta no se recogió nada.</p>
                                </li>
                            @endforeach
                        </ul>
                        @error('recoger') <p class="error-campo">{{ $message }}</p> @enderror
                        <button type="button" x-on:click="$wire.registrarRecogidas(cant)" wire:loading.attr="disabled" class="btn-primario !min-h-14 w-full text-lg">Guardar</button>
                        <button type="button" wire:click="cerrarRecoger" class="btn-secundario w-full">Cancelar</button>
                    </div>
                </div>
            @endif

            {{-- Empujoncito para la foto de entrega (no es obligatoria). --}}
            @if ($hayEntrega && $evidencias === 0)
                <section class="flex flex-col gap-3 rounded-xl border border-purple-200 bg-purple-50 p-3 sm:flex-row sm:items-center sm:p-4" data-recordatorio-foto>
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-purple-700 text-xl text-white" aria-hidden="true">📷</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-base font-bold text-purple-900">{{ $esCampo ? 'No te vayas sin la foto de entrega' : 'Foto de entrega: todavía no hay' }}</p>
                    </div>
                    <button type="button" x-data x-on:click="{{ $abrirFoto }}" class="btn !min-h-12 shrink-0 bg-purple-700 font-bold text-white hover:bg-purple-800">📷 Tomar foto de entrega</button>
                </section>
            @endif

            {{-- Responsable, etiquetas y fecha compromiso --}}
            <div class="flex flex-wrap gap-x-6 gap-y-4">
                <div>
                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Responsable</h3>
                    <div class="flex items-center gap-2">
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-marino text-[11px] font-bold text-white" title="{{ $pedido->responsable->name ?? '' }}">{{ $iniciales($pedido->responsable) }}</span>
                        @if ($esCampo)
                            <span class="font-medium">{{ $pedido->responsable->name ?? '' }}</span>
                        @else
                            <select wire:change="cambiarResponsable($event.target.value)" class="rounded border border-slate-300 bg-white px-2 py-1.5 text-sm" aria-label="Responsable">
                                @foreach ($responsables as $r)
                                    <option value="{{ $r->id }}" @selected($pedido->responsable_id === $r->id)>{{ $r->name }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                </div>

                <div>
                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Fecha compromiso</h3>
                    @if ($esCampo)
                        <span class="inline-block rounded px-2 py-1.5 text-sm font-semibold {{ $fechaClase }}">{{ $fc?->format('d/m/Y') ?? 'Sin fecha' }}</span>
                    @else
                        <input type="date" wire:model="fechaCompromiso" wire:change="guardarFechaCompromiso" aria-label="Fecha compromiso"
                               class="rounded border-0 px-2 py-1.5 text-sm font-semibold {{ $fechaClase }}">
                    @endif
                    @if ($fc && ! $todoEntregado && $fc->isPast() && ! $fc->isToday())
                        <span class="ml-1 text-xs font-semibold text-red-600">Vencida</span>
                    @endif
                    @error('fechaCompromiso') <p class="error-campo">{{ $message }}</p> @enderror
                </div>

                <div>
                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Fecha de la OC</h3>
                    <span class="inline-block py-1.5 text-sm font-medium">{{ $pedido->fecha_oc->format('d/m/Y') }}</span>
                </div>
            </div>

            <div x-data="{ editar: false }">
                <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Etiquetas</h3>
                <div class="flex flex-wrap gap-2">
                    @foreach ($pedido->etiquetas() as $e)
                        <span class="rounded px-3 py-1 text-sm font-semibold {{ $chips[$e['color']] ?? 'bg-slate-100 text-slate-700' }}">{{ $e['texto'] }}</span>
                    @endforeach
                    <button type="button" x-on:click="editar = !editar" x-bind:aria-expanded="editar" class="rounded bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-600 hover:bg-slate-200" aria-label="Poner o quitar etiquetas">+</button>
                    @if (! $esCampo)
                        <a href="{{ route('sicar.alta', ['pedido' => $pedido->id]) }}" class="rounded bg-slate-100 px-3 py-1 text-sm font-semibold text-marino hover:bg-slate-200">Alta en SICAR</a>
                    @endif
                </div>
                <div x-show="editar" x-cloak class="mt-2 flex flex-wrap gap-2 rounded-lg border border-slate-200 p-2" aria-label="Banderas manuales">
                    @foreach (['alta_sicar' => ['Material dado de alta en SICAR', 'bg-green-600'], 'revisar' => ['Revisar / dar seguimiento', 'bg-red-600'], 'modificar_oc' => ['Se envía a modificar OC', 'bg-yellow-500']] as $campo => [$texto, $color])
                        <button type="button" wire:click="alternarBandera('{{ $campo }}')" aria-pressed="{{ $pedido->$campo ? 'true' : 'false' }}"
                                class="min-h-9 rounded px-3 py-1 text-sm font-semibold {{ $pedido->$campo ? $color.' text-white' : 'border border-dashed border-slate-300 text-slate-500 hover:bg-slate-50' }}">
                            {{ $pedido->$campo ? '✓ ' : '+ ' }}{{ $texto }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Número de OC pendiente y portal del cliente --}}
            @if ($pedido->clienteGeneraOcDespues() && ! $pedido->cancelado_at && ! $esCampo)
                <div class="space-y-3 rounded-lg border border-orange-300 bg-orange-50 p-3">
                    <p class="text-sm font-semibold text-orange-900">OC después de subir la factura a su portal</p>
                    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                        @if ($pedido->cliente->portal_url)
                            <a href="{{ $pedido->cliente->portal_url }}" target="_blank" rel="noopener" class="btn-secundario text-center">Abrir portal de {{ $pedido->cliente->nombre }}</a>
                        @endif
                        @if (! $pedido->factura_portal_at)
                            <button type="button" wire:click="marcarFacturaPortal" class="btn-primario">Ya subí la factura al portal</button>
                        @endif
                    </div>
                    @if ($pedido->factura_portal_at)
                        <p class="text-sm text-orange-900">
                            Factura subida al portal por {{ $pedido->facturaPortalPor?->name ?? 'alguien' }} el {{ $pedido->factura_portal_at->timezone('America/Mexico_City')->format('d/m/Y H:i') }}.
                            @if (auth()->user()->esAdmin())
                                <button type="button" wire:click="deshacerFacturaPortal" class="ml-2 underline">Deshacer</button>
                            @endif
                        </p>
                    @endif
                    @if (! $pedido->numero_oc_cliente)
                        <form wire:submit="guardarNumeroOc" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <div class="flex-1">
                                <label class="etiqueta" for="numero-oc-{{ $pedido->id }}">Número de OC</label>
                                <input id="numero-oc-{{ $pedido->id }}" type="text" wire:model="numeroOc" class="campo" placeholder="Ej. 4509078358">
                                @error('numeroOc') <p class="error-campo">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn-primario">Guardar OC</button>
                        </form>
                    @endif
                </div>
            @else
                @if ($pedido->clienteSubeFacturaPortal() && ! $pedido->cancelado_at && ! $esCampo)
                    <div class="space-y-2 rounded-lg border border-orange-300 bg-orange-50 p-3">
                        <p class="text-sm font-semibold text-orange-900">Subir la factura a su portal</p>
                        @if ($pedido->factura_portal_at)
                            <p class="text-sm text-orange-900">
                                Factura subida al portal por {{ $pedido->facturaPortalPor?->name ?? 'alguien' }} el {{ $pedido->factura_portal_at->timezone('America/Mexico_City')->format('d/m/Y H:i') }}.
                                @if (auth()->user()->esAdmin())
                                    <button type="button" wire:click="deshacerFacturaPortal" class="ml-2 underline">Deshacer</button>
                                @endif
                            </p>
                        @else
                            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                @if ($pedido->cliente->portal_url)
                                    <a href="{{ $pedido->cliente->portal_url }}" target="_blank" rel="noopener" class="btn-secundario text-center">Abrir portal de {{ $pedido->cliente->nombre }}</a>
                                @endif
                                <button type="button" wire:click="marcarFacturaPortal" class="btn-primario">Ya subí la factura al portal</button>
                            </div>
                        @endif
                    </div>
                @endif
                @if (! $pedido->numero_oc_cliente && ! $esCampo)
                    <form wire:submit="guardarNumeroOc" class="flex flex-col gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 sm:flex-row sm:items-end">
                        <div class="flex-1">
                            <label class="etiqueta" for="numero-oc-{{ $pedido->id }}">Número de OC del cliente</label>
                            <input id="numero-oc-{{ $pedido->id }}" type="text" wire:model="numeroOc" class="campo" placeholder="Ej. 4509078358">
                            @error('numeroOc') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn-primario">Guardar OC</button>
                    </form>
                @endif
            @endif

            @if ($mostrarReglas)
                <livewire:pedidos.reglas-cliente :pedido-id="$pedido->id" :key="'reglas-'.$pedido->id" />
            @elseif ($pedido->sucursal)
                <p class="text-sm text-sky-900">📍 {{ $pedido->sucursal->ubicacion() }}</p>
            @endif

            {{-- Descripción (notas) con markdown simple --}}
            <section x-data="{ editando: false }">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="flex items-center gap-2 text-base font-semibold text-marino"><span aria-hidden="true">≡</span> Notas del pedido</h3>
                    <button type="button" x-show="!editando" x-on:click="editando = true" class="rounded bg-slate-100 px-3 py-1.5 text-sm font-medium hover:bg-slate-200">Editar</button>
                </div>
                <div x-show="!editando" x-on:click="if (!$event.target.closest('a')) editando = true" class="cursor-text">
                    @if ($notasHtml)
                        <div class="notas-md rounded-lg p-2 text-sm leading-relaxed text-slate-800 hover:bg-slate-50">{!! $notasHtml !!}</div>
                    @else
                        <p class="rounded-lg bg-slate-100 p-3 text-sm text-slate-500">Agrega una descripción más detallada…</p>
                    @endif
                </div>
                <div x-show="editando" x-cloak>
                    <textarea rows="6" wire:model="notas" class="campo" aria-label="Notas"></textarea>
                    @error('notas') <p class="error-campo">{{ $message }}</p> @enderror
                    <div class="mt-2 flex gap-2">
                        <button type="button" wire:click="guardarNotas" x-on:click="editando = false" class="btn-primario">Guardar notas</button>
                        <button type="button" x-on:click="editando = false" class="btn-secundario">Cancelar</button>
                    </div>
                </div>
            </section>

            {{-- ===== Material (partidas) ===== --}}
            <section id="partidas-{{ $pedido->id }}">
                @if ($faltanSicar)
                    <div class="mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-orange-300 bg-orange-50 px-3 py-2" role="alert" data-aviso-sicar>
                        <p class="mr-auto text-sm font-semibold text-orange-800">{{ count($faltanSicar) === 1 ? 'Falta 1 partida en SICAR' : 'Faltan '.count($faltanSicar).' partidas en SICAR' }}</p>
                        @unless (auth()->user()->esCampo())
                            <a href="{{ route('sicar.alta', ['pedido' => $pedido->id]) }}" class="btn-primario">Dar de alta lo que falta</a>
                        @endunless
                    </div>
                @endif
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <h3 class="mr-auto flex items-center gap-2 text-lg font-bold text-marino"><span aria-hidden="true">☑</span> Material ({{ $plural($n, 'partida', 'partidas') }})</h3>
                    @if ($verVenta)
                        <button type="button" wire:click="abrirBuscadorCotizacion" class="min-h-10 rounded bg-slate-100 px-3 text-sm font-medium hover:bg-slate-200">+ Agregar de cotización</button>
                        <button type="button" wire:click="$set('agregando', true)" class="min-h-10 rounded bg-slate-100 px-3 text-sm font-medium hover:bg-slate-200">+ Agregar manual</button>
                    @endif
                </div>

                @if ($verVenta)
                    @if ($agregando)
                        <form wire:submit="agregarPartida" class="mb-3 grid gap-3 rounded-lg border border-slate-200 p-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="etiqueta" for="n-desc-{{ $pedido->id }}">Descripción</label>
                                <input id="n-desc-{{ $pedido->id }}" type="text" wire:model="n_descripcion" class="campo uppercase">
                                @error('n_descripcion') <p class="error-campo">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="etiqueta" for="n-cant-{{ $pedido->id }}">Cantidad</label>
                                <input id="n-cant-{{ $pedido->id }}" type="number" step="any" inputmode="decimal" wire:model="n_cantidad" class="campo">
                                @error('n_cantidad') <p class="error-campo">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="etiqueta" for="n-uni-{{ $pedido->id }}">Unidad</label>
                                <input id="n-uni-{{ $pedido->id }}" type="text" wire:model="n_unidad" maxlength="10" class="campo uppercase">
                            </div>
                            <div>
                                <label class="etiqueta" for="n-mod-{{ $pedido->id }}">Modelo</label>
                                <input id="n-mod-{{ $pedido->id }}" type="text" wire:model="n_modelo" class="campo">
                            </div>
                            <div>
                                <label class="etiqueta" for="n-pre-{{ $pedido->id }}">Precio unitario (sin IVA)</label>
                                <input id="n-pre-{{ $pedido->id }}" type="number" step="any" inputmode="decimal" wire:model="n_precio" class="campo">
                                @error('n_precio') <p class="error-campo">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="etiqueta" for="n-cos-{{ $pedido->id }}">Costo unitario (opcional)</label>
                                <input id="n-cos-{{ $pedido->id }}" type="number" step="any" inputmode="decimal" wire:model="n_costo" class="campo">
                            </div>
                            <div>
                                <label class="etiqueta" for="n-prov-{{ $pedido->id }}">Proveedor (opcional)</label>
                                <input id="n-prov-{{ $pedido->id }}" type="text" list="lista-proveedores-ped" wire:model="n_proveedor" autocomplete="off" class="campo" placeholder="Escribe y elige de la lista">
                                @error('n_proveedor') <p class="error-campo">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-col gap-2 sm:col-span-2 sm:flex-row">
                                <button type="submit" class="btn-primario">Agregar</button>
                                <button type="button" wire:click="$set('agregando', false)" class="btn-secundario">Cancelar</button>
                            </div>
                        </form>
                    @endif

                    @if ($agregando || $editandoId)
                        <datalist id="lista-proveedores-ped">
                            @foreach ($proveedores as $nombreProv)
                                <option value="{{ $nombreProv }}"></option>
                            @endforeach
                        </datalist>
                    @endif

                    @if ($buscandoCot)
                        <div class="mb-3 rounded-lg border border-slate-200 p-3 sm:p-4" data-buscador-cotizacion>
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                <div class="flex-1">
                                    <label class="etiqueta" for="cot-busca-{{ $pedido->id }}">Folio de cotización o texto (descripción / modelo)</label>
                                    <input id="cot-busca-{{ $pedido->id }}" type="search" wire:model.live.debounce.400ms="cotBusca" class="campo" placeholder="Ej. 90123 o llave stilson" autocomplete="off">
                                </div>
                                <label class="flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" wire:model.live="cotTodas" class="h-5 w-5 rounded border-slate-300"> Buscar en todas las cotizaciones</label>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">{{ $cotTodas ? 'Mostrando cotizaciones de todos los clientes.' : 'Mostrando las cotizaciones de este cliente.' }} Se listan las 40 más recientes.</p>

                            @if ($cotizadas->isEmpty())
                                <p class="py-4 text-center text-sm text-slate-600">No encontré partidas cotizadas con eso.</p>
                            @else
                                <ul class="mt-3 max-h-96 space-y-2 overflow-y-auto">
                                    @foreach ($cotizadas as $cp)
                                        <li wire:key="cot-{{ $cp->id }}" class="rounded-lg border border-slate-200 p-2 {{ $yaEsta($cp->id) ? 'bg-slate-50 text-slate-500' : '' }}">
                                            <div class="flex items-start gap-3">
                                                <input type="checkbox" value="{{ $cp->id }}" wire:model.live="cotSel" @disabled($yaEsta($cp->id)) class="mt-1 h-6 w-6 shrink-0 rounded border-slate-300" aria-label="Elegir partida cotizada">
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-xs text-slate-500">Cotización {{ $cp->cotizacion->folio }} · {{ $cp->cotizacion->fecha?->format('d/m/Y') }}</p>
                                                    <p class="text-sm font-semibold">{{ $cp->descripcion }}</p>
                                                    <p class="text-xs text-slate-600">
                                                        {{ $cantidadTexto($cp->cantidad) }} {{ $cp->unidad }}
                                                        @if ($cp->modelo) · Modelo {{ $cp->modelo }} @endif
                                                        · Costo ${{ number_format((float) $cp->costo, 2) }}
                                                        · Precio ${{ number_format((float) $cp->precio, 2) }}
                                                        · {{ $cp->proveedor->nombre ?? 'Sin proveedor' }}
                                                    </p>
                                                    @if ($yaEsta($cp->id))
                                                        <p class="text-xs font-semibold text-blue-700">Ya está en este pedido</p>
                                                    @elseif (in_array((string) $cp->id, array_map('strval', $cotSel), true))
                                                        <div class="mt-1 flex items-center gap-2">
                                                            <label class="text-xs" for="cot-cant-{{ $cp->id }}">Cantidad</label>
                                                            <input id="cot-cant-{{ $cp->id }}" type="number" step="any" inputmode="decimal" wire:model="cotCant.{{ $cp->id }}" placeholder="{{ $cantidadTexto($cp->cantidad) }}" class="campo w-28">
                                                        </div>
                                                        @error('cotCant.'.$cp->id) <p class="error-campo">{{ $message }}</p> @enderror
                                                    @endif
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                                <button type="button" wire:click="agregarDeCotizacion" @disabled(! count($cotSel)) class="btn-primario">Agregar seleccionadas{{ count($cotSel) ? ' ('.count($cotSel).')' : '' }}</button>
                                <button type="button" wire:click="cerrarBuscadorCotizacion" class="btn-secundario">Cerrar</button>
                            </div>
                        </div>
                    @endif
                @endif

                @if ($n)
                    <div class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600" aria-label="Qué significa cada color">
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-slate-400"></span>Pendiente</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-pink-500"></span>Pedida al proveedor</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-blue-600"></span>Recogida</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-green-600"></span>Entregada</span>
                    </div>
                @endif

                @if (count($seleccion))
                    <div class="sticky top-0 z-10 mb-3 flex flex-wrap items-center gap-2 rounded-lg bg-marino p-2 text-white shadow">
                        <span class="px-1 text-sm">{{ count($seleccion) }} {{ count($seleccion) === 1 ? 'seleccionada' : 'seleccionadas' }}:</span>
                        @foreach ($estados as $e)
                            @continue($e === \App\Enums\EstadoPartidaPedido::Pendiente || $e === \App\Enums\EstadoPartidaPedido::Solicitada)
                            <button type="button" wire:click="accionMasiva('{{ $e->value }}')" class="min-h-10 rounded bg-white px-3 text-sm font-semibold text-marino">Marcar {{ strtolower($e->etiqueta()) }}</button>
                        @endforeach
                        @if ($verVenta)
                            <button type="button" wire:click="accionMasiva('facturada')" class="min-h-10 rounded bg-white px-3 text-sm font-semibold text-marino">Marcar facturada</button>
                        @endif
                        <button type="button" wire:click="$set('seleccion', [])" class="min-h-10 px-2 text-sm underline">Quitar selección</button>
                    </div>
                @elseif ($n > 1)

                @endif

                @if ($avisoPrecioWa)
                    <div class="mb-3 flex flex-col gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-base text-amber-900 sm:flex-row sm:items-center sm:justify-between">
                        <span>{{ $avisoPrecioTexto }}</span>
                        <a href="{{ $avisoPrecioWa }}" target="_blank" rel="noopener" wire:click="marcarAvisoPrecio" class="btn bg-green-600 text-white hover:bg-green-700">Avisar a Daniel por WhatsApp</a>
                    </div>
                @endif

                <ul class="space-y-2">
                    @foreach ($partidas as $p)
                        @php
                            $iEstado = array_search($p->estado, $permitidos, true);
                            $sig = $permitidos[$iEstado === false ? 0 : ($iEstado + 1) % count($permitidos)];
                            $sigTexto = $p->estado === \App\Enums\EstadoPartidaPedido::Entregada ? '' : 'Marcar '.mb_strtolower($sig->etiqueta());
                            // Al pasar a "Recogida" se pregunta cuántas (el proveedor a veces entrega solo una parte).
                            $preguntaCuantas = $sig === \App\Enums\EstadoPartidaPedido::Recibida && (float) $p->cantidad > 1 && $p->faltaRecoger() > 0;
                            $parcial = $p->recogidaParcial();
                            $puntoEstado = ['pendiente' => 'bg-slate-400', 'solicitada' => 'bg-pink-500', 'recibida' => 'bg-blue-600', 'entregada' => 'bg-green-600'][$p->estado->value];
                            $fe = $p->fecha_entrega;
                            $entregada = $p->estado === \App\Enums\EstadoPartidaPedido::Entregada;
                            [$feTexto, $feClase] = match (true) {
                                ! $fe => ['+ Fecha estimada', 'border-dashed border-slate-400 bg-white text-slate-600'],
                                $entregada => ['Estimada '.$diaMes($fe), 'border-slate-200 bg-slate-50 text-slate-500'],
                                $fe->isToday() => ['Llega hoy', 'border-orange-300 bg-orange-50 text-orange-800'],
                                $fe->isPast() => ['Atrasada · '.$diaMes($fe), 'border-red-300 bg-red-50 text-red-700'],
                                default => ['Llega '.$diaMes($fe), 'border-slate-300 bg-white text-slate-700'],
                            };
                            $seleccionada = in_array((string) $p->id, array_map('strval', $seleccion), true);
                        @endphp
                        <li wire:key="partida-{{ $p->id }}" class="rounded-xl border {{ $seleccionada ? 'border-blue-300 bg-blue-50/40' : 'border-slate-200' }} p-3">
                            <div class="flex items-start gap-3">
                                <input type="checkbox" value="{{ $p->id }}" wire:model.live="seleccion" class="mt-1 h-6 w-6 shrink-0 rounded border-slate-300" aria-label="Seleccionar partida {{ $p->orden }}">
                                <div class="min-w-0 flex-1">
                                    <p class="text-base font-semibold {{ $entregada ? 'text-slate-500' : '' }}">{{ $p->descripcion }}
                                        @switch($estadoSicar[$p->id] ?? 'sin_alta')
                                            @case('en_sicar')
                                                <span class="ml-1 inline-block whitespace-nowrap rounded bg-green-100 px-1.5 py-0.5 align-middle text-xs font-semibold text-green-800" data-en-sicar>✓ En SICAR</span>
                                                @break
                                            @case('falta')
                                                <span class="ml-1 inline-block whitespace-nowrap rounded bg-orange-500 px-1.5 py-0.5 align-middle text-xs font-bold text-white" data-falta-sicar>Falta en SICAR</span>
                                                @break
                                            @default
                                                <span class="ml-1 inline-block whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 align-middle text-xs font-semibold text-slate-600" data-sin-alta-sicar>Sin alta en SICAR</span>
                                        @endswitch
                                    </p>
                                    <p class="text-sm text-slate-600">
                                        <strong class="text-base text-marino">{{ $cantidadTexto($p->cantidad) }} {{ $p->unidad }}</strong>
                                        @if ($p->modelo) · Modelo {{ $p->modelo }} @endif
                                        @if ($p->marca) · {{ $p->marca }} @endif
                                    </p>
                                    @if ($verCostos)
                                        <p class="mt-1 text-sm text-slate-600">
                                            Proveedor: {{ $p->proveedor->nombre ?? '—' }}
                                            @if ($p->proveedor_vendedor) ({{ $p->proveedor_vendedor }}) @endif
                                            @if ($p->proveedor_telefono)
                                                · @if ($wa = \App\Support\Telefono::whatsapp($p->proveedor_telefono))<a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="font-semibold text-green-700 underline">WhatsApp {{ $p->proveedor_telefono }}</a>@else Tel. {{ $p->proveedor_telefono }}@endif
                                            @endif
                                            @if (preg_match('#^https?://#i', (string) $p->link_compra))
                                                · <a href="{{ $p->link_compra }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-marino underline">🛒 Dónde comprar</a>
                                            @elseif ($p->link_compra)
                                                · Dónde comprar: {{ $p->link_compra }}
                                            @endif
                                            · Costo unitario ${{ number_format((float) $p->costo, 2) }}
                                            · Costo total <strong>${{ number_format((float) $p->costo * (float) $p->cantidad, 2) }}</strong>
                                            @if ($verVenta)
                                                · Precio ${{ number_format((float) $p->precio, 2) }}
                                                · Importe <strong>${{ number_format((float) $p->precio * (float) $p->cantidad, 2) }}</strong>
                                            @endif
                                        </p>
                                    @endif
                                    @if ($verVenta && ($cp = $cambiosPrecio->get($p->id)))
                                        @if ($cp->estatus === 'pendiente')
                                            <p class="mt-1 text-sm font-semibold text-amber-800">
                                                <span class="rounded bg-amber-100 px-2 py-0.5">Precio por autorizar: ${{ number_format((float) $cp->precio_solicitado, 2) }} (cotizado ${{ number_format((float) $cp->precio_anterior, 2) }})</span>
                                                @if (auth()->user()->esAdmin()) <a href="{{ route('cambios-precio.ver', $cp->id) }}" class="ml-1 text-marino underline">Revisar</a> @endif
                                            </p>
                                        @else
                                            <p class="mt-1 text-xs text-slate-500">Precio ajustado (antes ${{ number_format((float) $cp->precio_anterior, 2) }})</p>
                                        @endif
                                    @endif
                                    @if ($parcial)
                                        @php $pctR = (int) round($p->recogido() * 100 / max(0.001, (float) $p->cantidad)); @endphp
                                        <div class="mt-2 space-y-1.5 rounded-lg border border-orange-300 bg-orange-50 p-2.5" data-recogida-parcial>
                                            <div class="flex flex-wrap justify-between gap-x-3 text-sm font-bold text-orange-900">
                                                <span>Recogidas {{ $cantidadTexto($p->recogido()) }} de {{ $cantidadTexto($p->cantidad) }}</span>
                                                <span>faltan {{ $cantidadTexto($p->faltaRecoger()) }} {{ $p->unidad }}</span>
                                            </div>
                                            <div class="h-2.5 overflow-hidden rounded-full bg-orange-100" role="img" aria-label="{{ $pctR }}% recogido"><div class="h-full bg-naranja" style="width: {{ $pctR }}%"></div></div>
                                            <div class="flex flex-wrap gap-2 pt-1">
                                                <button type="button" wire:click="registrarRecogida({{ $p->id }}, {{ $p->faltaRecoger() }})" wire:confirm="¿Ya recogiste las {{ $cantidadTexto($p->faltaRecoger()) }} {{ $p->unidad }} que faltaban?" class="btn-primario !min-h-11 flex-1 text-sm">Recogí lo que faltaba</button>
                                                <button type="button" wire:click="abrirRecoger({{ $p->id }})" class="btn-secundario !min-h-11 flex-1 text-sm">Recogí otra parte</button>
                                            </div>
                                        </div>
                                    @endif
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <button type="button" wire:click="{{ $preguntaCuantas ? 'abrirRecoger' : 'siguienteEstado' }}({{ $p->id }})" title="Toca para pasar al siguiente estado"
                                                class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-full px-4 py-1 text-sm font-semibold sm:w-auto {{ $parcial ? 'bg-orange-100 text-orange-900 hover:bg-orange-200' : $estadoColor[$p->estado->value] }}">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $parcial ? 'bg-orange-500' : $puntoEstado }}" aria-hidden="true"></span>
                                            {{ $parcial ? 'Recogida parcial' : $p->estado->etiqueta() }} ›
                                            @if ($sigTexto)<span class="font-normal opacity-80">{{ $sigTexto }}</span>@endif
                                        </button>
                                        @if ($esCampo)
                                            @if ($fe)
                                                <span class="inline-flex min-h-10 items-center gap-1.5 rounded-lg border px-3 text-sm font-semibold {{ $feClase }}">🗓 {{ $feTexto }}</span>
                                            @endif
                                        @else
                                            <div class="relative" x-data="{ abierto: false, fecha: @js($fe?->format('Y-m-d') ?? ''),
                                                    dias(n) { const d = new Date(); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); },
                                                    guardar(f) { this.abierto = false; $wire.guardarFechaEntrega({{ $p->id }}, f); } }"
                                                 x-on:keydown.escape.stop="abierto = false" x-on:click.outside="abierto = false">
                                                <button type="button" x-on:click="abierto = !abierto" x-bind:aria-expanded="abierto"
                                                        class="inline-flex min-h-10 items-center gap-1.5 rounded-lg border px-3 text-sm font-semibold {{ $feClase }}" title="Fecha estimada de entrega">🗓 {{ $feTexto }}</button>
                                                <div x-show="abierto" x-cloak class="absolute left-0 z-20 mt-1 w-72 space-y-2 rounded-xl border border-slate-200 bg-white p-3 shadow-lg" data-fecha-entrega>
                                                    <p class="text-sm font-semibold">¿Cuándo llega?</p>
                                                    <div class="flex flex-wrap gap-1.5">
                                                        <button type="button" x-on:click="guardar(dias(0))" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm hover:bg-slate-50">Hoy</button>
                                                        <button type="button" x-on:click="guardar(dias(1))" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm hover:bg-slate-50">Mañana</button>
                                                        <button type="button" x-on:click="guardar(dias(3))" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm hover:bg-slate-50">En 3 días</button>
                                                    </div>
                                                    <label class="etiqueta" for="fe-{{ $p->id }}">Otra fecha</label>
                                                    <input id="fe-{{ $p->id }}" type="date" x-model="fecha" class="campo">
                                                    <div class="flex gap-2">
                                                        <button type="button" x-on:click="guardar(fecha)" class="btn-primario flex-1 !min-h-10">Guardar</button>
                                                        @if ($fe)
                                                            <button type="button" x-on:click="guardar('')" class="btn-secundario !min-h-10 !text-red-600">Quitar fecha</button>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                            @error('fechaEntrega.'.$p->id) <p class="error-campo w-full">{{ $message }}</p> @enderror
                                        @endif
                                        @if ($verVenta)
                                            <label class="flex min-h-10 items-center gap-2 px-1 text-sm">
                                                <input type="checkbox" @checked($p->facturada) wire:click="alternarFacturada({{ $p->id }})" class="h-6 w-6 rounded border-slate-300">
                                                Facturada
                                            </label>
                                        @elseif ($p->facturada)
                                            <span class="rounded-full bg-blue-100 px-3 py-1 text-sm font-semibold text-blue-800">Facturada</span>
                                        @endif
                                        @if ($verVenta)
                                            <button type="button" wire:click="editarPartida({{ $p->id }})" class="min-h-10 rounded bg-slate-100 px-3 text-sm font-medium hover:bg-slate-200">Editar</button>
                                            @if (\App\Livewire\Pedidos\Ver::sePuedeQuitar($p, auth()->user()->esAdmin()))
                                                @php
                                                    $confirmar = '¿Quitar esta partida del pedido?'
                                                        .($p->orden_proveedor_id ? ' También se quita de su OC al proveedor (si ya se la mandaste, avísale).' : '')
                                                        .($p->estado->nivel() >= \App\Enums\EstadoPartidaPedido::Recibida->nivel() || $p->facturada ? ' OJO: ya está '.($p->facturada ? 'facturada' : mb_strtolower($p->estado->etiqueta())).'.' : '');
                                                @endphp
                                                <button type="button" wire:click="quitarPartida({{ $p->id }})" wire:confirm="{{ $confirmar }}" class="min-h-10 rounded bg-red-50 px-3 text-sm font-medium text-red-700 hover:bg-red-100">Quitar</button>
                                            @else
                                                <span class="text-xs text-slate-500">Solo el admin la quita</span>
                                            @endif
                                        @endif
                                        <details class="text-sm">
                                            <summary class="cursor-pointer select-none rounded px-2 py-1.5 text-slate-500 hover:bg-slate-100">Elegir estado</summary>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                @foreach ($estados as $e)
                                                    <button type="button" wire:click="establecerEstado({{ $p->id }}, '{{ $e->value }}')" @disabled($p->estado === $e)
                                                            class="{{ $p->estado === $e ? 'btn bg-marino text-white' : 'btn-secundario' }}">{{ $e->etiqueta() }}</button>
                                                @endforeach
                                            </div>
                                        </details>
                                    </div>
                                </div>
                            </div>
                            @if ($verVenta && $editandoId === $p->id)
                                <form wire:submit="guardarPartida" class="mt-3 grid gap-3 rounded-lg border border-naranja/40 bg-orange-50/40 p-3 sm:grid-cols-2" data-editar-partida>
                                    <div class="sm:col-span-2">
                                        <label class="etiqueta" for="e-desc-{{ $p->id }}">Descripción</label>
                                        <input id="e-desc-{{ $p->id }}" type="text" wire:model.live.debounce.600ms="e_descripcion" autocomplete="off" class="campo uppercase">
                                        @error('e_descripcion') <p class="error-campo">{{ $message }}</p> @enderror
                                        @if ($eSugerencias)
                                            <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white" data-sugerencias-partida>
                                                @foreach ($eSugerencias as $i => $sug)
                                                    <li wire:key="sug-{{ $p->id }}-{{ $i }}" class="flex flex-col gap-2 p-2 sm:flex-row sm:items-center">
                                                        <div class="min-w-0 flex-1 text-sm">
                                                            <p class="font-semibold text-marino">{{ $sug['descripcion'] }}</p>
                                                            <p class="text-xs text-slate-600">
                                                                {{ $sug['modelo'] !== '' ? 'Modelo '.$sug['modelo'].' · ' : '' }}{{ $sug['unidad'] }}
                                                                @if ($sug['costo'] !== null) · costo ${{ number_format($sug['costo'], 2) }} @endif
                                                                @if ($sug['proveedor'] !== '') · {{ $sug['proveedor'] }} @endif
                                                                · Cot. {{ $sug['folio'] }} {{ $sug['fecha'] }}{{ $sug['cliente'] !== '' ? ' · '.$sug['cliente'] : '' }}
                                                            </p>
                                                        </div>
                                                        <button type="button" wire:click="usarSugerencia({{ $i }})" class="btn-secundario shrink-0">Usar este</button>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                        @if ($eOrigen)
                                            <p class="mt-1 text-sm text-green-700">Datos de la {{ $eOrigen }}</p>
                                        @endif
                                    </div>
                                    <div>
                                        <label class="etiqueta" for="e-cant-{{ $p->id }}">Cantidad</label>
                                        <input id="e-cant-{{ $p->id }}" type="number" step="any" inputmode="decimal" wire:model="e_cantidad" class="campo">
                                        @error('e_cantidad') <p class="error-campo">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="etiqueta" for="e-uni-{{ $p->id }}">Unidad</label>
                                        <input id="e-uni-{{ $p->id }}" type="text" wire:model="e_unidad" maxlength="10" class="campo uppercase">
                                        @error('e_unidad') <p class="error-campo">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="etiqueta" for="e-mod-{{ $p->id }}">Modelo</label>
                                        <input id="e-mod-{{ $p->id }}" type="text" wire:model.live.debounce.600ms="e_modelo" autocomplete="off" class="campo">
                                    </div>
                                    <div>
                                        <label class="etiqueta" for="e-cos-{{ $p->id }}">Costo unitario</label>
                                        <input id="e-cos-{{ $p->id }}" type="number" step="any" inputmode="decimal" wire:model="e_costo" class="campo">
                                        @error('e_costo') <p class="error-campo">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="etiqueta" for="e-prov-{{ $p->id }}">Proveedor</label>
                                        <input id="e-prov-{{ $p->id }}" type="text" list="lista-proveedores-ped" wire:model="e_proveedor" autocomplete="off" @readonly($p->orden_proveedor_id) class="campo" placeholder="Escribe y elige de la lista">
                                        @error('e_proveedor') <p class="error-campo">{{ $message }}</p> @enderror
                                    </div>
                                    @if ($p->orden_proveedor_id)
                                        <p class="text-sm text-amber-800 sm:col-span-2">Ya está en una OC al proveedor: habrá que reenviarla.</p>
                                    @endif
                                    <div class="flex flex-col gap-2 sm:col-span-2 sm:flex-row">
                                        <button type="submit" class="btn-primario">Guardar cambios</button>
                                        <button type="button" wire:click="cancelarEdicion" class="btn-secundario">Cancelar</button>
                                    </div>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($partidas->isEmpty())
                    <p class="p-4 text-center text-slate-600">Este pedido no tiene partidas.</p>
                @endif

                @if ($verCostos)
                    <dl class="mt-4 grid gap-3 border-t border-slate-200 pt-4 text-base sm:grid-cols-3">
                        @if ($verVenta)
                            <div><dt class="text-sm text-slate-600">Total venta (sin IVA)</dt><dd class="text-lg font-bold text-marino">${{ number_format($totalVenta, 2) }}</dd></div>
                        @endif
                        <div><dt class="text-sm text-slate-600">Total costo</dt><dd class="text-lg font-bold">${{ number_format($totalCosto, 2) }}</dd></div>
                        @if ($verVenta)
                            <div><dt class="text-sm text-slate-600">Utilidad</dt><dd class="text-lg font-bold text-green-700">${{ number_format($totalVenta - $totalCosto, 2) }}@if ($totalVenta > 0) <span class="text-sm">({{ number_format(($totalVenta - $totalCosto) / $totalVenta * 100, 1) }}%)</span>@endif</dd></div>
                        @endif
                    </dl>
                @endif

            </section>

            {{-- ===== OC a proveedores ===== --}}
            @if ($verCostos)
                <section>
                    <h3 class="mb-2 flex items-center gap-2 text-base font-semibold text-marino"><span aria-hidden="true">🛒</span> OC a proveedores</h3>
                    @if ($ordenes->isEmpty())
                        <p class="text-sm text-slate-600">Sin OC a proveedores</p>
                    @else
                        <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200">
                            @foreach ($ordenes as $o)
                                <li class="flex flex-col gap-1 px-3 py-2 sm:flex-row sm:items-center sm:justify-between" wire:key="oc-{{ $o->id }}">
                                    <a href="{{ url('/ordenes-proveedor/'.$o->id) }}" class="font-semibold text-marino hover:underline">{{ $o->folioTexto() }} · {{ $o->proveedor->nombre ?? '' }}</a>
                                    <span class="text-sm text-slate-600">@if ($o->requiere_reenvio)<strong class="text-orange-700">Reenviar al proveedor · </strong>@endif{{ $o->estado->etiqueta() }} · ${{ number_format((float) $o->total_costo, 2) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            {{-- ===== Expediente y conciliación (plegables) ===== --}}
            {{-- Se abre solo si hay una factura por confirmar (pregunta de razón social). --}}
            {{-- wire:ignore.self: al subir o quitar un archivo la tarjeta se vuelve a pintar y el expediente se cerraba. --}}
            <details wire:ignore.self class="group rounded-lg border border-slate-200" id="expediente" @if (! $enModal || $ventasPorConfirmar->isNotEmpty()) open @endif>
                <summary class="flex min-h-12 cursor-pointer select-none flex-wrap items-center gap-2 px-4 py-2 text-base font-semibold text-marino">
                    <span aria-hidden="true">📎</span> Expediente (OC, facturas y notas, evidencias)
                    @foreach ($ventasLigadas as $v)
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $v->estatus === 'pagada' ? 'bg-green-100 text-green-800' : ($v->estatus === 'cancelada' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800') }}">{{ $v->folio }} · {{ $v->estatus === 'pagada' ? 'cobrada' : ($v->estatus === 'cancelada' ? 'cancelada' : 'pendiente') }}</span>
                    @endforeach
                    @if ($ventasPorConfirmar->isNotEmpty())
                        <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-700">{{ $ventasPorConfirmar->count() }} por confirmar</span>
                    @endif
                    <span class="ml-auto text-slate-400 group-open:rotate-90">›</span>
                </summary>
                <div class="border-t border-slate-200 p-4">
                    <livewire:pedidos.expediente :pedido-id="$pedido->id" :key="'expediente-'.$pedido->id" />
                </div>
            </details>

            <details class="group rounded-lg border border-slate-200">
                <summary class="flex min-h-12 cursor-pointer select-none items-center gap-2 px-4 text-base font-semibold text-marino">
                    <span aria-hidden="true">⇄</span> Conciliación con la OC del cliente
                    <span class="ml-auto text-slate-400 group-open:rotate-90">›</span>
                </summary>
                <div class="border-t border-slate-200 p-4">
                    <livewire:pedidos.conciliacion :pedido-id="$pedido->id" :key="'conciliacion-'.$pedido->id.'-'.md5((string) $pedido->oc_cliente_path)" />
                </div>
            </details>

            {{-- ===== Comentarios y actividad ===== --}}
            <section>
                <h3 class="mb-3 flex items-center gap-2 text-base font-semibold text-marino"><span aria-hidden="true">💬</span> Comentarios y actividad</h3>
                <form wire:submit="comentar" class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-naranja text-[11px] font-bold text-white">{{ $iniciales(auth()->user()) }}</span>
                    <div class="min-w-0 flex-1">
                        <textarea wire:model="comentario" rows="2" maxlength="5000" placeholder="Escribe un comentario…" aria-label="Escribe un comentario"
                                  x-on:keydown.ctrl.enter="$el.form.requestSubmit()" class="campo"></textarea>
                        @error('comentario') <p class="error-campo">{{ $message }}</p> @enderror
                        <button type="submit" class="btn-primario mt-2">Comentar</button>
                    </div>
                </form>

                <ol class="mt-4 space-y-3">
                    @forelse ($historial as $h)
                        <li class="flex items-start gap-3" wire:key="h-{{ $h['tipo'] }}-{{ $h['id'] }}">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $h['tipo'] === 'comentario' ? 'bg-marino' : 'bg-slate-400' }} text-[11px] font-bold text-white">{{ $iniciales($h['user']) }}</span>
                            <div class="min-w-0 flex-1">
                                @if ($h['tipo'] === 'comentario')
                                    <p class="text-sm"><strong>{{ $h['user']->name ?? 'Alguien' }}</strong>
                                        <span class="text-xs text-slate-500">{{ $h['fecha']?->timezone('America/Mexico_City')->format('d/m/Y H:i') }}</span></p>
                                    <p class="mt-1 whitespace-pre-line rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm">{{ $h['texto'] }}</p>
                                    @if ($h['puedeBorrar'])
                                        <button type="button" wire:click="borrarComentario({{ $h['id'] }})" wire:confirm="¿Borrar este comentario?" class="mt-1 text-xs text-slate-500 underline">Borrar</button>
                                    @endif
                                @else
                                    <p class="text-sm text-slate-700"><strong>{{ $h['user']->name ?? 'Sistema' }}</strong> {{ $h['texto'] }}</p>
                                    <p class="text-xs text-slate-500">{{ $h['fecha']?->timezone('America/Mexico_City')->format('d/m/Y H:i') }}</p>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="text-sm text-slate-500">Sin comentarios ni actividad todavía.</li>
                    @endforelse
                </ol>
            </section>
        </div>

        {{-- ================= Botones a la derecha (abajo en celular), agrupados ================= --}}
        <aside class="space-y-5 md:order-none">
            @if ($verVenta)
                <div class="space-y-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Hacer ahora</h3>
                    <a href="{{ url('/pedidos/'.$pedido->id.'/ordenes-proveedor/nueva') }}" class="btn-primario w-full">Generar OC a proveedores</a>
                </div>
            @endif

            @if (! $esCampo || $pedido->oc_cliente_path || $cotizaciones->isNotEmpty() || $pedido->trello_url)
            <div class="space-y-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Documentos</h3>
            @if ($pedido->oc_cliente_path)
                <a href="{{ $pedido->urlOc() }}" target="_blank" class="btn w-full border border-blue-300 bg-blue-50 text-blue-900 hover:bg-blue-100">📋 Ver OC del cliente</a>
            @endif

            @unless ($esCampo)
                <div x-data="{ subiendo: false, activo: false, aviso: '',
                        subir(file) {
                            if (!file) { this.aviso = 'Eso no es un PDF ni una imagen.'; setTimeout(() => this.aviso = '', 5000); return; }
                            this.aviso = ''; this.subiendo = true;
                            $wire.upload('archivoOc', file, () => this.subiendo = false, () => { this.subiendo = false; this.aviso = 'No se pudo subir el archivo.'; }, () => {});
                        } }"
                     tabindex="0" role="button" aria-label="Arrastra aquí el PDF de la OC, pega una captura con Ctrl+V o haz clic para elegir"
                     x-on:click="$refs.elegirOc.click()"
                     x-on:paste.prevent="const it = [...($event.clipboardData?.items || [])].find(t => t.kind === 'file'); subir(it ? it.getAsFile() : null)"
                     x-on:dragover.prevent="activo = true" x-on:dragleave="activo = false"
                     x-on:drop.prevent="activo = false; subir([...($event.dataTransfer?.files || [])][0] || null)"
                     x-bind:class="activo ? 'border-blue-600 bg-blue-100 ring-2 ring-blue-300' : 'border-blue-300 bg-blue-50/60'"
                     class="flex min-h-12 cursor-pointer flex-col justify-center rounded-lg border-2 border-dashed px-3 py-2 text-xs text-blue-900 outline-none focus:border-blue-600">
                    <span x-show="subiendo">Subiendo…</span>
                    <span x-show="!subiendo">📋 {{ $pedido->oc_cliente_path ? 'Reemplazar OC del cliente' : 'Cargar OC del cliente' }}</span>
                    <input x-ref="elegirOc" type="file" wire:model="archivoOc" accept="application/pdf,image/jpeg,image/png,image/webp" class="hidden">
                    <span x-show="aviso" x-text="aviso" class="text-red-600"></span>
                </div>
                @error('archivoOc') <p class="error-campo">{{ $message }}</p> @enderror
            @endunless

            @foreach ($cotizaciones as $c)
                <a href="{{ route('cotizaciones.editar', $c) }}" class="btn-secundario w-full">Cotización {{ $c->folio }}</a>
            @endforeach

            @if ($pedido->trello_url)
                <a href="{{ $pedido->trello_url }}" target="_blank" rel="noopener" class="btn-secundario w-full">Ver en Trello</a>
            @endif
            </div>
            @endif

            <div class="space-y-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Compartir</h3>
            <div x-data="{ copiado: false,
                    async compartir() {
                        const url = @js($enlaceTarjeta);
                        if (navigator.share) { try { await navigator.share({ title: @js($pedido->titulo()), url }); return; } catch (e) { if (e.name === 'AbortError') return; } }
                        try { await navigator.clipboard.writeText(url); } catch (e) { window.prompt('Copia el enlace:', url); return; }
                        this.copiado = true; setTimeout(() => this.copiado = false, 3000);
                    } }">
                <button type="button" x-on:click="compartir()" class="btn-secundario w-full">🔗 Compartir tarjeta</button>
                <p x-show="copiado" x-cloak class="mt-1 text-xs text-green-700" role="status">Enlace copiado.</p>
            </div>

            @if ($enModal)
                <a href="{{ url('/pedidos/'.$pedido->id) }}" class="btn-secundario w-full">Abrir en página completa</a>
            @endif
            </div>

            <div class="space-y-2 border-t border-slate-200 pt-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Cerrar el pedido</h3>
            @unless ($esCampo)
                @if ($pedido->archivado_at)
                    <button type="button" wire:click="desarchivar" class="btn-secundario w-full">Desarchivar pedido</button>
                    <p class="text-xs text-slate-500">Archivado el {{ $pedido->archivado_at->format('d/m/Y') }}</p>
                @else
                    @php
                        $pendientes = [];
                        if ($sinEntregar) { $pendientes[] = $sinEntregar.($sinEntregar === 1 ? ' partida sin entregar' : ' partidas sin entregar'); }
                        if ($sinFacturar) { $pendientes[] = $sinFacturar.($sinFacturar === 1 ? ' partida sin facturar' : ' partidas sin facturar'); }
                        $confirmaArchivo = $pendientes
                            ? 'Este pedido tiene '.implode(' y ', $pendientes).'. ¿Archivar de todas formas?'
                            : '¿Archivar este pedido? Saldrá del tablero.';
                    @endphp
                    <button type="button" wire:click="archivar" wire:confirm="{{ $confirmaArchivo }}" class="btn-secundario w-full {{ $pendientes ? '!text-red-600' : '' }}">🗄 Archivar pedido</button>
                @endif
            @endunless

            @if ($pedido->cancelado_at)
                <button type="button" wire:click="alternarCancelado" wire:confirm="¿Quitar la marca de cancelado?" class="btn-secundario w-full">Quitar cancelación</button>
                <p class="text-xs text-slate-500">Cancelado el {{ $pedido->cancelado_at->format('d/m/Y') }}</p>
            @else
                <button type="button" wire:click="alternarCancelado" wire:confirm="¿Marcar este pedido como CANCELADO por el cliente?" class="btn-secundario w-full !text-red-600">Cancelado por el cliente</button>
            @endif
            </div>
        </aside>

    </div>
</div>

@php
    $dinero = fn ($n) => '$'.number_format((float) $n, 2);
@endphp
<div class="space-y-6">

    <form wire:submit="buscarFolio" class="flex flex-col gap-2 sm:flex-row">
        <label for="buscar-folio" class="sr-only">Buscar factura o nota por folio</label>
        <input id="buscar-folio" type="search" wire:model="folio" class="campo" placeholder="Buscar por folio (ej. 5-5072 o NOTA-420)" autocomplete="off">
        <button type="submit" class="btn-primario">Buscar</button>
    </form>
    @if ($errorFolio)
        <p class="error-campo" role="alert">{{ $errorFolio }}</p>
    @endif
    @if ($encontradas)
        <ul class="divide-y divide-slate-100 rounded-xl bg-white text-sm shadow-sm">
            @foreach ($encontradas as $e)
                <li wire:key="enc-{{ $e['id'] }}"><button type="button" wire:click="verVenta({{ $e['id'] }})" class="block w-full px-4 py-2 text-left text-marino hover:bg-slate-50">{{ $e['texto'] }}</button></li>
            @endforeach
        </ul>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-sm text-slate-500">Por cobrar (con IVA)</p>
            <p class="text-2xl font-bold text-marino">{{ $dinero($cartera) }}</p>
            <p class="text-sm text-slate-500">{{ $pendientes }} {{ $pendientes === 1 ? 'documento' : 'documentos' }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-2 ring-red-200">
            <p class="text-sm text-red-700">Vencida ⚠</p>
            <p class="text-2xl font-bold text-red-700">{{ $dinero($vencida) }}</p>
            <p class="text-sm text-slate-500">{{ $vencidas }} {{ $vencidas === 1 ? 'documento' : 'documentos' }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-sm text-slate-500">Se espera en 7 días</p>
            <p class="text-2xl font-bold text-marino">{{ $dinero($siete) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-sm text-slate-500">Se espera en 14 días</p>
            <p class="text-2xl font-bold text-marino">{{ $dinero($catorce) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-sm text-slate-500">Al corriente</p>
            <p class="text-2xl font-bold text-green-700">{{ $dinero($cartera - $vencida) }}</p>
        </div>
    </div>

        @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif
    @if ($saldadas->isNotEmpty())
        <section class="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4">
            <h2 class="text-lg font-semibold text-amber-900">SICAR ya no las debe ({{ $saldadas->count() }})</h2>
            <ul class="divide-y divide-amber-200 text-sm">
                @foreach ($saldadas as $v)
                    <li wire:key="sald-{{ $v->id }}" class="flex flex-wrap justify-between gap-2 py-1.5">
                        <span><strong>{{ $v->folio }}</strong> · {{ $v->cliente_nombre }} · {{ $v->fecha->format('d/m/Y') }}</span>
                        <span class="font-medium">${{ number_format((float) $v->total, 2) }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($puedeVentas)
                <form wire:submit="marcarSaldadas" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div>
                        <label for="fecha-saldadas" class="etiqueta">Fecha en que se cobraron</label>
                        <input id="fecha-saldadas" type="date" wire:model="fechaSaldadas" class="campo">
                        @error('fechaSaldadas') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" wire:confirm="¿Marcar cobradas estas {{ $saldadas->count() }} ventas con esa fecha?" class="btn-primario">Marcarlas cobradas</button>
                </form>
            @endif
        </section>
    @endif

    <section>
        <h2 class="mb-3 text-lg font-semibold text-marino">A quién llamar hoy</h2>
        @if ($llamar->isEmpty())
            <p class="rounded-xl bg-white p-6 text-base text-slate-600 shadow-sm">No hay facturas vencidas. 🎉</p>
        @else
            <div class="hidden overflow-hidden rounded-xl bg-white shadow-sm md:block">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Cliente</th>
                            <th class="px-4 py-3">Folio</th>
                            <th class="px-4 py-3">Fecha</th>
                            <th class="px-4 py-3">Venció</th>
                            <th class="px-4 py-3 text-right">Días de atraso</th>
                            <th class="px-4 py-3 text-right">Monto</th>
                            <th class="px-4 py-3">Portal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($llamar as $x)
                            <tr wire:key="llamar-{{ $x['venta']->id }}">
                                <td class="px-4 py-3 font-medium">{{ $x['venta']->cliente?->nombre ?? $x['venta']->cliente_nombre }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    @if ($puedeVentas)
                                        <button type="button" wire:click="verVenta({{ $x['venta']->id }})" class="text-marino underline">{{ $x['venta']->folio }}</button>
                                    @else
                                        {{ $x['venta']->folio }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $x['venta']->fecha->format('d/m/Y') }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $x['vence']->format('d/m/Y') }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-red-700">{{ $x['dias'] }} d</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ $dinero($x['monto']) }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ \App\Models\Venta::PORTAL[$x['venta']->estatus_portal] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="space-y-3 md:hidden">
                @foreach ($llamar as $x)
                    <li wire:key="llamar-m-{{ $x['venta']->id }}" class="rounded-xl bg-white p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <p class="font-semibold text-marino">{{ $x['venta']->cliente?->nombre ?? $x['venta']->cliente_nombre }}</p>
                            <p class="text-base font-bold">{{ $dinero($x['monto']) }}</p>
                        </div>
                        <p class="mt-1 text-sm text-slate-600">{{ $x['venta']->folio }} · {{ $x['venta']->fecha->format('d/m/Y') }} · venció {{ $x['vence']->format('d/m/Y') }}</p>
                        <p class="mt-1 text-sm font-semibold text-red-700">{{ $x['dias'] }} días de atraso</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section>
        <h2 class="mb-1 text-lg font-semibold text-marino">Quién me debe</h2>
        @if ($clientes->isEmpty())
            <p class="rounded-xl bg-white p-6 text-base text-slate-600 shadow-sm">Nadie te debe. 🎉</p>
        @else
            @php
                $maximo = max(1.0, (float) $clientes->max('total'));
                $corto = fn ($n) => \App\Support\EscalaGrafica::corto((float) $n);
            @endphp
            <div class="rounded-xl bg-white p-4 shadow-sm">
                <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-700">
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#e34948"></span>Vencido</span>
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#2a78d6"></span>Al corriente</span>
                    <span class="text-slate-500">Toca un cliente para ver el detalle.</span>
                </div>
                <ul class="space-y-1.5" role="list" aria-label="Lo que debe cada cliente">
                    @foreach ($clientes as $c)
                        @php
                            $corriente = max(0.0, (float) $c['total'] - (float) $c['vencido']);
                            $pctV = round((float) $c['vencido'] / $maximo * 100, 2);
                            $pctC = round($corriente / $maximo * 100, 2);
                        @endphp
                        <li wire:key="barra-{{ $c['clave'] }}">
                            <button type="button" wire:click="abrirCliente(@js($c['clave']))"
                                    class="group grid w-full grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-2 rounded-lg px-1 py-1 text-left hover:bg-slate-50 focus:bg-slate-50 focus:outline-none sm:grid-cols-[minmax(0,15rem)_1fr_auto]"
                                    aria-label="{{ $c['nombre'] }}: debe {{ $dinero($c['total']) }}, vencido {{ $dinero($c['vencido']) }}"
                                    title="{{ $c['nombre'] }} · {{ $dinero($c['total']) }}{{ $c['vencido'] > 0 ? ' · vencido '.$dinero($c['vencido']) : ' · al corriente' }}">
                                <span class="truncate text-sm font-medium text-slate-800 group-hover:text-marino">{{ $c['nombre'] }}</span>
                                <span class="flex h-5 items-stretch gap-0.5 overflow-hidden" aria-hidden="true">
                                    @if ($pctV > 0)<span class="rounded-l" style="width: {{ $pctV }}%; background:#e34948; {{ $pctC <= 0 ? 'border-radius:0 4px 4px 0' : '' }}"></span>@endif
                                    @if ($pctC > 0)<span class="{{ $pctV > 0 ? '' : 'rounded-l' }} rounded-r" style="width: {{ $pctC }}%; background:#2a78d6"></span>@endif
                                </span>
                                <span class="whitespace-nowrap text-right text-sm font-semibold text-slate-800">{{ $corto($c['total']) }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>

            @php $abierto = $clienteAbierto !== '' ? $clientes->firstWhere('clave', $clienteAbierto) : null; @endphp
            @if ($abierto)
                @teleport('body')
                <div class="fixed inset-0 z-[70] flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="titulo-cliente"
                     wire:click.self="cerrarCliente" x-data x-on:keydown.escape.window="$wire.cerrarCliente()">
                    <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 id="titulo-cliente" class="text-lg font-semibold text-marino">{{ $abierto['nombre'] }}</h2>
                                <p class="text-sm text-slate-600">{{ $abierto['facturas'] }} {{ $abierto['facturas'] === 1 ? 'documento' : 'documentos' }} · crédito {{ $abierto['dias_credito'] }} días</p>
                            </div>
                            <button type="button" wire:click="cerrarCliente" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-2xl text-slate-600 hover:bg-slate-100" aria-label="Cerrar">✕</button>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <div class="rounded-lg bg-slate-50 p-3"><p class="text-sm text-slate-500">Me debe</p><p class="text-xl font-bold text-marino">{{ $dinero($abierto['total']) }}</p></div>
                            <div class="rounded-lg {{ $abierto['vencido'] > 0 ? 'bg-red-50' : 'bg-green-50' }} p-3">
                                <p class="text-sm text-slate-500">Vencido</p>
                                @if ($abierto['vencido'] > 0)
                                    <p class="text-xl font-bold text-red-700">{{ $dinero($abierto['vencido']) }}</p>
                                    <p class="text-sm text-red-700">el más atrasado {{ $abierto['mas_atrasada'] }} días</p>
                                @else
                                    <p class="text-xl font-bold text-green-700">Al corriente</p>
                                @endif
                            </div>
                        </div>
                        <ul class="mt-4 divide-y divide-slate-100">
                            @foreach ($abierto['documentos'] as $x)
                                <li wire:key="doc-{{ $x['venta']->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                    <span>
                                        @if ($puedeVentas)
                                            <button type="button" wire:click="verVenta({{ $x['venta']->id }})" class="font-semibold text-marino underline">{{ $x['venta']->folio }}</button>
                                        @else
                                            <strong>{{ $x['venta']->folio }}</strong>
                                        @endif
                                        · {{ $x['venta']->fecha->format('d/m/Y') }} · vence {{ $x['vence']->format('d/m/Y') }}
                                    </span>
                                    <span class="flex items-center gap-2">
                                        <span class="{{ $x['vencida'] ? 'font-semibold text-red-700' : 'text-slate-500' }}">{{ $x['vencida'] ? $x['dias'].' d de atraso' : 'faltan '.$x['dias'].' d' }}</span>
                                        <span class="font-semibold">{{ $dinero($x['monto']) }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                @endteleport
            @endif
        @endif
    </section>

    @if ($portal->isNotEmpty())
        <section>
            <h2 class="mb-3 text-lg font-semibold text-marino">Pendientes por estatus del portal</h2>
            <div class="flex flex-wrap gap-3">
                @foreach ($portal as $g)
                    <div wire:key="portal-{{ $g['clave'] }}" class="rounded-xl bg-white px-4 py-3 shadow-sm">
                        <p class="text-sm text-slate-500">{{ $g['etiqueta'] }}</p>
                        <p class="text-lg font-bold text-marino">{{ $dinero($g['total']) }}</p>
                        <p class="text-xs text-slate-500">{{ $g['facturas'] }} {{ $g['facturas'] === 1 ? 'documento' : 'documentos' }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
    @if ($venta)
        @teleport('body')
        <div class="fixed inset-0 z-[80] flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="titulo-venta"
             wire:click.self="cerrarVenta" x-data x-on:keydown.escape.window="$wire.cerrarVenta()">
            <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="titulo-venta" class="text-lg font-semibold text-marino">{{ $venta->tipo === 'nota' ? 'Nota de venta' : 'Factura' }} {{ $venta->folio }}</h2>
                        <p class="text-sm text-slate-600">{{ $venta->cliente?->nombre ?? $venta->cliente_nombre }} · {{ $venta->fecha->format('d/m/Y') }}</p>
                    </div>
                    <button type="button" wire:click="cerrarVenta" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-2xl text-slate-600 hover:bg-slate-100" aria-label="Cerrar">✕</button>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                    @if ($puedeVentas)
                        <div><dt class="text-xs uppercase text-slate-500">Costo</dt><dd class="font-medium">{{ $dinero($venta->costo) }}</dd></div>
                        <div><dt class="text-xs uppercase text-slate-500">Venta sin IVA</dt><dd class="font-medium">{{ $dinero($venta->subtotal) }}</dd></div>
                        <div><dt class="text-xs uppercase text-slate-500">Utilidad</dt><dd class="font-medium">{{ $dinero($venta->utilidad()) }}</dd></div>
                    @endif
                    <div><dt class="text-xs uppercase text-slate-500">Total con IVA</dt><dd class="font-semibold">{{ $dinero($venta->total) }}</dd></div>
                    <div class="col-span-2">
                        <dt class="text-xs uppercase text-slate-500">Estatus</dt>
                        <dd>@include('livewire.finanzas.partials.insignia-estatus', ['v' => $venta, 'hoy' => today()])</dd>
                    </div>
                    <div class="col-span-2"><dt class="text-xs uppercase text-slate-500">Portal del cliente</dt><dd>@include('livewire.finanzas.partials.insignia-portal', ['v' => $venta])</dd></div>
                    @if ($venta->concepto)
                        <div class="col-span-2 sm:col-span-4"><dt class="text-xs uppercase text-slate-500">Concepto</dt><dd class="whitespace-pre-line">{{ $venta->concepto }}</dd></div>
                    @endif
                    @if ($venta->comentario_sicar)
                        <div class="col-span-2 sm:col-span-4"><dt class="text-xs uppercase text-slate-500">Comentario en SICAR</dt><dd class="whitespace-pre-line text-slate-700">{{ $venta->comentario_sicar }}</dd></div>
                    @endif
                    @if ($venta->notas)
                        <div class="col-span-2 sm:col-span-4"><dt class="text-xs uppercase text-slate-500">Notas</dt><dd class="whitespace-pre-line text-slate-700">{{ $venta->notas }}</dd></div>
                    @endif
                    <div class="col-span-2 sm:col-span-4">
                        <dt class="text-xs uppercase text-slate-500">Pedidos ligados</dt>
                        <dd>
                            @forelse ($venta->pedidos as $ped)
                                <a href="{{ route('pedidos.index', ['pedido' => $ped->id]) }}" class="mr-1 inline-block rounded bg-orange-100 px-1.5 py-0.5 text-xs font-semibold text-orange-800 hover:underline">P-{{ $ped->folio }}</a>
                            @empty
                                <span class="text-slate-500">Ninguno</span>
                            @endforelse
                        </dd>
                    </div>
                    @if ($venta->reemplazadaPor->isNotEmpty() || $venta->reemplaza->isNotEmpty())
                        <div class="col-span-2 sm:col-span-4">
                            <dt class="text-xs uppercase text-slate-500">Trazabilidad</dt>
                            <dd class="text-slate-700">
                                @foreach ($venta->reemplazadaPor as $l) Reemplazada por {{ $l->destino?->folio }}. @endforeach
                                @foreach ($venta->reemplaza as $l) Reemplaza a {{ $l->origen?->folio }}. @endforeach
                            </dd>
                        </div>
                    @endif
                </dl>
                <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                    <button type="button" wire:click="cerrarVenta" class="btn-primario">Cerrar</button>
                    @if ($puedeVentas)
                        <a href="{{ route('finanzas.ventas', ['ver' => $venta->id, 'anio' => 'todos']) }}" class="btn-secundario text-center">Editar en Ventas</a>
                    @endif
                </div>
            </div>
        </div>
        @endteleport
    @endif
</div>

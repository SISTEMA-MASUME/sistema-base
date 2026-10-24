@use('App\Models\Venta')
@php
    $dinero = fn ($n) => '$'.number_format((float) $n, 2);
    $porcentaje = fn (?float $m) => $m === null ? '—' : number_format($m * 100, 1).'%';
    $hoy = today();
    $enSemana = $semanaInfo !== null;
@endphp
<div>
    {{-- Con palomitas puestas, la barra de acciones tapa el final de la página: se deja espacio. --}}
    <div class="space-y-4" :class="$wire.seleccion.length > 0 ? 'pb-56 sm:pb-28' : ''">

        @if ($esAdmin)
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                @if ($puedeImportar)
                    <a href="{{ route('finanzas.importar') }}" class="btn-secundario w-full sm:w-auto">Importar del Sheets</a>
                @endif
                <button type="button" wire:click="nueva" class="btn-primario w-full sm:w-auto">+ Nueva venta</button>
            </div>
        @endif

        @if ($ligasPorConfirmar > 0)
            <a href="{{ route('finanzas.ligas-por-confirmar') }}" class="flex flex-col gap-1 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900 sm:flex-row sm:items-center sm:justify-between">
                <span>Hay {{ $ligasPorConfirmar }} {{ $ligasPorConfirmar === 1 ? 'factura por confirmar' : 'facturas por confirmar' }} (salen a otra razón social).</span>
                <span class="font-semibold underline">Revisar</span>
            </a>
        @endif

        @php
            $depositosPendientes = \App\Models\DepositoCobro::whereIn('estatus', ['provisional', 'revisar'])->count();
        @endphp
        <a href="{{ route('finanzas.depositos') }}" class="block rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900">Depósitos provisionales: {{ $depositosPendientes }} por confirmar o revisar. <strong class="underline">Registrar o conciliar depósito</strong></a>
        @error('conciliacion')<div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-4 text-red-800">{{ $message }}</div>@enderror
        @if ($mensaje)
            <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
        @endif
        @if ($error)
            <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
        @endif

        {{-- ===== Filtros ===== --}}
        <div class="space-y-3 rounded-xl bg-white p-4 shadow-sm">
            <input type="search" wire:model.live.debounce.300ms="buscar" class="campo" placeholder="Buscar por cliente, folio o concepto" aria-label="Buscar">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                <div>
                    <label class="etiqueta" for="f-anio">Año</label>
                    <select id="f-anio" wire:model.live="anio" class="campo">
                        @foreach ($anios as $a)
                            <option value="{{ $a }}">{{ $a }}</option>
                        @endforeach
                        <option value="todos">Todos</option>
                    </select>
                </div>
                <div>
                    <label class="etiqueta" for="f-mes">Mes de la factura</label>
                    <select id="f-mes" wire:model.live="mes" class="campo" @disabled($enSemana || $todosLosAnios)>
                        <option value="">{{ $todosLosAnios ? '—' : 'Todo el año' }}</option>
                        @foreach ($meses as $n => $nombre)
                            <option value="{{ $n }}">{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="etiqueta" for="f-estatus">Estatus</label>
                    <select id="f-estatus" wire:model.live="estatus" class="campo" @disabled($enSemana)>
                        <option value="">Todos</option>
                        @foreach (Venta::ESTATUS as $clave => $texto)
                            <option value="{{ $clave }}">{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="etiqueta" for="f-portal">Portal del cliente</label>
                    <select id="f-portal" wire:model.live="portal" class="campo">
                        <option value="">Todos</option>
                        @foreach (Venta::PORTAL as $clave => $texto)
                            <option value="{{ $clave }}">{{ $texto }}</option>
                        @endforeach
                        <option value="sin">Sin estatus de portal</option>
                    </select>
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <label class="etiqueta" for="f-tipo">Tipo / revisar</label>
                    <select id="f-tipo" wire:model.live="tipo" class="campo">
                        <option value="">Todos</option>
                        @foreach (Venta::TIPOS as $clave => $texto)
                            <option value="{{ $clave }}">{{ $texto }}</option>
                        @endforeach
                        <option value="{{ \App\Livewire\Finanzas\Ventas::NOTAS_SIN_FACTURAR }}">Notas sin facturar</option>
                        <option value="{{ \App\Livewire\Finanzas\Ventas::DE_SICAR }}">Vinieron de SICAR (no estaban en el Sheets)</option>
                        <option value="{{ \App\Livewire\Finanzas\Ventas::REVISAR_SICAR }}">Revisar: pagadas aquí, con saldo en SICAR</option>
                    </select>
                </div>
                <div class="col-span-2 sm:col-span-3 lg:col-span-2">
                    <label class="etiqueta" for="f-semana">Semana de cobro</label>
                    <select id="f-semana" wire:model.live="semana" class="campo" @disabled($todosLosAnios)>
                        <option value="">{{ $todosLosAnios ? 'Elige un año' : 'Sin elegir semana' }}</option>
                        @foreach ($semanas as $n => $etiqueta)
                            <option value="{{ $n }}">{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <button type="button" wire:click="limpiar" class="text-sm font-medium text-marino underline">Limpiar filtros</button>
        </div>

        {{-- ===== ¿Qué facturas suman un depósito? (aritmética exacta, sin IA) ===== --}}
        <details class="rounded-xl bg-white p-4 shadow-sm" @if ($cuadre || $montoDeposito !== '') open @endif>
            <summary class="cursor-pointer text-base font-semibold text-marino">🧮 ¿Qué facturas suman un depósito?</summary>
            <form wire:submit="calcularDeposito" class="mt-3 grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                <div>
                    <label class="etiqueta" for="monto-deposito">Monto del depósito</label>
                    <input id="monto-deposito" type="text" inputmode="decimal" wire:model="montoDeposito" class="campo" placeholder="43477.17" autocomplete="off">
                </div>
                <label class="flex min-h-11 items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="cuadreConPagadas" class="h-5 w-5 rounded border-slate-300">
                    Incluir también las ya pagadas
                </label>
                <button type="submit" class="btn-primario" wire:loading.attr="disabled" wire:target="calcularDeposito">Calcular</button>
            </form>

            @if ($cuadre)
                @php
                    $fila = function (array $ids) use ($cuadreVentas, $dinero) {
                        return collect($ids)->map(fn ($id) => $cuadreVentas[$id] ?? null)->filter();
                    };
                    $centavos = fn ($c) => $dinero($c / 100);
                    $importe = function ($v) use ($dinero) {
                        $saldo = $v->saldo_sicar !== null ? (float) $v->saldo_sicar : null;
                        $parcial = $v->estatus === 'pendiente' && $saldo !== null && $saldo > 0.005 && $saldo < (float) $v->total - 0.005;

                        return $parcial ? 'saldo '.$dinero($saldo).' de '.$dinero($v->total) : $dinero($v->total);
                    };
                @endphp
                <div class="mt-4 space-y-3" aria-live="polite">
                    @unless ($cuadre['completa'])
                        <p class="rounded-lg bg-amber-50 p-3 text-sm font-semibold text-amber-900">Son muchas combinaciones: se muestra lo mejor que se encontró.</p>
                    @endunless

                    @foreach ($cuadre['exactas'] as $i => $combo)
                        <div wire:key="exacta-{{ $i }}" class="rounded-lg border-2 border-green-400 bg-green-50 p-3">
                            <p class="font-semibold text-green-900">✓ Opción {{ $i + 1 }}: suma exacto {{ $centavos($cuadre['objetivo']) }} ({{ count($combo) }} {{ count($combo) === 1 ? 'factura' : 'facturas' }})</p>
                            <ul class="mt-1 text-sm text-green-900">
                                @foreach ($fila($combo) as $v)
                                    <li>{{ $v->folio }} · {{ $v->fecha?->format('d/m/Y') }} · {{ $importe($v) }}@if ($v->estatus === 'pagada') <span class="text-xs">(ya pagada)</span>@endif</li>
                                @endforeach
                            </ul>
                            <button type="button" wire:click="seleccionarCuadre('exacta', {{ $i }})" class="btn-secundario mt-2 !min-h-9 !py-1 text-sm">Palomear estas</button>
                        </div>
                    @endforeach

                        @if ($cuadre['exactas'])
                            <p class="pt-1 text-sm font-semibold text-slate-700">Las más cercanas</p>
                        @else
                            <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Ninguna da exacto. Las más cercanas:</p>
                        @endif
                        @foreach (['abajo' => 'Lo más cercano por debajo', 'arriba' => 'Lo más cercano por arriba'] as $k => $titulo)
                            @if ($cuadre[$k])
                                @php $dif = $cuadre[$k]['suma'] - $cuadre['objetivo']; @endphp
                                <div wire:key="cerca-{{ $k }}" class="rounded-lg border border-slate-200 p-3">
                                    <p class="font-semibold text-marino">{{ $titulo }}: {{ $centavos($cuadre[$k]['suma']) }}
                                        <span class="{{ $dif < 0 ? 'text-amber-700' : 'text-red-700' }}">({{ $dif < 0 ? 'faltan' : 'sobran' }} {{ $centavos(abs($dif)) }})</span></p>
                                    <ul class="mt-1 text-sm text-slate-700">
                                        @foreach ($fila($cuadre[$k]['ids']) as $v)
                                            <li>{{ $v->folio }} · {{ $v->fecha?->format('d/m/Y') }} · {{ $importe($v) }}@if ($v->estatus === 'pagada') <span class="text-xs">(ya pagada)</span>@endif</li>
                                        @endforeach
                                    </ul>
                                    <button type="button" wire:click="seleccionarCuadre('{{ $k }}')" class="btn-secundario mt-2 !min-h-9 !py-1 text-sm">Palomear estas</button>
                                </div>
                            @endif
                        @endforeach
                    <button type="button" wire:click="cerrarCuadre" class="text-sm text-marino underline">Cerrar resultado</button>
                </div>
            @endif
        </details>

        {{-- ===== Semana de comisión ===== --}}
        @if ($semanaInfo)
            <section class="rounded-xl border border-naranja/40 bg-orange-50 p-4">
                <p class="text-base font-semibold text-marino">{{ $semanaInfo['etiqueta'] }}</p>
                <p class="text-sm text-slate-600">Cobradas del {{ $semanaInfo['cobros'] }}</p>
                <p class="mt-2 text-lg font-bold text-slate-900">Utilidad cobrada: {{ $dinero($semanaInfo['utilidad']) }} · Comisión 10%: {{ $dinero($semanaInfo['comision']) }}</p>
            </section>
        @endif

        {{-- ===== Totales de todo el filtro (sin canceladas) ===== --}}
        <section class="rounded-xl bg-white p-4 shadow-sm" aria-label="Totales">
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <div>
                    <dt class="text-xs uppercase text-slate-500">Documentos</dt>
                    <dd class="text-xl font-bold text-marino">{{ number_format($totales['n']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Venta sin IVA</dt>
                    <dd class="text-xl font-bold text-marino">{{ $dinero($totales['venta']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Costo</dt>
                    <dd class="text-xl font-bold text-marino">{{ $dinero($totales['costo']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Utilidad</dt>
                    <dd class="text-xl font-bold text-marino">{{ $dinero($totales['utilidad']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Margen</dt>
                    <dd class="text-xl font-bold text-marino">{{ $porcentaje($totales['margen']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Total con IVA</dt>
                    <dd class="text-xl font-bold text-marino">{{ $dinero($totales['total']) }}</dd>
                </div>
            </dl>
            @if ($totales['canceladas'] > 0)
                <p class="mt-3 text-sm text-slate-500">+ {{ $totales['canceladas'] }} {{ $totales['canceladas'] === 1 ? 'cancelada' : 'canceladas' }} fuera de los totales</p>
            @endif
        </section>

        {{-- ===== Escritorio ===== --}}
        <div class="hidden overflow-x-auto rounded-xl bg-white shadow-sm md:block">
            <table class="w-full text-[13px]">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="w-10 px-1.5 py-3">
                            <input type="checkbox" wire:click="alternarPagina" @checked($paginaCompleta) aria-label="Seleccionar las ventas de esta página" title="Seleccionar las ventas de esta página" class="h-5 w-5 rounded border-slate-300">
                        </th>
                        <th class="px-1.5 py-3">Folio</th>
                        <th class="px-1.5 py-3">Fecha</th>
                        <th class="px-1.5 py-3">Cliente / concepto</th>
                        <th class="px-1.5 py-3 text-right">Costo</th>
                        <th class="px-1.5 py-3 text-right">Venta</th>
                        <th class="px-1.5 py-3 text-right">Utilidad / %</th>
                        <th class="px-1.5 py-3 text-right">Total</th>
                        <th class="px-1.5 py-3">Estatus</th>
                        <th class="px-1.5 py-3">Portal</th>
                        <th class="px-1.5 py-3"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($ventas as $v)
                        <tr wire:key="fila-{{ $v->id }}" class="{{ $v->estatus === 'cancelada' ? 'bg-slate-50 text-slate-400' : 'hover:bg-slate-50' }}">
                            <td class="px-1.5 py-2">
                                <input type="checkbox" wire:model="seleccion" value="{{ $v->id }}" aria-label="Seleccionar la venta {{ $v->folio }}" class="h-5 w-5 rounded border-slate-300">
                            </td>
                            <td class="whitespace-nowrap px-1.5 py-2 font-semibold {{ $v->estatus === 'cancelada' ? '' : 'text-marino' }}">
                                <span class="{{ $v->estatus === 'cancelada' ? 'line-through' : '' }}">{{ $v->folio }}</span>
                                @if ($v->tipo === 'nota')
                                    <span class="block w-fit rounded bg-slate-200 px-1.5 text-xs font-semibold text-slate-700">Nota</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-1.5 py-2">{{ $v->fecha->format('d/m/Y') }}</td>
                            <td class="max-w-[10rem] px-1.5 py-2 2xl:max-w-[16rem]">
                                <span class="block truncate" title="{{ $v->cliente_nombre }}">{{ $v->cliente_nombre }}</span>
                                <span class="block truncate text-xs text-slate-500" title="{{ $v->concepto }}">{{ $v->concepto }}</span>
                                @include('livewire.finanzas.partials.insignias-trazabilidad')
                            </td>
                            <td class="whitespace-nowrap px-1.5 py-2 text-right {{ (float) $v->costo <= 0 && $v->estatus !== 'cancelada' ? 'text-amber-700' : '' }}" @if ((float) $v->costo <= 0 && $v->estatus !== 'cancelada') title="Sin costo capturado" @endif>{{ $dinero($v->costo) }}</td>
                            <td class="whitespace-nowrap px-1.5 py-2 text-right">{{ $dinero($v->subtotal) }}</td>
                            <td class="whitespace-nowrap px-1.5 py-2 text-right font-medium">
                                {{ $dinero($v->utilidad()) }}
                                <span class="block text-xs font-normal text-slate-500">{{ $porcentaje($v->margen()) }}</span>
                            </td>
                            <td class="whitespace-nowrap px-1.5 py-2 text-right">{{ $dinero($v->total) }}</td>
                            <td class="px-1.5 py-2">@include('livewire.finanzas.partials.insignia-estatus')</td>
                            <td class="px-1.5 py-2">@include('livewire.finanzas.partials.selector-portal')</td>
                            <td class="px-1.5 py-2 text-right">
                                <div class="flex justify-end gap-1">
                                    <button type="button" wire:click="verDetalle({{ $v->id }})" class="btn-secundario !min-h-8 !px-1.5 !py-1 !text-xs" aria-label="Ver el detalle de la venta {{ $v->folio }}">Ver</button>
                                    @if ($esAdmin)
                                        <button type="button" wire:click="editar({{ $v->id }})" class="btn-secundario !min-h-8 !px-1.5 !py-1 !text-xs" aria-label="Editar la venta {{ $v->folio }}">Editar</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="px-4 py-8 text-center text-slate-500">No hay ventas con esos filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ===== Celular ===== --}}
        <div class="space-y-3 md:hidden">
            @if ($ventas->count() > 0)
                <label class="flex min-h-12 items-center gap-3 rounded-xl bg-white px-4 text-base font-medium text-slate-700 shadow-sm">
                    <input type="checkbox" wire:click="alternarPagina" @checked($paginaCompleta) class="h-6 w-6 rounded border-slate-300">
                    Seleccionar las de esta página
                </label>
            @endif
            <ul class="space-y-3">
                @forelse ($ventas as $v)
                    <li wire:key="tarjeta-{{ $v->id }}" class="rounded-xl bg-white p-4 shadow-sm {{ $v->estatus === 'cancelada' ? 'opacity-60' : '' }}">
                        <div class="flex items-start gap-3">
                            <input type="checkbox" wire:model="seleccion" value="{{ $v->id }}" aria-label="Seleccionar la venta {{ $v->folio }}" class="mt-1 h-6 w-6 shrink-0 rounded border-slate-300">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-lg font-bold text-marino {{ $v->estatus === 'cancelada' ? 'line-through' : '' }}">
                                        {{ $v->folio }}
                                        @if ($v->tipo === 'nota')
                                            <span class="ml-1 inline-block rounded bg-slate-200 px-1.5 py-0.5 align-middle text-xs font-semibold text-slate-700">Nota</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 text-sm text-slate-500">{{ $v->fecha->format('d/m/Y') }}</span>
                                </div>
                                <p class="font-medium">{{ $v->cliente_nombre }}</p>
                                @if ($v->concepto)
                                    <p class="truncate text-sm text-slate-600">{{ $v->concepto }}</p>
                                @endif
                                @include('livewire.finanzas.partials.insignias-trazabilidad')
                                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                    <div class="flex justify-between gap-2"><dt class="text-slate-500">Costo</dt><dd class="{{ (float) $v->costo <= 0 && $v->estatus !== 'cancelada' ? 'text-amber-700' : '' }}">{{ $dinero($v->costo) }}</dd></div>
                                    <div class="flex justify-between gap-2"><dt class="text-slate-500">Venta</dt><dd>{{ $dinero($v->subtotal) }}</dd></div>
                                    <div class="flex justify-between gap-2"><dt class="text-slate-500">Utilidad</dt><dd class="font-medium">{{ $dinero($v->utilidad()) }} <span class="font-normal text-slate-500">({{ $porcentaje($v->margen()) }})</span></dd></div>
                                    <div class="flex justify-between gap-2"><dt class="text-slate-500">Total</dt><dd class="font-semibold">{{ $dinero($v->total) }}</dd></div>
                                </dl>
                                <div class="mt-3 flex flex-wrap items-start gap-x-4 gap-y-2">
                                    <div>@include('livewire.finanzas.partials.insignia-estatus')</div>
                                    <div>@include('livewire.finanzas.partials.selector-portal')</div>
                                </div>
                                <div class="mt-3 grid gap-2 {{ $esAdmin ? 'grid-cols-2' : '' }}">
                                    <button type="button" wire:click="verDetalle({{ $v->id }})" class="btn-secundario w-full" aria-label="Ver el detalle de la venta {{ $v->folio }}">Ver</button>
                                    @if ($esAdmin)
                                        <button type="button" wire:click="editar({{ $v->id }})" class="btn-secundario w-full" aria-label="Editar la venta {{ $v->folio }}">Editar</button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </li>
                @empty
                    <li class="rounded-xl bg-white p-6 text-center text-slate-500">No hay ventas con esos filtros.</li>
                @endforelse
            </ul>
        </div>

        {{-- Páginas (en español; el paginador de Laravel trae su texto en inglés) --}}
        @if ($ventas->hasPages())
            <nav class="grid grid-cols-2 items-center gap-3 sm:grid-cols-[auto_1fr_auto]" aria-label="Páginas de ventas">
                <p class="col-span-2 text-center text-sm text-slate-600 sm:order-2 sm:col-span-1">
                    Página {{ $ventas->currentPage() }} de {{ $ventas->lastPage() }} · {{ number_format($ventas->total()) }} ventas
                </p>
                <button type="button" wire:click="previousPage" @disabled($ventas->onFirstPage()) class="btn-secundario disabled:cursor-not-allowed disabled:opacity-40 sm:order-1">← Anterior</button>
                <button type="button" wire:click="nextPage" @disabled(! $ventas->hasMorePages()) class="btn-secundario disabled:cursor-not-allowed disabled:opacity-40 sm:order-3">Siguiente →</button>
            </nav>
        @endif
    </div>

    {{-- ===== Barra de acciones en lote (aparece al palomear; no consulta al servidor hasta que se presiona un botón) ===== --}}
    <div x-show="$wire.seleccion.length > 0" x-cloak
         class="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white p-3 shadow-[0_-4px_12px_rgba(0,0,0,0.12)] lg:left-72">
        <div class="mx-auto flex max-w-7xl flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-base font-semibold text-marino" x-text="$wire.seleccion.length + ($wire.seleccion.length === 1 ? ' seleccionada' : ' seleccionadas')"></p>
            <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                <button type="button" wire:click="abrirLote('pagar')" class="btn-primario">Marcar pagadas</button>
                <button type="button" wire:click="regresarPendiente"
                        wire:confirm="¿Regresar a pendiente las ventas seleccionadas? Se les quita la fecha de pago y la cuenta."
                        class="btn-secundario">Regresar a pendiente</button>
                <button type="button" wire:click="abrirLote('portal')" class="btn-secundario">Estatus del portal</button>
                <button type="button" wire:click="abrirLote('ligar')" class="btn-secundario">Ligar a una factura</button>
                <button type="button" @click="$wire.seleccion = []" class="btn-secundario">Quitar selección</button>
            </div>
        </div>
    </div>

    {{-- ===== Marcar pagadas ===== --}}
    @if ($panelLote === 'pagar')
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="titulo-pagar"
             x-data x-on:keydown.escape.window="$wire.cerrarLote()">
            <form wire:submit="marcarPagadas" class="max-h-full w-full max-w-lg space-y-4 overflow-y-auto rounded-t-xl bg-white p-5 shadow-xl sm:rounded-xl">
                <h2 id="titulo-pagar" class="text-lg font-semibold text-marino">Marcar como pagadas</h2>
                <div>
                    <label class="etiqueta" for="lote-fecha">Fecha en que se pagó</label>
                    <input id="lote-fecha" type="date" wire:model="fechaPago" class="campo">
                    @error('fechaPago') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="lote-cuenta">Cuenta donde se depositó</label>
                    <select id="lote-cuenta" wire:model="cuentaId" class="campo">
                        <option value="">Sin cuenta (la capturo después)</option>
                        @foreach ($cuentas as $c)
                            <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                        @endforeach
                    </select>
                    @error('cuentaId') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="lote-cuenta-nueva">Otra cuenta</label>
                    <input id="lote-cuenta-nueva" type="text" wire:model="cuentaNueva" maxlength="80" class="campo" placeholder="Ej. BBVA Empresarial" autocomplete="off">
                    @error('cuentaNueva') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" class="btn-primario" wire:loading.attr="disabled" wire:target="marcarPagadas">
                        <span wire:loading.remove wire:target="marcarPagadas">Marcar pagadas</span>
                        <span wire:loading wire:target="marcarPagadas">Guardando…</span>
                    </button>
                    <button type="button" wire:click="cerrarLote" class="btn-secundario">Cancelar</button>
                </div>
            </form>
        </div>
    @endif

    {{-- ===== Estatus del portal ===== --}}
    @if ($panelLote === 'portal')
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="titulo-portal"
             x-data x-on:keydown.escape.window="$wire.cerrarLote()">
            <form wire:submit="aplicarPortal" class="max-h-full w-full max-w-lg space-y-4 overflow-y-auto rounded-t-xl bg-white p-5 shadow-xl sm:rounded-xl">
                <h2 id="titulo-portal" class="text-lg font-semibold text-marino">Estatus del portal</h2>
                <div>
                    <label class="etiqueta" for="lote-portal">Estatus</label>
                    <select id="lote-portal" wire:model="portalLote" class="campo">
                        <option value="">Elige un estatus…</option>
                        @foreach (Venta::PORTAL as $clave => $texto)
                            <option value="{{ $clave }}">{{ $texto }}</option>
                        @endforeach
                        <option value="sin">Sin estatus (quitarlo)</option>
                    </select>
                    @error('portalLote') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" class="btn-primario" wire:loading.attr="disabled" wire:target="aplicarPortal">
                        <span wire:loading.remove wire:target="aplicarPortal">Aplicar</span>
                        <span wire:loading wire:target="aplicarPortal">Guardando…</span>
                    </button>
                    <button type="button" wire:click="cerrarLote" class="btn-secundario">Cancelar</button>
                </div>
            </form>
        </div>
    @endif

    {{-- ===== Ligar a una factura ===== --}}
    @if ($panelLote === 'ligar')
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="titulo-ligar"
             x-data x-on:keydown.escape.window="$wire.cerrarLote()">
            <form wire:submit="ligarSeleccion" class="max-h-full w-full max-w-lg space-y-4 overflow-y-auto rounded-t-xl bg-white p-5 shadow-xl sm:rounded-xl">
                <h2 id="titulo-ligar" class="text-lg font-semibold text-marino">Ligar a una factura</h2>
                <div>
                    <label class="etiqueta" for="lote-destino">Folio de la factura</label>
                    <input id="lote-destino" type="text" wire:model.live.debounce.300ms="folioDestino" maxlength="40" class="campo" placeholder="Escribe el folio o el cliente" autocomplete="off">
                    @error('folioDestino') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                </div>
                @if ($sugerencias->isNotEmpty())
                    <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200" aria-label="Facturas sugeridas">
                        @foreach ($sugerencias as $s)
                            <li wire:key="sug-{{ $s->id }}">
                                <button type="button" wire:click="elegirDestino({{ $s->id }})" class="flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-slate-50">
                                    <span class="min-w-0">
                                        <span class="block font-semibold text-marino">{{ $s->folio }} <span class="text-xs font-normal text-slate-500">{{ $s->fecha->format('d/m/Y') }}</span></span>
                                        <span class="block truncate text-xs text-slate-600">{{ $s->cliente_nombre }}</span>
                                    </span>
                                    <span class="shrink-0 text-right text-sm">
                                        {{ $dinero($s->total) }}
                                        @if ($s->mismo_cliente)
                                            <span class="block text-xs font-semibold text-green-700">Mismo cliente</span>
                                        @endif
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-slate-500">No hay facturas vigentes con ese folio o cliente.</p>
                @endif
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" class="btn-primario" wire:loading.attr="disabled" wire:target="ligarSeleccion">
                        <span wire:loading.remove wire:target="ligarSeleccion">Reemplazar por esta factura</span>
                        <span wire:loading wire:target="ligarSeleccion">Guardando…</span>
                    </button>
                    <button type="button" wire:click="cerrarLote" class="btn-secundario">Cancelar</button>
                </div>
            </form>
        </div>
    @endif

    {{-- ===== Detalle y trazabilidad (cualquiera con permiso de ventas) ===== --}}
    @if ($detalle)
        @php
            $ordenLigas = [['reemplaza', 'Reemplaza a', 'origen'], ['reemplazadaPor', 'Fue reemplazada por', 'destino']];
        @endphp
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="titulo-detalle"
             x-data x-on:keydown.escape.window="$wire.cerrarDetalle()">
            <div class="max-h-full w-full max-w-3xl space-y-5 overflow-y-auto rounded-t-xl bg-white p-5 shadow-xl sm:rounded-xl">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="titulo-detalle" class="text-lg font-semibold text-marino">
                            {{ $detalle->tipo === 'nota' ? 'Nota de venta' : 'Factura' }} {{ $detalle->folio }}
                        </h2>
                        <p class="text-sm text-slate-600">{{ $detalle->cliente_nombre }} · {{ $detalle->fecha->format('d/m/Y') }}</p>
                    </div>
                    <button type="button" wire:click="cerrarDetalle" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-2xl text-slate-600 hover:bg-slate-100" aria-label="Cerrar el detalle">✕</button>
                </div>

                @if ($mensajeDetalle)
                    <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensajeDetalle }}</div>
                @endif

                {{-- Datos principales --}}
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs uppercase text-slate-500">Costo</dt><dd class="font-medium">{{ $dinero($detalle->costo) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-500">Venta sin IVA</dt><dd class="font-medium">{{ $dinero($detalle->subtotal) }}</dd></div>
                    <div><dt class="text-xs uppercase text-slate-500">Utilidad</dt><dd class="font-medium">{{ $dinero($detalle->utilidad()) }} <span class="font-normal text-slate-500">({{ $porcentaje($detalle->margen()) }})</span></dd></div>
                    <div><dt class="text-xs uppercase text-slate-500">Total con IVA</dt><dd class="font-semibold">{{ $dinero($detalle->total) }}</dd></div>
                    <div class="col-span-2 sm:col-span-2">
                        <dt class="text-xs uppercase text-slate-500">Estatus</dt>
                        <dd>@include('livewire.finanzas.partials.insignia-estatus', ['v' => $detalle])</dd>
                    </div>
                    <div class="col-span-2 sm:col-span-2"><dt class="text-xs uppercase text-slate-500">Portal del cliente</dt><dd>@include('livewire.finanzas.partials.insignia-portal', ['v' => $detalle])</dd></div>
                    @if ($detalle->concepto)
                        <div class="col-span-2 sm:col-span-4"><dt class="text-xs uppercase text-slate-500">Concepto</dt><dd class="whitespace-pre-line">{{ $detalle->concepto }}</dd></div>
                    @endif
                    @if ($detalle->comentario_sicar)
                        <div class="col-span-2 sm:col-span-4"><dt class="text-xs uppercase text-slate-500">Comentario en SICAR</dt><dd class="whitespace-pre-line text-slate-700">{{ $detalle->comentario_sicar }}</dd></div>
                    @endif
                    @if ($detalle->notas)
                        <div class="col-span-2 sm:col-span-4"><dt class="text-xs uppercase text-slate-500">Notas</dt><dd class="whitespace-pre-line text-slate-700">{{ $detalle->notas }}</dd></div>
                    @endif
                </dl>

                {{-- ===== Trazabilidad ===== --}}
                <section class="space-y-4 border-t border-slate-200 pt-4" aria-label="Trazabilidad">
                    <h3 class="text-base font-semibold text-marino">Trazabilidad</h3>

                    @foreach ($ordenLigas as [$relacion, $titulo, $lado])
                        @if ($detalle->$relacion->isNotEmpty())
                            <div>
                                <h4 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $titulo }}</h4>
                                <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200">
                                    @foreach ($detalle->$relacion as $l)
                                        @php
                                            $otro = $l->$lado;
                                            $quien = $l->fuente === 'sicar' ? 'SICAR' : ($l->fuente === 'sheets' ? 'Sheets' : ($l->user?->name ?? 'Usuario'));
                                        @endphp
                                        <li wire:key="liga-{{ $l->id }}" class="flex flex-col gap-2 px-3 py-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div class="min-w-0 text-sm">
                                                <p class="font-semibold text-marino">
                                                    {{ $otro?->folio ?? '(documento eliminado)' }}
                                                    @if ($otro)
                                                        <span class="text-xs font-normal text-slate-500">{{ $otro->tipo === 'nota' ? 'Nota' : 'Factura' }} · {{ $otro->fecha->format('d/m/Y') }} · {{ $dinero($otro->total) }}</span>
                                                    @endif
                                                </p>
                                                <p class="text-xs text-slate-600">
                                                    @if ($relacion === 'reemplaza')
                                                        Antes estaba {{ $l->estatus_anterior ? mb_strtolower(Venta::ESTATUS[$l->estatus_anterior] ?? $l->estatus_anterior) : 'sin dato' }} ·
                                                    @endif
                                                    Ligó {{ $quien }} · {{ $l->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i') }}
                                                </p>
                                            </div>
                                            <button type="button" wire:click="deshacerLiga({{ $l->id }})"
                                                    wire:confirm="¿Deshacer esta liga? {{ $relacion === 'reemplaza' ? ($otro?->folio ?? 'El documento') : $detalle->folio }} regresa a como estaba antes de ligarse."
                                                    class="btn-secundario shrink-0 !text-red-600">Deshacer</button>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach

                    <div>
                        <h4 class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Pedidos ligados</h4>
                        @if ($detalle->pedidos->isEmpty())
                            <p class="text-sm text-slate-500">No está ligada a ningún pedido.</p>
                        @else
                            <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200">
                                @foreach ($detalle->pedidos as $p)
                                    <li wire:key="ped-{{ $p->id }}" class="flex items-center justify-between gap-3 px-3 py-2">
                                        <a href="{{ route('pedidos.index', ['pedido' => $p->id]) }}" class="font-semibold text-marino hover:underline">P-{{ $p->folio }}</a>
                                        <button type="button" wire:click="quitarPedido({{ $p->id }})" wire:confirm="¿Quitar el pedido P-{{ $p->folio }} de esta venta?" class="btn-secundario shrink-0">Quitar</button>
                                    </li>
                                @endforeach
                            </ul>
                            <x-costo-sicar-pedido :c="$costoSicar" class="mt-2" />
                        @endif
                        <form wire:submit="ligarAPedido" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                            <div class="flex-1">
                                <label class="etiqueta" for="detalle-pedido">Ligar a pedido</label>
                                <input id="detalle-pedido" type="text" wire:model="folioPedido" maxlength="20" class="campo" placeholder="P-60 o 60" autocomplete="off">
                                @error('folioPedido') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn-primario" wire:loading.attr="disabled" wire:target="ligarAPedido">Ligar</button>
                        </form>
                    </div>

                    @if ($detalle->reemplaza->isEmpty() && $detalle->reemplazadaPor->isEmpty())
                        <p class="text-sm text-slate-500">Sin documentos ligados.</p>
                    @endif
                </section>

                {{-- ===== Bitácora ===== --}}
                <section class="border-t border-slate-200 pt-4" aria-label="Historial de cambios">
                    <h3 class="mb-2 text-base font-semibold text-marino">Historial de cambios</h3>
                    @if ($cambiosDetalle->isEmpty())
                        <p class="text-sm text-slate-500">Todavía no hay cambios anotados.</p>
                    @else
                        <ul class="divide-y divide-slate-200">
                            @foreach ($cambiosDetalle as $c)
                                <li class="py-2 text-sm">
                                    <p class="text-xs text-slate-500">{{ $c['cuando'] }} · {{ $c['quien'] }}</p>
                                    <p><span class="font-semibold">{{ $c['campo'] }}:</span> <span class="text-slate-500">{{ $c['antes'] }}</span> → <span class="text-slate-900">{{ $c['despues'] }}</span></p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <div class="flex flex-col gap-2 sm:flex-row">
                    @if ($esAdmin)
                        <button type="button" wire:click="editar({{ $detalle->id }})" class="btn-primario">Editar</button>
                    @endif
                    <button type="button" wire:click="cerrarDetalle" class="btn-secundario">Cerrar</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ===== Editar o capturar una venta (solo admin) ===== --}}
    @if ($mostrarForm && $esAdmin)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="titulo-venta"
             x-data x-on:keydown.escape.window="$wire.cerrarForm()">
            <div class="max-h-full w-full max-w-3xl overflow-y-auto rounded-t-xl bg-white p-5 shadow-xl sm:rounded-xl">
                <form wire:submit="guardar" class="space-y-4">
                    @error('conciliacion')<div role="alert" class="rounded bg-red-50 p-3 text-red-800">{{ $message }}</div>@enderror
                    <div class="flex items-start justify-between gap-3">
                        <h2 id="titulo-venta" class="text-lg font-semibold text-marino">{{ $editando ? 'Editar venta '.$editando->folio : 'Nueva venta' }}</h2>
                        @if ($editando)
                            <div class="text-right">@include('livewire.finanzas.partials.insignia-estatus', ['v' => $editando])</div>
                        @endif
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label class="etiqueta" for="v-tipo">Tipo</label>
                            <select id="v-tipo" wire:model="form.tipo" class="campo">
                                @foreach (Venta::TIPOS as $clave => $texto)
                                    <option value="{{ $clave }}">{{ $texto }}</option>
                                @endforeach
                            </select>
                            @error('form.tipo') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="etiqueta" for="v-folio">Folio</label>
                            <input id="v-folio" type="text" wire:model="form.folio" maxlength="40" class="campo uppercase" placeholder="5-3317 o NOTA-298" autocomplete="off">
                            @error('form.folio') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="etiqueta" for="v-fecha">Fecha de la factura</label>
                            <input id="v-fecha" type="date" wire:model="form.fecha" class="campo">
                            @error('form.fecha') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="etiqueta" for="v-cliente">Cliente</label>
                        <input id="v-cliente" type="text" wire:model="form.cliente_nombre" list="v-lista-clientes" maxlength="255" class="campo uppercase" autocomplete="off">
                        <datalist id="v-lista-clientes">
                            @foreach ($clientes as $nombre)
                                <option value="{{ $nombre }}"></option>
                            @endforeach
                        </datalist>
                        @error('form.cliente_nombre') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="etiqueta" for="v-concepto">Concepto</label>
                        <textarea id="v-concepto" rows="2" wire:model="form.concepto" class="campo"></textarea>
                        @error('form.concepto') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
                        <div>
                            <label class="etiqueta" for="v-costo">Costo (sin IVA)</label>
                            <input id="v-costo" type="number" step="0.01" min="0" inputmode="decimal" wire:model="form.costo" class="campo">
                            @error('form.costo') <p class="error-campo">{{ $message }}</p> @enderror
                            @if ($editando && $editando->costo_sicar !== null && (float) $editando->costo_sicar !== (float) $editando->costo)
                                <p class="mt-1 text-xs text-slate-500">SICAR dio {{ $dinero($editando->costo_sicar) }}</p>
                            @endif
                        </div>
                        <div>
                            <label class="etiqueta" for="v-subtotal">Venta (sin IVA)</label>
                            <input id="v-subtotal" type="number" step="0.01" min="0" inputmode="decimal" wire:model="form.subtotal" class="campo">
                            @error('form.subtotal') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="etiqueta" for="v-iva">IVA</label>
                            <input id="v-iva" type="number" step="0.01" min="0" inputmode="decimal" wire:model="form.iva" class="campo" placeholder="Automático">
                            @error('form.iva') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="etiqueta" for="v-retencion">Retención</label>
                            <input id="v-retencion" type="number" step="0.01" min="0" inputmode="decimal" wire:model="form.retencion" class="campo">
                            @error('form.retencion') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="etiqueta" for="v-total">Total</label>
                            <input id="v-total" type="number" step="0.01" min="0" inputmode="decimal" wire:model="form.total" class="campo" placeholder="Automático">
                            @error('form.total') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm font-medium text-slate-700" x-data
                           x-text="(() => { const v = parseFloat($wire.form.subtotal) || 0; const c = parseFloat($wire.form.costo) || 0; const u = v - c; return 'Utilidad: $' + u.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (c > 0 ? ' (' + (u / c * 100).toFixed(1) + '% sobre el costo)' : ''); })()"></p>
                        <button type="button" wire:click="recalcularMontos" class="btn-secundario">Recalcular IVA y total</button>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="etiqueta" for="v-portal">Estatus del portal</label>
                            <select id="v-portal" wire:model="form.estatus_portal" class="campo">
                                <option value="">Sin estatus</option>
                                @foreach (Venta::PORTAL as $clave => $texto)
                                    <option value="{{ $clave }}">{{ $texto }}</option>
                                @endforeach
                            </select>
                            @error('form.estatus_portal') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="etiqueta" for="v-sustituta">Factura sustituta</label>
                            <input id="v-sustituta" type="text" wire:model="form.factura_sustituta" maxlength="40" class="campo" autocomplete="off">
                            @error('form.factura_sustituta') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="etiqueta" for="v-notas">Notas</label>
                        <textarea id="v-notas" rows="2" wire:model="form.notas" class="campo"></textarea>
                        @error('form.notas') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <button type="submit" class="btn-primario" wire:loading.attr="disabled" wire:target="guardar">
                            <span wire:loading.remove wire:target="guardar">Guardar</span>
                            <span wire:loading wire:target="guardar">Guardando…</span>
                        </button>
                        <button type="button" wire:click="cerrarForm" class="btn-secundario">Cancelar</button>
                        @if ($editando && $editando->estatus !== 'cancelada')
                            <button type="button" wire:click="cancelarVenta"
                                    wire:confirm="¿Cancelar la venta {{ $editando->folio }}? Ya no contará en los totales ni en las comisiones."
                                    class="btn-secundario !text-red-600 sm:ml-auto">Cancelar venta</button>
                        @endif
                    </div>
                </form>

                {{-- ===== Bitácora ===== --}}
                @if ($editando)
                    <section class="mt-6 border-t border-slate-200 pt-4" aria-label="Historial de cambios">
                        <h3 class="mb-2 text-base font-semibold text-marino">Historial de cambios</h3>
                        @if ($cambios->isEmpty())
                            <p class="text-sm text-slate-500">Todavía no hay cambios anotados.</p>
                        @else
                            <ul class="divide-y divide-slate-200">
                                @foreach ($cambios as $c)
                                    <li class="py-2 text-sm">
                                        <p class="text-xs text-slate-500">{{ $c['cuando'] }} · {{ $c['quien'] }}</p>
                                        <p><span class="font-semibold">{{ $c['campo'] }}:</span> <span class="text-slate-500">{{ $c['antes'] }}</span> → <span class="text-slate-900">{{ $c['despues'] }}</span></p>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif
            </div>
        </div>
    @endif
</div>

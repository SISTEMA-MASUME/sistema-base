@php
    $pagada = $n->pagada();
    $sinGuardar = $n->origen === 'sheets';
    $antesDelSistema = $n->hasta->lt(\Illuminate\Support\Carbon::parse($desde));
    $pctPorRepartir = $n->bolsa > 0 && abs($pctTotal - 100) > 0.009;
@endphp
<div class="space-y-4">
    {{-- ===== Semana ===== --}}
    <div class="space-y-3 rounded-xl bg-white p-4 shadow-sm">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-[8rem_1fr_auto] sm:items-end">
            <div>
                <label class="etiqueta" for="n-anio">Año</label>
                <select id="n-anio" wire:model.live="anio" class="campo">
                    @foreach ($anios as $a)
                        <option value="{{ $a }}">{{ $a }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-span-2 sm:col-span-1">
                <label class="etiqueta" for="n-semana">Semana</label>
                <select id="n-semana" wire:model.live="semana" class="campo">
                    @foreach ($semanasDelAnio as $s)
                        <option value="{{ $s }}">{{ \App\Support\Semanas::etiqueta((int) $anio, $s) }}</option>
                    @endforeach
                </select>
            </div>
            @unless ($esActual)
                <button type="button" wire:click="semanaActual" class="btn-secundario col-span-2 sm:col-span-1">Ir a la semana actual</button>
            @endunless
        </div>
    </div>

    @if ($pagada)
        <div class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">
            <strong>Pagada</strong>
            @if ($n->pagada_at) el {{ $n->pagada_at->format('d/m/Y H:i') }}@endif
            @if ($n->user) por {{ $n->user->nombreReal() }}@endif
            @if ($sinGuardar) · viene del Sheets @endif
        </div>
    @elseif ($antesDelSistema)
        <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
            Antes del {{ \Illuminate\Support\Carbon::parse($desde)->format('d/m/Y') }}: comisión pagada en el Sheets.
        </div>
    @endif

    {{-- ===== Resumen de la semana ===== --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-sm text-slate-500">Utilidad cobrada sin IVA</p>
            <p class="text-2xl font-bold text-marino">{{ $dinero($n->utilidad) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-sm text-slate-500">Comisión total (10%)</p>
            <p class="text-2xl font-bold text-naranja">{{ $dinero($n->bolsa) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-sm text-slate-500">Cobros que entran</p>
            <p class="text-2xl font-bold text-marino">{{ $cobrosN }}</p>
            @if ($cobrosN > 0)
                <button type="button" wire:click="alternarCobros" class="text-sm font-medium text-marino underline">{{ $verCobros ? 'Ocultar la lista' : 'Ver la lista' }}</button>
            @endif
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-2 ring-naranja/40">
            <p class="text-sm text-slate-500">Total de la semana</p>
            <p class="text-2xl font-bold text-marino">{{ $dinero($total) }}</p>
            <p class="text-xs text-slate-500">{{ $lineas->count() }} {{ $lineas->count() === 1 ? 'persona' : 'personas' }}</p>
        </div>
    </div>

    @if ($verCobros && $cobros->isNotEmpty())
        <section class="space-y-2 rounded-xl bg-white p-4 shadow-sm" aria-label="Cobros que entran en la semana">
            <h2 class="text-lg font-semibold text-marino">Cobros que entran en esta semana</h2>
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2">Folio</th>
                            <th class="px-3 py-2">Cliente</th>
                            <th class="px-3 py-2">Fecha de pago</th>
                            <th class="px-3 py-2 text-right">Venta</th>
                            <th class="px-3 py-2 text-right">Costo</th>
                            <th class="px-3 py-2 text-right">Utilidad</th>
                            @unless ($pagada)
                                <th class="px-3 py-2"><span class="sr-only">Quitar</span></th>
                            @endunless
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($cobros as $v)
                            <tr wire:key="cobro-{{ $v->id }}">
                                <td class="whitespace-nowrap px-3 py-2 font-medium">{{ $v->folio }}</td>
                                <td class="px-3 py-2">{{ $v->cliente_nombre }}</td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    {{ $v->fecha_pago?->format('d/m/Y') }}
                                    @if ($v->fecha_pago && $v->fecha_pago->lt($inicioCobros))
                                        <span class="ml-1 rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">cobro atrasado</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">{{ $dinero($v->subtotal) }}</td>
                                <td class="px-3 py-2 text-right">{{ $dinero($v->costo) }}</td>
                                <td class="px-3 py-2 text-right font-semibold">{{ $dinero($v->subtotal - $v->costo) }}</td>
                                @unless ($pagada)
                                    <td class="px-3 py-2 text-right">
                                        <button type="button" x-on:click="const m = prompt('¿Por qué se quita {{ $v->folio }} de la comisión? (ej. ya se comisionó la semana pasada)', 'Ya se comisionó en otra semana'); if (m !== null) $wire.quitarCobro({{ $v->id }}, m)" class="rounded bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-100">Quitar</button>
                                    </td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="space-y-2 md:hidden">
                @foreach ($cobros as $v)
                    <li wire:key="cobro-m-{{ $v->id }}" class="rounded-lg bg-slate-50 p-3">
                        <div class="flex items-start justify-between gap-3">
                            <p class="font-semibold text-marino">{{ $v->cliente_nombre }}</p>
                            <p class="font-bold">{{ $dinero($v->subtotal - $v->costo) }}</p>
                        </div>
                        <p class="text-sm text-slate-600">{{ $v->folio }} · pagó el {{ $v->fecha_pago?->format('d/m/Y') }}</p>
                        <p class="text-sm text-slate-600">Venta {{ $dinero($v->subtotal) }} · costo {{ $dinero($v->costo) }}</p>
                        @if ($v->fecha_pago && $v->fecha_pago->lt($inicioCobros))
                            <span class="mt-1 inline-block rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">cobro atrasado</span>
                        @endif
                        @unless ($pagada)
                            <button type="button" x-on:click="const m = prompt('¿Por qué se quita {{ $v->folio }} de la comisión? (ej. ya se comisionó la semana pasada)', 'Ya se comisionó en otra semana'); if (m !== null) $wire.quitarCobro({{ $v->id }}, m)" class="mt-2 min-h-10 rounded bg-red-50 px-3 text-sm font-semibold text-red-700">Quitar de la comisión</button>
                        @endunless
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @foreach (array_filter(['quitados' => $quitados, 'quitadosAntes' => $quitadosAntes], fn ($l) => $l->isNotEmpty()) as $grupo => $lista)
        {{-- Cobros que se quitaron de la comisión: se pueden regresar si fue por error. Los de semanas anteriores, plegados. --}}
        <details @if ($grupo === 'quitados') open @endif wire:key="grupo-{{ $grupo }}" class="space-y-2 rounded-xl border p-4 {{ $grupo === 'quitados' ? 'border-red-200 bg-red-50/50' : 'border-slate-200 bg-white' }}" aria-label="Cobros quitados de la comisión" data-{{ $grupo }}>
            <summary class="cursor-pointer text-base font-semibold {{ $grupo === 'quitados' ? 'text-red-900' : 'text-slate-600' }}">{{ $grupo === 'quitados' ? 'Quitados de la comisión' : 'Quitados en semanas anteriores' }} ({{ $lista->count() }})</summary>
            <ul class="divide-y divide-red-100">
                @foreach ($lista as $v)
                    <li wire:key="quitado-{{ $v->id }}" class="flex flex-col gap-2 py-2 sm:flex-row sm:items-center">
                        <div class="min-w-0 flex-1 text-sm">
                            <p><strong>{{ $v->folio }}</strong> · {{ $v->cliente_nombre }} · pagó el {{ $v->fecha_pago?->format('d/m/Y') }} · utilidad {{ $dinero($v->subtotal - $v->costo) }}</p>
                            <p class="text-xs text-slate-600">Quitó {{ $v->comisionExcluidaPor?->name ?? '—' }} el {{ $v->comision_excluida_at->format('d/m/Y H:i') }}: {{ $v->comision_excluida_motivo }}</p>
                        </div>
                        <button type="button" wire:click="regresarCobro({{ $v->id }})" wire:confirm="¿Regresar {{ $v->folio }} a la comisión?" class="btn-secundario !min-h-9 !py-1 text-sm">Regresar</button>
                    </li>
                @endforeach
            </ul>
        </details>
    @endforeach

    {{-- ===== Personas ===== --}}
    <section class="space-y-3" aria-label="Nómina por persona">
        @if ($pctPorRepartir && ! $pagada)
            <p class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900">
                Los porcentajes de esta semana suman {{ rtrim(rtrim(number_format($pctTotal, 2), '0'), '.') }}%: la comisión de la bolsa no se reparte completa.
            </p>
        @endif

        <div class="hidden grid-cols-[1.3fr_1fr_.8fr_1.1fr_1.5fr_1.3fr_1fr] gap-3 px-4 text-xs font-semibold uppercase text-slate-500 lg:grid">
            <span>Persona</span><span>Sueldo base</span><span>% comisión</span><span>Comisión</span><span>Bono (+) o descuento (−)</span><span>Descuento de préstamo</span><span class="text-right">Total</span>
        </div>

        @forelse ($lineas as $l)
            @php
                $saldo = $saldos[$l->user_id] ?? 0;
                $sugerido = (! $pagada && $l->user_id) ? \App\Support\CalculoNomina::descuentoSugerido($l->user_id) : 0;
                $puedeQuitar = true;
            @endphp
            <article wire:key="linea-{{ $l->id }}" class="grid grid-cols-2 gap-3 rounded-xl bg-white p-4 shadow-sm lg:grid-cols-[1.3fr_1fr_.8fr_1.1fr_1.5fr_1.3fr_1fr] lg:items-start">
                <div class="col-span-2 flex items-start justify-between gap-2 lg:col-span-1 lg:block">
                    <div>
                        <p class="text-lg font-semibold text-marino">{{ $l->nombre }}</p>
                        @if ($l->notas && $pagada)
                            <p class="text-sm text-slate-600">{{ $l->notas }}</p>
                        @endif
                        @if ($pagada)
                            @if ($l->cuenta)
                                <p class="text-sm text-slate-600">Se pagó desde {{ $l->cuenta->nombre }}</p>
                            @endif
                        @else
                            <label class="sr-only" for="cu-{{ $l->id }}">Se le paga desde</label>
                            <select id="cu-{{ $l->id }}" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-2 py-1 text-sm"
                                    wire:key="cu-{{ $l->id }}-{{ $version }}" wire:change="guardarCuentaLinea({{ $l->id }}, $event.target.value)" aria-label="Cuenta de la que se le paga a {{ $l->nombre }}">
                                <option value="" @selected(! $l->cuenta_id)>Desde {{ ($l->user?->cuentaNomina ?? $cuentaGeneral)?->nombre ?? 'la cuenta general' }}</option>
                                @foreach ($cuentasPago as $c)
                                    <option value="{{ $c->id }}" @selected($l->cuenta_id === $c->id)>Desde {{ $c->nombre }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    @if (! $pagada && $puedeQuitar)
                        <button type="button" wire:click="quitarLinea({{ $l->id }})" wire:confirm="¿Quitar a {{ $l->nombre }} de la nómina de esta semana?"
                                class="shrink-0 text-sm font-medium text-red-700 underline lg:mt-1">Quitar</button>
                    @endif
                </div>

                @if ($pagada)
                    <div><span class="etiqueta lg:hidden">Sueldo base</span><p class="py-2">{{ $dinero($l->sueldo_base) }}</p></div>
                    <div><span class="etiqueta lg:hidden">% comisión</span><p class="py-2">{{ rtrim(rtrim(number_format((float) $l->comision_pct, 2), '0'), '.') }}%</p></div>
                    <div><span class="etiqueta lg:hidden">Comisión</span><p class="py-2">{{ $dinero($l->comision) }}</p></div>
                    <div><span class="etiqueta lg:hidden">Bono o descuento</span><p class="py-2">{{ $dinero($l->ajuste) }}</p></div>
                    <div><span class="etiqueta lg:hidden">Descuento de préstamo</span><p class="py-2">{{ $dinero($l->descuento_prestamo) }}</p></div>
                @else
                    <div>
                        <label class="etiqueta lg:hidden" for="sb-{{ $l->id }}">Sueldo base</label>
                        <input id="sb-{{ $l->id }}" type="number" step="0.01" min="0" inputmode="decimal" value="{{ $l->sueldo_base }}" class="campo"
                               wire:key="sb-{{ $l->id }}-{{ $version }}" wire:change="guardarLinea({{ $l->id }}, 'sueldo_base', $event.target.value)" aria-label="Sueldo base de {{ $l->nombre }}">
                    </div>
                    <div>
                        <label class="etiqueta lg:hidden" for="pc-{{ $l->id }}">% comisión</label>
                        <input id="pc-{{ $l->id }}" type="number" step="0.01" min="0" max="100" inputmode="decimal" value="{{ $l->comision_pct }}" class="campo"
                               wire:key="pc-{{ $l->id }}-{{ $version }}" wire:change="guardarLinea({{ $l->id }}, 'comision_pct', $event.target.value)" aria-label="Porcentaje de comisión de {{ $l->nombre }}">
                    </div>
                    <div>
                        <label class="etiqueta lg:hidden" for="co-{{ $l->id }}">Comisión</label>
                        <input id="co-{{ $l->id }}" type="number" step="0.01" min="0" inputmode="decimal" value="{{ $l->comision }}" class="campo {{ $l->comision_manual ? 'border-naranja bg-orange-50' : '' }}"
                               wire:key="co-{{ $l->id }}-{{ $l->comision }}-{{ $version }}" wire:change="guardarLinea({{ $l->id }}, 'comision', $event.target.value)" aria-label="Comisión de {{ $l->nombre }}">
                        @if ($l->comision_manual)
                            <button type="button" wire:click="volverACalcular({{ $l->id }})" class="mt-1 text-xs font-medium text-marino underline">Escrita a mano · volver a calcular</button>
                        @endif
                    </div>
                    <div class="col-span-2 space-y-1 lg:col-span-1">
                        <label class="etiqueta lg:hidden" for="aj-{{ $l->id }}">Bono (+) o descuento (−)</label>
                        <input id="aj-{{ $l->id }}" type="number" step="0.01" inputmode="decimal" value="{{ (float) $l->ajuste === 0.0 ? '' : $l->ajuste }}" placeholder="0.00" class="campo"
                               wire:key="aj-{{ $l->id }}-{{ $version }}" wire:change="guardarLinea({{ $l->id }}, 'ajuste', $event.target.value)" aria-label="Bono o descuento de {{ $l->nombre }}">
                        <input type="text" maxlength="500" value="{{ $l->notas }}" placeholder="Nota (por qué)" class="campo"
                               wire:key="no-{{ $l->id }}-{{ $version }}" wire:change="guardarLinea({{ $l->id }}, 'notas', $event.target.value)" aria-label="Nota de {{ $l->nombre }}">
                    </div>
                    <div class="col-span-2 lg:col-span-1">
                        <label class="etiqueta lg:hidden" for="dp-{{ $l->id }}">Descuento de préstamo</label>
                        <input id="dp-{{ $l->id }}" type="number" step="0.01" min="0" inputmode="decimal" value="{{ (float) $l->descuento_prestamo === 0.0 ? '' : $l->descuento_prestamo }}" placeholder="0.00" class="campo"
                               wire:key="dp-{{ $l->id }}-{{ $version }}" wire:change="guardarLinea({{ $l->id }}, 'descuento_prestamo', $event.target.value)" aria-label="Descuento de préstamo de {{ $l->nombre }}">
                        @if ($saldo > 0)
                            <p class="mt-1 text-xs text-slate-600">Debe {{ $dinero($saldo) }}</p>
                            @if (abs($sugerido - (float) $l->descuento_prestamo) > 0.004)
                                <button type="button" wire:click="usarDescuentoSugerido({{ $l->id }})" class="text-xs font-medium text-marino underline">Sugerido {{ $dinero($sugerido) }} · usar</button>
                            @endif
                        @endif
                    </div>
                @endif

                <div class="col-span-2 flex items-center justify-between border-t border-slate-200 pt-2 lg:col-span-1 lg:block lg:border-0 lg:pt-0 lg:text-right">
                    <span class="text-sm text-slate-500 lg:hidden">Total</span>
                    <p class="text-xl font-bold text-marino lg:py-2">{{ $dinero($l->total) }}</p>
                </div>
            </article>
        @empty
            <p class="rounded-xl bg-white p-6 text-base text-slate-600 shadow-sm">Nadie en la nómina</p>
        @endforelse
    </section>

    @if (! $pagada && $disponibles->isNotEmpty())
        <div class="flex flex-col gap-2 rounded-xl bg-white p-4 shadow-sm sm:flex-row sm:items-end">
            <div class="sm:w-72">
                <label class="etiqueta" for="n-agregar">Agregar persona a esta semana</label>
                <select id="n-agregar" wire:model="agregarUsuario" class="campo">
                    <option value="">Elige a quién…</option>
                    @foreach ($disponibles as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button" wire:click="agregarPersona" class="btn-secundario">Agregar persona</button>
        </div>
    @endif

    {{-- ===== Total y pago ===== --}}
    <section class="space-y-3 rounded-xl bg-white p-4 shadow-sm">
        <p class="text-xl font-bold text-marino">Total de la semana {{ $n->semana }}: {{ $dinero($total) }}</p>
        @if (! $pagada && $lineas->isNotEmpty() && $cuentasPago->isNotEmpty())
            <div class="space-y-2" data-pagar-desde>
                <p class="text-base font-semibold text-slate-700">¿De qué cuenta pagas esta semana?</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($cuentasPago as $c)
                        @php($ultimo = $saldosCuentas[$c->id] ?? null)
                        <button type="button" wire:click="pagarTodosDesde({{ $c->id }})" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-left hover:border-marino hover:bg-slate-50">
                            <span class="block font-semibold text-marino">Todos desde {{ $c->nombre }}</span>
                            @if ($ultimo)
                                <span class="block text-xs text-slate-600">tenía {{ $dinero($ultimo->saldo) }} al {{ $ultimo->corte->fecha->format('d/m') }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
        @if ($porCuenta->isNotEmpty())
            <div class="space-y-1" data-por-cuenta>
                <p class="text-sm font-semibold text-slate-600">{{ $pagada ? 'Salió de:' : 'Sale de:' }}</p>
                @foreach ($porCuenta as $g)
                    <p class="flex flex-wrap justify-between gap-x-3 rounded-lg bg-slate-50 px-3 py-2 text-base">
                        <span><strong>{{ $g['cuenta']?->nombre ?? 'Cuenta general' }}</strong> <span class="text-sm text-slate-600">· {{ implode(', ', $g['nombres']) }}</span></span>
                        <strong class="text-marino">{{ $dinero($g['monto']) }}</strong>
                    </p>
                @endforeach
            </div>
        @endif
        @if (! $pagada)
            <button type="button" wire:click="registrarPago" wire:loading.attr="disabled" wire:target="registrarPago"
                    wire:confirm="¿Registrar el pago de la semana {{ $n->semana }} por {{ $dinero($total) }}? ({{ $porCuenta->map(fn ($g) => ($g['cuenta']?->nombre ?? 'Cuenta general').' '.$dinero($g['monto']))->implode(' · ') }}) Después ya no se podrá cambiar (solo el admin la puede reabrir)."
                    class="btn-primario w-full text-lg sm:w-auto">Registrar pago</button>
        @elseif ($esAdmin && ! $sinGuardar)
            <button type="button" wire:click="reabrirSemana" wire:confirm="¿Reabrir la semana {{ $n->semana }}? Se sueltan las ventas comisionadas y se quitan los descuentos de préstamo que hizo."
                    class="btn-secundario">Reabrir semana</button>
        @endif
    </section>
</div>

@php
    $dinero = fn ($v) => '$'.number_format((float) $v, 2);
    $mesesNombre = \App\Livewire\Finanzas\Nomina::MESES;
    $anioElegido = $histAnio === 'todos' ? 'todos los años' : $histAnio;
@endphp
<div class="space-y-4">
    <div class="space-y-3 rounded-xl bg-white p-4 shadow-sm">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-[10rem_12rem]">
            <div>
                <label class="etiqueta" for="h-anio">Año</label>
                <select id="h-anio" wire:model.live="histAnio" class="campo">
                    @foreach (collect($aniosHistorial)->push((int) today()->year)->unique()->sortDesc() as $a)
                        <option value="{{ $a }}">{{ $a }}</option>
                    @endforeach
                    <option value="todos">Todos</option>
                </select>
            </div>
            <div>
                <label class="etiqueta" for="h-mes">Mes</label>
                <select id="h-mes" wire:model.live="histMes" class="campo">
                    <option value="">Todo el año</option>
                    @foreach ($mesesNombre as $num => $nombre)
                        <option value="{{ $num }}">{{ $nombre }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- ===== Cuánto le pagué a cada quien ===== --}}
    <section class="space-y-3 rounded-xl bg-white p-4 shadow-sm" aria-label="Cuánto le pagué a cada quien">
        <h2 class="text-lg font-semibold text-marino">
            Cuánto le pagué a cada quien
            <span class="text-base font-normal text-slate-600">({{ $histMes !== '' ? $mesesNombre[(int) $histMes].' de ' : '' }}{{ $anioElegido }})</span>
        </h2>
        @if ($personas->isEmpty())
            <p class="text-base text-slate-600">Todavía no hay pagos registrados en este periodo.</p>
        @else
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2">Persona</th>
                            <th class="px-3 py-2 text-right">Sueldo</th>
                            <th class="px-3 py-2 text-right">Comisiones</th>
                            <th class="px-3 py-2 text-right">Reparto del socio</th>
                            <th class="px-3 py-2 text-right">Bonos y descuentos</th>
                            <th class="px-3 py-2 text-right">Total pagado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($personas as $p)
                            <tr wire:key="persona-{{ $loop->index }}">
                                <td class="px-3 py-2 font-medium">{{ $p['nombre'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $dinero($p['sueldo']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $dinero($p['comisiones']) }}</td>
                                <td class="px-3 py-2 text-right">{{ $dinero($p['reparto']) }}</td>
                                <td class="px-3 py-2 text-right {{ $p['bonos'] < 0 ? 'text-red-700' : '' }}">{{ $dinero($p['bonos']) }}</td>
                                <td class="px-3 py-2 text-right font-bold text-marino">{{ $dinero($p['total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-300 font-bold">
                            <td class="px-3 py-2">Total</td>
                            <td class="px-3 py-2 text-right">{{ $dinero($personas->sum('sueldo')) }}</td>
                            <td class="px-3 py-2 text-right">{{ $dinero($personas->sum('comisiones')) }}</td>
                            <td class="px-3 py-2 text-right">{{ $dinero($personas->sum('reparto')) }}</td>
                            <td class="px-3 py-2 text-right">{{ $dinero($personas->sum('bonos')) }}</td>
                            <td class="px-3 py-2 text-right text-marino">{{ $dinero($totalPagado) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <ul class="space-y-2 md:hidden">
                @foreach ($personas as $p)
                    <li wire:key="persona-m-{{ $loop->index }}" class="rounded-lg bg-slate-50 p-3">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="font-semibold text-marino">{{ $p['nombre'] }}</p>
                            <p class="text-lg font-bold">{{ $dinero($p['total']) }}</p>
                        </div>
                        <dl class="mt-1 grid grid-cols-[1fr_auto] gap-x-3 text-sm text-slate-600">
                            <dt>Sueldo</dt><dd class="text-right">{{ $dinero($p['sueldo']) }}</dd>
                            <dt>Comisiones</dt><dd class="text-right">{{ $dinero($p['comisiones']) }}</dd>
                            @if ($p['reparto'] != 0)
                                <dt>Reparto del socio</dt><dd class="text-right">{{ $dinero($p['reparto']) }}</dd>
                            @endif
                            <dt>Bonos y descuentos</dt><dd class="text-right {{ $p['bonos'] < 0 ? 'text-red-700' : '' }}">{{ $dinero($p['bonos']) }}</dd>
                        </dl>
                    </li>
                @endforeach
                <li class="flex justify-between rounded-lg bg-marino px-3 py-2 font-bold text-white"><span>Total</span><span>{{ $dinero($totalPagado) }}</span></li>
            </ul>
        @endif
    </section>

    {{-- ===== Nóminas pagadas ===== --}}
    <section class="space-y-3" aria-label="Nóminas pagadas">
        <h2 class="text-lg font-semibold text-marino">Nóminas pagadas</h2>
        @forelse ($nominas as $h)
            @php
                $abierto = $abierta === $h->id;
                $etiqueta = $h->tipo === 'reparto'
                    ? 'Reparto del socio · '.$mesesNombre[(int) $h->mes].' '.$h->anio
                    : \App\Support\Semanas::etiqueta($h->anio, (int) $h->semana);
            @endphp
            <article wire:key="nomina-{{ $h->id }}" class="rounded-xl bg-white shadow-sm">
                <button type="button" wire:click="alternarNomina({{ $h->id }})" aria-expanded="{{ $abierto ? 'true' : 'false' }}"
                        class="flex w-full flex-wrap items-center justify-between gap-x-4 gap-y-1 p-4 text-left">
                    <span>
                        <span class="block text-base font-semibold text-marino">{{ $etiqueta }}</span>
                        <span class="block text-sm text-slate-600">
                            Pagada el {{ $h->pagada_at?->format('d/m/Y') ?? $h->hasta->format('d/m/Y') }}
                            @if ($h->user) por {{ $h->user->nombreReal() }}@endif
                            @if ($h->origen === 'sheets') · del Sheets @endif
                            · {{ $h->lineas->count() }} {{ $h->lineas->count() === 1 ? 'persona' : 'personas' }}
                        </span>
                    </span>
                    <span class="text-xl font-bold text-marino">{{ $dinero($h->lineas->sum('total')) }} <span class="text-sm text-slate-500">{{ $abierto ? '▲' : '▼' }}</span></span>
                </button>
                @if ($abierto)
                    <div class="space-y-2 border-t border-slate-200 p-4">
                        @if ($h->tipo === 'semanal' && $h->origen !== 'sheets')
                            <p class="text-sm text-slate-600">Utilidad cobrada {{ $dinero($h->utilidad) }} · comisión total (10%) {{ $dinero($h->bolsa) }}</p>
                        @endif
                        <div class="hidden overflow-x-auto md:block">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                    <tr>
                                        <th class="px-3 py-2">Persona</th>
                                        <th class="px-3 py-2 text-right">Sueldo base</th>
                                        <th class="px-3 py-2 text-right">Comisión</th>
                                        <th class="px-3 py-2 text-right">Bono / descuento</th>
                                        <th class="px-3 py-2 text-right">Préstamo</th>
                                        <th class="px-3 py-2 text-right">Total</th>
                                        <th class="px-3 py-2">Nota</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    @foreach ($h->lineas as $l)
                                        <tr wire:key="hl-{{ $l->id }}">
                                            <td class="px-3 py-2 font-medium">{{ $l->nombre }}</td>
                                            <td class="px-3 py-2 text-right">{{ $dinero($l->sueldo_base) }}</td>
                                            <td class="px-3 py-2 text-right {{ $l->comision < 0 ? 'text-red-700' : '' }}">{{ $dinero($l->comision) }}</td>
                                            <td class="px-3 py-2 text-right">{{ $dinero($l->ajuste) }}</td>
                                            <td class="px-3 py-2 text-right">{{ $dinero($l->descuento_prestamo) }}</td>
                                            <td class="px-3 py-2 text-right font-bold">{{ $dinero($l->total) }}</td>
                                            <td class="px-3 py-2 text-slate-600">{{ $l->notas }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <ul class="space-y-2 md:hidden">
                            @foreach ($h->lineas as $l)
                                <li wire:key="hl-m-{{ $l->id }}" class="rounded-lg bg-slate-50 p-3">
                                    <div class="flex items-baseline justify-between gap-3">
                                        <p class="font-semibold">{{ $l->nombre }}</p>
                                        <p class="font-bold">{{ $dinero($l->total) }}</p>
                                    </div>
                                    <p class="text-sm text-slate-600">
                                        Base {{ $dinero($l->sueldo_base) }} · comisión {{ $dinero($l->comision) }}
                                        @if ((float) $l->ajuste != 0) · bono/desc. {{ $dinero($l->ajuste) }}@endif
                                        @if ((float) $l->descuento_prestamo != 0) · préstamo {{ $dinero($l->descuento_prestamo) }}@endif
                                    </p>
                                    @if ($l->notas)<p class="text-sm text-slate-600">{{ $l->notas }}</p>@endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($h->tipo === 'semanal' && $h->origen !== 'sheets')
                            <a href="{{ route('finanzas.nomina', ['ver' => 'semana', 'anio' => $h->anio, 'semana' => $h->semana]) }}" class="inline-block text-sm font-medium text-marino underline">Abrir en la pestaña Semana</a>
                        @endif
                    </div>
                @endif
            </article>
        @empty
            <p class="rounded-xl bg-white p-6 text-base text-slate-600 shadow-sm">No hay nóminas pagadas en este periodo.</p>
        @endforelse
    </section>
</div>

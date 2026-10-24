@php
    $dinero = fn ($v) => '$'.number_format((float) $v, 2);
    $pagada = $rep->pagada();
    $sheets = $rep->origen === 'sheets';
    $mesesNombre = \App\Livewire\Finanzas\Nomina::MESES;
    $pct = \App\Support\CalculoNomina::SOCIO_PCT;
@endphp
<div class="space-y-4">
    <div class="space-y-3 rounded-xl bg-white p-4 shadow-sm">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-[12rem_8rem]">
            <div>
                <label class="etiqueta" for="r-mes">Mes</label>
                <select id="r-mes" wire:model.live="repMes" class="campo">
                    @foreach ($mesesNombre as $num => $nombre)
                        <option value="{{ $num }}">{{ $nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="etiqueta" for="r-anio">Año</label>
                <select id="r-anio" wire:model.live="repAnio" class="campo">
                    @foreach ($aniosReparto as $a)
                        <option value="{{ $a }}">{{ $a }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    @if ($pagada)
        <div class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">
            <strong>Pagado</strong>
            @if ($rep->pagada_at) el {{ $rep->pagada_at->format('d/m/Y H:i') }}@endif
            @if ($rep->user) por {{ $rep->user->nombreReal() }}@endif
            @if ($sheets) · viene del Sheets @endif
            · todo está en solo lectura.
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-sm text-slate-500">Utilidad bruta cobrada</p>
            <p class="text-2xl font-bold text-marino">{{ $sheets ? '—' : $dinero($rep->utilidad) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-sm text-slate-500">Gastos MASUME del mes</p>
            @if ($pagada)
                <p class="text-2xl font-bold text-marino">{{ $sheets ? '—' : $dinero($rep->gastos) }}</p>
            @else
                <input type="number" step="0.01" min="0" inputmode="decimal" value="{{ (float) $rep->gastos === 0.0 ? '' : $rep->gastos }}" placeholder="0.00" class="campo mt-1"
                       wire:key="gastos-{{ $repAnio }}-{{ $repMes }}-{{ $version }}" wire:change="guardarGastos($event.target.value)" aria-label="Gastos MASUME del mes">
                @if (Route::has('finanzas.gastos') && auth()->user()->puede('gastos'))
                    <p class="mt-1 text-xs text-slate-600">En <a href="{{ route('finanzas.gastos', ['anio' => $repAnio, 'mes' => $repMes, 'de' => 'masume', 'tipo' => 'gasto']) }}" class="font-medium text-marino underline">Gastos</a>: <strong>{{ $dinero($gastosModulo) }}</strong></p>
                @else
                    <p class="mt-1 text-xs text-slate-600">En Gastos: <strong>{{ $dinero($gastosModulo) }}</strong></p>
                @endif
                @if (round($gastosModulo, 2) != round((float) $rep->gastos, 2))
                    <button type="button" wire:click="usarGastosDelModulo" class="btn-secundario mt-2 w-full !min-h-9 !text-sm">Usar este monto</button>
                @endif
            @endif
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-sm text-slate-500">Utilidad neta</p>
            <p class="text-2xl font-bold text-marino">{{ $sheets ? '—' : $dinero($neta) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            <p class="text-sm text-slate-500">Capital retenido ({{ 100 - $pct }}%)</p>
            <p class="text-2xl font-bold text-green-700">{{ $sheets ? '—' : $dinero($retenido) }}</p>
        </div>
    </div>

    <section class="space-y-3 rounded-xl bg-white p-4 shadow-sm ring-2 ring-naranja/40">
        <h2 class="text-lg font-semibold text-marino">{{ $repLinea?->nombre ?? 'Socio' }}</h2>
        @if (! $sheets)
            <p class="text-sm text-slate-600">{{ $pct }}% de la utilidad neta: <strong>{{ $dinero($rep->bolsa) }}</strong></p>
        @endif
        <div class="max-w-xs">
            <label class="etiqueta" for="r-monto">Monto final del reparto</label>
            @if ($pagada || ! $repLinea)
                <p class="text-2xl font-bold text-marino">{{ $dinero($repLinea?->total ?? 0) }}</p>
            @else
                <input id="r-monto" type="number" step="0.01" min="0" inputmode="decimal" value="{{ $repLinea->comision }}" class="campo {{ $repLinea->comision_manual ? 'border-naranja bg-orange-50' : '' }}"
                       wire:key="monto-{{ $repAnio }}-{{ $repMes }}-{{ $repLinea->comision }}-{{ $version }}" wire:change="guardarMontoReparto($event.target.value)">
                @if ($repLinea->comision_manual)
                    <button type="button" wire:click="volverACalcularReparto" class="mt-1 text-xs font-medium text-marino underline">Escrito a mano · volver a calcular</button>
                @endif
            @endif
        </div>
        @if ($repLinea && ! $sheets && (! $pagada || $repLinea->cuenta))
            <div class="max-w-xs">
                @if ($pagada)
                    <p class="text-sm text-slate-600">Se pagó desde {{ $repLinea->cuenta->nombre }}</p>
                @else
                    <label class="etiqueta" for="r-cuenta">Se le paga desde</label>
                    <select id="r-cuenta" class="campo" wire:key="rcu-{{ $repLinea->id }}-{{ $version }}" wire:change="guardarCuentaLinea({{ $repLinea->id }}, $event.target.value)">
                        <option value="" @selected(! $repLinea->cuenta_id)>{{ ($repLinea->user?->cuentaNomina ?? $cuentaGeneral)?->nombre ?? 'La cuenta general' }}</option>
                        @foreach ($cuentasPago as $c)
                            <option value="{{ $c->id }}" @selected($repLinea->cuenta_id === $c->id)>{{ $c->nombre }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        @endif
        @if ($repLinea?->notas)
            <p class="text-sm text-slate-600">{{ $repLinea->notas }}</p>
        @endif
        @if ($sheets && $rep->notas)
            <p class="text-sm text-slate-600">{{ $rep->notas }}</p>
        @endif

        @if (! $pagada)
            <button type="button" wire:click="registrarReparto" wire:loading.attr="disabled" wire:target="registrarReparto"
                    wire:confirm="¿Registrar el reparto de {{ $mesesNombre[$repMes] }} {{ $repAnio }} por {{ $dinero($repLinea?->total ?? 0) }}? Después ya no se podrá cambiar (solo el admin lo puede reabrir)."
                    class="btn-primario w-full text-lg sm:w-auto">Registrar pago</button>
        @elseif ($esAdmin && ! $sheets)
            <button type="button" wire:click="reabrirReparto" wire:confirm="¿Reabrir el reparto de {{ $mesesNombre[$repMes] }} {{ $repAnio }}?" class="btn-secundario">Reabrir</button>
        @endif
    </section>
</div>

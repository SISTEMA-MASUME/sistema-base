@php
    $p = $x['p'];
@endphp
<article wire:key="prestamo-{{ $p->id }}" class="space-y-2 rounded-lg {{ $saldado ? 'bg-slate-50' : 'border border-slate-200' }} p-3">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <p class="text-base font-semibold">Préstamo de {{ $dinero($p->monto) }} <span class="font-normal text-slate-600">del {{ $p->fecha->format('d/m/Y') }}</span></p>
        <p class="text-base font-bold {{ $saldado ? 'text-green-700' : 'text-naranja' }}">{{ $saldado ? 'Saldado el '.$p->saldado_at->format('d/m/Y') : 'Saldo '.$dinero($x['saldo']) }}</p>
    </div>
    @if ($p->notas)
        <p class="text-sm text-slate-600">{{ $p->notas }}</p>
    @endif

    @unless ($saldado)
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div class="sm:w-56">
                <label class="etiqueta" for="ds-{{ $p->id }}">Descuento semanal</label>
                <input id="ds-{{ $p->id }}" type="number" step="0.01" min="0" inputmode="decimal" value="{{ $p->descuento_semanal }}" class="campo"
                       wire:key="ds-{{ $p->id }}-{{ $version }}" wire:change="guardarDescuentoSemanal({{ $p->id }}, $event.target.value)">
            </div>
            <button type="button" wire:click="abrirAbono({{ $p->id }})" class="btn-secundario">Abono suelto</button>
        </div>
    @endunless

    @if ($abonoPara === $p->id && ! $saldado)
        <form wire:submit="guardarAbono" class="space-y-2 rounded-lg bg-orange-50 p-3" aria-label="Abono suelto">
            <p class="text-sm font-semibold text-marino">Abono fuera de nómina</p>
            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <label class="etiqueta" for="am-{{ $p->id }}">Monto</label>
                    <input id="am-{{ $p->id }}" type="number" step="0.01" min="0" inputmode="decimal" wire:model="aMonto" class="campo">
                    @error('aMonto') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="af-{{ $p->id }}">Fecha</label>
                    <input id="af-{{ $p->id }}" type="date" wire:model="aFecha" class="campo">
                    @error('aFecha') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="an-{{ $p->id }}">Nota</label>
                    <input id="an-{{ $p->id }}" type="text" maxlength="255" wire:model="aNota" class="campo">
                    @error('aNota') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="submit" class="btn-primario">Guardar abono</button>
                <button type="button" wire:click="cancelarPrestamo" class="btn-secundario">Cancelar</button>
            </div>
        </form>
    @endif

    @if ($p->abonos->isNotEmpty())
        <details class="text-sm" @if (! $saldado) open @endif>
            <summary class="cursor-pointer font-medium text-slate-600">Abonos ({{ $p->abonos->count() }})</summary>
            <ul class="mt-2 divide-y divide-slate-200">
                @foreach ($p->abonos as $a)
                    <li wire:key="abono-{{ $a->id }}" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-1.5">
                        <span>{{ $a->fecha->format('d/m/Y') }} · <strong>{{ $dinero($a->monto) }}</strong></span>
                        <span class="text-slate-600">
                            @if ($a->nomina_id)
                                En nómina{{ $a->nomina?->semana ? ' semana '.$a->nomina->semana : '' }}
                            @else
                                Abono suelto{{ $a->nota && $a->nota !== 'Abono suelto' ? ': '.$a->nota : '' }}
                                <button type="button" wire:click="quitarAbono({{ $a->id }})" wire:confirm="¿Quitar este abono de {{ $dinero($a->monto) }}? El saldo vuelve a subir."
                                        class="ml-2 font-medium text-red-700 underline">Quitar</button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</article>

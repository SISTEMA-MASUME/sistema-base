{{-- Insignias de un gasto: nómina (enlaza al historial), movimiento entre cuentas y origen. Recibe $g y $enlaceNomina. --}}
@if ($g->origen === 'nomina')
    @if ($enlaceNomina)
        <a href="{{ $enlaceNomina }}" class="ml-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 align-middle text-xs font-semibold text-amber-800 underline" title="Lo creó la nómina: para cambiarlo reabre la nómina">Nómina</a>
    @else
        <span class="ml-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 align-middle text-xs font-semibold text-amber-800" title="Lo creó la nómina: para cambiarlo reabre la nómina">Nómina</span>
    @endif
@endif
@if ($g->tipo === 'movimiento')
    <span class="ml-1 inline-block rounded bg-slate-200 px-1.5 py-0.5 align-middle text-xs font-semibold text-slate-700">Movimiento</span>
@endif

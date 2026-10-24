@props(['titulo', 'categorias' => [], 'series' => [], 'minimo' => 560])
@php
    // Barras apiladas: una columna por categoría (mes) partida por serie (persona). Separación blanca de 2 px entre trozos,
    // total corto encima de cada columna y el detalle exacto al pasar el dedo/ratón (<title>). La tabla visible va aparte.
    $ancho = 720;
    $alto = 300;
    $izq = 56;
    $der = 12;
    $arriba = 18;
    $abajo = 30;
    $n = max(1, count($categorias));
    $totales = collect(range(0, $n - 1))->map(fn ($i) => collect($series)->sum(fn ($s) => max(0.0, (float) ($s['valores'][$i] ?? 0))));
    $escala = \App\Support\EscalaGrafica::marcas(0.0, (float) $totales->max());
    $rango = $escala['max'] - $escala['min'];
    $y = fn (float $v) => $arriba + ($escala['max'] - $v) / $rango * ($alto - $arriba - $abajo);
    $banda = ($ancho - $izq - $der) / $n;
    $barra = $banda * 0.62;
    $dinero = fn ($v) => ($v < 0 ? '-' : '').'$'.number_format(abs((float) $v), 2);
@endphp
<figure class="m-0">
    <div class="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-700" aria-hidden="true">
        @foreach ($series as $s)
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background: {{ $s['color'] }}"></span>{{ $s['nombre'] }}</span>
        @endforeach
    </div>
    <div class="overflow-x-auto">
        <svg viewBox="0 0 {{ $ancho }} {{ $alto }}" width="100%" style="min-width: {{ $minimo }}px" role="img" aria-label="{{ $titulo }}" class="block h-auto" font-family="inherit">
            @foreach ($escala['marcas'] as $m)
                <line x1="{{ $izq }}" x2="{{ $ancho - $der }}" y1="{{ round($y($m), 1) }}" y2="{{ round($y($m), 1) }}" stroke="{{ $m == 0 ? '#94a3b8' : '#e2e8f0' }}" stroke-width="1"/>
                <text x="{{ $izq - 6 }}" y="{{ round($y($m), 1) + 4 }}" text-anchor="end" font-size="11" fill="#475569">{{ \App\Support\EscalaGrafica::corto($m) }}</text>
            @endforeach
            @foreach ($categorias as $i => $cat)
                @php
                    $x0 = $izq + $banda * $i + ($banda - $barra) / 2;
                    $base = 0.0;
                @endphp
                @foreach ($series as $s)
                    @php $v = max(0.0, (float) ($s['valores'][$i] ?? 0)); @endphp
                    @if ($v > 0)
                        @php
                            $y1 = $y($base + $v);
                            $y0 = $y($base);
                            $base += $v;
                        @endphp
                        <rect x="{{ round($x0, 1) }}" y="{{ round($y1, 1) }}" width="{{ round($barra, 1) }}" height="{{ round(max($y0 - $y1, 0.5), 1) }}" rx="2" fill="{{ $s['color'] }}" stroke="#ffffff" stroke-width="2" paint-order="stroke"><title>{{ $cat }} · {{ $s['nombre'] }}: {{ $dinero($v) }} ({{ $totales[$i] > 0 ? round($v / $totales[$i] * 100) : 0 }}%)</title></rect>
                    @endif
                @endforeach
                @if ($totales[$i] > 0)
                    <text x="{{ round($izq + $banda * ($i + 0.5), 1) }}" y="{{ round($y($totales[$i]) - 5, 1) }}" text-anchor="middle" font-size="10" fill="#334155">{{ \App\Support\EscalaGrafica::corto($totales[$i]) }}</text>
                @endif
                <text x="{{ round($izq + $banda * ($i + 0.5), 1) }}" y="{{ $alto - 10 }}" text-anchor="middle" font-size="{{ $n > 8 ? 10 : 11 }}" fill="#475569">{{ $cat }}</text>
            @endforeach
        </svg>
    </div>
</figure>

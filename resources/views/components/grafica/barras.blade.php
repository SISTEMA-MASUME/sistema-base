@props(['titulo', 'categorias' => [], 'series' => [], 'linea' => null, 'minimo' => 560])
@php
    // Barras agrupadas (una por serie y categoría) con una línea punteada opcional encima. SVG a todo el ancho de su caja;
    // si no cabe en el celular se desliza dentro de la caja. Los valores exactos van en <title> y en una tabla sr-only.
    $ancho = 720;
    $alto = 300;
    $izq = 56;
    $der = 12;
    $arriba = 12;
    $abajo = 30;
    $todos = collect($series)->flatMap(fn ($s) => $s['valores'])->when($linea, fn ($c) => $c->merge($linea['valores']))->map(fn ($v) => (float) $v);
    $escala = \App\Support\EscalaGrafica::marcas((float) $todos->min(), (float) $todos->max());
    $rango = $escala['max'] - $escala['min'];
    $y = fn (float $v) => $arriba + ($escala['max'] - $v) / $rango * ($alto - $arriba - $abajo);
    $n = max(1, count($categorias));
    $banda = ($ancho - $izq - $der) / $n;
    $grupo = $banda * 0.72;
    $ns = max(1, count($series));
    $barra = $grupo / $ns;
    $dinero = fn ($v) => ($v < 0 ? '-' : '').'$'.number_format(abs((float) $v), 2);
    $cero = $y(0.0);
@endphp
<figure class="m-0">
    <div class="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-700" aria-hidden="true">
        @foreach ($series as $s)
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background: {{ $s['color'] }}"></span>{{ $s['nombre'] }}</span>
        @endforeach
        @if ($linea)
            <span class="inline-flex items-center gap-1.5"><svg width="22" height="8" viewBox="0 0 22 8"><line x1="0" y1="4" x2="22" y2="4" stroke="#64748b" stroke-width="2" stroke-dasharray="4 3"/></svg>{{ $linea['nombre'] }}</span>
        @endif
    </div>
    <div class="overflow-x-auto">
        <svg viewBox="0 0 {{ $ancho }} {{ $alto }}" width="100%" style="min-width: {{ $minimo }}px" role="img" aria-label="{{ $titulo }}" class="block h-auto" font-family="inherit">
            @foreach ($escala['marcas'] as $m)
                <line x1="{{ $izq }}" x2="{{ $ancho - $der }}" y1="{{ round($y($m), 1) }}" y2="{{ round($y($m), 1) }}" stroke="{{ $m == 0 ? '#94a3b8' : '#e2e8f0' }}" stroke-width="1"/>
                <text x="{{ $izq - 6 }}" y="{{ round($y($m), 1) + 4 }}" text-anchor="end" font-size="11" fill="#475569">{{ \App\Support\EscalaGrafica::corto($m) }}</text>
            @endforeach
            @foreach ($categorias as $i => $cat)
                @php
                    $x0 = $izq + $banda * $i + ($banda - $grupo) / 2;
                @endphp
                @foreach ($series as $j => $s)
                    @php
                        $v = (float) ($s['valores'][$i] ?? 0);
                        $yv = $y($v);
                    @endphp
                    <rect x="{{ round($x0 + $barra * $j, 1) }}" y="{{ round(min($yv, $cero), 1) }}" width="{{ round(max($barra - 1, 1), 1) }}" height="{{ round(abs($yv - $cero), 1) }}" rx="2" fill="{{ $s['color'] }}"><title>{{ $cat }} · {{ $s['nombre'] }}: {{ $dinero($v) }}</title></rect>
                @endforeach
                <text x="{{ round($izq + $banda * ($i + 0.5), 1) }}" y="{{ $alto - 10 }}" text-anchor="middle" font-size="{{ $n > 8 ? 10 : 11 }}" fill="#475569">{{ $cat }}</text>
            @endforeach
            @if ($linea)
                @php
                    $puntos = collect($linea['valores'])->map(fn ($v, $i) => round($izq + $banda * ($i + 0.5), 1).','.round($y((float) $v), 1))->implode(' ');
                @endphp
                <polyline points="{{ $puntos }}" fill="none" stroke="#64748b" stroke-width="2" stroke-dasharray="5 4" stroke-linejoin="round"/>
                @foreach ($linea['valores'] as $i => $v)
                    <circle cx="{{ round($izq + $banda * ($i + 0.5), 1) }}" cy="{{ round($y((float) $v), 1) }}" r="3" fill="#fff" stroke="#64748b" stroke-width="1.5"><title>{{ $categorias[$i] ?? '' }} · {{ $linea['nombre'] }}: {{ $dinero($v) }}</title></circle>
                @endforeach
            @endif
        </svg>
    </div>
    <div class="sr-only"><table>
        <caption>{{ $titulo }}</caption>
        <thead><tr><th scope="col">Periodo</th>@foreach ($series as $s)<th scope="col">{{ $s['nombre'] }}</th>@endforeach @if ($linea)<th scope="col">{{ $linea['nombre'] }}</th>@endif</tr></thead>
        <tbody>
            @foreach ($categorias as $i => $cat)
                <tr><th scope="row">{{ $cat }}</th>@foreach ($series as $s)<td>{{ $dinero($s['valores'][$i] ?? 0) }}</td>@endforeach @if ($linea)<td>{{ $dinero($linea['valores'][$i] ?? 0) }}</td>@endif</tr>
            @endforeach
        </tbody>
    </table></div>
</figure>

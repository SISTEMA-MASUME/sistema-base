@props(['titulo', 'filas' => [], 'color' => '#64748b'])
@php
    // Barras horizontales (nombre, monto) ya ordenadas de mayor a menor.
    $ancho = 480;
    $izq = 138;
    $der = 64;
    $fila = 30;
    $alto = max(1, count($filas)) * $fila + 8;
    $max = max(1.0, (float) collect($filas)->max('monto'));
    $dinero = fn ($v) => '$'.number_format((float) $v, 2);
@endphp
<figure class="m-0">
    <svg viewBox="0 0 {{ $ancho }} {{ $alto }}" width="100%" role="img" aria-label="{{ $titulo }}" class="block h-auto" font-family="inherit">
        @foreach ($filas as $i => $f)
            @php
                $w = ($ancho - $izq - $der) * ((float) $f['monto'] / $max);
                $cy = 4 + $i * $fila;
            @endphp
            <text x="{{ $izq - 8 }}" y="{{ $cy + 19 }}" text-anchor="end" font-size="12" fill="#334155">{{ \Illuminate\Support\Str::limit($f['nombre'], 20) }}</text>
            <rect x="{{ $izq }}" y="{{ $cy + 4 }}" width="{{ round(max($w, 1), 1) }}" height="{{ $fila - 10 }}" rx="2" fill="{{ $color }}"><title>{{ $f['nombre'] }}: {{ $dinero($f['monto']) }}</title></rect>
            <text x="{{ round($izq + max($w, 1) + 6, 1) }}" y="{{ $cy + 19 }}" font-size="12" fill="#334155">{{ \App\Support\EscalaGrafica::corto((float) $f['monto']) }}</text>
        @endforeach
    </svg>
    <div class="sr-only"><table>
        <caption>{{ $titulo }}</caption>
        <thead><tr><th scope="col">Categoría</th><th scope="col">Monto</th></tr></thead>
        <tbody>@foreach ($filas as $f)<tr><th scope="row">{{ $f['nombre'] }}</th><td>{{ $dinero($f['monto']) }}</td></tr>@endforeach</tbody>
    </table></div>
</figure>

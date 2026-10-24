@props(['nombre', 'tipo' => null])
@php
    // Distintivo de cada banco/tarjeta en Mis finanzas, para reconocerla de un vistazo. Se elige por el NOMBRE de la cuenta
    // (sin tocar la base). Amex, Banamex, Mercado Pago, Nu y Revolut usan su logo real (public/logos/cuentas); las demás, su
    // nombre con el color de la marca. Una cuenta que no coincide con ninguna sale con sus iniciales en gris. Abajo, una franja
    // dice si es CRÉDITO, DÉBITO o PRÉSTAMO: así BBVA, Hey o Mercado Pago de crédito no se confunden con las de débito.
    // Las claves con espacios buscan la palabra sola: " AMEX " no debe encontrarse dentro de "BANAMEX".
    $n = ' '.preg_replace('/[^A-Z0-9]+/', ' ', strtoupper(\Illuminate\Support\Str::ascii($nombre))).' ';
    $marcas = [
        'COSTCO' => ['bg' => '#ffffff', 'txt' => '#e31837', 'palabra' => 'COSTCO', 'borde' => true],
        ' AMEX ' => ['bg' => '#ffffff', 'img' => 'amex-color', 'grande' => true, 'borde' => true],
        'AMERICAN EXPRESS' => ['bg' => '#ffffff', 'img' => 'amex-color', 'grande' => true, 'borde' => true],
        'MERCADO PAGO' => ['bg' => '#00b1ea', 'img' => 'mercadopago'],
        'REVOL' => ['bg' => '#191c1f', 'img' => 'revolut'],
        ' NU ' => ['bg' => '#820ad1', 'img' => 'nu'],
        'BBVA' => ['bg' => '#001391', 'txt' => '#ffffff', 'palabra' => 'BBVA'],
        'BANAMEX' => ['bg' => '#ffffff', 'img' => 'banamex', 'grande' => true, 'borde' => true],
        'HEY' => ['bg' => '#000000', 'txt' => '#ffffff', 'palabra' => 'hey'],
        'VOLARIS' => ['bg' => '#a12885', 'txt' => '#ffffff', 'palabra' => 'volaris'],
        'DESPEGAR' => ['bg' => '#4b2a9e', 'txt' => '#ffffff', 'palabra' => 'despegar'],
        'INVEX' => ['bg' => '#d6001c', 'txt' => '#ffffff', 'palabra' => 'INVEX'],
        'GBM' => ['bg' => '#0b2341', 'txt' => '#ffffff', 'palabra' => 'GBM+'],
    ];
    $m = null;
    foreach ($marcas as $clave => $datos) {
        if (str_contains($n, $clave)) {
            $m = $datos;
            break;
        }
    }
    $icono = null;
    if (! $m && $tipo === 'efectivo') {
        $m = ['bg' => '#15803d'];
        $icono = 'M3 7h18v10H3zM12 14.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM6 10v.01M18 14v.01';
    } elseif (! $m && in_array($tipo, ['por_cobrar', 'prestamo'], true)) {
        $m = ['bg' => '#475569'];
        $icono = 'M12 11a4 4 0 100-8 4 4 0 000 8zm-7 9a7 7 0 0114 0';
    }
    $franja = [
        'credito' => ['CRÉDITO', '#f59e0b', '#451a03'],
        'debito' => ['DÉBITO', '#16a34a', '#ffffff'],
        'prestamo' => ['PRÉSTAMO', '#9f1239', '#ffffff'],
    ][$tipo] ?? null;
    $iniciales = collect(preg_split('/\s+/', trim(preg_replace('/[^A-Z ]/', ' ', $n))))->filter()->take(2)->map(fn ($p) => $p[0])->implode('');
@endphp
<span aria-hidden="true" {{ $attributes->merge(['class' => 'inline-flex h-12 w-16 shrink-0 flex-col overflow-hidden rounded-md']) }}
      style="background: {{ $m['bg'] ?? '#e2e8f0' }};{{ ! empty($m['borde']) ? ' box-shadow: inset 0 0 0 1px #cbd5e1;' : '' }}">
    <span class="flex min-h-0 flex-1 items-center justify-center">
        @if (! empty($m['img']))
            <img src="/logos/cuentas/{{ $m['img'] }}.svg" alt="" class="{{ ! empty($m['grande']) ? ($franja ? 'h-7' : 'h-9') : 'h-6' }} w-auto">
        @elseif (! empty($m['palabra']))
            <span class="px-1 text-center font-extrabold leading-none tracking-tight" style="color: {{ $m['txt'] }}; font-size: {{ mb_strlen($m['palabra']) > 6 ? '10px' : (mb_strlen($m['palabra']) > 4 ? '11px' : '14px') }}">{{ $m['palabra'] }}</span>
        @elseif ($icono)
            <svg class="h-6 w-6" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icono }}"/></svg>
        @else
            <span class="text-sm font-bold text-slate-600">{{ $iniciales }}</span>
        @endif
    </span>
    @if ($franja)
        <span class="block text-center text-[9px] font-extrabold leading-[14px] tracking-wide" style="background: {{ $franja[1] }}; color: {{ $franja[2] }}">{{ $franja[0] }}</span>
    @endif
</span>

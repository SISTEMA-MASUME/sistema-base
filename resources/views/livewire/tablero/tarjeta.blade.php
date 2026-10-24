@php
    // Barritas de color de las etiquetas (sin texto, como Trello; al tocarlas se expanden con texto).
    $barras = [
        'rojo' => 'bg-red-500',
        'morado' => 'bg-purple-500',
        'verde' => 'bg-green-500',
        'azul' => 'bg-blue-600',
        'rosa' => 'bg-pink-400',
        'naranja' => 'bg-orange-500',
        'amarillo' => 'bg-yellow-400 text-yellow-950',
        'gris' => 'bg-slate-400',
    ];
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
    $partidas = $p->partidas;
    $n = $partidas->count();
    $recogidas = $partidas->filter(fn ($x) => $x->estado->nivel() >= \App\Enums\EstadoPartidaPedido::Recibida->nivel())->count();
    $entregadas = $partidas->filter(fn ($x) => $x->estado === \App\Enums\EstadoPartidaPedido::Entregada)->count();
    $facturadas = $partidas->where('facturada', true)->count();
    $completa = $n > 0 && $entregadas === $n && $facturadas === $n;
    $fc = $p->fecha_compromiso;
    $vencido = $fc && $fc->isPast() && ! $fc->isToday() && ! ($n > 0 && $entregadas === $n);
    $etiquetas = $p->lista_etiquetas ?? $p->etiquetas();
    $numArchivos = (int) $p->archivos_count + ($p->oc_cliente_path ? 1 : 0);
    $iniciales = $p->responsable->iniciales ?? mb_strtoupper(mb_substr((string) ($p->responsable->name ?? ''), 0, 2));
    // Recordatorio (no obligatorio): ya se entregó algo y el pedido no tiene foto de entrega.
    $faltaFoto = $entregadas > 0 && ! (int) $p->evidencias_count && ! $p->cancelado_at && ! $p->archivado_at;
    // Fecha estimada más próxima de lo que falta entregar.
    $proxima = $partidas->filter(fn ($x) => $x->fecha_entrega && $x->estado !== \App\Enums\EstadoPartidaPedido::Entregada)->min('fecha_entrega');
    $proximaVencida = $proxima && $proxima->isPast() && ! $proxima->isToday();
    // El proveedor entregó incompleto: lo que falta recoger.
    $parciales = $p->cancelado_at ? collect() : $partidas->filter(fn ($x) => $x->recogidaParcial());
    $textoFalta = match ($parciales->count()) {
        0 => null,
        1 => 'Falta recoger '.rtrim(rtrim(number_format($parciales->first()->faltaRecoger(), 3, '.', ','), '0'), '.').' '.$parciales->first()->unidad,
        default => 'Falta recoger '.$parciales->count().' partidas',
    };
@endphp
<article x-on:click="abrirTarjeta({{ $p->id }})" x-on:keydown.enter="abrirTarjeta({{ $p->id }})" tabindex="0" role="button" aria-label="Abrir tarjeta {{ $p->titulo() }}"
         class="overflow-hidden rounded-lg bg-white text-slate-800 shadow-sm outline-none ring-naranja hover:ring-2 focus:ring-2 dark:bg-slate-800 dark:text-slate-100 {{ $p->cancelado_at || $p->archivado_at ? 'opacity-50' : '' }}">
    @if ($p->portada_id)
        <img src="{{ route('pedidos.archivo', [$p->id, $p->portada_id]) }}" alt="" loading="lazy" class="h-28 w-full object-cover">
    @endif
    <div class="px-2.5 pb-2 pt-2">
        @if ($etiquetas)
            <button type="button" x-on:click.stop="alternarEtiquetas()" class="mb-1.5 flex w-full flex-wrap gap-1 text-left" title="Mostrar u ocultar el texto de las etiquetas">
                @foreach ($etiquetas as $e)
                    <span x-show="!etqAbiertas" class="h-2 w-10 rounded-full {{ $barras[$e['color']] ?? 'bg-slate-400' }}" title="{{ $e['texto'] }}"></span>
                    <span x-show="etqAbiertas" x-cloak class="rounded px-1.5 py-0.5 text-[11px] font-semibold leading-tight text-white {{ $barras[$e['color']] ?? 'bg-slate-400' }}">{{ $e['texto'] }}</span>
                @endforeach
            </button>
        @endif

        <div class="flex items-start gap-2">
            @if ($completa)
                <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-green-600 text-[10px] font-bold text-white" title="Todo entregado y facturado">✓</span>
            @else
                <span class="mt-0.5 h-4 w-4 shrink-0 rounded-full border-2 border-slate-400 dark:border-slate-500" aria-hidden="true"></span>
            @endif
            <p class="min-w-0 flex-1 text-sm leading-snug">{{ $p->titulo() }}</p>
        </div>
        @if ($p->sucursal)
            <p class="mt-1 truncate pl-6 text-xs text-sky-800 dark:text-sky-300">📍 {{ $p->sucursal->ciudad ?: $p->sucursal->nombre }}</p>
        @endif

        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600 dark:text-slate-300">
            <span class="font-semibold text-slate-500">P-{{ $p->folio }}</span>
            @if ($p->tiene_notas)
                <span title="Tiene notas" aria-label="Tiene notas">≡</span>
            @endif
            @if ($p->comentarios_count)
                <span title="Comentarios">💬 {{ $p->comentarios_count }}</span>
            @endif
            @if ($numArchivos)
                <span title="Archivos (OC, facturas, evidencias)">📎 {{ $numArchivos }}</span>
            @endif
            @if ($n)
                <span class="rounded px-1 {{ $recogidas === $n ? 'bg-green-600 text-white' : ($vencido ? 'bg-red-600 text-white' : '') }}" title="Partidas recogidas">☑ {{ $recogidas }}/{{ $n }}</span>
            @endif
            @if ($fc)
                <span class="rounded px-1 {{ $vencido ? 'bg-red-600 text-white' : '' }}" title="Fecha compromiso">⏰ {{ $fc->day }} {{ $meses[$fc->month - 1] }}</span>
            @endif
            @if ($proxima)
                <span class="rounded px-1 {{ $proximaVencida ? 'bg-red-600 text-white' : ($proxima->isToday() ? 'bg-orange-500 text-white' : '') }}" title="Fecha estimada de entrega más próxima">🚚 {{ $proxima->day }} {{ $meses[$proxima->month - 1] }}</span>
            @endif
            @if ($p->facturas_count)
                <span title="Tiene factura">🧾</span>
            @endif
            @if ($textoFalta)
                <span class="rounded bg-orange-500 px-1.5 py-0.5 font-semibold text-white" title="El proveedor no entregó completo" data-falta-recoger>🚚 {{ $textoFalta }}</span>
            @endif
            @if ($faltaFoto)
                <span class="rounded bg-purple-700 px-1.5 py-0.5 font-semibold text-white" title="Ya se entregó material y no hay foto de entrega">📷 Falta foto</span>
            @endif
            @if ($iniciales !== '')
                <span class="ml-auto flex h-7 w-7 items-center justify-center rounded-full bg-marino text-[8px] font-bold tracking-tighter text-white" title="{{ $p->responsable->name ?? '' }}">{{ $iniciales }}</span>
            @endif
        </div>
    </div>
</article>

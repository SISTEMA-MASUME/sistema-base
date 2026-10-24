@php
    $cant = fn ($c) => rtrim(rtrim(number_format((float) $c, 3, '.', ','), '0'), '.');
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
@endphp
<div class="space-y-4">
    <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <label class="flex-1">
                <span class="sr-only">Buscar</span>
                <input type="search" wire:model.live.debounce.400ms="buscar" class="campo" placeholder="Buscar artículo, proveedor o cliente">
            </label>
            <label class="flex min-h-11 items-center gap-2 text-base">
                <input type="checkbox" wire:model.live="soloParciales" class="h-6 w-6 rounded border-slate-300">
                Solo lo que entregaron a medias
            </label>
            <a href="{{ url('/pedidos') }}" class="btn-secundario">← Tablero</a>
        </div>
        <p class="text-sm text-slate-600">
            {{ $total }} {{ $total === 1 ? 'partida' : 'partidas' }} por recoger
            @if ($totalParciales) · <strong class="text-orange-800">{{ $totalParciales }} {{ $totalParciales === 1 ? 'entregada a medias' : 'entregadas a medias' }}</strong> @endif
        </p>
    </div>

    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    @forelse ($grupos as $g)
        @php
            $tel = $g['partidas']->first()->proveedor?->datosContacto()['telefono'] ?? $g['partidas']->first()->proveedor_telefono;
            $wa = \App\Support\Telefono::whatsapp($tel);
        @endphp
        <section wire:key="prov-{{ md5($g['proveedor']) }}" class="overflow-hidden rounded-xl bg-white shadow-sm" data-proveedor>
            <div class="flex flex-wrap items-center justify-between gap-2 bg-marino px-4 py-2.5 text-white">
                <h2 class="text-base font-bold">{{ $g['proveedor'] }}</h2>
                <div class="flex items-center gap-3">
                    <span class="text-sm">{{ $g['partidas']->count() }} {{ $g['partidas']->count() === 1 ? 'partida' : 'partidas' }}</span>
                    @if ($wa)
                        <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center rounded-lg bg-green-600 px-3 text-sm font-semibold text-white hover:bg-green-700" data-whatsapp-proveedor>WhatsApp</a>
                    @elseif ($tel)
                        <a href="tel:{{ preg_replace('/[^\d+]/', '', $tel) }}" class="inline-flex min-h-10 items-center rounded-lg bg-white/15 px-3 text-sm font-semibold text-white">Llamar</a>
                    @endif
                </div>
            </div>
            <ul class="divide-y divide-slate-200">
                @foreach ($g['partidas'] as $p)
                    @php
                        $parcial = $p->recogidaParcial();
                        $fe = $p->fecha_entrega;
                    @endphp
                    <li wire:key="pr-{{ $p->id }}" class="flex items-center gap-2 pr-3 {{ $parcial ? 'border-l-4 border-naranja' : '' }}">
                        <a href="{{ route('pedidos.index', ['pedido' => $p->pedido_id]) }}" class="flex min-w-0 flex-1 flex-col gap-1 px-4 py-3 hover:bg-slate-50">
                            <span class="text-base font-semibold text-slate-900">{{ $p->descripcion }}</span>
                            @if ($p->modelo)
                                <span class="text-sm text-slate-600">Modelo {{ $p->modelo }}</span>
                            @endif
                            @if ($parcial)
                                <span class="text-base font-bold text-orange-800">Faltan {{ $cant($p->faltaRecoger()) }} {{ $p->unidad }} · ya se recogieron {{ $cant($p->recogido()) }} de {{ $cant($p->cantidad) }}</span>
                            @else
                                <span class="text-base font-semibold text-slate-800">{{ $cant($p->cantidad) }} {{ $p->unidad }} · nada recogido aún</span>
                            @endif
                            <span class="text-sm text-slate-600">
                                P-{{ $p->pedido->folio }} · {{ $p->pedido->cliente->nombre ?? '' }}
                                @if ($p->ordenProveedor) · {{ $p->ordenProveedor->folioTexto() }} @endif
                                @if ($fe)
                                    · <span class="{{ $fe->isPast() && ! $fe->isToday() ? 'font-semibold text-red-700' : ($fe->isToday() ? 'font-semibold text-orange-700' : '') }}">{{ $fe->isToday() ? 'llega hoy' : 'llega '.$fe->day.' '.$meses[$fe->month - 1] }}</span>
                                @endif
                            </span>
                        </a>
                        <button type="button" wire:click="recoger({{ $p->id }})" wire:loading.attr="disabled" wire:target="recoger({{ $p->id }})"
                                class="min-h-12 shrink-0 rounded-lg border-2 border-green-600 bg-white px-3 text-base font-bold text-green-700 hover:bg-green-50" data-recogi>✓ Recogí</button>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p class="rounded-xl bg-white p-6 text-center text-base text-slate-600 shadow-sm">
            {{ $buscar !== '' || $soloParciales ? 'No hay nada por recoger con ese filtro.' : 'No hay nada pendiente de recoger. ¡Todo al día!' }}
        </p>
    @endforelse

    @if ($recogiendo)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="titulo-recoger"
             x-data x-on:keydown.escape.window="$wire.cerrar()">
            <form wire:submit="guardarRecogida" class="flex w-full max-w-md flex-col gap-3 rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                <h2 id="titulo-recoger" class="text-xl font-bold text-marino">¿Cuántas recogiste?</h2>
                <p class="text-base text-slate-700">{{ $recogiendo->descripcion }}</p>
                <p class="text-sm text-slate-600">Faltan {{ $cant($recogiendo->faltaRecoger()) }} {{ $recogiendo->unidad }}@if ($recogiendo->recogidaParcial()) (ya se recogieron {{ $cant($recogiendo->recogido()) }} de {{ $cant($recogiendo->cantidad) }})@endif. Si el proveedor entregó incompleto, escribe solo lo que te dieron.</p>
                <label class="block"><span class="etiqueta">Recogí</span>
                    <input type="text" inputmode="decimal" wire:model="cantidad" class="campo text-2xl font-bold" autofocus></label>
                @error('cantidad') <p class="error-campo">{{ $message }}</p> @enderror
                <button type="submit" class="btn-primario w-full !min-h-14 text-lg">Guardar</button>
                <button type="button" wire:click="cerrar" class="btn-secundario w-full">Cancelar</button>
            </form>
        </div>
    @endif
</div>

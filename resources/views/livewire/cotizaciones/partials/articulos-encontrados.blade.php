{{-- Artículos de la cotización que coinciden con lo buscado (solo cuando se buscó un artículo o modelo). --}}
@if ($c->relationLoaded('partidas') && $c->partidas->isNotEmpty())
    <ul class="mt-1 space-y-0.5">
        @foreach ($c->partidas->take(3) as $pa)
            <li class="text-xs text-slate-600">
                <span class="rounded bg-naranja/15 px-1 font-semibold text-marino">{{ rtrim(rtrim(number_format((float) $pa->cantidad, 3, '.', ','), '0'), '.') }} {{ $pa->unidad }}</span>
                {{ \Illuminate\Support\Str::limit($pa->descripcion, 70) }}@if ($pa->modelo) · <span class="font-mono font-semibold">{{ \Illuminate\Support\Str::limit($pa->modelo, 30) }}</span>@endif
            </li>
        @endforeach
        @if ($c->partidas->count() > 3)
            <li class="text-xs text-slate-500">y {{ $c->partidas->count() - 3 }} más…</li>
        @endif
    </ul>
@endif

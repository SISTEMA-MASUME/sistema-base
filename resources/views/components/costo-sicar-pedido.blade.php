@props(['c'])
{{-- Solo admin: costo de SICAR contra el del pedido (Support\CostoSicarVsPedido). --}}
@if ($c)
    @php
        $d = fn ($n) => '$'.number_format(abs((float) $n), 2);
        $favor = $c['diferencia'] < 0;
    @endphp
    <div {{ $attributes->merge(['class' => 'rounded-lg border px-3 py-2 text-sm '.($c['igual'] ? 'border-slate-200 bg-white' : ($favor ? 'border-green-300 bg-green-50' : 'border-red-300 bg-red-50'))]) }} data-costo-sicar>
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Costo SICAR vs pedido @foreach ([...$c['facturas'], ...$c['pedidos']] as $f)· <span class="whitespace-nowrap">{{ $f }}</span> @endforeach</p>
        <dl class="mt-1 grid grid-cols-3 gap-2">
            <div><dt class="text-xs uppercase text-slate-500">SICAR</dt><dd class="font-semibold">{{ $d($c['sicar']) }}</dd></div>
            <div><dt class="text-xs uppercase text-slate-500">Pedido{{ $c['partidas'] < $c['partidas_total'] ? ' ('.$c['partidas'].' de '.$c['partidas_total'].')' : '' }}</dt><dd class="font-semibold">{{ $d($c['pedido']) }}</dd></div>
            <div>
                <dt class="text-xs uppercase text-slate-500">Diferencia</dt>
                @if ($c['igual'])
                    <dd class="font-semibold text-slate-700">Igual</dd>
                @else
                    <dd class="font-bold {{ $favor ? 'text-green-700' : 'text-red-700' }}">{{ $favor ? '−' : '+' }}{{ $d($c['diferencia']) }} {{ $favor ? 'a favor' : 'en contra' }}</dd>
                @endif
            </div>
        </dl>
    </div>
@endif

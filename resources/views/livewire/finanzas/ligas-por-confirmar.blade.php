<div class="space-y-4">
    <a href="{{ route('finanzas.ventas') }}" class="text-marino hover:underline">&larr; Volver a Ventas</a>


    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    @forelse ($grupos as $g)
        <section class="rounded-xl bg-white p-4 shadow-sm sm:p-5" wire:key="grupo-{{ $g['clave'] }}">
            <h2 class="text-lg font-semibold text-marino">¿{{ $g['razon'] }} factura por {{ $g['cliente'] }}?</h2>
            <ul class="mt-3 space-y-2">
                @foreach ($g['items'] as $i)
                    <li class="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700" wire:key="liga-{{ $i['liga_id'] }}">
                        La {{ $i['venta']->tipo === 'nota' ? 'nota' : 'factura' }}
                        <a href="{{ route('finanzas.ventas', ['buscar' => $i['venta']->folio, 'anio' => 'todos']) }}" class="font-semibold text-marino hover:underline">{{ $i['venta']->folio }}</a>
                        sale a nombre de <strong>{{ $i['venta']->cliente_nombre }}</strong> y trae la OC <strong>{{ $i['pedido']->numero_oc_cliente }}</strong>
                        del pedido <a href="{{ route('pedidos.index', ['pedido' => $i['pedido']->id]) }}" class="font-semibold text-marino hover:underline">P-{{ $i['pedido']->folio }}</a>
                        ({{ $i['pedido']->cliente?->nombre }}).
                    </li>
                @endforeach
            </ul>
            @php($primero = $g['items']->first())
            <div class="mt-4 flex gap-3">
                <button type="button" wire:click="si({{ $primero['pedido']->id }}, {{ $primero['venta']->id }})" wire:loading.attr="disabled" class="btn-primario flex-1 sm:flex-none">Sí, es el mismo</button>
                <button type="button" wire:click="no({{ $primero['pedido']->id }}, {{ $primero['venta']->id }})" wire:loading.attr="disabled" class="btn-secundario flex-1 sm:flex-none">No</button>
            </div>
        </section>
    @empty
        <div class="rounded-xl bg-white p-5 text-base text-slate-600 shadow-sm">No hay facturas por confirmar.</div>
    @endforelse
</div>

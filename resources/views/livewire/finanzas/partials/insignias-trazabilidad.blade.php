{{-- Insignias de trazabilidad de una venta: en qué factura quedó, cuántos documentos reemplaza y los pedidos ligados. Recibe $v
     (con reemplaza_count y pedidos cargados para no consultar fila por fila). --}}
@php($reemplaza = (int) ($v->reemplaza_count ?? 0))
@if ($v->factura_sustituta || $reemplaza > 0 || $v->pedidos->isNotEmpty())
    <span class="mt-1 flex flex-wrap gap-1">
        @if ($v->factura_sustituta)
            <span class="inline-block rounded bg-sky-100 px-1.5 py-0.5 text-[11px] font-semibold text-sky-800">{{ $v->tipo === 'nota' ? 'Facturada en' : 'Sustituida por' }} {{ $v->factura_sustituta }}</span>
        @endif
        @if ($reemplaza > 0)
            <span class="inline-block rounded bg-indigo-100 px-1.5 py-0.5 text-[11px] font-semibold text-indigo-800">Reemplaza {{ $reemplaza }} {{ $reemplaza === 1 ? 'doc' : 'docs' }}</span>
        @endif
        @foreach ($v->pedidos as $p)
            <a href="{{ route('pedidos.index', ['pedido' => $p->id]) }}" class="inline-block rounded bg-orange-100 px-1.5 py-0.5 text-[11px] font-semibold text-orange-800 hover:bg-orange-200" title="Abrir el pedido P-{{ $p->folio }}">P-{{ $p->folio }}</a>
        @endforeach
    </span>
@endif

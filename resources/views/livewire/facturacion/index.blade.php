@php
    $fecha = fn ($f) => $f?->timezone('America/Mexico_City')->format('d/m/Y H:i');
    $grupos = $pestana === 'oc'
        ? ['Esperando OC del portal (ya se facturó / se sube la factura)' => $pedidos->filter(fn ($p) => $p->clienteGeneraOcDespues()),
           'Esperando OC del cliente (se surtió con nota de venta)' => $pedidos->reject(fn ($p) => $p->clienteGeneraOcDespues())]
        : ['' => $pedidos];
@endphp
<div class="space-y-4">
    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0" role="tablist">
        @foreach ($pestanas as $clave => $texto)
            <button type="button" role="tab" aria-selected="{{ $pestana === $clave ? 'true' : 'false' }}" wire:click="elegir('{{ $clave }}')"
                    class="flex min-h-12 shrink-0 items-center gap-2 rounded-full px-4 text-sm font-semibold {{ $pestana === $clave ? 'bg-marino text-white' : 'bg-white text-slate-700 shadow-sm' }}">
                {{ $texto }}
                @if ($conteos[$clave] !== null)
                    <span class="rounded-full px-2 py-0.5 text-xs {{ $conteos[$clave] ? 'bg-naranja text-white' : 'bg-slate-200 text-slate-600' }}">{{ $conteos[$clave] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    @foreach ($grupos as $titulo => $lista)
        <section class="rounded-xl bg-white p-4 shadow-sm">
            @if ($titulo)
                <h2 class="mb-2 text-base font-semibold text-marino">{{ $titulo }} ({{ $lista->count() }})</h2>
            @endif
            <ul class="divide-y divide-slate-200">
                @forelse ($lista as $p)
                    <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center" wire:key="f-{{ $pestana }}-{{ $p->id }}">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('pedidos.index', ['pedido' => $p->id]) }}" class="font-semibold text-marino hover:underline">{{ $p->titulo() }}</a>
                            <p class="text-sm text-slate-600">
                                P-{{ $p->folio }} · {{ $p->cliente->nombre }}
                                @if ($p->sucursal) · 📍 {{ $p->sucursal->ubicacion() }} @endif
                                · {{ $p->columna->etiqueta() }}
                                @if ($p->facturas_count) · 🧾 Factura en el expediente @endif
                                @if ($p->archivado_at) · Archivado @endif
                            </p>
                            @if ($pestana === 'subidas')
                                <p class="text-sm text-slate-600">Subida por {{ $p->facturaPortalPor?->name ?? 'alguien' }} el {{ $fecha($p->factura_portal_at) }}</p>
                            @elseif ($pestana === 'plazas' && $p->sucursal)
                                <p class="text-sm text-slate-600">Correos de la plaza: {{ $p->sucursal->correos_facturacion ?: 'sin correos (agrégalos en la ficha del cliente)' }}</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($pestana === 'portal')
                                @if ($p->cliente->portal_url)
                                    <a href="{{ $p->cliente->portal_url }}" target="_blank" rel="noopener" class="btn-secundario">Abrir portal</a>
                                @else
                                    <a href="{{ route('clientes.index', ['editar' => $p->cliente_id]) }}" class="btn-secundario">Capturar portal</a>
                                @endif
                                <button type="button" wire:click="marcarSubida({{ $p->id }})" wire:confirm="¿Ya subiste la factura de {{ $p->titulo() }} al portal?" class="btn-primario">Ya la subí</button>
                            @else
                                <a href="{{ route('pedidos.index', ['pedido' => $p->id]) }}" class="btn-secundario">Abrir tarjeta</a>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="py-6 text-center text-slate-600">No hay pendientes aquí. 🎉</li>
                @endforelse
            </ul>
        </section>
    @endforeach
</div>

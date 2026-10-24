@use('App\Enums\EstadoLoteSicar')
@php
    $dinero = fn ($v) => '$'.number_format((float) $v, 2);
    $cant = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
@endphp
<div class="space-y-4" @if ($hayAbiertos) wire:poll.15s @endif>
    {{-- Estado del puente --}}
    <div class="flex flex-wrap items-center gap-3 rounded-xl bg-white p-4 shadow-sm">
        <div class="mr-auto">
            <p class="text-sm font-semibold text-marino">
                Puente con SICAR:
                <span class="rounded-full px-3 py-1 {{ $activo ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $activo ? 'Encendido' : 'Apagado' }}</span>
            </p>
            <p class="mt-2 text-sm {{ $conexionVieja ? 'font-semibold text-red-700' : 'text-slate-600' }}">
                @if ($conexion)
                    La PC se conectó hace {{ max(0, (int) $conexion->diffInMinutes(now())) }} min.
                @else
                    La PC nunca se ha conectado.
                @endif
                @if ($conexionVieja)
                    La PC de Daniel no responde.
                @endif
            </p>
        </div>
        @if (auth()->user()->esAdmin())
            <button type="button" wire:click="alternarPuente" class="btn-secundario">{{ $activo ? 'Apagar puente' : 'Encender puente' }}</button>
        @endif
        <a href="{{ route('sicar.alta') }}" class="btn-secundario">Alta en SICAR</a>
    </div>

    @if ($mensaje)
        <p class="rounded-lg bg-green-100 p-3 text-sm font-semibold text-green-800" role="status">{{ $mensaje }}</p>
    @endif
    @if ($error)
        <p class="rounded-lg bg-red-100 p-3 text-sm font-semibold text-red-800" role="alert">{{ $error }}</p>
    @endif

    @forelse ($lotes as $l)
        @php
            $res = $l->resultado ?? [];
            $bloqueos = $l->bloqueos();
            $desplegado = $abierto === $l->id;
        @endphp
        <div wire:key="lote-{{ $l->id }}" class="rounded-xl bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-center gap-2">
                <p class="text-lg font-bold text-marino">Lote {{ $l->id }}</p>
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $l->estado->color() }}">{{ $l->estado->etiqueta() }}</span>
                <span class="ml-auto text-xs text-slate-500">{{ $l->created_at->timezone('America/Mexico_City')->format('d/m/Y H:i') }}</span>
            </div>
            <p class="mt-1 text-sm text-slate-700">
                Lo mandó {{ $l->creador?->name ?? '—' }}
                @if ($l->confirmador) · confirmó {{ $l->confirmador->name }} @endif
            </p>
            <p class="mt-1 text-sm text-slate-700">Pedidos: {{ $l->pedidos->map(fn ($p) => 'P-'.$p->folio)->implode(', ') ?: '—' }}</p>

            @if ($l->mensaje && in_array($l->estado, [EstadoLoteSicar::Error, EstadoLoteSicar::Simulado, EstadoLoteSicar::Aplicado], true))
                <p class="mt-2 rounded-lg p-3 text-sm {{ $l->estado === EstadoLoteSicar::Error ? 'bg-red-100 font-semibold text-red-800' : 'bg-slate-100 text-slate-700' }}">{{ $l->mensaje }}</p>
            @endif

            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" wire:click="detalle({{ $l->id }})" class="btn-secundario">{{ $desplegado ? 'Ocultar detalle' : 'Ver detalle' }}</button>
                @if ($l->estado === EstadoLoteSicar::Simulado && ! $bloqueos)
                    <button type="button" wire:click="confirmar({{ $l->id }})" wire:confirm="¿Guardar en SICAR? Esto crea artículos y compras de verdad." class="btn-primario">Confirmar</button>
                @endif
                @if (in_array($l->estado, [EstadoLoteSicar::Pendiente, EstadoLoteSicar::Simulado, EstadoLoteSicar::Error], true))
                    <button type="button" wire:click="cancelar({{ $l->id }})" wire:confirm="¿Cancelar el lote {{ $l->id }}? Sus pedidos quedan libres para mandarse de nuevo." class="btn-secundario">Cancelar</button>
                @endif
            </div>

            @if ($desplegado)
                <div class="mt-4 space-y-4 border-t border-slate-200 pt-4 text-sm">
                    @foreach ($res['bloqueos'] ?? [] as $b)
                        <p class="rounded-lg bg-red-100 p-3 font-semibold text-red-800">⛔ {{ $b }}</p>
                    @endforeach
                    @foreach ($res['avisos'] ?? [] as $a)
                        <p class="rounded-lg bg-yellow-100 p-3 text-yellow-800">⚠ {{ $a }}</p>
                    @endforeach

                    @if ($l->estado === EstadoLoteSicar::Aplicado)
                        <div class="rounded-lg bg-green-50 p-3">
                            <p class="font-semibold text-green-800">Guardado en SICAR{{ $l->aplicado_at ? ' el '.$l->aplicado_at->timezone('America/Mexico_City')->format('d/m/Y H:i') : '' }}</p>
                            @foreach ($res['articulos'] ?? [] as $a)
                                <p class="mt-1">Artículo <span class="font-mono font-semibold">{{ $a['clave'] ?? '' }}</span> · SICAR #{{ $a['art_id'] ?? '' }}</p>
                            @endforeach
                            @foreach ($res['compras'] ?? [] as $c)
                                <p class="mt-1">Compra {{ $c['proveedor'] ?? '' }} · folio SICAR {{ $c['com_id'] ?? '' }} · total {{ $dinero($c['total'] ?? 0) }}</p>
                            @endforeach
                            @if (! empty($res['respaldo']))
                                <p class="mt-1 text-xs text-slate-600">Respaldo: {{ $res['respaldo'] }}</p>
                            @endif
                        </div>
                    @elseif ($l->estado === EstadoLoteSicar::Simulado)
                        <div class="rounded-lg bg-yellow-50 p-3">
                            <p class="font-semibold text-yellow-800">Simulacro de la PC (aún no se guardó nada)</p>
                            @foreach ($res['articulos'] ?? [] as $a)
                                <p class="mt-1">🆕 <span class="font-mono font-semibold">{{ $a['clave'] ?? '' }}</span> · {{ $a['descripcion'] ?? '' }} · {{ $a['unidad'] ?? '' }} · SAT {{ $a['clave_sat'] ?? '—' }}</p>
                            @endforeach
                            @foreach ($res['claves_sat'] ?? [] as $a)
                                <p class="mt-1">Clave SAT de <span class="font-mono font-semibold">{{ $a['clave'] ?? '' }}</span>: {{ ($a['anterior'] ?? '') !== '' ? $a['anterior'].' → ' : '' }}{{ $a['clave_sat'] ?? '' }}</p>
                            @endforeach
                            @foreach ($res['compras'] ?? [] as $c)
                                <p class="mt-1">🧾 Compra {{ $c['proveedor'] ?? '' }} · {{ $c['renglones'] ?? 0 }} renglones · subtotal {{ $dinero($c['subtotal'] ?? 0) }} · IVA {{ $dinero($c['iva'] ?? 0) }} · total {{ $dinero($c['total'] ?? 0) }}</p>
                            @endforeach
                        </div>
                    @endif

                    <div>
                        <p class="font-semibold text-marino">Lo que se manda</p>
                        <p class="mt-1 font-semibold">Artículos nuevos ({{ count($l->datos['articulos'] ?? []) }})</p>
                        @forelse ($l->datos['articulos'] ?? [] as $a)
                            <p class="mt-1">🆕 <span class="font-mono font-semibold">{{ $a['clave'] }}</span> · {{ $a['descripcion'] }} · {{ $a['unidad'] }} · costo {{ $dinero($a['costo']) }} · precio {{ $dinero($a['precio']) }} · SAT {{ $a['clave_sat'] ?: '—' }}</p>
                        @empty
                            <p class="text-slate-500">Ninguno: todo existe en SICAR.</p>
                        @endforelse
                        @if (! empty($l->datos['claves_sat']))
                            <p class="mt-3 font-semibold">Claves SAT por poner o corregir ({{ count($l->datos['claves_sat']) }})</p>
                            @foreach ($l->datos['claves_sat'] as $a)
                                <p class="mt-1"><span class="font-mono font-semibold">{{ $a['clave'] }}</span>: {{ ($a['anterior'] ?? '') !== '' ? $a['anterior'].' → ' : 'sin clave → ' }}{{ $a['clave_sat'] }}</p>
                            @endforeach
                        @endif
                        <p class="mt-3 font-semibold">Compras ({{ count($l->datos['compras'] ?? []) }})</p>
                        @foreach ($l->comprasConTotales() as $c)
                            <div class="mt-1 rounded-lg bg-slate-50 p-3">
                                <p class="font-semibold">{{ $c['proveedor'] }} · subtotal {{ $dinero($c['subtotal']) }} · IVA {{ $dinero($c['iva']) }} · total {{ $dinero($c['total']) }}</p>
                                @foreach ($c['renglones'] as $r)
                                    <p class="mt-1 text-slate-700">{{ $cant($r['cantidad']) }} × <span class="font-mono">{{ $r['clave'] }}</span> · {{ $r['descripcion'] }} · {{ $dinero($r['costo']) }} <span class="text-xs text-slate-500">({{ implode(', ', $r['pedidos'] ?? []) }})</span></p>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @empty
        <p class="rounded-xl bg-white p-6 text-center text-slate-600 shadow-sm">Sin lotes</p>
    @endforelse
</div>

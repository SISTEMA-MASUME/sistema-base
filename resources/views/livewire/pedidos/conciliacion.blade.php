@php
    $chipClase = [
        'coincide' => 'bg-green-100 text-green-800',
        'cantidad_distinta' => 'bg-red-100 text-red-800',
        'modelo_distinto' => 'bg-red-100 text-red-800',
        'falta_en_pedido' => 'bg-red-100 text-red-800',
        'unidad_distinta' => 'bg-orange-100 text-orange-800',
        'precio_distinto' => 'bg-orange-100 text-orange-800',
        'sobra_en_pedido' => 'bg-yellow-100 text-yellow-800',
        'dudoso' => 'bg-yellow-100 text-yellow-800',
        'confianza_baja' => 'bg-yellow-100 text-yellow-800',
    ];
    $chipTexto = [
        'coincide' => 'Coincide',
        'cantidad_distinta' => 'Cantidad distinta',
        'modelo_distinto' => 'Modelo distinto',
        'unidad_distinta' => 'Unidad distinta',
        'precio_distinto' => 'Precio distinto',
        'falta_en_pedido' => 'Falta en el pedido',
        'sobra_en_pedido' => 'Sobra en el pedido',
        'dudoso' => 'Dudoso',
        'confianza_baja' => 'Lectura dudosa',
    ];
    $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.');
    $dinero = fn ($v) => '$'.number_format((float) $v, 2);
@endphp
<div class="space-y-4" @if ($enProceso) wire:poll.3s="consultar" @endif>
    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif
    @if ($error)
        <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
    @endif

    {{-- Botón / estado --}}
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
        @if (! $esCampo)
            @if ($tieneArchivo)
                <button type="button" wire:click="revisar" wire:loading.attr="disabled" wire:target="revisar" class="btn-primario" @disabled($enProceso)>
                    Revisar contra la OC
                </button>
            @else
                <span class="text-sm text-slate-500">Sin archivo de la OC</span>
            @endif
        @endif
        @if ($enProceso)
            <span class="text-base text-slate-700" role="status">Leyendo la OC…</span>
        @endif
    </div>

    @if ($ultimoError)
        <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $ultimoError->error }}</div>
    @endif

    @if ($resultado)
        {{-- Resumen --}}
        <div class="rounded-lg border px-4 py-3 text-base {{ $resultado['hay_diferencias'] ? 'border-red-300 bg-red-50 text-red-800' : 'border-green-300 bg-green-50 text-green-800' }}">
            <p class="font-semibold">
                {{ $resultado['hay_diferencias'] ? 'Hay diferencias con la OC del cliente' : 'El pedido coincide con la OC del cliente' }}
            </p>
            <p>{{ $resultado['resumen'] }}</p>
            <p class="mt-1 text-xs opacity-75">Revisión del {{ $actual->created_at->format('d/m/Y H:i') }}</p>
        </div>

        @foreach ($resultado['advertencias'] as $a)
            <div role="alert" class="rounded-lg border px-4 py-3 text-base {{ $a['nivel'] === 'alto' ? 'border-red-300 bg-red-50 text-red-800' : 'border-yellow-300 bg-yellow-50 text-yellow-800' }}">{{ $a['texto'] }}</div>
        @endforeach

        {{-- Lado a lado --}}
        <div class="overflow-x-auto">
            <table class="w-full min-w-[46rem] text-sm">
                <thead class="text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="w-2/5 px-2 py-2">OC del cliente</th>
                        <th class="w-2/5 px-2 py-2">Pedido</th>
                        <th class="px-2 py-2">Resultado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @php $sobrantes = collect($resultado['filas'])->filter(fn ($x) => ! $x['renglon'] && $x['partida'])->pluck('partida'); @endphp
                    @foreach ($resultado['filas'] as $f)
                        @php
                            $r = $f['renglon'];
                            $p = $f['partida'];
                            $fondo = $f['resuelta'] ? 'bg-slate-50' : ($f['estado'] === 'coincide' ? '' : 'bg-red-50/40');
                        @endphp
                        <tr wire:key="fila-{{ $f['clave'] }}" class="align-top {{ $fondo }}">
                            <td class="px-2 py-3">
                                @if ($r)
                                    <p class="font-medium">{{ $r['descripcion'] }}</p>
                                    @if (($r['origen'] ?? 'tabla') === 'observaciones')
                                        <p class="text-xs italic text-slate-500">De las observaciones</p>
                                    @endif
                                    <p class="text-slate-600">
                                        @if ($r['modelo']) Modelo {{ $r['modelo'] }} · @endif
                                        <strong>{{ $n($r['cantidad']) }}</strong> {{ $r['unidad'] }}
                                        @if (! $esCampo && $r['precio_unitario'] !== null) · {{ $dinero($r['precio_unitario']) }} @endif
                                    </p>
                                @else
                                    <p class="text-slate-400">— no viene en la OC —</p>
                                @endif
                            </td>
                            <td class="px-2 py-3">
                                @if ($p)
                                    <p class="font-medium">{{ $p['descripcion'] }}</p>
                                    <p class="text-slate-600">
                                        @if ($p['modelo']) Modelo {{ $p['modelo'] }} · @endif
                                        <strong>{{ $n($p['cantidad']) }}</strong> {{ $p['unidad'] }}
                                        @if (! $esCampo && $p['precio'] !== null) · {{ $dinero($p['precio']) }} @endif
                                    </p>
                                @else
                                    <p class="text-slate-400">— no está en el pedido —</p>
                                @endif
                            </td>
                            <td class="px-2 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @if ($f['estado'] === 'coincide')
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $chipClase['coincide'] }}">{{ $chipTexto['coincide'] }}</span>
                                    @else
                                        @foreach ($f['diferencias'] as $d)
                                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $chipClase[$d] }}">{{ $chipTexto[$d] }}</span>
                                        @endforeach
                                    @endif
                                </div>
                                @if ($f['estado'] !== 'coincide')
                                    <ul class="mt-1 space-y-0.5 text-xs text-slate-600">
                                        @foreach ($f['detalles'] as $clave => $texto)
                                            {{-- el personal de campo no ve dinero: se oculta el detalle del precio --}}
                                            @continue($esCampo && $clave === 'precio_distinto')
                                            <li>{{ $texto }}</li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($f['metodo'] === 'manual')
                                    @php $par = $resultado['parejas'][(int) substr($f['clave'], 1)] ?? []; @endphp
                                    <p class="mt-1 text-xs text-slate-600">Emparejada a mano por {{ $par['usuario'] ?? '' }}.
                                        @unless ($esCampo)<button type="button" wire:click="deshacerPareja('{{ $f['clave'] }}')" class="underline">Deshacer</button>@endunless</p>
                                @endif
                                @if ($f['resuelta'])
                                    <p class="mt-1 text-xs text-slate-600">Ignorada por {{ $f['resolucion']['usuario'] ?? '' }}: “{{ $f['resolucion']['motivo'] ?? '' }}”</p>
                                @elseif (! $esCampo && $f['estado'] !== 'coincide')
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @if ($r && $p && in_array('cantidad_distinta', $f['diferencias'], true))
                                            <button type="button" wire:click="usarCantidad('{{ $f['clave'] }}')" class="btn-secundario !min-h-9 !px-3 !py-1 text-xs">Usar cantidad de la OC</button>
                                        @endif
                                        @if ($r && ! $p)
                                            <button type="button" wire:click="agregarAlPedido('{{ $f['clave'] }}')" class="btn-secundario !min-h-9 !px-3 !py-1 text-xs">Agregar al pedido</button>
                                            {{-- La IA no lo emparejó: la persona decide con qué partida que sobra es la misma. --}}
                                            @if ($sobrantes->isNotEmpty())
                                                <select x-on:change="if ($event.target.value) $wire.emparejarAMano('{{ $f['clave'] }}', parseInt($event.target.value))" class="campo !min-h-9 !py-1 text-xs sm:max-w-xs" aria-label="Es la misma que">
                                                    <option value="">Es la misma que…</option>
                                                    @foreach ($sobrantes as $s)
                                                        <option value="{{ $s['id'] }}">{{ \Illuminate\Support\Str::limit($s['descripcion'], 50) }} ({{ $n($s['cantidad']) }} {{ $s['unidad'] }})</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                        @endif
                                        <button type="button" wire:click="pedirIgnorar('{{ $f['clave'] }}')" class="btn-secundario !min-h-9 !px-3 !py-1 text-xs">Ignorar</button>
                                    </div>
                                    @if ($ignorando === $f['clave'])
                                        <form wire:submit="ignorar" class="mt-2 space-y-2">
                                            <label class="etiqueta" for="motivo-{{ $f['clave'] }}">Motivo (obligatorio)</label>
                                            <input id="motivo-{{ $f['clave'] }}" type="text" wire:model="motivo" maxlength="500" class="campo" placeholder="Ej. El cliente confirmó por teléfono que son 2 piezas">
                                            @error('motivo') <p class="error-campo">{{ $message }}</p> @enderror
                                            <div class="flex gap-2">
                                                <button type="submit" class="btn-primario !min-h-9 !px-3 !py-1 text-xs">Guardar motivo</button>
                                                <button type="button" wire:click="cancelarIgnorar" class="btn-secundario !min-h-9 !px-3 !py-1 text-xs">Cancelar</button>
                                            </div>
                                        </form>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif (! $enProceso && ! $ultimoError)
        <p class="text-slate-600">Sin revisar contra la OC</p>
    @endif

    {{-- Historial --}}
    @if ($historial->isNotEmpty())
        <details class="rounded-lg border border-slate-200 px-4 py-2">
            <summary class="cursor-pointer text-sm font-semibold text-marino">Historial de revisiones ({{ $historial->count() }})</summary>
            <ul class="mt-2 divide-y divide-slate-200 text-sm">
                @foreach ($historial as $h)
                    <li wire:key="hist-{{ $h->id }}" class="flex flex-col gap-0.5 py-2 sm:flex-row sm:items-center sm:justify-between">
                        <span>{{ $h->created_at->format('d/m/Y H:i') }} · {{ $h->user->name ?? '' }}@if (! $esCampo) · {{ $h->modelo }} @endif</span>
                        <span class="text-slate-600">
                            @if ($h->estado === 'procesando') Procesando…
                            @elseif ($h->estado === 'error') Error: {{ \Illuminate\Support\Str::limit((string) $h->error, 90) }}
                            @else {{ $h->resumen }}
                            @endif
                            @if (! $esCampo && $h->estado !== 'procesando') · US${{ number_format((float) $h->costo_usd, 4) }} @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>

<div class="space-y-4">
    {{-- Última sincronización del catálogo --}}
    <div class="rounded-xl p-3 text-sm {{ $syncViejo ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}" role="status">
        @if (! $sync)
            <strong>El catálogo de SICAR nunca se ha sincronizado.</strong>
        @else
            Catálogo de SICAR sincronizado el <strong>{{ $sync->fin->timezone('America/Mexico_City')->format('d/m/Y H:i') }}</strong> ({{ $sync->activos }} artículos activos).
            @if ($syncViejo)
                <strong>Tiene más de 24 horas.</strong>
            @endif
        @endif
    </div>

    {{-- 1. Elegir tarjetas --}}
    <div class="rounded-xl bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1 sm:max-w-sm">
                <label class="etiqueta" for="columna">Columna del tablero</label>
                <select id="columna" wire:model.live="columna" class="campo" @disabled($pedido)>
                    <option value="">Todas las columnas</option>
                    @foreach ($columnas as $c)
                        <option value="{{ $c->value }}">{{ $c->etiqueta() }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button" wire:click="marcarTodas" class="btn-secundario">Marcar todas</button>
            <button type="button" wire:click="quitarTodas" class="btn-secundario">Quitar todas</button>
            @if ($pedido)
                <a href="{{ route('sicar.alta') }}" class="btn-secundario">Ver todas las tarjetas</a>
            @endif
            <a href="{{ route('pedidos.index') }}" class="btn-secundario">Volver al tablero</a>
        </div>
        <p class="mt-3 text-sm font-semibold text-marino">1. Tarjetas ({{ count($seleccion) }} de {{ $pedidos->count() }})</p>
        <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($pedidos as $p)
                @php
                    $fp = $filasPorPedido->get($p->id, collect());
                    $porCapturar = $fp->where('estado', '!=', 'existe')->count();
                    $marcada = in_array($p->id, array_map('intval', $seleccion), true);
                @endphp
                <label wire:key="ped-{{ $p->id }}" class="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 {{ $marcada ? 'border-marino bg-blue-50' : 'border-slate-200' }}">
                    <input type="checkbox" wire:model.live="seleccion" value="{{ $p->id }}" class="h-5 w-5 rounded">
                    <span class="min-w-0 flex-1">
                        <span class="block text-xs font-semibold text-slate-500">P-{{ $p->folio }} · {{ $p->columna->etiqueta() }}</span>
                        @if ($p->alta_sicar)
                            <span class="mt-0.5 inline-block rounded bg-orange-100 px-1.5 py-0.5 text-xs font-semibold text-orange-800" data-falta-sicar>Ya dado de alta · falta{{ $fp->count() === 1 ? '' : 'n' }} {{ $fp->count() }} en SICAR</span>
                        @endif
                        <span class="block truncate text-sm font-semibold text-marino">{{ $p->cliente?->nombre }}{{ $p->numero_oc_cliente ? ' · OC '.$p->numero_oc_cliente : ' · sin OC' }}</span>
                        <span class="block text-xs text-slate-600">{{ $fp->count() }} {{ $fp->count() === 1 ? 'partida' : 'partidas' }} · {{ $porCapturar ? $porCapturar.' por dar de alta o revisar' : 'todo existe en SICAR' }}</span>
                    </span>
                </label>
            @empty
                <p class="text-sm text-slate-600">No hay tarjetas por dar de alta en SICAR{{ $columna ? ' en esta columna' : '' }}.</p>
            @endforelse
        </div>
    </div>

    @if ($mensaje)
        <p class="rounded-lg bg-green-100 p-3 text-sm font-semibold text-green-800" role="status">{{ $mensaje }}
            @if ($loteCreado)
                <a href="{{ route('sicar.lotes') }}" class="underline">Ir a Lotes SICAR</a>
            @endif
        </p>
    @endif
    @if ($error)
        <p class="rounded-lg bg-red-100 p-3 text-sm font-semibold text-red-800" role="alert">{{ $error }}</p>
    @endif

    {{-- Proveedores de las partidas marcadas que aún no se relacionan con uno de SICAR --}}
    @if ($faltantes->isNotEmpty())
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-yellow-300">
            <p class="text-sm font-semibold text-marino">Proveedores en SICAR</p>
            <div class="mt-3 space-y-2">
                @foreach ($faltantes as $fa)
                    <div wire:key="provs-{{ $fa['proveedor']->id }}" class="flex flex-wrap items-end gap-2">
                        <div class="min-w-0 flex-1 sm:max-w-md">
                            <label class="etiqueta" for="provs-{{ $fa['proveedor']->id }}">{{ $fa['proveedor']->nombre }}</label>
                            <select id="provs-{{ $fa['proveedor']->id }}" wire:model="proveedorSicar.{{ $fa['proveedor']->id }}" class="campo">
                                <option value="">Elige el proveedor de SICAR…</option>
                                @foreach ($fa['opciones'] as $o)
                                    <option value="{{ $o->pro_id }}" @selected($fa['sugerido'] && $fa['sugerido']->pro_id === $o->pro_id)>{{ $o->nombre }} ({{ $o->pro_id }}){{ $fa['sugerido'] && $fa['sugerido']->pro_id === $o->pro_id ? ' — sugerido' : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="button" wire:click="guardarProveedorSicar({{ $fa['proveedor']->id }})" class="btn-secundario">Guardar</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 2. Tabla de lo que hay que capturar --}}
    <div class="rounded-xl bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <p class="mr-auto font-semibold text-marino">2. Lo que hay que capturar en SICAR</p>
            <span class="rounded-full bg-blue-100 px-3 py-1 font-semibold text-blue-800">🆕 Dar de alta + compra: {{ $conteo['nuevo'] ?? 0 }}</span>
            <span class="rounded-full bg-yellow-100 px-3 py-1 font-semibold text-yellow-800">🟡 Revisar: {{ $conteo['posible'] ?? 0 }}</span>
            <span class="rounded-full bg-green-100 px-3 py-1 font-semibold text-green-800">✅ Solo compra: {{ $conteo['existe'] ?? 0 }}</span>
            @if ($pendientesSat > 0)
                <button type="button" wire:click="sugerirSat" wire:loading.attr="disabled" wire:target="sugerirSat" class="btn-primario">
                    <span wire:loading.remove wire:target="sugerirSat">🤖 Sugerir clave SAT con IA ({{ $pendientesSat }})</span>
                    <span wire:loading wire:target="sugerirSat">La IA está revisando…</span>
                </button>
            @endif
        </div>

        @if ($seleccionadas->isEmpty())
            <p class="mt-3 rounded-lg bg-slate-50 p-6 text-center text-slate-600">Sin tarjetas marcadas</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-100 text-xs uppercase text-slate-600">
                        <tr>
                            <th class="px-2 py-2">Qué hacer</th>
                            <th class="px-2 py-2">Pedido</th>
                            <th class="px-2 py-2">Clave SICAR</th>
                            <th class="px-2 py-2">Descripción</th>
                            <th class="px-2 py-2">Unidad</th>
                            <th class="px-2 py-2 text-right">Cant.</th>
                            <th class="px-2 py-2 text-right">Costo sin IVA</th>
                            <th class="px-2 py-2 text-right">Precio sin IVA</th>
                            <th class="px-2 py-2 text-right">Utilidad</th>
                            <th class="px-2 py-2">Clave SAT</th>
                            <th class="px-2 py-2">Proveedor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($seleccionadas as $f)
                            @php
                                $v = \App\Support\HojaAltasSicar::valores($f);
                                [$accion, $ped, $clave, $desc, $unidad, $cant, $costo, $precio, $utilidad, $sat, $prov]
                                    = [$v['accion'], $v['pedido'], $v['clave'], $v['descripcion'], $v['unidad'], $v['cantidad'], $v['costo'], $v['precio'], $v['utilidad'], $v['sat'], $v['proveedor']];
                                $pa = $f['partida'];
                            @endphp
                            <tr wire:key="par-{{ $pa->id }}" class="{{ ['nuevo' => 'bg-blue-50', 'posible' => 'bg-yellow-50', 'existe' => 'bg-green-50'][$f['estado']] }} align-top">
                                <td class="whitespace-nowrap px-2 py-2 font-semibold">
                                    {{ ['nuevo' => '🆕 Nuevo', 'posible' => '🟡 Posible', 'existe' => '✅ Existe'][$f['estado']] }}
                                    <span class="block text-xs font-normal text-slate-700">{{ ucfirst(mb_strtolower($accion)) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-2 py-2">{{ $ped }}</td>
                                <td class="px-2 py-2 font-mono font-semibold">{{ $clave }}</td>
                                <td class="min-w-64 px-2 py-2">
                                    {{ $desc }}
                                    @if ($v['nuestra'] !== '')
                                        <span class="block text-xs text-slate-600">Nuestra descripción: {{ $v['nuestra'] }}</span>
                                    @endif
                                    @if ($f['estado'] === 'posible')
                                        <span class="mt-1 block text-xs text-yellow-800">Parece <span class="font-mono font-semibold">{{ $f['candidato']->clave }}</span> · {{ $f['candidato']->descripcion }} ({{ round($f['similitud'] * 100) }}% parecido)</span>
                                        <span class="mt-1 flex flex-wrap gap-2">
                                            <button type="button" wire:click="esEste({{ $pa->id }}, {{ $f['candidato']->art_id }})" class="btn-primario">Es este</button>
                                            <button type="button" wire:click="esNuevo({{ $pa->id }})" class="btn-secundario">Es nuevo</button>
                                        </span>
                                    @endif
                                    @foreach ($f['avisos'] as $aviso)
                                        <span class="mt-1 block text-xs font-semibold text-orange-700">⚠ {{ $aviso }}</span>
                                    @endforeach
                                    @if ($f['decidido'])
                                        <button type="button" wire:click="deshacer({{ $pa->id }})" class="mt-1 block text-xs text-slate-500 underline">Quitar decisión guardada</button>
                                    @endif
                                </td>
                                <td class="px-2 py-2">{{ $unidad }}</td>
                                <td class="px-2 py-2 text-right">{{ rtrim(rtrim(number_format($cant, 3), '0'), '.') }}</td>
                                <td class="whitespace-nowrap px-2 py-2 text-right">${{ number_format($costo, 2) }}</td>
                                <td class="whitespace-nowrap px-2 py-2 text-right">${{ number_format($precio, 2) }}</td>
                                <td class="whitespace-nowrap px-2 py-2 text-right">{{ $utilidad === '' ? '—' : rtrim(rtrim(number_format($utilidad, 1), '0'), '.').'%' }}</td>
                                <td class="min-w-48 px-2 py-2">
                                    @if ($f['estado'] === 'existe')
                                        <span class="font-mono">{{ $sat ?: '—' }}</span>
                                        @if ($f['sat_nombre'])<span class="block text-xs text-slate-600">{{ $f['sat_nombre'] }}</span>@endif
                                        @if ($f['sat_sugerida'])
                                            <span class="mt-1 block text-xs font-semibold text-blue-800">Cambiar a <span class="font-mono">{{ $f['sat_sugerida'] }}</span> · {{ $f['sat_sugerida_nombre'] }}</span>
                                        @endif
                                    @else
                                        <input type="text" inputmode="numeric" maxlength="8" value="{{ $sat }}" placeholder="8 dígitos" aria-label="Clave SAT"
                                            class="campo w-28 font-mono" wire:change="guardarSat({{ $pa->id }}, $event.target.value)" wire:key="sat-{{ $pa->id }}-{{ $sat }}">
                                        @if ($f['sat_nombre'])<span class="block text-xs text-slate-600">{{ $f['sat_nombre'] }}</span>@endif
                                    @endif
                                    @if ($f['sat_ia'])
                                        <span class="mt-1 inline-block rounded px-1.5 text-[11px] font-semibold {{ ['alta' => 'bg-green-100 text-green-800', 'media' => 'bg-yellow-100 text-yellow-800', 'baja' => 'bg-red-100 text-red-800', 'manual' => 'bg-slate-100 text-slate-700'][$f['sat_ia']] ?? 'bg-slate-100' }}">
                                            {{ $f['sat_ia'] === 'manual' ? 'Capturada a mano' : 'IA: confianza '.$f['sat_ia'] }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-2 py-2">{{ $prov }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- 3. Descargar y marcar --}}
    <div class="sticky bottom-2 z-10 space-y-2 rounded-xl bg-white p-4 shadow-lg ring-1 ring-slate-200">
        @if (count($seleccion) > 0 && $bloqueos)
            <ul class="list-disc space-y-0.5 rounded-lg bg-red-50 p-3 pl-6 text-sm text-red-800">
                @foreach ($bloqueos as $b)
                    <li>{{ $b }}</li>
                @endforeach
            </ul>
        @endif
        @unless ($puenteActivo)
            <p class="rounded-lg bg-yellow-100 p-3 text-sm font-semibold text-yellow-800">El puente con SICAR está apagado: el lote quedará pendiente hasta que se encienda.</p>
        @endunless
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="mandar" wire:loading.attr="disabled" @disabled(count($seleccion) === 0 || $bloqueos) class="btn-primario px-6 py-3 text-lg">Mandar a SICAR</button>
            <a href="{{ route('sicar.lotes') }}" class="btn-secundario">Lotes SICAR</a>
        <button type="button" wire:click="descargar" wire:loading.attr="disabled" @disabled(count($seleccion) === 0) class="btn-primario">Descargar Excel de las tarjetas marcadas</button>
        <button type="button" wire:click="marcarAlta" wire:confirm="¿Ya capturaste en SICAR los artículos de las tarjetas marcadas? Se les pondrá la etiqueta Material dado de alta en SICAR." @disabled(count($seleccion) === 0) class="btn-secundario">Marcar como dados de alta</button>
    </div>
    </div>
</div>

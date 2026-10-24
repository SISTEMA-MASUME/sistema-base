@php
    $dinero = fn ($n) => ((float) $n < 0 ? '-' : '').'$'.number_format(abs((float) $n), 2);
    $pct = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.').'%';
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
    $periodo = fn ($r) => $meses[$r['inicio']->month - 1].'–'.$meses[$r['fin']->month - 1].' '.$r['anio'];
@endphp
<div class="space-y-4">

    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    @foreach ($programas as ['p' => $p, 'r' => $r])
        @php
            $sig = $r['siguiente_factura'];
            $avance = $sig ? max(2, min(100, round(100 * $r['actual'] / max(1, (float) $sig['desde'])))) : 100;
        @endphp
        <section wire:key="prog-{{ $p->id }}" class="space-y-4 rounded-xl bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-xl font-bold text-marino">{{ $p->proveedor?->nombre }}</h2>
                <p class="text-sm text-slate-600">{{ $p->nombre }}</p>
            </div>

            {{-- Hoy: lo que toca por el trimestre pasado --}}
            <div class="rounded-lg border border-green-200 bg-green-50 p-4">
                <p class="text-sm text-green-900">Este trimestre · T{{ $r['trimestre_anterior'] }}: <strong>{{ $dinero($r['anterior']) }}</strong>{{ $r['oficial_anterior'] ? ' según el portal' : '' }}</p>
                <p class="text-2xl font-extrabold text-green-900">{{ $r['descuentos_hoy'] }}% en factura</p>
                <p class="text-sm text-green-900">{{ $p->bono_en_factura ? 'Incluye' : 'Además, por nota de crédito:' }} bono de volumen {{ $r['bono_real'] ? '(el de tu factura)' : 'aprox.' }} {{ $pct($r['bono_hoy']) }} · {{ $pct($p->anticipado) }} más si pagas por anticipado</p>
            </div>
            <label class="flex items-center gap-2 text-base">
                <input type="checkbox" @checked($p->bono_en_factura) wire:click="alternarBonoEnFactura({{ $p->id }})" class="h-5 w-5 rounded border-slate-300">
                Bono de volumen en factura
            </label>
            @if ($p->bono_en_factura)
                <form wire:submit="guardarBonoFactura({{ $p->id }})" class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="etiqueta" for="bono-{{ $p->id }}">% de bono en tu factura</label>
                        <input id="bono-{{ $p->id }}" type="text" inputmode="decimal" wire:model="bonoFactura.{{ $p->id }}" class="campo w-32" placeholder="ej. 4">
                    </div>
                    <button type="submit" class="btn-secundario">Guardar</button>
                    @error("bonoFactura.{$p->id}") <p class="error-campo w-full">{{ $message }}</p> @enderror
                </form>
            @endif

            @if ($r['lista_desactualizada'])
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-orange-300 bg-orange-50 p-4" data-lista-desactualizada>
                    <p class="text-base text-orange-900">Tu lista <strong>{{ $r['lista']->nombre }}</strong> calcula los costos con <strong>{{ $r['lista']->descuentos }}%</strong>, pero este trimestre te toca <strong>{{ $r['descuentos_hoy'] }}%</strong>.</p>
                    <button type="button" wire:click="actualizarLista({{ $p->id }})" class="btn-primario">Actualizar costos a {{ $r['descuentos_hoy'] }}%</button>
                </div>
            @endif

            {{-- El trimestre que corre: define el siguiente --}}
            <div class="space-y-2">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-base">T{{ $r['trimestre'] }} ({{ $periodo($r) }}): llevas <strong class="text-xl text-marino">{{ $dinero($r['actual']) }}</strong> sin IVA
                        @if ($r['oficial_actual'])<span class="text-sm text-slate-600">(portal al {{ $r['oficial_actual']->fecha->format('d/m') }} + lo comprado después)</span>@endif</p>
                    <p class="text-sm text-slate-600">faltan {{ $r['dias_restantes'] }} {{ $r['dias_restantes'] === 1 ? 'día' : 'días' }}</p>
                </div>
                <div class="h-3 overflow-hidden rounded-full bg-slate-200" role="img" aria-label="Avance al siguiente nivel: {{ $avance }}%">
                    <div class="h-full rounded-full bg-marino" style="width: {{ $avance }}%"></div>
                </div>
                @if ($r['mantener'] && (! $sig || $r['mantener']['desde'] > $sig['desde']))
                    <p class="text-lg font-semibold text-orange-800" data-mantener>Para seguir con el {{ $pct($r['mantener']['factura']) }} en factura el próximo trimestre te faltan {{ $dinero($r['mantener']['falta']) }}</p>
                @endif
                @if ($sig)
                    <p class="text-lg font-semibold text-marino" data-falta>Te faltan {{ $dinero($sig['falta']) }} para el {{ $pct($sig['factura']) }} en factura del próximo trimestre</p>
                @else
                    <p class="text-lg font-semibold text-green-800">Ya estás en el nivel más alto de descuento en factura ({{ $pct($r['nivel_siguiente']['factura']) }}).</p>
                @endif
                <p class="text-sm text-slate-600">
                    Con lo que llevas, el próximo trimestre tendrías <strong>{{ $r['descuentos_siguiente'] }}%</strong> en factura{{ $p->bono_en_factura ? '' : ' y bono aprox. '.$pct($r['bono_siguiente']) }}.
                    @if ($r['siguiente_bono'] && (! $sig || $r['siguiente_bono']['desde'] < $sig['desde']))
                        Con {{ $dinero($r['siguiente_bono']['falta']) }} más, el bono sube a {{ $pct($r['siguiente_bono']['bono']) }}.
                    @endif
                    A este ritmo cerrarías el trimestre en {{ $dinero($r['proyeccion']) }}.
                </p>
                <p class="text-sm text-slate-600">Productos distintos comprados en 12 meses: {{ number_format($r['skus']) }} (aprox.){{ $r['acelerador'] > 0 ? ' · acelerador +'.$pct($r['acelerador']) : '' }}.</p>
            </div>

            {{-- Ajustes a mano --}}
            <details class="rounded-lg border border-slate-200 p-3">
                <summary class="cursor-pointer text-base font-semibold text-marino">Anotar total de {{ $p->proveedor?->nombre === 'URREA' ? 'Urreanet' : 'su portal' }} o compras por fuera ({{ $p->ajustes->count() }})</summary>
                <form wire:submit="agregarAjuste({{ $p->id }})" class="mt-3 grid gap-3 sm:grid-cols-[14rem_10rem_10rem_1fr_auto] sm:items-end">
                    <div><label class="etiqueta" for="aj-t-{{ $p->id }}">¿Qué es?</label>
                        <select id="aj-t-{{ $p->id }}" wire:model="ajuste.{{ $p->id }}.tipo" class="campo">
                            <option value="total">Total del trimestre (portal)</option>
                            <option value="compra">Compra por fuera / nota de crédito</option>
                        </select></div>
                    <div><label class="etiqueta" for="aj-f-{{ $p->id }}">Fecha</label><input id="aj-f-{{ $p->id }}" type="date" wire:model="ajuste.{{ $p->id }}.fecha" class="campo"></div>
                    <div><label class="etiqueta" for="aj-m-{{ $p->id }}">Monto sin IVA</label><input id="aj-m-{{ $p->id }}" type="text" inputmode="decimal" wire:model="ajuste.{{ $p->id }}.monto" class="campo" placeholder="-1500 = nota de crédito"></div>
                    <div><label class="etiqueta" for="aj-n-{{ $p->id }}">Nota</label><input id="aj-n-{{ $p->id }}" type="text" wire:model="ajuste.{{ $p->id }}.nota" class="campo" placeholder="Factura 12345 sin OC"></div>
                    <button type="submit" class="btn-secundario">Anotar</button>
                </form>
                @error("ajuste.{$p->id}.fecha") <p class="error-campo">{{ $message }}</p> @enderror
                @error("ajuste.{$p->id}.monto") <p class="error-campo">{{ $message }}</p> @enderror
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach ($p->ajustes as $a)
                        <li wire:key="aj-{{ $a->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                            <span>{{ $a->fecha->format('d/m/Y') }} · {{ $a->tipo === 'total' ? 'Total del trimestre según el portal' : ($a->monto < 0 ? 'Nota de crédito' : 'Compra por fuera') }}{{ $a->nota ? ' · '.$a->nota : '' }}</span>
                            <span class="flex items-center gap-3"><strong>{{ $dinero($a->monto) }}</strong>
                                <button type="button" wire:click="quitarAjuste({{ $a->id }})" wire:confirm="¿Quitar este ajuste?" class="text-red-700 underline">Quitar</button></span>
                        </li>
                    @endforeach
                </ul>
            </details>

            <details class="rounded-lg border border-slate-200 p-3">
                <summary class="cursor-pointer text-base font-semibold text-marino">Ver la tabla de niveles</summary>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[420px] text-sm">
                        <thead class="text-left text-slate-600"><tr><th class="py-1">Comprado en el trimestre (sin IVA)</th><th class="py-1 text-right">Descuento en factura</th><th class="py-1 text-right">Bono</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($p->niveles as $n)
                                <tr class="{{ (float) $n['desde'] === (float) $r['nivel_siguiente']['desde'] ? 'bg-indigo-50 font-semibold' : '' }}">
                                    <td class="py-1">desde {{ $dinero($n['desde']) }}</td><td class="py-1 text-right">{{ $pct($n['factura']) }}</td><td class="py-1 text-right">{{ $pct($n['bono']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="mt-2 text-xs text-slate-600">Descuento base {{ $pct($p->descuento_base) }} siempre. Acelerador por productos distintos en 12 meses (desde {{ $dinero($p->acelerador_desde) }} al trimestre): @foreach ($p->acelerador ?? [] as $a){{ $a['skus'] }}+ = +{{ $pct($a['bono']) }}@if (! $loop->last), @endif @endforeach. Bono máximo {{ $pct($p->bono_maximo) }}.</p>
                </div>
            </details>
        </section>
    @endforeach

    {{-- Agregar programa --}}
    <section class="space-y-3 rounded-xl bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-marino">{{ $programas->isEmpty() ? 'Empezar con el programa de Urrea' : 'Agregar otro proveedor' }}</h2>
        <div class="relative">
            <label class="etiqueta" for="pg-prov">Proveedor</label>
            <input id="pg-prov" type="search" wire:model.live.debounce.300ms="proveedorTexto" class="campo" autocomplete="off" placeholder="URREA">
            @if ($proveedoresSugeridos->isNotEmpty())
                <ul class="absolute inset-x-0 top-full z-20 mt-1 max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg">
                    @foreach ($proveedoresSugeridos as $s)
                        <li wire:key="pg-{{ $s->id }}"><button type="button" wire:click="elegirProveedor({{ $s->id }})" class="block min-h-11 w-full px-3 py-2 text-left text-sm hover:bg-slate-50">{{ $s->nombre }}</button></li>
                    @endforeach
                </ul>
            @endif
            @if ($proveedorId)<p class="mt-1 text-sm font-semibold text-green-800">✓ Elegido</p>@endif
            @error('proveedorId') <p class="error-campo">{{ $message }}</p> @enderror
        </div>
        <button type="button" wire:click="usarUrreaM1" class="btn-primario">Usar el programa M1 de Urrea 2026</button>
    </section>
</div>

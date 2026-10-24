@php
    $dinero = fn ($n) => ($n < 0 ? '-' : '').'$'.number_format(abs((float) $n), 2);
    $pct = fn ($n) => $n === null ? '—' : number_format((float) $n, 1).'%';
    $entero = fn ($n) => number_format((float) $n, 0);
    $mesesCortos = \App\Support\Tablero::MESES;
    $nombresMeses = \App\Support\Tablero::MESES_LARGOS;
    $r = $resumen;
    $hayDatos = $r['documentos'] > 0 || $r['gastos'] > 0;
@endphp
<div class="space-y-6">
    <form class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow-sm" aria-label="Periodo" onsubmit="return false">
        <div class="min-w-28 flex-1 sm:flex-none">
            <label class="etiqueta" for="t-anio">Año</label>
            <select id="t-anio" wire:model.live="anio" class="campo">
                @foreach ($anios as $a)
                    <option value="{{ $a }}">{{ $a }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-40 flex-1 sm:flex-none">
            <label class="etiqueta" for="t-mes">Mes</label>
            <select id="t-mes" wire:model.live="mes" class="campo">
                <option value="">Todo el año</option>
                @foreach ($nombresMeses as $i => $nombre)
                    <option value="{{ $i + 1 }}">{{ ucfirst($nombre) }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @unless ($hayDatos)
        <p class="rounded-xl bg-white p-6 text-base text-slate-600 shadow-sm">No hay ventas ni gastos en {{ $periodo }}.</p>
    @endunless

    {{-- ===== Corte del periodo: semana, mes o año ===== --}}
    @php
        $c = $corte;
        $ant = $corteAnterior;
        $cambio = function (string $k, bool $subirEsBueno = true) use ($c, $ant, $p) {
            $a = (float) $c[$k];
            $b = (float) $ant[$k];
            if ($b <= 0 || abs($a - $b) < 0.005) {
                return ['texto' => $b <= 0 ? 'sin base para comparar' : 'igual', 'clase' => 'bg-slate-100 text-slate-700'];
            }
            $pct = round(($a - $b) / $b * 100);
            $bueno = $subirEsBueno ? $a > $b : $a < $b;

            return ['texto' => ($a > $b ? '▲ ' : '▼ ').abs($pct).'% vs '.$p['contra'], 'clase' => $bueno ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'];
        };
        $de100 = fn ($x) => $c['venta'] > 0 ? max(0, $x / $c['venta'] * 100) : 0;
        $kpis = [
            ['Venta sin IVA', $c['venta'], $cambio('venta'), $c['documentos'].' facturas y notas', true],
            ['Utilidad bruta', $c['bruta'], $cambio('bruta'), $pct($c['margen_bruto']).' de margen', false],
            ['Gastos', $c['gastos'], $cambio('gastos', false), ($c['venta'] > 0 ? number_format($c['gastos'] / $c['venta'] * 100, 1).'%' : '—').' de la venta', false],
            ['Utilidad neta', $c['neta'], $cambio('neta'), $pct($c['margen_neto']).' · lo que queda limpio', false],
        ];
    @endphp
    <section aria-labelledby="t-corte" class="space-y-3" data-corte="{{ $p['vista'] }}">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h2 id="t-corte" class="text-2xl font-extrabold text-marino">{{ $p['titulo'] }}</h2>
                <p class="text-sm text-slate-600">{{ $p['subtitulo'] }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-xl bg-white p-1 shadow-sm" role="group" aria-label="Ver el corte por">
                    @foreach (['semana' => ['Semana', 'verSemana'], 'mes' => ['Mes', 'verMes'], 'anio' => ['Año', 'verAnio']] as $clave => [$texto, $accion])
                        <button type="button" wire:click="{{ $accion }}" aria-pressed="{{ $p['vista'] === $clave ? 'true' : 'false' }}"
                                class="min-h-10 rounded-lg px-4 text-sm font-bold {{ $p['vista'] === $clave ? 'bg-marino text-white' : 'text-marino hover:bg-slate-100' }}">{{ $texto }}</button>
                    @endforeach
                </div>
                <div class="inline-flex items-center rounded-xl bg-white p-1 shadow-sm">
                    <button type="button" wire:click="mover(-1)" class="flex h-10 w-10 items-center justify-center rounded-lg text-xl text-marino hover:bg-slate-100" aria-label="Periodo anterior">‹</button>
                    <span class="min-w-32 px-2 text-center text-sm font-bold text-marino">{{ $p['titulo'] }}</span>
                    <button type="button" wire:click="mover(1)" class="flex h-10 w-10 items-center justify-center rounded-lg text-xl text-marino hover:bg-slate-100" aria-label="Periodo siguiente">›</button>
                </div>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($kpis as [$nombre, $valor, $cmb, $nota, $oscuro])
                <div class="flex flex-col gap-1.5 rounded-2xl p-4 shadow-sm {{ $oscuro ? 'bg-marino text-white' : 'bg-white' }}">
                    <p class="text-sm font-semibold {{ $oscuro ? 'text-indigo-200' : 'text-slate-600' }}">{{ $nombre }}</p>
                    <p class="text-2xl font-extrabold {{ ! $oscuro && $valor < 0 ? 'text-red-700' : '' }}">{{ $dinero($valor) }}</p>
                    <p class="self-start rounded-full px-2.5 py-0.5 text-xs font-bold {{ $oscuro ? 'bg-white/15 text-white' : $cmb['clase'] }}">{{ $cmb['texto'] }}</p>
                    <p class="text-sm {{ $oscuro ? 'text-indigo-100' : 'text-slate-500' }}">{{ $nota }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-3 lg:grid-cols-2">
            <div class="rounded-2xl bg-white p-4 shadow-sm">
                <h3 class="mb-2 text-lg font-bold text-marino">Corte {{ $p['palabra'] }}</h3>
                <dl class="divide-y divide-slate-100 text-base">
                    @foreach ([
                        ['Venta (sin IVA)', $c['venta'], false, ''],
                        ['− Compra a proveedores', $c['compra'], false, ''],
                        ['= Utilidad bruta', $c['bruta'], true, ''],
                        ['− Gastos de MASUME', $c['gastos'], false, ''],
                        ['= Utilidad neta', $c['neta'], true, $c['neta'] < 0 ? 'text-red-700' : 'text-green-700'],
                        ['Cobrado '.$p['palabra'].' (con IVA)', $c['cobrado'], false, ''],
                        ['Comisiones pagadas', $c['comisiones'], false, ''],
                    ] as [$nombre, $valor, $fuerte, $color])
                        <div class="flex items-baseline justify-between gap-3 py-2">
                            <dt class="{{ $fuerte ? 'font-bold text-slate-900' : 'text-slate-600' }}">{{ $nombre }}</dt>
                            <dd class="{{ $fuerte ? 'font-extrabold' : 'font-semibold' }} {{ $color }}">{{ $dinero($valor) }}</dd>
                        </div>
                    @endforeach
                    <div class="flex items-baseline justify-between gap-3 py-2"><dt class="text-slate-600">Facturas y notas emitidas</dt><dd class="font-semibold">{{ $entero($c['documentos']) }}</dd></div>
                </dl>
            </div>
            <div class="space-y-3 rounded-2xl bg-white p-4 shadow-sm">
                <h3 class="text-lg font-bold text-marino">¿De dónde sale la utilidad?</h3>
                @if ($c['venta'] > 0)
                    <p class="text-sm text-slate-600">De cada $100 que vendimos {{ $p['palabra'] }}:</p>
                    <div class="flex h-9 overflow-hidden rounded-lg text-xs font-bold text-white" aria-hidden="true">
                        <div class="flex items-center justify-center bg-slate-400" style="width: {{ number_format($de100($c['compra']), 1, '.', '') }}%">compra</div>
                        <div class="flex items-center justify-center bg-naranja" style="width: {{ number_format($de100($c['gastos']), 1, '.', '') }}%">gastos</div>
                        <div class="flex items-center justify-center bg-green-700" style="width: {{ number_format($de100($c['neta']), 1, '.', '') }}%">{{ $c['neta'] > 0 ? 'queda' : '' }}</div>
                    </div>
                    <ul class="space-y-1.5 text-base">
                        <li class="flex justify-between"><span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded-sm bg-slate-400"></span>Pagamos al proveedor</span><strong>${{ number_format($de100($c['compra'])) }}</strong></li>
                        <li class="flex justify-between"><span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded-sm bg-naranja"></span>Gastos de MASUME</span><strong>${{ number_format($c['venta'] > 0 ? $c['gastos'] / $c['venta'] * 100 : 0) }}</strong></li>
                        <li class="flex justify-between"><span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded-sm bg-green-700"></span>Nos queda limpio</span><strong class="{{ $c['neta'] < 0 ? 'text-red-700' : 'text-green-700' }}">${{ number_format($c['neta'] / $c['venta'] * 100) }}</strong></li>
                    </ul>
                @else
                    <p class="text-base text-slate-600">No hay ventas {{ $p['palabra'] }} todavía.</p>
                @endif
            </div>
        </div>

        @if ($p['vista'] === 'semana')
            <div class="rounded-2xl bg-white p-4 shadow-sm">
                <h3 class="mb-2 text-lg font-bold text-marino">Últimas 12 semanas</h3>
                <x-grafica.barras
                    :titulo="'Venta y utilidad neta de las últimas 12 semanas'"
                    :categorias="array_column($tendencia, 'etiqueta')"
                    :series="[
                        ['nombre' => 'Venta', 'color' => '#12235a', 'valores' => array_column($tendencia, 'venta')],
                        ['nombre' => 'Utilidad neta', 'color' => '#ef7d22', 'valores' => array_column($tendencia, 'neta')],
                    ]" />
            </div>
            @if ($topSemana)
                <div class="rounded-2xl bg-white p-4 shadow-sm">
                    <h3 class="mb-3 text-lg font-bold text-marino">Top clientes de la semana</h3>
                    @php $maxTop = max(1, $topSemana[0]['venta']); @endphp
                    <ul class="space-y-3">
                        @foreach ($topSemana as $tc)
                            <li>
                                <div class="flex justify-between gap-3 text-sm"><span class="font-semibold">{{ $tc['nombre'] }}</span><span><strong>{{ $dinero($tc['venta']) }}</strong> · <span class="{{ ($tc['margen'] ?? 0) < 10 ? 'text-red-700' : 'text-green-700' }}">{{ $pct($tc['margen']) }}</span></span></div>
                                <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full bg-marino" style="width: {{ number_format(max(0, $tc['venta']) / $maxTop * 100, 1, '.', '') }}%"></div></div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
    </section>

    {{-- ===== Comparativo ===== --}}
    <section aria-labelledby="t-comp" class="space-y-3">
        <h2 id="t-comp" class="text-lg font-semibold text-marino">Contra {{ $mesActual ? $nombresMeses[$mesActual - 1].' '.($anioActual - 1) : ($anioActual === (int) now()->year ? ($anioActual - 1).' al mismo día ('.now()->format('d/m').')' : 'todo '.($anioActual - 1)) }}</h2>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ($comparativo as $c)
                @php
                    $color = $c['mejora'] === null ? 'text-slate-500' : ($c['mejora'] ? 'text-green-700' : 'text-red-700');
                    $flecha = $c['mejora'] === null ? '＝' : (($c['actual'] > $c['anterior']) ? '▲' : '▼');
                @endphp
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <p class="text-sm text-slate-500">{{ $c['nombre'] }}</p>
                    <p class="text-lg font-bold text-marino">{{ $dinero($c['actual']) }}</p>
                    <p class="text-base font-semibold {{ $color }}">
                        <span aria-hidden="true">{{ $flecha }}</span>
                        {{ $c['cambio'] === null ? 'Sin base de comparación' : ($c['cambio'] > 0 ? '+' : '').number_format($c['cambio'], 1).'%' }}
                        <span class="sr-only">{{ $c['mejora'] === null ? '' : ($c['mejora'] ? 'mejora' : 'empeora') }}</span>
                    </p>
                    <p class="text-sm text-slate-500">Antes: {{ $dinero($c['anterior']) }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ===== Métricas ===== --}}
    <section aria-labelledby="t-met" class="space-y-3">
        <h2 id="t-met" class="text-lg font-semibold text-marino">Métricas · {{ $periodo }}</h2>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4">
            @foreach ([
                ['Margen de utilidad neta', $pct($r['margen_neto'])],
                ['Facturas cobradas', $pct($r['cobradas_pct']).' ('.$r['cobrados'].' de '.$r['documentos'].')'],
                ['Gastos / Venta', $pct($r['gastos_venta'])],
                ['Documentos emitidos', $entero($r['documentos'])],
                ['Cobrados', $entero($r['cobrados'])],
                ['Pendientes', $entero($r['pendientes'])],
                ['Monto pendiente (con IVA)', $dinero($r['monto_pendiente'])],
                ['Ticket promedio', $r['ticket'] === null ? '—' : $dinero($r['ticket'])],
                ['Días promedio de cobro', $r['dias_cobro'] === null ? '—' : number_format($r['dias_cobro'], 1).' días'],
                ['Clientes activos', $entero($r['clientes'])],
                ['Comisiones pagadas', $dinero($r['comisiones'])],
                ['Costo por documento', $r['costo_documento'] === null ? '—' : $dinero($r['costo_documento'])],
                ['ROI operativo', $pct($r['roi'])],
            ] as [$nombre, $valor])
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <p class="text-sm text-slate-500">{{ $nombre }}</p>
                    <p class="text-base font-bold sm:text-lg xl:text-xl text-marino">{{ $valor }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ===== Gráficas ===== --}}
    <section aria-labelledby="t-graf" class="space-y-4">
        <h2 id="t-graf" class="text-lg font-semibold text-marino">Gráficas</h2>

        <div class="rounded-xl bg-white p-4 shadow-sm">
            <h3 class="mb-2 text-base font-semibold text-slate-800">Mes por mes de {{ $anioActual }}</h3>
            <x-grafica.barras
                :titulo="'Venta y utilidad neta por mes de '.$anioActual"
                :categorias="$mesesCortos"
                :series="[
                    ['nombre' => 'Venta', 'color' => '#12235a', 'valores' => array_column($mensual, 'venta')],
                    ['nombre' => 'Utilidad neta', 'color' => '#ef7d22', 'valores' => array_column($mensual, 'neta')],
                ]"
                :linea="['nombre' => 'Venta '.($anioActual - 1), 'valores' => $ventaAnterior]" />
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm">
            <h3 class="mb-2 text-base font-semibold text-slate-800">
                {{ $mesActual ? 'Semanas de '.$nombresMeses[$mesActual - 1].' '.$anioActual : 'Últimas 12 semanas' }}
            </h3>
            <x-grafica.barras
                :titulo="'Venta, utilidad bruta y gastos por semana'"
                :categorias="array_column($semanas, 'etiqueta')"
                :series="[
                    ['nombre' => 'Venta', 'color' => '#12235a', 'valores' => array_column($semanas, 'venta')],
                    ['nombre' => 'Utilidad bruta', 'color' => '#ef7d22', 'valores' => array_column($semanas, 'utilidad')],
                    ['nombre' => 'Gastos', 'color' => '#64748b', 'valores' => array_column($semanas, 'gastos')],
                ]"
                :minimo="640" />
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm">
            <h3 class="mb-2 text-base font-semibold text-slate-800">Gastos por categoría · {{ $periodo }}</h3>
            @if ($categorias)
                <x-grafica.barras-horizontales :titulo="'Gastos por categoría de '.$periodo" :filas="$categorias" color="#64748b" />
            @else
                <p class="text-base text-slate-600">No hay gastos en este periodo.</p>
            @endif
        </div>
    </section>

    {{-- ===== Nómina por empleado ===== --}}
    <section aria-labelledby="t-nomina" class="space-y-3">
        <h2 id="t-nomina" class="text-lg font-semibold text-marino">Nómina por empleado · {{ $anioActual }}</h2>
        <div class="rounded-xl bg-white p-4 shadow-sm">
            @if ($nomina['empleados'])
                <x-grafica.barras-apiladas :titulo="'Nómina por empleado y mes de '.$anioActual" :categorias="$mesesCortos" :series="array_map(fn ($e) => ['nombre' => $e['nombre'], 'color' => $e['color'], 'valores' => $e['meses']], $nomina['empleados'])" />
                <details class="mt-3" open>
                    <summary class="cursor-pointer text-base font-medium text-marino">Tabla con los montos</summary>
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-right text-sm">
                            <caption class="sr-only">Nómina por empleado y mes de {{ $anioActual }}</caption>
                            <thead class="text-slate-600">
                                <tr class="border-b border-slate-200">
                                    <th scope="col" class="py-2 pr-3 text-left font-medium">Mes</th>
                                    @foreach ($nomina['empleados'] as $e)
                                        <th scope="col" class="px-2 py-2 font-medium"><span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: {{ $e['color'] }}" aria-hidden="true"></span>{{ $e['nombre'] }}</span></th>
                                    @endforeach
                                    <th scope="col" class="py-2 pl-2 font-semibold text-slate-800">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-800">
                                @foreach ($mesesCortos as $i => $nombreMes)
                                    @if ($nomina['total_mes'][$i] > 0)
                                        <tr>
                                            <th scope="row" class="py-1.5 pr-3 text-left font-normal">{{ $nombreMes }}</th>
                                            @foreach ($nomina['empleados'] as $e)
                                                <td class="px-2 py-1.5 tabular-nums">{{ $e['meses'][$i] > 0 ? $dinero($e['meses'][$i]) : '—' }}</td>
                                            @endforeach
                                            <td class="py-1.5 pl-2 font-semibold tabular-nums">{{ $dinero($nomina['total_mes'][$i]) }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                            <tfoot class="border-t-2 border-slate-200 font-semibold text-marino">
                                <tr>
                                    <th scope="row" class="py-2 pr-3 text-left">Total</th>
                                    @foreach ($nomina['empleados'] as $e)
                                        <td class="px-2 py-2 tabular-nums">{{ $dinero($e['total']) }}<span class="block text-xs font-normal text-slate-500">{{ $nomina['total'] > 0 ? round($e['total'] / $nomina['total'] * 100) : 0 }}%</span></td>
                                    @endforeach
                                    <td class="py-2 pl-2 tabular-nums">{{ $dinero($nomina['total']) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </details>
            @else
                <p class="text-base text-slate-600">No hay nóminas pagadas en {{ $anioActual }}.</p>
            @endif
        </div>
    </section>

    {{-- ===== Top clientes ===== --}}
    <section aria-labelledby="t-top" class="space-y-3">
        <h2 id="t-top" class="text-lg font-semibold text-marino">Top 10 clientes · {{ $periodo }}</h2>
        @if (! $top)
            <p class="rounded-xl bg-white p-6 text-base text-slate-600 shadow-sm">Sin ventas en este periodo.</p>
        @else
            <div class="hidden overflow-hidden rounded-xl bg-white shadow-sm md:block">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-3">#</th><th class="px-4 py-3">Cliente</th>
                            <th class="px-4 py-3 text-right">Venta</th><th class="px-4 py-3 text-right">Utilidad</th>
                            <th class="px-4 py-3 text-right">Margen</th><th class="px-4 py-3 text-right">Docs</th>
                            <th class="px-4 py-3 text-right">Concentración</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($top as $i => $c)
                            <tr>
                                <td class="px-4 py-2 text-slate-500">{{ $i + 1 }}</td>
                                <td class="px-4 py-2 font-medium text-slate-800">{{ $c['nombre'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $dinero($c['venta']) }}</td>
                                <td class="px-4 py-2 text-right">{{ $dinero($c['utilidad']) }}</td>
                                <td class="px-4 py-2 text-right">{{ $pct($c['margen']) }}</td>
                                <td class="px-4 py-2 text-right">{{ $c['documentos'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $pct($c['concentracion']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="space-y-2 md:hidden">
                @foreach ($top as $i => $c)
                    <div class="rounded-xl bg-white p-4 shadow-sm">
                        <p class="text-base font-semibold text-slate-800">{{ $i + 1 }}. {{ $c['nombre'] }}</p>
                        <p class="text-lg font-bold text-marino">{{ $dinero($c['venta']) }}</p>
                        <p class="text-sm text-slate-600">Utilidad {{ $dinero($c['utilidad']) }} · margen {{ $pct($c['margen']) }}</p>
                        <p class="text-sm text-slate-600">{{ $c['documentos'] }} {{ $c['documentos'] === 1 ? 'documento' : 'documentos' }} · {{ $pct($c['concentracion']) }} de la venta</p>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ===== Desglose mensual ===== --}}
    <section aria-labelledby="t-mens" class="space-y-3">
        <h2 id="t-mens" class="text-lg font-semibold text-marino">Mes por mes de {{ $anioActual }}</h2>
        <div class="overflow-x-auto rounded-xl bg-white shadow-sm">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Mes</th>
                        <th class="px-4 py-3 text-right">Venta</th><th class="px-4 py-3 text-right">Compra</th>
                        <th class="px-4 py-3 text-right">Utilidad</th><th class="px-4 py-3 text-right">Margen</th>
                        <th class="px-4 py-3 text-right">Gastos</th><th class="px-4 py-3 text-right">Utilidad neta</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($mensual as $f)
                        <tr class="{{ $mesActual === $f['mes'] ? 'bg-naranja/10' : '' }}">
                            <td class="px-4 py-2 font-medium text-slate-800">{{ ucfirst($nombresMeses[$f['mes'] - 1]) }}</td>
                            <td class="px-4 py-2 text-right">{{ $dinero($f['venta']) }}</td>
                            <td class="px-4 py-2 text-right">{{ $dinero($f['compra']) }}</td>
                            <td class="px-4 py-2 text-right">{{ $dinero($f['utilidad']) }}</td>
                            <td class="px-4 py-2 text-right">{{ $pct($f['margen']) }}</td>
                            <td class="px-4 py-2 text-right">{{ $dinero($f['gastos']) }}</td>
                            <td class="px-4 py-2 text-right font-semibold {{ $f['neta'] < 0 ? 'text-red-700' : 'text-green-700' }}">{{ $dinero($f['neta']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-slate-50 font-bold text-marino">
                    <tr>
                        <td class="px-4 py-3">Total {{ $anioActual }}</td>
                        <td class="px-4 py-3 text-right">{{ $dinero($totalMensual['venta']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $dinero($totalMensual['compra']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $dinero($totalMensual['utilidad']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $pct($totalMensual['margen']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $dinero($totalMensual['gastos']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $dinero($totalMensual['neta']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</div>

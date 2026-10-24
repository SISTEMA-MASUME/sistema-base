<div class="space-y-4">
    @php
        $dinero = fn ($v) => '$'.number_format((float) $v, 2);
        $n = fn ($v) => number_format((int) $v);
        $etiquetas = [
            'cliente' => 'Cliente', 'concepto' => 'Concepto', 'fecha' => 'Fecha de la factura', 'folio' => 'Folio', 'tipo' => 'Tipo de documento',
            'costo' => 'Compra (costo)', 'estatus' => 'Estatus', 'fecha_pago' => 'Fecha de pago', 'venta' => 'Venta (sin IVA)', 'iva' => 'IVA',
            'retencion' => 'Retención', 'total' => 'Pago (total)', 'portal' => 'Estatus del portal', 'sustituta' => 'Factura sustituta', 'cuenta' => 'Cuenta de depósito',
        ];
    @endphp
    @error('archivo')
        <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $message }}</div>
    @enderror

    @if ($resultado || $resultadoNomina || $resultadoGastos || $resultadoSaldos)
        <section class="space-y-4 rounded-xl bg-white p-5 shadow-sm">
            @if ($resultado)
                @php $t = $resultado['totales']; @endphp
                <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">
                    Listo: se importaron {{ $n($t['insertadas']) }} facturas y notas de venta del Sheets
                    ({{ $n($t['ya_estaban']) }} ya estaban{{ $t['repetidas'] ? ' y '.$n($t['repetidas']).' venían repetidas en el Excel' : '' }}).
                </div>
            @endif
            @if ($resultadoNomina)
                @php
                    $rs = $resultadoNomina['semanas']['a_importar'];
                    $rr = $resultadoNomina['repartos']['a_importar'];
                    $ya = $resultadoNomina['semanas']['ya_estaban'] + $resultadoNomina['repartos']['ya_estaban'];
                @endphp
                <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ "Listo: se importaron {$n($rs)} ".($rs === 1 ? 'semana' : 'semanas')." y {$n($rr)} ".($rr === 1 ? 'reparto' : 'repartos')." del historial de nómina ({$n($ya)} ya estaban)." }}</div>
            @endif
            @if ($resultadoGastos)
                @php $g = $resultadoGastos['totales']; @endphp
                <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">
                    Listo: se importaron {{ $n($g['insertadas']) }} {{ $g['insertadas'] === 1 ? 'gasto' : 'gastos' }} de las hojas de gastos ({{ $n($g['ya_estaban']) }} ya estaban).
                </div>
            @endif
            @if ($resultadoSaldos)
                @if ($resultadoSaldos['omitido'])
                    <div role="status" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">Los saldos no se importaron: ya había un corte "Importado del Sheets".</div>
                @else
                    <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">Listo: se importaron {{ $n(count($resultadoSaldos['cuentas'])) }} cuentas con sus saldos de hoy (un corte nuevo).</div>
                @endif
            @endif
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (($resultado['hojas'] ?? []) as $h)
                    <div class="rounded-lg bg-slate-50 p-3">
                        <p class="text-sm font-semibold text-marino">{{ $h['nombre'] }}</p>
                        @if ($h['error'])
                            <p class="text-sm text-red-700">{{ $h['error'] }}</p>
                        @else
                            <p class="text-2xl font-bold text-green-800">{{ $n($h['insertadas'] ?? 0) }}</p>
                            <p class="text-xs text-slate-600">importadas · {{ $n($h['ya_estaban']) }} ya estaban · {{ $n($h['repetidas']) }} repetidas</p>
                        @endif
                    </div>
                @endforeach
            </div>
            @if ($resultadoGastos)
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($resultadoGastos['hojas'] as $h)
                        <div class="rounded-lg bg-slate-50 p-3">
                            <p class="text-sm font-semibold text-marino">{{ $h['nombre'] }}</p>
                            @if ($h['error'])
                                <p class="text-sm text-red-700">{{ $h['error'] }}</p>
                            @else
                                <p class="text-2xl font-bold text-green-800">{{ $n($h['a_importar']) }}</p>
                                <p class="text-xs text-slate-600">importados · {{ $n($h['ya_estaban']) }} ya estaban · {{ $n($h['saltadas']) }} saltados</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="flex flex-col gap-2 sm:flex-row">
                @if ($resultado)
                    <a href="{{ route('finanzas.ventas') }}" class="btn-primario">Ir a Ventas</a>
                @endif
                @if ($resultadoNomina)
                    <a href="{{ route('finanzas.nomina', ['ver' => 'historial']) }}" class="{{ $resultado ? 'btn-secundario' : 'btn-primario' }}">Ver el historial de nómina</a>
                @endif
                @if ($resultadoSaldos && ! $resultadoSaldos['omitido'])
                    <a href="{{ route('finanzas.mis-finanzas') }}" class="{{ $resultado || $resultadoNomina || $resultadoGastos ? 'btn-secundario' : 'btn-primario' }}">Ir a Mis finanzas</a>
                @endif
                @if ($resultadoGastos)
                    <a href="{{ route('finanzas.gastos', ['anio' => 'todos']) }}" class="{{ $resultado || $resultadoNomina ? 'btn-secundario' : 'btn-primario' }}">Ir a Gastos</a>
                @endif
                <button type="button" wire:click="cancelar" class="btn-secundario">Subir otro archivo</button>
            </div>
        </section>
    @elseif ($clave === '')
        <section class="space-y-3 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">1. En Google Sheets: Archivo → Descargar → Excel (.xlsx)</h2>
            <h2 class="pt-2 text-lg font-semibold text-marino">2. Súbelo aquí</h2>
            <input type="file" wire:model="archivo" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="text-base">
            <div wire:loading wire:target="archivo" class="text-sm text-slate-600">Leyendo el Excel…</div>
        </section>
    @else
        @php
            $t = $previa['totales'] ?? ['a_importar' => 0];
            $pn = $previaNomina ?: null;
            $nominaNueva = $pn ? $pn['semanas']['a_importar'] + $pn['repartos']['a_importar'] : 0;
            $pg = $previaGastos ?: null;
            $gastosNuevos = $pg ? $pg['totales']['a_importar'] : 0;
            $ps = $previaSaldos ?: null;
            $saldosNuevos = $ps && $ps['cuentas'] && (! $ps['ya_importado'] || $reimportarSaldos) ? count($ps['cuentas']) : 0;
        @endphp
        <section class="space-y-4 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">3. Revisa antes de importar</h2>

            @if ($previa)
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg bg-green-50 p-3"><dt class="text-sm text-slate-600">Facturas y notas nuevas</dt><dd class="text-2xl font-bold text-green-800">{{ $n($t['a_importar']) }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-3"><dt class="text-sm text-slate-600">Ya estaban en el sistema</dt><dd class="text-2xl font-bold">{{ $n($t['ya_estaban']) }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-3"><dt class="text-sm text-slate-600">Repetidas en el Excel</dt><dd class="text-2xl font-bold">{{ $n($t['repetidas']) }}</dd></div>
                <div class="rounded-lg {{ $t['saltadas'] ? 'bg-amber-50' : 'bg-slate-50' }} p-3"><dt class="text-sm text-slate-600">No se pueden importar</dt><dd class="text-2xl font-bold">{{ $n($t['saltadas']) }}</dd></div>
            </dl>
            <dl class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-slate-50 p-3"><dt class="text-sm text-slate-600">Venta (sin IVA)</dt><dd class="text-xl font-bold">{{ $dinero($t['venta']) }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-3"><dt class="text-sm text-slate-600">Costo</dt><dd class="text-xl font-bold">{{ $dinero($t['costo']) }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-3"><dt class="text-sm text-slate-600">Utilidad</dt><dd class="text-xl font-bold">{{ $dinero($t['utilidad']) }}</dd></div>
            </dl>
            <p class="text-sm text-slate-500">
                Pagadas {{ $n($t['pagadas']) }} · Pendientes {{ $n($t['pendientes']) }} · Canceladas {{ $n($t['canceladas']) }}
            </p>

            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($previa['hojas'] as $h)
                    <article class="space-y-2 rounded-lg border border-slate-200 p-4">
                        <header class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <h3 class="text-base font-semibold text-marino">{{ $h['nombre'] }}</h3>
                            @if ($h['fila_encabezado'])
                                <span class="text-xs text-slate-500">Encabezados en el renglón {{ $h['fila_encabezado'] }}</span>
                            @endif
                        </header>

                        @if ($h['error'])
                            <p class="rounded-lg border border-red-300 bg-red-50 p-2 text-sm text-red-800">{{ $h['error'] }} Esta hoja no se importa.</p>
                        @else
                            <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
                                <dt class="text-slate-600">Renglones leídos</dt><dd class="text-right font-medium">{{ $n($h['filas']) }}</dd>
                                <dt class="text-slate-600">A importar</dt><dd class="text-right font-semibold text-green-800">{{ $n($h['a_importar']) }}</dd>
                                <dt class="pl-3 text-slate-500">Pagadas · Pendientes · Canceladas</dt><dd class="text-right text-slate-600">{{ $n($h['pagadas']) }} · {{ $n($h['pendientes']) }} · {{ $n($h['canceladas']) }}</dd>
                                <dt class="text-slate-600">Ya estaban en el sistema</dt><dd class="text-right font-medium">{{ $n($h['ya_estaban']) }}</dd>
                                <dt class="text-slate-600">Repetidas en el Excel</dt><dd class="text-right font-medium">{{ $n($h['repetidas']) }}</dd>
                                @if (($h['completadas'] ?? 0) > 0)
                                    <dt class="text-slate-600">Completaron montos de otra hoja</dt><dd class="text-right font-medium">{{ $n($h['completadas']) }}</dd>
                                @endif
                                <dt class="text-slate-600">No se pueden importar</dt><dd class="text-right font-medium">{{ $n($h['saltadas']) }}</dd>
                                <dt class="text-slate-600">Venta (sin IVA)</dt><dd class="text-right font-medium">{{ $dinero($h['venta']) }}</dd>
                                <dt class="text-slate-600">Costo</dt><dd class="text-right font-medium">{{ $dinero($h['costo']) }}</dd>
                                <dt class="text-slate-600">Utilidad</dt><dd class="text-right font-medium">{{ $dinero($h['utilidad']) }}</dd>
                            </dl>

                            @if ($h['repetidas_folios'])
                                <details class="text-sm text-slate-600">
                                    <summary class="cursor-pointer">Folios repetidos (solo entra el primero)</summary>
                                    <p class="mt-1">{{ implode(', ', $h['repetidas_folios']) }}@if ($h['repetidas'] > count($h['repetidas_folios'])) … y {{ $n($h['repetidas'] - count($h['repetidas_folios'])) }} más @endif</p>
                                </details>
                            @endif

                            @if ($h['anomalias_total'])
                                <details class="text-sm text-slate-600">
                                    <summary class="cursor-pointer text-amber-800">{{ $n($h['anomalias_total']) }} {{ $h['anomalias_total'] === 1 ? 'dato por revisar' : 'datos por revisar' }}</summary>
                                    <ul class="mt-1 list-disc space-y-0.5 pl-5">
                                        @foreach ($h['anomalias'] as $a)
                                            <li>{{ $a }}</li>
                                        @endforeach
                                    </ul>
                                    @if ($h['anomalias_total'] > count($h['anomalias']))
                                        <p class="mt-1 text-xs text-slate-500">…y {{ $n($h['anomalias_total'] - count($h['anomalias'])) }} más (se muestran las primeras {{ count($h['anomalias']) }}).</p>
                                    @endif
                                </details>
                            @endif
                        @endif
                    </article>
                @endforeach
            </div>

            @if ($previa['cuentas'])
                <div class="rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
                    <p class="font-semibold">Cuentas de depósito que aparecen</p>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach ($previa['cuentas'] as $c)
                            <li>{{ $c['nombre'] }}: {{ $n($c['ventas']) }} {{ $c['ventas'] === 1 ? 'venta' : 'ventas' }}{{ $c['nueva'] ? ' (se creará la cuenta)' : '' }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <details class="text-sm text-slate-600">
                <summary class="cursor-pointer">Columnas que reconocí en cada hoja</summary>
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    @foreach ($previa['hojas'] as $h)
                        <li>
                            <strong>{{ $h['nombre'] }}:</strong>
                            @foreach ($h['columnas'] as $campo => $letras)
                                {{ $etiquetas[$campo] ?? $campo }} ({{ implode(' o ', (array) $letras) }}){{ $loop->last ? '.' : ',' }}
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            </details>

            @endif

            @if ($pn)
                <article class="space-y-3 rounded-lg border border-slate-200 p-4">
                    <h3 class="text-base font-semibold text-marino">Historial de nómina (hoja {{ $pn['hoja'] }})</h3>
                    @if ($pn['error'])
                        <p class="rounded-lg border border-red-300 bg-red-50 p-2 text-sm text-red-800">{{ $pn['error'] }} Esta hoja no se importa.</p>
                    @else
                        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="rounded-lg bg-green-50 p-3"><dt class="text-sm text-slate-600">Semanas nuevas</dt><dd class="text-2xl font-bold text-green-800">{{ $n($pn['semanas']['a_importar']) }}</dd>
                                @if ($pn['semanas']['primera'])<dd class="text-xs text-slate-600">de la {{ $pn['semanas']['primera'] }} a la {{ $pn['semanas']['ultima'] }}</dd>@endif</div>
                            <div class="rounded-lg bg-green-50 p-3"><dt class="text-sm text-slate-600">Repartos del socio nuevos</dt><dd class="text-2xl font-bold text-green-800">{{ $n($pn['repartos']['a_importar']) }}</dd></div>
                            <div class="rounded-lg bg-slate-50 p-3"><dt class="text-sm text-slate-600">Ya estaban en el sistema</dt><dd class="text-2xl font-bold">{{ $n($pn['semanas']['ya_estaban'] + $pn['repartos']['ya_estaban']) }}</dd></div>
                            <div class="rounded-lg bg-slate-50 p-3"><dt class="text-sm text-slate-600">Total que se pagó</dt><dd class="text-xl font-bold">{{ $dinero($pn['semanas']['total'] + $pn['repartos']['total']) }}</dd></div>
                        </dl>
                        @if ($pn['sin_usuario'])
                            <p class="text-sm text-slate-600">Sin usuario en el sistema: {{ implode(', ', $pn['sin_usuario']) }}</p>
                        @endif
                        @if ($pn['semanas']['detalle'] || $pn['repartos']['detalle'])
                            <details class="text-sm text-slate-600">
                                <summary class="cursor-pointer">Ver semanas y repartos del Excel</summary>
                                <ul class="mt-1 space-y-0.5 pl-1">
                                    @foreach ($pn['semanas']['detalle'] as $d)
                                        <li>{{ $d['etiqueta'] }}: {{ $d['personas'] }} personas · {{ $dinero($d['total']) }} {{ $d['nueva'] ? '' : '(ya estaba)' }}</li>
                                    @endforeach
                                    @foreach ($pn['repartos']['detalle'] as $d)
                                        <li>Reparto del socio, {{ $d['etiqueta'] }}: {{ $dinero($d['total']) }} {{ $d['nueva'] ? '' : '(ya estaba)' }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                        @if ($pn['anomalias_total'])
                            <details class="text-sm text-slate-600">
                                <summary class="cursor-pointer text-amber-800">{{ $n($pn['anomalias_total']) }} {{ $pn['anomalias_total'] === 1 ? 'dato por revisar' : 'datos por revisar' }}</summary>
                                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                                    @foreach ($pn['anomalias'] as $a)
                                        <li>{{ $a }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    @endif
                </article>
            @endif

            @if ($pg)
                <article class="space-y-3 rounded-lg border border-slate-200 p-4">
                    <h3 class="text-base font-semibold text-marino">Gastos (hojas GASTOS)</h3>
                    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-lg bg-green-50 p-3"><dt class="text-sm text-slate-600">Gastos nuevos</dt><dd class="text-2xl font-bold text-green-800">{{ $n($pg['totales']['a_importar']) }}</dd></div>
                        <div class="rounded-lg bg-slate-50 p-3"><dt class="text-sm text-slate-600">Ya estaban en el sistema</dt><dd class="text-2xl font-bold">{{ $n($pg['totales']['ya_estaban']) }}</dd></div>
                        <div class="rounded-lg {{ $pg['totales']['saltadas'] ? 'bg-amber-50' : 'bg-slate-50' }} p-3"><dt class="text-sm text-slate-600">No se pueden importar</dt><dd class="text-2xl font-bold">{{ $n($pg['totales']['saltadas']) }}</dd></div>
                        <div class="rounded-lg bg-slate-50 p-3"><dt class="text-sm text-slate-600">Movimientos entre cuentas</dt><dd class="text-2xl font-bold">{{ $n($pg['totales']['movimientos']) }}</dd><dd class="text-xs text-slate-500">no suman como gasto</dd></div>
                    </dl>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <caption class="pb-1 text-left text-sm text-slate-600">Lo que traen las hojas por año (sin los movimientos entre cuentas)</caption>
                            <thead class="text-left text-xs uppercase text-slate-500">
                                <tr><th class="py-1 pr-3">Año</th><th class="py-1 pr-3 text-right">Renglones</th><th class="py-1 pr-3 text-right">MASUME</th><th class="py-1 text-right">Personal</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($pg['totales']['por_anio'] as $anio => $x)
                                    <tr><td class="py-1 pr-3 font-medium">{{ $anio }}</td><td class="py-1 pr-3 text-right">{{ $n($x['n']) }}</td><td class="py-1 pr-3 text-right">{{ $dinero($x['masume']) }}</td><td class="py-1 text-right">{{ $dinero($x['personal']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-3">
                        @foreach ($pg['hojas'] as $h)
                            <article class="space-y-2 rounded-lg border border-slate-200 p-3">
                                <header class="flex flex-wrap items-baseline justify-between gap-x-3">
                                    <h4 class="text-base font-semibold text-marino">{{ $h['nombre'] }}</h4>
                                    @if ($h['fila_encabezado'])
                                        <span class="text-xs text-slate-500">Encabezados en el renglón {{ $h['fila_encabezado'] }}</span>
                                    @endif
                                </header>
                                @if ($h['error'])
                                    <p class="rounded-lg border border-red-300 bg-red-50 p-2 text-sm text-red-800">{{ $h['error'] }} Esta hoja no se importa.</p>
                                @else
                                    <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
                                        <dt class="text-slate-600">Renglones leídos</dt><dd class="text-right font-medium">{{ $n($h['filas']) }}</dd>
                                        <dt class="text-slate-600">A importar</dt><dd class="text-right font-semibold text-green-800">{{ $n($h['a_importar']) }}</dd>
                                        <dt class="text-slate-600">Ya estaban en el sistema</dt><dd class="text-right font-medium">{{ $n($h['ya_estaban']) }}</dd>
                                        <dt class="text-slate-600">No se pueden importar</dt><dd class="text-right font-medium">{{ $n($h['saltadas']) }}</dd>
                                        @foreach ($h['por_anio'] as $anio => $x)
                                            <dt class="text-slate-600">{{ $anio }}: MASUME</dt><dd class="text-right font-medium">{{ $dinero($x['masume']) }}</dd>
                                            @if ($x['personal'] != 0)
                                                <dt class="text-slate-600">{{ $anio }}: personal</dt><dd class="text-right font-medium">{{ $dinero($x['personal']) }}</dd>
                                            @endif
                                        @endforeach
                                    </dl>
                                    @if ($h['anomalias_total'])
                                        <details class="text-sm text-slate-600">
                                            <summary class="cursor-pointer text-amber-800">{{ $n($h['anomalias_total']) }} {{ $h['anomalias_total'] === 1 ? 'dato por revisar' : 'datos por revisar' }}</summary>
                                            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                                                @foreach ($h['anomalias'] as $a)
                                                    <li>{{ $a }}</li>
                                                @endforeach
                                            </ul>
                                            @if ($h['anomalias_total'] > count($h['anomalias']))
                                                <p class="mt-1 text-xs text-slate-500">…y {{ $n($h['anomalias_total'] - count($h['anomalias'])) }} más (se muestran las primeras {{ count($h['anomalias']) }}).</p>
                                            @endif
                                        </details>
                                    @endif
                                @endif
                            </article>
                        @endforeach
                    </div>
                    @if ($pg['cuentas'])
                        <details class="text-sm text-slate-700">
                            <summary class="cursor-pointer font-semibold">Cuentas de "Pagado con" que aparecen ({{ $n(count($pg['cuentas'])) }})</summary>
                            <ul class="mt-1 list-disc pl-5">
                                @foreach ($pg['cuentas'] as $c)
                                    <li>{{ $c['nombre'] }}: {{ $n($c['gastos']) }} {{ $c['gastos'] === 1 ? 'gasto' : 'gastos' }}{{ $c['nueva'] ? ' (se creará la cuenta)' : '' }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </article>
            @endif

            @if ($ps)
                <article class="space-y-3 rounded-lg border border-slate-200 p-4">
                    <h3 class="text-base font-semibold text-marino">Cuentas y saldos (hojas {{ implode(' y ', $ps['hojas']) }})</h3>
                    @foreach ($ps['avisos'] as $aviso)
                        <p role="alert" class="rounded-lg border border-red-300 bg-red-50 p-2 text-sm text-red-800">{{ $aviso }}</p>
                    @endforeach
                    @if ($ps['ya_importado'])
                        <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                            Los saldos ya se importaron antes.
                            <label class="mt-2 flex items-center gap-2 text-base"><input type="checkbox" wire:model.live="reimportarSaldos" class="h-5 w-5"> Importarlos otra vez de todos modos (crea otro corte)</label>
                        </div>
                    @endif
                    @if ($ps['cuentas'])
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-1 pr-3">Cuenta</th><th class="py-1 pr-3">Tipo</th><th class="py-1 pr-3 text-right">Saldo</th><th class="py-1">Hoja</th></tr></thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($ps['cuentas'] as $c)
                                        <tr><td class="py-1 pr-3">{{ $c['nombre'] }}{{ $c['nueva'] ? ' (nueva)' : '' }}</td><td class="py-1 pr-3">{{ \App\Models\Cuenta::TIPOS[$c['tipo']] ?? $c['tipo'] }}</td><td class="py-1 pr-3 text-right">{{ $dinero($c['saldo']) }}</td><td class="py-1">{{ $c['origen'] }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-slate-600">No hay cuentas que importar de estas hojas.</p>
                    @endif
                    @if ($ps['conflictos'])
                        <details class="text-sm text-slate-700">
                            <summary class="cursor-pointer font-medium">{{ count($ps['conflictos']) }} cuentas traen saldos distintos en CONTROL y SALDOS (se usa CONTROL)</summary>
                            <ul class="mt-1 list-disc space-y-0.5 pl-5">@foreach ($ps['conflictos'] as $x)<li>{{ $x }}</li>@endforeach</ul>
                        </details>
                    @endif
                </article>
            @endif

            <div class="flex flex-col gap-2 sm:flex-row">
                @if ($t['a_importar'] > 0 || $nominaNueva > 0 || $gastosNuevos > 0 || $saldosNuevos > 0)
                    @php
                        $queImporta = array_filter([
                            $t['a_importar'] > 0 ? $n($t['a_importar']).' facturas y notas de venta' : null,
                            $nominaNueva > 0 ? $n($pn['semanas']['a_importar']).' semanas y '.$n($pn['repartos']['a_importar']).' repartos del historial de nómina' : null,
                            $gastosNuevos > 0 ? $n($gastosNuevos).' gastos' : null,
                            $saldosNuevos > 0 ? $n($saldosNuevos).' cuentas con sus saldos' : null,
                        ]);
                    @endphp
                    <button type="button" wire:click="importar" wire:loading.attr="disabled" wire:target="importar"
                            wire:confirm="¿Importar {{ implode(' y ', $queImporta) }} del Sheets?"
                            class="btn-primario">Importar</button>
                @else
                    <p class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-base text-slate-700">No hay nada nuevo que importar: todo lo del Excel ya está en el sistema.</p>
                @endif
                <button type="button" wire:click="cancelar" class="btn-secundario">Cancelar</button>
            </div>
            <div wire:loading wire:target="importar" class="text-sm text-slate-600">Importando… no cierres la página.</div>
        </section>
    @endif
</div>

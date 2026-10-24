@php
    use App\Support\Solicitudes as Sol;
    $colores = ['verde' => 'bg-green-100 text-green-800', 'amarillo' => 'bg-amber-100 text-amber-800', 'rojo' => 'bg-red-100 text-red-700'];
    $meses = collect(range(0, 23))->mapWithKeys(fn ($i) => [now()->startOfMonth()->subMonths($i)->format('Y-m') => now()->startOfMonth()->subMonths($i)->locale('es')->translatedFormat('F Y')]);
@endphp
<div>
    @if ($error)
        <div role="alert" class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
    @endif

    <div class="mb-4 flex justify-end">
        <a href="{{ route('solicitudes.nueva') }}" class="btn-primario w-full sm:w-auto">+ Nueva solicitud</a>
    </div>

    {{-- Pestañas --}}
    <div class="mb-4 flex flex-wrap gap-2" role="tablist">
        @foreach (\App\Livewire\Solicitudes\Index::PESTANAS as $clave => $texto)
            <button type="button" role="tab" wire:click="$set('pestana', '{{ $clave }}')" aria-selected="{{ $pestana === $clave ? 'true' : 'false' }}"
                    class="inline-flex min-h-11 items-center gap-2 rounded-lg px-4 py-2 text-base font-medium {{ $pestana === $clave ? 'bg-marino text-white' : 'bg-white text-slate-700 shadow-sm hover:bg-slate-50' }}">
                {{ $texto }}
                @if ($clave !== 'tiempos')
                    <span class="rounded-full px-2 text-xs font-bold {{ $pestana === $clave ? 'bg-white/20' : 'bg-slate-100' }}">{{ $conteos[$clave] ?? 0 }}</span>
                @endif
            </button>
        @endforeach
    </div>

    @if ($pestana === 'cotizada' && $buscar === '')
        <p class="mb-3 text-sm text-slate-600">Últimas 24 horas</p>
    @endif

    @if ($pestana === 'tiempos')
        @php
            $m = $metricas;
            $vs = $m['vendedores'];
            $maxEnviadas = max(1, ...array_column($vs ?: [['total' => 0]], 'total'));
            $maxPartidas = max(1, ...array_column($vs ?: [['partidas' => 0]], 'partidas'));
            $maxMonto = max(1, ...array_column($vs ?: [['monto' => 0]], 'monto'));
            $maxPedidos = max(1, ...array_column($vs ?: [['pedidos' => 0]], 'pedidos'));
            $ancho = fn ($n, $max) => $n > 0 ? number_format(max(1, $n / $max * 100), 1, '.', '').'%' : '0%';
            // Escala de las barras de velocidad: 0–1 h, 1–2 h y 2–4 h ocupan un tercio cada uno; más de 4 h se queda al final.
            $posicion = function ($min) {
                if ($min === null) {
                    return null;
                }
                $p = $min <= 60 ? $min / 60 : ($min <= 120 ? 1 + ($min - 60) / 60 : 2 + min(1, ($min - 120) / 120));

                return number_format(min(98.5, $p / 3 * 100), 1, '.', '');
            };
            $iniciales = fn ($n) => mb_strtoupper(collect(preg_split('/\s+/', trim($n)))->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode(''));
            $pct = fn ($n, $t) => $t > 0 ? number_format($n / $t * 100, 1, '.', '') : '0';
            $enviadasMes = array_sum(array_column($vs, 'total'));
        @endphp

        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <p class="text-2xl font-extrabold text-marino">{{ ucfirst($mesTexto) }}</p>
            <div class="w-full sm:w-64">
                <label class="etiqueta" for="mes">Mes (por fecha de llegada)</label>
                <select id="mes" wire:model.live="mes" class="campo">
                    @foreach ($meses as $valor => $texto)
                        <option value="{{ $valor }}">{{ ucfirst($texto) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Resumen del mes --}}
        <section aria-label="Resumen del mes" class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="flex flex-col gap-1 rounded-2xl bg-marino p-5 text-white shadow-sm">
                <p class="text-sm font-semibold text-indigo-200">Monto cotizado y enviado</p>
                <p class="text-3xl font-extrabold">${{ number_format($m['monto']) }}</p>
                <p class="text-sm text-indigo-100">sin IVA · {{ $enviadasMes }} cotizaciones · {{ number_format($m['partidas']) }} partidas</p>
            </div>
            <div class="flex flex-col gap-1 rounded-2xl bg-white p-5 shadow-sm">
                <p class="text-sm font-semibold text-slate-600">Utilidad cotizada</p>
                <p class="text-3xl font-extrabold text-slate-900">${{ number_format($m['utilidad']) }}</p>
                <div class="flex items-center gap-2">
                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200" aria-hidden="true"><div class="h-full bg-naranja" style="width: {{ $ancho($m['utilidad'], $m['monto'] ?: 1) }}"></div></div>
                    <span class="text-sm font-bold text-orange-800">{{ $pct($m['utilidad'], $m['monto']) }}% del monto</span>
                </div>
            </div>
            <div class="flex flex-col gap-1 rounded-2xl bg-white p-5 shadow-sm">
                <p class="text-sm font-semibold text-slate-600">Solicitudes que llegaron</p>
                <p class="text-3xl font-extrabold text-slate-900">{{ $m['llegaron'] }}</p>
                <div class="flex h-2.5 overflow-hidden rounded-full bg-slate-200" aria-hidden="true">
                    <div class="bg-green-700" style="width: {{ $ancho($m['cotizadas'], max(1, $m['llegaron'])) }}"></div>
                    <div class="bg-naranja" style="width: {{ $ancho($m['en_proceso'], max(1, $m['llegaron'])) }}"></div>
                </div>
                <p class="text-sm text-slate-600"><strong class="text-green-700">{{ $m['cotizadas'] }} cotizadas</strong> · <strong class="text-orange-800">{{ $m['en_proceso'] }} en proceso</strong> · {{ $m['pendientes'] }} pendientes · {{ $m['descartadas'] }} descartadas</p>
            </div>
            <div class="flex flex-col gap-1 rounded-2xl bg-white p-5 shadow-sm">
                <p class="text-sm font-semibold text-slate-600">Hechas directo en Cotizaciones</p>
                <p class="text-3xl font-extrabold text-slate-900">{{ $m['directas'] }}</p>
                @if ($m['directas'])<p class="text-sm text-slate-600">Mediana <strong class="text-slate-900">{{ Sol::humano($m['mediana_directas']) }}</strong></p>@endif
            </div>
        </section>

        {{-- Velocidad --}}
        <section aria-label="Velocidad" class="mb-4 grid gap-3 md:grid-cols-2">
            @foreach ([['Tiempo para tomarla', $m['mediana_tomar'], $m['promedio_tomar'], 'text-green-700', 'bg-green-700'],
                       ['Tiempo para enviarla', $m['mediana_enviar'], $m['promedio_enviar'], 'text-orange-800', 'bg-orange-800']] as [$titulo, $mediana, $promedio, $color, $marca])
                <div class="flex flex-col gap-3 rounded-2xl bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-bold text-marino">{{ $titulo }}</h2>
                    @if ($mediana === null)
                        <p class="text-slate-500">Sin datos este mes</p>
                    @else
                        <p class="text-base text-slate-700">Mediana <strong class="text-xl {{ $color }}">{{ Sol::humano($mediana) }}</strong> · <span class="text-marino">Promedio <strong>{{ Sol::humano($promedio) }}</strong></span></p>
                        <div class="flex flex-col gap-1">
                            <div class="relative h-3 rounded-full" style="background: linear-gradient(90deg, #dcfce7 0%, #dcfce7 33.3%, #fef3c7 33.3%, #fef3c7 66.7%, #fee2e2 66.7%, #fee2e2 100%)" aria-hidden="true">
                                <div class="absolute -top-1 h-5 w-1 rounded {{ $marca }}" style="left: {{ $posicion($mediana) }}%"></div>
                                <div class="absolute -top-1 h-5 w-1 rounded bg-marino" style="left: {{ $posicion($promedio) }}%"></div>
                            </div>
                            <div class="flex justify-between text-xs text-slate-500"><span>0</span><span>1 h</span><span>2 h</span><span>4 h +</span></div>
                        </div>
                    @endif
                </div>
            @endforeach
        </section>

        {{-- Por vendedor --}}
        <section aria-label="Por vendedor" class="mb-4 flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm sm:p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-xl font-extrabold text-marino">Por vendedor ({{ $mesTexto }})</h2>
                <div class="flex flex-wrap gap-4 text-sm text-slate-600">
                    <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-marino"></span>Desde Por cotizar</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-naranja"></span>Directo en Cotizaciones</span>
                    <span>Ordenado por monto</span>
                </div>
            </div>

            @forelse ($vs as $i => $v)
                <article wire:key="vend-{{ $i }}" class="flex flex-wrap gap-5 rounded-xl border border-slate-200 p-4">
                    <div class="flex min-w-0 flex-[1_1_200px] items-center gap-3">
                        <div class="flex h-12 w-12 flex-none items-center justify-center rounded-full bg-indigo-100 text-lg font-extrabold text-marino" aria-hidden="true">{{ $iniciales($v['nombre']) }}</div>
                        <div class="min-w-0">
                            <p class="text-lg font-bold text-slate-900">{{ $v['nombre'] }}</p>
                            <p class="text-sm text-slate-600">#{{ $i + 1 }} en monto</p>
                            @if ($v['partidas_sin_costo'])
                                <p class="mt-1 inline-block rounded-full bg-orange-50 px-2 py-0.5 text-xs font-bold text-orange-800">{{ $v['partidas_sin_costo'] }} sin costo</p>
                            @endif
                        </div>
                    </div>

                    <div class="flex min-w-0 flex-[2_1_200px] flex-col gap-1.5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Cotizaciones enviadas</p>
                        <p class="text-2xl font-extrabold">{{ $v['total'] }}</p>
                        <div class="flex h-2.5 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                            <div class="bg-marino" style="width: {{ $ancho($v['cotizadas'], $maxEnviadas) }}"></div>
                            <div class="bg-naranja" style="width: {{ $ancho($v['directas'], $maxEnviadas) }}"></div>
                        </div>
                        <p class="text-sm text-slate-600">Desde Por cotizar: {{ $v['cotizadas'] }} · Directo en Cotizaciones: {{ $v['directas'] }}</p>
                    </div>

                    <div class="flex min-w-0 flex-[2_1_180px] flex-col gap-1.5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Partidas</p>
                        <p class="text-2xl font-extrabold">{{ number_format($v['partidas']) }}</p>
                        <div class="h-2.5 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div class="h-full bg-slate-600" style="width: {{ $ancho($v['partidas'], $maxPartidas) }}"></div></div>
                        <p class="text-sm text-slate-600"><strong class="text-slate-900">{{ $v['total'] ? number_format($v['partidas'] / $v['total'], 1) : '0' }}</strong> por cotización</p>
                    </div>

                    <div class="flex min-w-0 flex-[2_1_200px] flex-col gap-1.5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Monto · utilidad</p>
                        <p class="text-2xl font-extrabold">${{ number_format($v['monto'], 2) }}</p>
                        <div class="h-2.5 overflow-hidden rounded-full bg-slate-100" aria-hidden="true"><div class="h-full bg-marino" style="width: {{ $ancho($v['monto'], $maxMonto) }}"></div></div>
                        <p class="text-sm text-slate-600">utilidad <strong class="text-green-700">${{ number_format($v['utilidad'], 2) }}</strong>@if ($v['monto'] > 0) · <span class="rounded-full bg-green-100 px-2 font-bold text-green-800">{{ $pct($v['utilidad'], $v['monto']) }}%</span>@endif</p>
                    </div>

                    <div class="flex min-w-0 flex-[1_1_160px] flex-col gap-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tiempo hasta enviar</p>
                        <p class="flex items-center gap-2 text-sm"><span class="h-2.5 w-2.5 flex-none rounded-sm bg-marino"></span>{{ $v['cotizadas'] ? Sol::humano($v['promedio']).' desde que llegó' : '—' }}</p>
                        <p class="flex items-center gap-2 text-sm"><span class="h-2.5 w-2.5 flex-none rounded-sm bg-naranja"></span>{{ $v['directas'] ? Sol::humano($v['promedio_directas']).' desde que se creó' : '—' }}</p>
                    </div>

                    <div class="flex basis-full flex-wrap items-center gap-x-7 gap-y-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">
                        <p class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-green-800">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 10h18"></path><path d="M8 15h3"></path></svg>
                            Pedidos procesados
                        </p>
                        <p><strong class="text-lg">{{ $v['pedidos'] }}</strong> pedido(s)</p>
                        <p><strong class="text-lg">{{ number_format($v['pedidos_partidas']) }}</strong> partidas @if ($v['pedidos'])<span class="text-green-800">({{ number_format($v['pedidos_partidas'] / $v['pedidos'], 1) }} por pedido)</span>@endif</p>
                        <p>venta <strong class="text-lg">${{ number_format($v['pedidos_monto'], 2) }}</strong></p>
                        <p><strong class="text-lg">{{ $v['ordenes'] }}</strong> OC a proveedor</p>
                        <div class="h-2.5 min-w-28 flex-[1_1_140px] overflow-hidden rounded-full bg-green-100" aria-hidden="true"><div class="h-full bg-green-700" style="width: {{ $ancho($v['pedidos'], $maxPedidos) }}"></div></div>
                    </div>
                </article>
            @empty
                <p class="text-slate-500">Sin cotizaciones ni pedidos este mes</p>
            @endforelse

        </section>

        {{-- Trabajo por día: solo el admin --}}
        @if ($porDia)
            <section aria-label="Trabajo por día" class="flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm sm:p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-xl font-extrabold text-marino">Trabajo por día</h2>
                    <div class="flex flex-wrap items-center gap-4 text-sm text-slate-600">
                        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-marino"></span>Cotizaciones enviadas</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-green-700"></span>Pedidos procesados</span>
                    </div>
                </div>
                @if ($porDia['personas'] === [])
                    <p class="text-slate-500">Nadie ha enviado cotizaciones ni dado de alta pedidos en este mes.</p>
                @else
                    @php
                        $cargaMax = 1;
                        foreach ($porDia['dias'] as $d) {
                            foreach ($d['por'] as $n) {
                                $cargaMax = max($cargaMax, $n['cot'] + 2 * $n['ped']);
                            }
                        }
                    @endphp
                    <div class="overflow-x-auto">
                        <table class="w-full border-separate text-sm" style="border-spacing: 6px">
                            <thead>
                                <tr>
                                    <th scope="col" class="px-2 py-1 text-left font-semibold text-slate-500">Día</th>
                                    @foreach ($porDia['personas'] as $persona)
                                        <th scope="col" class="px-2 py-1 text-center font-semibold text-slate-700">{{ $persona }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($porDia['dias'] as $d)
                                    <tr wire:key="dia-{{ $d['fecha']->format('Ymd') }}">
                                        <th scope="row" class="whitespace-nowrap px-2 py-1 text-left font-semibold text-slate-700">
                                            <span class="text-xs uppercase text-slate-500">{{ $d['fecha']->locale('es')->translatedFormat('D') }}</span> {{ $d['fecha']->format('d') }}
                                        </th>
                                        @foreach ($porDia['personas'] as $persona)
                                            @php
                                                $n = $d['por'][$persona] ?? ['cot' => 0, 'ped' => 0];
                                                $carga = ($n['cot'] + 2 * $n['ped']) / $cargaMax;
                                                $fondo = $carga == 0 ? 'bg-white' : ($carga < 0.35 ? 'bg-indigo-50' : ($carga < 0.7 ? 'bg-indigo-100' : 'bg-indigo-200'));
                                            @endphp
                                            <td class="min-w-24 rounded-lg border border-slate-200 px-2 py-2 {{ $fondo }}"
                                                title="{{ $persona }}, {{ $d['fecha']->format('d/m') }}: {{ $n['cot'] }} cotización(es), {{ $n['ped'] }} pedido(s)">
                                                <span class="flex items-center gap-1.5 font-bold {{ $n['cot'] ? 'text-marino' : 'text-slate-400' }}"><span class="h-2 w-2 flex-none rounded-sm bg-marino"></span>{{ $n['cot'] }} cot.</span>
                                                <span class="flex items-center gap-1.5 font-bold {{ $n['ped'] ? 'text-green-700' : 'text-slate-400' }}"><span class="h-2 w-2 flex-none rounded-sm bg-green-700"></span>{{ $n['ped'] }} ped.</span>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    @else
        <div class="mb-4 rounded-xl bg-white p-4 shadow-sm">
            <input type="search" wire:model.live.debounce.300ms="buscar" class="campo" placeholder="Buscar por cliente, detalle o artículo" aria-label="Buscar">
        </div>

        <div class="space-y-3">
            @forelse ($solicitudes as $s)
                @php $sem = $pestana === 'pendiente' ? Sol::semaforo($s) : null; @endphp
                <article wire:key="sol-{{ $s->id }}" class="rounded-xl bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="break-words text-lg font-semibold text-marino">
                                <a href="{{ route('solicitudes.ver', $s) }}" class="underline">{{ $s->folioTexto() }}</a> · {{ $s->nombreCliente() }}
                                @if (! $s->cliente_id)<span class="ml-1 rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">cliente sin dar de alta</span>@endif
                            </p>
                            <p class="text-sm text-slate-600">
                                {{ $s->canalTexto() }} · llegó {{ $s->recibida_at->format('d/m H:i') }}
                                @if ($s->contacto_texto) · {{ $s->contacto_texto }} @endif
                            </p>
                        </div>
                        @if ($sem)
                            <span class="shrink-0 rounded-full px-3 py-1 text-sm font-semibold {{ $colores[$sem] }}">{{ Sol::hace($s->recibida_at) }}</span>
                        @endif
                    </div>

                    @if ($s->detalle)
                        <p class="mt-2 line-clamp-2 break-words text-base text-slate-700">{{ $s->detalle }}</p>
                    @endif
                    <p class="mt-1 text-sm text-slate-500">{{ count((array) $s->partidas) }} partida(s){{ $s->archivos_count ? ' · '.$s->archivos_count.' archivo(s)' : '' }}</p>

                    @if ($pestana === 'en_proceso')
                        <p class="mt-2 text-base font-medium text-amber-800">
                            La está trabajando {{ $s->tomadaPor?->nombreReal() ?? 'alguien' }}
                            @if ($s->cotizacion) · cotización {{ $s->cotizacion->folio }} @endif
                            · tomada {{ $s->tomada_at ? Sol::hace($s->tomada_at) : '' }}
                        </p>
                    @elseif ($pestana === 'cotizada')
                        @php $envio = $envios[$s->cotizacion_id] ?? null; @endphp
                        <p class="mt-2 text-base text-green-800">
                            Cotizada por {{ $s->tomadaPor?->nombreReal() ?? 'alguien' }}
                            @if ($s->cotizacion) · cotización {{ $s->cotizacion->folio }} @endif
                            @if ($envio) · enviada {{ $envio->format('d/m H:i') }} (tardó {{ Sol::humano(max(0, $s->recibida_at->diffInMinutes($envio, false))) }}) @endif
                        </p>
                    @elseif ($pestana === 'descartada')
                        <p class="mt-2 text-base text-slate-600">Descartada: {{ $s->motivo_descarte }}</p>
                    @endif

                    <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                        @if ($pestana === 'pendiente')
                            <button type="button" wire:click="tomar({{ $s->id }})" wire:loading.attr="disabled" class="btn-primario">Tomar y cotizar</button>
                        @elseif ($pestana === 'en_proceso' && $s->cotizacion && $s->tomada_por === auth()->id())
                            <a href="{{ route('cotizaciones.editar', $s->cotizacion) }}" class="btn-primario">Ir a la cotización</a>
                        @elseif ($pestana === 'en_proceso')
                            <button type="button" disabled class="btn-primario cursor-not-allowed opacity-50">La está trabajando {{ $s->tomadaPor?->nombreReal() }}</button>
                        @endif
                        <a href="{{ route('solicitudes.ver', $s) }}" class="btn-secundario">Ver</a>
                    </div>
                </article>
            @empty
                <div class="rounded-xl bg-white p-8 text-center text-slate-500 shadow-sm">
                    {{ $buscar !== '' ? 'No hay solicitudes que coincidan.' : 'No hay solicitudes en esta lista.' }}
                </div>
            @endforelse
        </div>

        <div class="mt-4">{{ $solicitudes->links() }}</div>
    @endif
</div>

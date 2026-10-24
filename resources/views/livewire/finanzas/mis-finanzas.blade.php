<div class="space-y-6">
    @php
        $dinero = fn ($v) => ($v < 0 ? '-' : '').'$'.number_format(abs((float) $v), 2);
        $hace = $resumen['dias'] === null ? null : ($resumen['dias'] === 0 ? 'hoy' : ($resumen['dias'] === 1 ? 'hace 1 día' : 'hace '.$resumen['dias'].' días'));
        $nombresTipo = \App\Models\Cuenta::TIPOS;
        $colores = ['rojo' => 'bg-red-100 text-red-800', 'amarillo' => 'bg-amber-100 text-amber-800', 'verde' => 'bg-green-100 text-green-800'];
        $barra = ['rojo' => 'bg-red-500', 'amarillo' => 'bg-amber-500', 'verde' => 'bg-green-600'];
    @endphp

    @if ($mensaje)
        <div role="status" wire:key="msg-{{ md5($mensaje) }}" x-data x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'center' })" class="scroll-mt-28 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    <nav class="flex gap-2" aria-label="Secciones">
        <button type="button" wire:click="$set('pestana', 'resumen')" class="{{ $pestana === 'resumen' ? 'btn-primario' : 'btn-secundario' }}">Mi patrimonio</button>
        <button type="button" wire:click="$set('pestana', 'cuentas')" class="{{ $pestana === 'cuentas' ? 'btn-primario' : 'btn-secundario' }}">Cuentas</button>
    </nav>

    @if ($pestana === 'resumen')
        <section class="space-y-3">
            @if ($resumen['dias'] === null || $resumen['dias'] > 7)
                <div role="alert" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-orange-300 bg-orange-50 px-4 py-3 text-base text-orange-900">
                    <strong>{{ $resumen['dias'] === null ? 'Todavía no capturas tus saldos.' : 'Tus saldos tienen '.$resumen['dias'].' días.' }}</strong>
                    @unless ($actualizando)
                        <button type="button" wire:click="abrirActualizar" class="btn-secundario !min-h-10">Actualizar</button>
                    @endunless
                </div>
            @endif

            @php
                $tengo = $resumen['disponible'] + $resumen['me_deben'] + $resumen['cartera'];
                $debo = $resumen['tarjetas'] + $resumen['creditos'] + $resumen['proveedores'];
                $base = max(1, $tengo, $debo);
                $ancho = fn ($x, $total) => $total > 0 ? number_format(max(0, $x) / $total * 100, 1, '.', '') : '0';
                $esAdmin = auth()->user()->esAdmin();
            @endphp
            <div class="grid gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <div class="flex flex-col gap-2 rounded-2xl bg-marino p-5 text-white shadow-sm">
                    <p class="text-sm font-semibold text-indigo-200">Patrimonio neto</p>
                    <p class="text-4xl font-extrabold">{{ $dinero($resumen['neto']) }}</p>
                    @if ($cambioNeto !== null)
                        <p class="self-start rounded-full bg-white/15 px-3 py-0.5 text-sm font-bold">{{ $cambioNeto >= 0 ? '▲' : '▼' }} {{ $dinero(abs($cambioNeto)) }} vs el corte anterior</p>
                    @endif
                    <p class="text-sm text-indigo-100">
                        @if ($hace)
                            Última actualización de saldos: <strong class="text-white">{{ $hace }}</strong> ({{ $resumen['ultima']->translatedFormat('j \d\e F \d\e Y') }}).
                        @else
                            Todavía no has capturado tus saldos.
                        @endif
                    </p>
                </div>
                <div class="space-y-4 rounded-2xl bg-white p-5 shadow-sm" data-tengo-debo>
                    <div class="space-y-1.5">
                        <div class="flex justify-between text-sm"><strong>Lo que tengo y me deben</strong><strong class="text-green-700">{{ $dinero($tengo) }}</strong></div>
                        <div class="flex h-5 overflow-hidden rounded-md bg-slate-100" style="width: {{ $ancho($tengo, $base) }}%" aria-hidden="true">
                            <div class="bg-green-700" style="width: {{ $ancho($resumen['disponible'], $tengo) }}%"></div>
                            <div class="bg-green-400" style="width: {{ $ancho($resumen['me_deben'], $tengo) }}%"></div>
                            <div class="bg-green-200" style="width: {{ $ancho($resumen['cartera'], $tengo) }}%"></div>
                        </div>
                        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-700">
                            <li><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-green-700"></span>Disponible {{ $dinero($resumen['disponible']) }}</li>
                            <li><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-green-400"></span>Me deben {{ $dinero($resumen['me_deben']) }}</li>
                            <li><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-green-200"></span>Cartera MASUME {{ $dinero($resumen['cartera']) }} <span class="text-slate-500">(por cobrar, en vivo)</span></li>
                        </ul>
                        {{-- En dónde está lo disponible: débito primero, la inversión (acciones) al final. --}}
                        @if ($desgloseDisponible)
                            <div class="grid gap-x-6 gap-y-2 rounded-lg bg-green-50 px-3 py-2 text-xs text-slate-700 sm:grid-cols-2" data-desglose-disponible>
                                @foreach ($desgloseDisponible as $g)
                                    <dl>
                                        <div class="flex justify-between gap-3 font-semibold text-green-800"><dt>{{ $g['titulo'] }}</dt><dd>{{ $dinero($g['total']) }}</dd></div>
                                        @foreach ($g['cuentas'] as $c)
                                            <div class="flex justify-between gap-3"><dt class="min-w-0 truncate">{{ $c['nombre'] }}</dt><dd class="shrink-0">{{ $dinero($c['saldo']) }}</dd></div>
                                        @endforeach
                                    </dl>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="space-y-1.5">
                        <div class="flex justify-between text-sm"><strong>Lo que debo</strong><strong class="text-red-700">{{ $dinero($debo) }}</strong></div>
                        <div class="flex h-5 overflow-hidden rounded-md bg-slate-100" style="width: {{ $ancho($debo, $base) }}%" aria-hidden="true">
                            <div class="bg-red-700" style="width: {{ $ancho($resumen['tarjetas'], $debo) }}%"></div>
                            <div class="bg-red-400" style="width: {{ $ancho($resumen['creditos'], $debo) }}%"></div>
                            <div class="bg-red-200" style="width: {{ $ancho($resumen['proveedores'], $debo) }}%"></div>
                        </div>
                        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-700">
                            <li><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-red-700"></span>Tarjetas {{ $dinero($resumen['tarjetas']) }}</li>
                            <li><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-red-400"></span>Créditos {{ $dinero($resumen['creditos']) }}</li>
                            <li data-debo-proveedores><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-red-200"></span>Proveedores {{ $dinero($resumen['proveedores']) }}
                                @if ($esAdmin)<a href="{{ route('finanzas.por-pagar') }}" class="ml-1 font-semibold text-marino underline">ver a quién</a>@endif</li>
                        </ul>
                    </div>
                </div>
            </div>

            @if (! $actualizando)
                <button type="button" wire:click="abrirActualizar" class="btn-primario w-full py-4 text-lg sm:w-auto">Actualizar saldos</button>
            @endif
        </section>

        @if ($actualizando)
            <form wire:submit="guardarSaldos" class="space-y-5 rounded-xl bg-white p-5 shadow-sm">
                <div>
                    <h2 class="text-lg font-semibold text-marino">Actualizar saldos de hoy</h2>
                </div>
                @error('general') <p class="error-campo" role="alert">{{ $message }}</p> @enderror

                @foreach ($grupos as $g)
                    @php $lista = $activas->whereIn('tipo', $g['tipos']); @endphp
                    @if ($lista->isNotEmpty())
                        <fieldset class="{{ in_array('credito', $g['tipos'], true) ? 'space-y-3' : 'grid gap-3 sm:grid-cols-2' }}">
                            <legend class="mb-3 text-base font-semibold text-marino sm:col-span-2">{{ $g['titulo'] }}</legend>
                            @foreach ($lista as $c)
                                <div class="rounded-lg border border-slate-200 p-3">
                                    <div class="mb-2 flex items-center gap-3">
                                        <x-logo-cuenta :nombre="$c->nombre" :tipo="$c->tipo" />
                                        <label for="saldo-{{ $c->id }}" class="etiqueta mb-0">{{ $c->nombre }} <span class="font-normal text-slate-500">· {{ $nombresTipo[$c->tipo] ?? $c->tipo }}</span></label>
                                    </div>
                                    @include('livewire.finanzas.partials.cobros-cuenta', ['cobro' => $cobros[$c->id] ?? null])
                                    <input id="saldo-{{ $c->id }}" type="text" inputmode="decimal" autocomplete="off" wire:model="saldos.{{ $c->id }}" class="campo text-lg" placeholder="$0.00">
                                    @error('saldos.'.$c->id) <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                                    @if ($c->tipo === 'credito')
                                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <label for="pago-{{ $c->id }}" class="etiqueta">Pago para no generar intereses (opcional)</label>
                                                <input id="pago-{{ $c->id }}" type="text" inputmode="decimal" autocomplete="off" wire:model="pagos.{{ $c->id }}" class="campo text-lg" placeholder="$0.00">
                                                @error('pagos.'.$c->id) <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                                            </div>
                                            <div>
                                                <label for="limite-{{ $c->id }}" class="etiqueta">Fecha límite (opcional)</label>
                                                <input id="limite-{{ $c->id }}" type="date" wire:model="limites.{{ $c->id }}" class="campo text-lg">
                                                @error('limites.'.$c->id) <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </fieldset>
                    @endif
                @endforeach
                @if ($activas->isEmpty())
                    <p class="text-base text-slate-600">Sin cuentas</p>
                @endif

                <div>
                    <label for="notas-corte" class="etiqueta">Notas (opcional)</label>
                    <input id="notas-corte" type="text" wire:model="notas" class="campo" maxlength="500">
                    @error('notas') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" wire:loading.attr="disabled" wire:target="guardarSaldos" class="btn-primario py-3 text-lg">Guardar saldos</button>
                    <button type="button" wire:click="cancelarActualizar" class="btn-secundario py-3 text-lg">Cancelar</button>
                </div>
            </form>
        @endif

        {{-- Lo que hay que pagar pronto: tarjetas y compras a crédito a proveedores, por fecha. --}}
        <section class="space-y-3" data-pagos-proximos>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold text-marino">Lo que tengo que pagar pronto</h2>
                <span class="text-sm text-slate-500">próximos 21 días</span>
            </div>
            @forelse ($pagosProximos as $pg)
                @php
                    $nivel = $pg['dias'] <= 1 ? 'urgente' : ($pg['tipo'] === 'proveedor' ? 'proveedor' : ($pg['dias'] <= 7 ? 'aviso' : 'normal'));
                    $estilo = ['urgente' => 'border-red-300 bg-red-50 text-red-700', 'aviso' => 'border-orange-300 bg-orange-50 text-orange-700',
                        'proveedor' => 'border-indigo-200 bg-indigo-50 text-indigo-800', 'normal' => 'border-slate-200 bg-white text-marino'][$nivel];
                    $cuando = $pg['dias'] < 0 ? 'Vencida hace '.abs($pg['dias']).' días' : ($pg['dias'] === 0 ? 'Hoy' : ($pg['dias'] === 1 ? 'Mañana' : 'En '.$pg['dias'].' días'));
                @endphp
                <div class="flex items-center gap-3 rounded-xl border p-3 {{ $estilo }}">
                    <div class="w-14 shrink-0 text-center">
                        <p class="text-2xl font-extrabold leading-none">{{ $pg['fecha']->day }}</p>
                        <p class="text-xs font-bold uppercase text-slate-500">{{ $pg['fecha']->translatedFormat('M') }}</p>
                    </div>
                    <div class="min-w-0 flex-1 text-slate-900">
                        <p class="font-bold">{{ $pg['nombre'] }}</p>
                        <p class="text-sm text-slate-600">{{ $pg['detalle'] }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-lg font-extrabold text-slate-900">{{ $dinero($pg['monto']) }}</p>
                        <p class="text-xs font-bold">{{ $cuando }}</p>
                    </div>
                </div>
            @empty
                <p class="rounded-xl bg-white p-4 text-base text-slate-600 shadow-sm">No hay pagos de tarjetas, proveedores ni nómina en los próximos 21 días.</p>
            @endforelse
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-marino">Tarjetas de crédito</h2>
            @if ($tarjetas->isEmpty())
                <p class="rounded-xl bg-white p-5 text-base text-slate-600 shadow-sm">Sin tarjetas</p>
            @else
                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach ($tarjetas as $t)
                        @php $pct = $t['utilizacion'] === null ? null : round($t['utilizacion'] * 100); @endphp
                        <article class="space-y-2 rounded-xl bg-white p-4 shadow-sm">
                            <header class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <h3 class="text-base font-semibold text-marino">{{ $t['cuenta']->nombre }}</h3>
                                    <p class="text-xs uppercase tracking-wide text-slate-500">{{ $t['cuenta']->de === 'masume' ? 'MASUME' : 'Personal' }}</p>
                                </div>
                                @if ($t['semaforo'])
                                    <span class="rounded-full px-3 py-1 text-sm font-semibold {{ $colores[$t['semaforo']['clave']] }}">{{ $t['semaforo']['texto'] }}</span>
                                @endif
                            </header>
                            <p class="text-2xl font-bold text-red-700">{{ $dinero($t['saldo']) }} <span class="text-sm font-normal text-slate-500">de deuda{{ $t['limite'] ? ' · línea '.$dinero($t['limite']) : '' }}</span></p>
                            @if ($t['sin_saldo'])
                                <p class="text-sm text-slate-500">Sin saldo capturado todavía.</p>
                            @endif
                            @if ($pct !== null)
                                <div>
                                    <div class="h-3 w-full overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-valuenow="{{ min(100, $pct) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Utilización de {{ $t['cuenta']->nombre }}">
                                        <div class="h-3 rounded-full {{ $barra[$t['semaforo']['clave']] }}" style="width: {{ min(100, $pct) }}%"></div>
                                    </div>
                                    <p class="mt-1 text-sm text-slate-600">Usado: <strong>{{ $pct }}%</strong> · Disponible al 70%: <strong>{{ $dinero($t['disponible70']) }}</strong></p>
                                </div>
                            @endif
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                <dt class="text-slate-500">Próximo corte</dt>
                                <dd class="text-right font-medium">{{ $t['corte'] ? $t['corte']->translatedFormat('j \d\e M') : '—' }}</dd>
                                <dt class="text-slate-500">Próximo pago</dt>
                                <dd class="text-right font-medium">
                                    @if ($t['pago'])
                                        {{ $t['pago']->translatedFormat('j \d\e M') }}
                                        <span class="{{ $t['dias_pago'] <= 3 ? 'text-red-700' : 'text-slate-500' }}">({{ $t['dias_pago'] === 0 ? 'hoy' : ($t['dias_pago'] === 1 ? 'mañana' : 'en '.$t['dias_pago'].' días') }})</span>
                                    @else — @endif
                                </dd>
                                @if ($t['pago_sin_intereses'] !== null && $t['pago_sin_intereses'] > 0)
                                    <dt class="text-slate-500">Pago sin intereses</dt>
                                    <dd class="text-right font-medium">{{ $dinero($t['pago_sin_intereses']) }}{{ $t['fecha_limite'] ? ' · hasta el '.$t['fecha_limite']->translatedFormat('j \d\e M') : '' }}</dd>
                                @endif
                            </dl>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-marino">Cómo ha cambiado mi patrimonio</h2>
            @if (count($puntos) < 2)
                <p class="rounded-xl bg-white p-5 text-base text-slate-600 shadow-sm">Sin suficientes cortes para la gráfica</p>
            @else
                @php
                    $ancho = 720; $alto = 280; $izq = 64; $der = 16; $arriba = 14; $abajo = 34;
                    $valores = collect($puntos)->pluck('neto');
                    $escala = \App\Support\EscalaGrafica::marcas((float) $valores->min(), (float) $valores->max());
                    $rango = max(1.0, $escala['max'] - $escala['min']);
                    $y = fn (float $v) => $arriba + ($escala['max'] - $v) / $rango * ($alto - $arriba - $abajo);
                    $n = count($puntos);
                    $x = fn (int $i) => $izq + ($n === 1 ? 0 : $i * ($ancho - $izq - $der) / ($n - 1));
                    $linea = collect($puntos)->map(fn ($p, $i) => round($x($i), 1).','.round($y((float) $p['neto']), 1))->implode(' ');
                    $paso = max(1, (int) ceil($n / 8));
                @endphp
                {{-- Al pasar el cursor (o tocar, en celular) un punto, se ve su fecha y su monto. --}}
                <figure class="m-0 rounded-xl bg-white p-4 shadow-sm" x-data="{ sel: null }" @pointerleave="if ($event.pointerType === 'mouse') sel = null" @click.outside="sel = null">
                    <div class="overflow-x-auto">
                        <svg viewBox="0 0 {{ $ancho }} {{ $alto }}" width="100%" style="min-width: 480px" role="img" aria-label="Patrimonio neto por actualización de saldos" class="block h-auto" font-family="inherit" data-grafica-patrimonio>
                            @foreach ($escala['marcas'] as $m)
                                <line x1="{{ $izq }}" x2="{{ $ancho - $der }}" y1="{{ round($y($m), 1) }}" y2="{{ round($y($m), 1) }}" stroke="{{ $m == 0 ? '#94a3b8' : '#e2e8f0' }}" stroke-width="1"/>
                                <text x="{{ $izq - 6 }}" y="{{ round($y($m), 1) + 4 }}" text-anchor="end" font-size="11" fill="#475569">{{ \App\Support\EscalaGrafica::corto($m) }}</text>
                            @endforeach
                            <polyline points="{{ $linea }}" fill="none" stroke="#12235a" stroke-width="2.5" stroke-linejoin="round"/>
                            @foreach ($puntos as $i => $p)
                                @php $px = round($x($i), 1); $py = round($y((float) $p['neto']), 1); @endphp
                                <line x1="{{ $px }}" x2="{{ $px }}" y1="{{ $py }}" y2="{{ $alto - $abajo }}" stroke="#94a3b8" stroke-width="1" stroke-dasharray="3 3" x-show="sel === {{ $i }}" x-cloak/>
                                <circle cx="{{ $px }}" cy="{{ $py }}" r="4" fill="#ef7d22" :r="sel === {{ $i }} ? 7 : 4"/>
                                @if ($i % $paso === 0 || $i === $n - 1)
                                    <text x="{{ $px }}" y="{{ $alto - 10 }}" text-anchor="{{ $i === $n - 1 ? 'end' : ($i === 0 ? 'start' : 'middle') }}" font-size="11" fill="#475569">{{ $p['fecha']->format('d/m/y') }}</text>
                                @endif
                            @endforeach
                            {{-- Zonas invisibles más grandes que el punto: fáciles de atinar con el cursor o el dedo. --}}
                            @foreach ($puntos as $i => $p)
                                <circle cx="{{ round($x($i), 1) }}" cy="{{ round($y((float) $p['neto']), 1) }}" r="18" fill="transparent" class="cursor-pointer"
                                        @pointerenter="if ($event.pointerType === 'mouse') sel = {{ $i }}" @click="sel = {{ $i }}" data-punto="{{ $i }}"><title>{{ $p['fecha']->format('d/m/Y') }}: {{ $dinero($p['neto']) }}</title></circle>
                            @endforeach
                            {{-- Globito con la fecha y el monto: arriba del punto (abajo si no cabe) y sin salirse a los lados. --}}
                            @foreach ($puntos as $i => $p)
                                @php
                                    $px = $x($i); $py = $y((float) $p['neto']); $gw = 138; $gh = 44;
                                    $gx = min(max($px - $gw / 2, $izq), $ancho - $der - $gw);
                                    $gy = $py - $gh - 12 >= 0 ? $py - $gh - 12 : $py + 12;
                                @endphp
                                <g x-show="sel === {{ $i }}" x-cloak pointer-events="none" data-globo="{{ $i }}">
                                    <rect x="{{ round($gx, 1) }}" y="{{ round($gy, 1) }}" width="{{ $gw }}" height="{{ $gh }}" rx="8" fill="#12235a"/>
                                    <text x="{{ round($gx + $gw / 2, 1) }}" y="{{ round($gy + 17, 1) }}" text-anchor="middle" font-size="11" fill="#cbd5e1">{{ $p['fecha']->format('d/m/Y') }}</text>
                                    <text x="{{ round($gx + $gw / 2, 1) }}" y="{{ round($gy + 35, 1) }}" text-anchor="middle" font-size="15" font-weight="700" fill="#ffffff">{{ $dinero($p['neto']) }}</text>
                                </g>
                            @endforeach
                        </svg>
                    </div>
                    <table class="sr-only">
                        <caption>Patrimonio neto por fecha</caption>
                        <tbody>
                            @foreach ($puntos as $p)
                                <tr><td>{{ $p['fecha']->format('d/m/Y') }}</td><td>{{ $dinero($p['neto']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </figure>
            @endif
        </section>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-marino">Mis saldos</h2>
            <div class="grid gap-3 lg:grid-cols-2">
                @foreach ($grupos as $g)
                    @php $lista = $activas->whereIn('tipo', $g['tipos']); @endphp
                    @if ($lista->isNotEmpty())
                        <article class="rounded-xl bg-white p-4 shadow-sm">
                            <h3 class="mb-2 text-base font-semibold text-marino">{{ $g['titulo'] }}</h3>
                            <ul class="divide-y divide-slate-100 text-base">
                                @foreach ($lista as $c)
                                    @if ($unoId === $c->id)
                                        <li wire:key="uno-{{ $c->id }}" class="space-y-2 py-3">
                                            <form wire:submit="guardarUno" class="space-y-2">
                                                <label for="uno-saldo" class="etiqueta">{{ $c->nombre }}: saldo de hoy</label>
                                                <input id="uno-saldo" type="text" inputmode="decimal" autocomplete="off" wire:model="unoSaldo" class="campo text-lg" placeholder="$0.00" x-data x-init="$el.focus(); $el.select()">
                                                @error('unoSaldo') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                                                @if ($c->tipo === 'credito')
                                                    <div class="grid gap-2 sm:grid-cols-2">
                                                        <div>
                                                            <label for="uno-pago" class="etiqueta">Pago para no generar intereses (opcional)</label>
                                                            <input id="uno-pago" type="text" inputmode="decimal" autocomplete="off" wire:model="unoPago" class="campo text-lg" placeholder="$0.00">
                                                            @error('unoPago') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                                                        </div>
                                                        <div>
                                                            <label for="uno-fecha" class="etiqueta">Fecha límite (opcional)</label>
                                                            <input id="uno-fecha" type="date" wire:model="unoFecha" class="campo text-lg">
                                                            @error('unoFecha') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                                                        </div>
                                                    </div>
                                                @endif
                                                <div class="flex gap-2">
                                                    <button type="submit" wire:loading.attr="disabled" wire:target="guardarUno" class="btn-primario flex-1">Guardar</button>
                                                    <button type="button" wire:click="cancelarUno" class="btn-secundario flex-1">Cancelar</button>
                                                </div>
                                            </form>
                                        </li>
                                    @else
                                        <li wire:key="saldo-{{ $c->id }}" class="flex items-center justify-between gap-3 py-1.5">
                                            <span class="min-w-0">{{ $c->nombre }}
                                                @include('livewire.finanzas.partials.cobros-cuenta', ['cobro' => $cobros[$c->id] ?? null])
                                            </span>
                                            <span class="flex items-center gap-2">
                                                <span class="font-medium">{{ isset($ultimos[$c->id]) ? $dinero((float) $ultimos[$c->id]->saldo + ($cobros[$c->id]['total'] ?? 0)) : '—' }}</span>
                                                <button type="button" wire:click="abrirUno({{ $c->id }})" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm text-marino hover:bg-slate-50" aria-label="Actualizar el saldo de {{ $c->nombre }}">Actualizar</button>
                                            </span>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                            <p class="mt-2 flex items-baseline justify-between gap-3 border-t-2 border-slate-200 pt-2 text-base font-semibold text-marino">
                                <span>Total</span>
                                <span>{{ $dinero($lista->sum(fn ($c) => isset($ultimos[$c->id]) ? (float) $ultimos[$c->id]->saldo + ($cobros[$c->id]['total'] ?? 0) : 0)) }}</span>
                            </p>
                        </article>
                    @endif
                @endforeach
            </div>
        </section>
    @else
        <section class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold text-marino">Mis cuentas</h2>
                @if (! $editandoCuenta)
                    <button type="button" wire:click="nuevaCuenta" class="btn-primario">Agregar cuenta</button>
                @endif
            </div>

            @if ($editandoCuenta)
                <form wire:submit="guardarCuenta" class="scroll-mt-28 space-y-4 rounded-xl bg-white p-5 shadow-sm" x-data x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'start' })">
                    <h3 class="text-base font-semibold text-marino">{{ $cuentaId ? 'Editar cuenta' : 'Nueva cuenta' }}</h3>
                    <div>
                        <label for="c-nombre" class="etiqueta">Nombre</label>
                        <input id="c-nombre" type="text" wire:model="nombre" class="campo" maxlength="80" autocomplete="off">
                        @error('nombre') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="c-tipo" class="etiqueta">Tipo</label>
                            <select id="c-tipo" wire:model.live="tipo" class="campo">
                                @foreach ($nombresTipo as $clave => $texto)
                                    <option value="{{ $clave }}">{{ $texto }}</option>
                                @endforeach
                            </select>
                            @error('tipo') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="c-de" class="etiqueta">De</label>
                            <select id="c-de" wire:model="de" class="campo">
                                <option value="personal">Personal</option>
                                <option value="masume">MASUME</option>
                            </select>
                            @error('de') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    @if ($tipo === 'credito')
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label for="c-corte" class="etiqueta">Día de corte (1 a 31)</label>
                                <input id="c-corte" type="text" inputmode="numeric" wire:model="diaCorte" class="campo">
                                @error('diaCorte') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="c-pago" class="etiqueta">Día de pago (1 a 31)</label>
                                <input id="c-pago" type="text" inputmode="numeric" wire:model="diaPago" class="campo">
                                @error('diaPago') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="c-limite" class="etiqueta">Límite de crédito</label>
                                <input id="c-limite" type="text" inputmode="decimal" wire:model="limite" class="campo">
                                @error('limite') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endif
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="c-orden" class="etiqueta">Orden (número chico sale primero)</label>
                            <input id="c-orden" type="text" inputmode="numeric" wire:model="orden" class="campo">
                            @error('orden') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <label class="flex items-center gap-3 pt-6 text-base"><input type="checkbox" wire:model="activa" class="h-5 w-5"> Cuenta activa (la uso)</label>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <button type="submit" class="btn-primario">Guardar cuenta</button>
                        <button type="button" wire:click="cancelarCuenta" class="btn-secundario">Cancelar</button>
                        @if ($cuentaId)
                            <button type="button" wire:click="eliminarCuenta({{ $cuentaId }})" wire:confirm="¿Eliminar la cuenta {{ $nombre }}? Si ya tiene historial solo se desactiva." class="btn-secundario text-red-700 sm:ml-auto">Eliminar cuenta</button>
                        @endif
                    </div>
                </form>
            @endif

            <div class="grid gap-3 lg:grid-cols-2">
                @forelse ($cuentas as $c)
                    <article class="flex items-start justify-between gap-3 rounded-xl bg-white p-4 shadow-sm {{ $c->activa ? '' : 'opacity-60' }}">
                        <div>
                            <h3 class="text-base font-semibold text-marino">{{ $c->nombre }}@unless ($c->activa) <span class="text-sm font-normal text-slate-500">(inactiva)</span> @endunless</h3>
                            <p class="text-sm text-slate-600">{{ $nombresTipo[$c->tipo] ?? $c->tipo }} · {{ $c->de === 'masume' ? 'MASUME' : 'Personal' }}</p>
                            @if ($c->tipo === 'credito')
                                <p class="text-sm text-slate-500">Corte día {{ $c->dia_corte ?: '—' }} · pago día {{ $c->dia_pago ?: '—' }} · línea {{ $c->limite !== null ? $dinero($c->limite) : '—' }}</p>
                            @endif
                        </div>
                        <button type="button" wire:click="editarCuenta({{ $c->id }})" class="btn-secundario">Editar</button>
                    </article>
                @empty
                    <p class="rounded-xl bg-white p-5 text-base text-slate-600 shadow-sm">Todavía no hay cuentas.</p>
                @endforelse
            </div>
        </section>
    @endif
</div>

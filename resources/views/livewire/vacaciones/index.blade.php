@php
    $fecha = fn ($f) => $f ? \Illuminate\Support\Carbon::parse($f)->format('d/m/Y') : '—';
    $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 1, '.', ''), '0'), '.') ?: '0';
    $inicioCal = $cal['inicio'];
    $nombresDias = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    $espacios = $inicioCal->dayOfWeekIso - 1;
    $totalDiasMes = $inicioCal->daysInMonth;
    $diasConGente = collect($cal['dias'])->sortKeys();
@endphp
<div class="space-y-4">

    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif
    @if ($aviso)
        <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">{{ $aviso }}</div>
    @endif
    @if ($error)
        <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
    @endif

    {{-- ===== Mis vacaciones ===== --}}
    <section class="rounded-xl bg-white p-4 shadow-sm" aria-labelledby="t-mis">
        <h2 id="t-mis" class="text-lg font-semibold text-marino">Mis vacaciones</h2>
        @if (! $miResumen['calculable'])
            <p class="mt-2 text-base text-slate-700">Falta la fecha de ingreso.</p>
        @else
            <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,14rem)_1fr]">
                <div class="rounded-xl bg-marino p-4 text-center text-white">
                    <p class="text-sm font-medium uppercase tracking-wide text-white/80">Me quedan</p>
                    <p class="text-5xl font-bold {{ $miResumen['saldo'] < 0 ? 'text-naranja' : '' }}">{{ $num($miResumen['saldo']) }}</p>
                    <p class="text-sm text-white/80">{{ abs($miResumen['saldo']) == 1 ? 'día' : 'días' }}{{ $miResumen['saldo'] < 0 ? ' (de más)' : '' }}</p>
                </div>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-base">
                    <div><dt class="text-sm text-slate-500">Me tocan en este periodo</dt><dd class="font-semibold">{{ $num($miResumen['dias_periodo']) }} días</dd></div>
                    <div><dt class="text-sm text-slate-500">Ya usé</dt><dd class="font-semibold">{{ $num($miResumen['usados'] - $miResumen['ajustes']) }} días</dd></div>
                    <div class="col-span-2"><dt class="text-sm text-slate-500">Periodo</dt><dd class="font-semibold">del {{ $fecha($miResumen['desde']) }} al {{ $fecha($miResumen['hasta']) }}</dd></div>
                    <div class="col-span-2"><dt class="text-sm text-slate-500">Mi antigüedad</dt><dd class="font-semibold">{{ $miAntiguedad }}</dd></div>
                    @if ($miResumen['proximo_aniversario'])
                        <div class="col-span-2"><dt class="text-sm text-slate-500">Próximo aniversario</dt>
                            <dd class="font-semibold">{{ $fecha($miResumen['proximo_aniversario']) }}: te tocarán {{ $num($miResumen['proximos_dias']) }} días</dd></div>
                    @endif
                </dl>
            </div>
        @endif
    </section>

    {{-- ===== Registrar ===== --}}
    <form wire:submit="registrar" class="space-y-3 rounded-xl bg-white p-4 shadow-sm" aria-labelledby="t-reg">
        <h2 id="t-reg" class="text-lg font-semibold text-marino">Registrar vacaciones</h2>

        @if ($esAdmin)
            <div>
                <label class="etiqueta" for="v-para">¿De quién?</label>
                <select id="v-para" wire:model.live="paraUsuario" class="campo">
                    <option value="">Mías ({{ $yo->nombreReal() }})</option>
                    @foreach ($equipo as $fila)
                        @if ($fila['usuario']->id !== $yo->id)
                            <option value="{{ $fila['usuario']->id }}">{{ $fila['usuario']->nombreReal() }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="etiqueta" for="v-desde">Primer día</label>
                <input id="v-desde" type="date" wire:model.live="desde" class="campo">
                @error('desde') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="etiqueta" for="v-hasta">Último día</label>
                <input id="v-hasta" type="date" wire:model.live="hasta" class="campo">
                @error('hasta') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
        </div>

        @if ($diasRango !== null)
            <p class="rounded-lg bg-slate-50 px-3 py-2 text-base text-slate-800">
                Son <strong>{{ $diasRango }}</strong> {{ $diasRango === 1 ? 'día hábil' : 'días hábiles' }}
                <span class="text-sm text-slate-500">(sin domingos{{ $destino->trabaja_sabados ? '' : ', sin sábados' }} ni festivos)</span>.
                @if ($resumenDestino['calculable'])
                    Te quedarían <strong>{{ $num($resumenDestino['saldo'] - $diasRango) }}</strong>.
                @endif
            </p>
        @endif

        <div>
            <label class="etiqueta" for="v-nota">Nota (opcional)</label>
            <input id="v-nota" type="text" wire:model="nota" maxlength="500" class="campo" placeholder="Ej. viaje familiar">
            @error('nota') <p class="error-campo">{{ $message }}</p> @enderror
        </div>

        @if ($pedirConfirmacion)
            <div role="alert" class="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-3">
                <p class="text-base text-amber-900">Estás pidiendo más días de los que te quedan ({{ $num($resumenDestino['saldo']) }}).
                    <strong>¿Ya te las aprobó Daniel?</strong></p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="registrar(true)" class="btn btn-primario min-h-12">Sí, ya me las aprobó</button>
                    <button type="button" wire:click="cancelarConfirmacion" class="btn btn-secundario min-h-12">No, regresar</button>
                </div>
            </div>
        @else
            <button type="submit" class="btn btn-primario min-h-12 w-full sm:w-auto" wire:loading.attr="disabled" wire:target="registrar">Registrar vacaciones</button>
        @endif
    </form>

    {{-- ===== Mi lista ===== --}}
    <section class="rounded-xl bg-white p-4 shadow-sm" aria-labelledby="t-lista">
        <h2 id="t-lista" class="text-lg font-semibold text-marino">Mis registros</h2>
        @forelse ($mias as $v)
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 py-3 last:border-0">
                <div class="min-w-0">
                    @if ($v->esAjuste())
                        <p class="font-semibold">Ajuste del {{ $fecha($v->desde) }}: {{ $v->dias > 0 ? '+' : '' }}{{ $num($v->dias) }} días</p>
                    @else
                        <p class="font-semibold">Del {{ $fecha($v->desde) }} al {{ $fecha($v->hasta) }} · {{ $num($v->dias) }} {{ $v->dias == 1 ? 'día' : 'días' }}</p>
                    @endif
                    @if ($v->notas) <p class="text-sm text-slate-600">{{ $v->notas }}</p> @endif
                </div>
                @if (! $v->esAjuste() && ($esAdmin || $v->desde->gte($hoy)))
                    <button type="button" wire:click="cancelar({{ $v->id }})" wire:confirm="¿Cancelar estas vacaciones?" class="btn btn-secundario min-h-11">Cancelar</button>
                @endif
            </div>
        @empty
            <p class="mt-2 text-base text-slate-600">Todavía no hay registros.</p>
        @endforelse
    </section>

    {{-- ===== Equipo (admin) ===== --}}
    @if ($esAdmin)
        <section class="rounded-xl bg-white p-4 shadow-sm" aria-labelledby="t-equipo">
            <h2 id="t-equipo" class="text-lg font-semibold text-marino">Todo el equipo</h2>

            <div class="mt-3 hidden overflow-x-auto md:block">
                <table class="w-full text-left text-base">
                    <thead class="text-sm text-slate-500">
                        <tr><th class="py-2 pr-3">Persona</th><th class="pr-3">Antigüedad</th><th class="pr-3 text-right">Periodo</th><th class="pr-3 text-right">Usados</th><th class="pr-3 text-right">Saldo</th><th class="pr-3">Próximas vacaciones</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($equipo as $fila)
                            @php
$r = $fila['resumen'];
@endphp
                            <tr class="border-t border-slate-100">
                                <td class="py-2 pr-3 font-semibold">{{ $fila['usuario']->nombreReal() }}</td>
                                <td class="pr-3">{{ $fila['antiguedad'] }}</td>
                                @if ($r['calculable'])
                                    <td class="pr-3 text-right">{{ $num($r['dias_periodo']) }}</td>
                                    <td class="pr-3 text-right">{{ $num($r['usados'] - $r['ajustes']) }}</td>
                                    <td class="pr-3 text-right text-lg font-bold {{ $r['saldo'] < 0 ? 'text-red-700' : 'text-marino' }}">{{ $num($r['saldo']) }}</td>
                                @else
                                    <td colspan="3" class="pr-3 text-right text-amber-700">Falta la fecha de ingreso</td>
                                @endif
                                <td class="pr-3">{{ $fila['proxima'] ? $fecha($fila['proxima']->desde).' al '.$fecha($fila['proxima']->hasta) : '—' }}</td>
                                <td class="text-right"><button type="button" wire:click="editar({{ $fila['usuario']->id }})" class="text-sm font-medium text-marino underline">Editar datos</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3 space-y-3 md:hidden">
                @foreach ($equipo as $fila)
                    @php
$r = $fila['resumen'];
@endphp
                    <div class="rounded-lg border border-slate-200 p-3">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="text-lg font-semibold">{{ $fila['usuario']->nombreReal() }}</p>
                            @if ($r['calculable'])
                                <p class="text-2xl font-bold {{ $r['saldo'] < 0 ? 'text-red-700' : 'text-marino' }}">{{ $num($r['saldo']) }} <span class="text-sm font-medium text-slate-500">días</span></p>
                            @endif
                        </div>
                        <p class="text-sm text-slate-600">Antigüedad: {{ $fila['antiguedad'] }}</p>
                        @if ($r['calculable'])
                            <p class="text-sm text-slate-600">Periodo: {{ $num($r['dias_periodo']) }} días · usados {{ $num($r['usados'] - $r['ajustes']) }}</p>
                        @else
                            <p class="text-sm text-amber-700">Falta la fecha de ingreso</p>
                        @endif
                        <p class="text-sm text-slate-600">Próximas: {{ $fila['proxima'] ? $fecha($fila['proxima']->desde).' al '.$fecha($fila['proxima']->hasta) : '—' }}</p>
                        <button type="button" wire:click="editar({{ $fila['usuario']->id }})" class="mt-1 min-h-11 text-sm font-medium text-marino underline">Editar datos</button>
                    </div>
                @endforeach
            </div>

            @if ($editandoId)
                <form wire:submit="guardarEmpleado" class="mt-4 space-y-3 rounded-lg border-2 border-naranja/60 p-3">
                    <h3 class="font-semibold text-marino">Datos de {{ $equipo->firstWhere('usuario.id', $editandoId)['usuario']->nombreReal() ?? '' }}</h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="etiqueta" for="e-ingreso">Fecha de ingreso</label>
                            <input id="e-ingreso" type="date" wire:model="edIngreso" class="campo">
                            @error('edIngreso') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="etiqueta" for="e-dias">Días por año</label>
                            <input id="e-dias" type="number" min="0" max="60" inputmode="numeric" wire:model="edDias" class="campo" placeholder="Según la ley">
                            @error('edDias') <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <label class="flex min-h-11 items-center gap-3 text-base">
                        <input type="checkbox" wire:model="edSabados" class="h-5 w-5 rounded border-slate-300">
                        Trabaja sábados
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primario min-h-12">Guardar</button>
                        <button type="button" wire:click="cancelarEdicion" class="btn btn-secundario min-h-12">Cancelar</button>
                    </div>
                </form>
            @endif
        </section>

        <form wire:submit="guardarAjuste" class="space-y-3 rounded-xl bg-white p-4 shadow-sm" aria-labelledby="t-aj">
            <h2 id="t-aj" class="text-lg font-semibold text-marino">Agregar un ajuste de días</h2>
            <div class="grid gap-3 sm:grid-cols-[1fr_8rem_10rem]">
                <div>
                    <label class="etiqueta" for="a-user">Persona</label>
                    <select id="a-user" wire:model="ajUsuario" class="campo">
                        <option value="">Elige…</option>
                        @foreach ($equipo as $fila)
                            <option value="{{ $fila['usuario']->id }}">{{ $fila['usuario']->nombreReal() }}</option>
                        @endforeach
                    </select>
                    @error('ajUsuario') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="a-dias">Días (+/−)</label>
                    <input id="a-dias" type="text" inputmode="decimal" wire:model="ajDias" class="campo" placeholder="-3">
                    @error('ajDias') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="a-fecha">Fecha</label>
                    <input id="a-fecha" type="date" wire:model="ajFecha" class="campo">
                    @error('ajFecha') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="etiqueta" for="a-nota">Nota</label>
                <input id="a-nota" type="text" wire:model="ajNota" maxlength="300" class="campo" placeholder="Ej. días anticipados">
                @error('ajNota') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primario min-h-12 w-full sm:w-auto">Guardar ajuste</button>
        </form>
    @endif

    {{-- ===== Calendario del mes ===== --}}
    <section class="rounded-xl bg-white p-4 shadow-sm" aria-labelledby="t-cal">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="t-cal" class="text-lg font-semibold text-marino">Quién está de vacaciones</h2>
            <div class="flex items-center gap-1">
                <button type="button" wire:click="mesAnterior" class="btn btn-secundario min-h-11 min-w-11" aria-label="Mes anterior">&lsaquo;</button>
                <button type="button" wire:click="esteMes" class="btn btn-secundario min-h-11">{{ \App\Livewire\Vacaciones\Index::MESES[$inicioCal->month] }} {{ $inicioCal->year }}</button>
                <button type="button" wire:click="mesSiguiente" class="btn btn-secundario min-h-11 min-w-11" aria-label="Mes siguiente">&rsaquo;</button>
            </div>
        </div>

        {{-- Rejilla (pantallas grandes) --}}
        <div class="mt-3 hidden grid-cols-7 gap-1 md:grid">
            @foreach ($nombresDias as $n)
                <div class="py-1 text-center text-sm font-semibold text-slate-500">{{ $n }}</div>
            @endforeach
            @for ($i = 0; $i < $espacios; $i++)
                <div></div>
            @endfor
            @for ($d = 1; $d <= $totalDiasMes; $d++)
                @php
                    $dia = $inicioCal->copy()->day($d);
                    $gente = $cal['dias'][$dia->toDateString()] ?? [];
                    $festivo = \App\Support\Vacaciones::esFestivo($dia);
                @endphp
                <div class="min-h-20 rounded-lg border p-1 text-sm {{ $dia->isSameDay($hoy) ? 'border-naranja' : 'border-slate-200' }} {{ $dia->isSunday() || $festivo ? 'bg-slate-50' : '' }}">
                    <p class="text-xs {{ $festivo ? 'font-semibold text-red-700' : 'text-slate-500' }}">{{ $d }}{{ $festivo ? ' · festivo' : '' }}</p>
                    @foreach ($gente as $nombre)
                        <p class="truncate rounded bg-marino px-1 py-0.5 text-xs text-white">{{ $nombre }}</p>
                    @endforeach
                </div>
            @endfor
        </div>

        {{-- Lista (celular) --}}
        <div class="mt-3 md:hidden">
            @forelse ($diasConGente as $f => $gente)
                <div class="flex gap-3 border-b border-slate-100 py-2 last:border-0">
                    <p class="w-16 shrink-0 font-semibold text-marino">{{ \Illuminate\Support\Carbon::parse($f)->format('d/m') }} <span class="text-xs font-normal text-slate-500">{{ $nombresDias[\Illuminate\Support\Carbon::parse($f)->dayOfWeekIso - 1] }}</span></p>
                    <p class="text-base">{{ implode(', ', $gente) }}</p>
                </div>
            @empty
                <p class="text-base text-slate-600">Nadie tiene vacaciones este mes.</p>
            @endforelse
        </div>
    </section>
</div>

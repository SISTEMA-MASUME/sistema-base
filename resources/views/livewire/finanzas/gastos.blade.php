@php
    $dinero = fn ($n) => '$'.number_format((float) $n, 2);
    $esMovimiento = ($form['tipo'] ?? 'gasto') === 'movimiento';
    $opcionBoton = 'flex min-h-14 cursor-pointer items-center justify-center rounded-lg border-2 border-slate-300 bg-white px-3 text-lg font-semibold text-slate-700 has-[:checked]:border-marino has-[:checked]:bg-marino has-[:checked]:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-naranja';
    $colorDe = ['masume' => 'bg-blue-100 text-blue-800', 'personal' => 'bg-purple-100 text-purple-800'];
    $maxResumen = $resumen ? max(array_column($resumen, 'total')) : 0;
@endphp
<div x-data x-on:gasto-editar.window="$nextTick(() => $refs.captura.scrollIntoView({ behavior: 'smooth', block: 'start' }))">
    <div class="space-y-4">

        <div class="flex justify-end">
            <a href="{{ route('finanzas.gastos.categorias') }}"class="btn btn-secundario">Ordenar categorías</a>
        </div>

        @if ($mensaje)
            <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
        @endif
        @if ($error)
            <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
        @endif

        {{-- ===== Captura rápida ===== --}}
        <form x-ref="captura" wire:submit="guardar" class="scroll-mt-4 space-y-4 rounded-xl bg-white p-4 shadow-sm {{ $editando ? 'ring-2 ring-naranja/60' : '' }}" aria-labelledby="titulo-captura">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="titulo-captura" class="text-lg font-semibold text-marino">{{ $editando ? 'Editar el gasto' : 'Capturar un gasto' }}</h2>
                @if ($editando)
                    <button type="button" wire:click="cancelarEdicion" class="text-sm font-medium text-marino underline">Cancelar y volver a capturar</button>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-[10rem_1fr]">
                <div>
                    <label class="etiqueta" for="g-fecha">Fecha</label>
                    <input id="g-fecha" type="date" wire:model="form.fecha" class="campo">
                    @error('form.fecha') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="g-monto">Monto</label>
                    <input id="g-monto" type="text" inputmode="decimal" wire:model="form.monto" class="campo text-xl font-semibold" placeholder="0.00" autocomplete="off">
                    @error('form.monto') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <fieldset>
                    <legend class="etiqueta">¿De quién es?</legend>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (\App\Models\Gasto::DE as $clave => $texto)
                            <label class="{{ $opcionBoton }}">
                                <input type="radio" wire:model="form.de" value="{{ $clave }}" class="sr-only">
                                {{ $texto }}
                            </label>
                        @endforeach
                    </div>
                    @error('form.de') <p class="error-campo">{{ $message }}</p> @enderror
                </fieldset>
                <fieldset>
                    <legend class="etiqueta">Tipo</legend>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (\App\Models\Gasto::TIPOS as $clave => $texto)
                            <label class="{{ $opcionBoton }} !text-base leading-tight">
                                <input type="radio" wire:model.live="form.tipo" value="{{ $clave }}" class="sr-only">
                                <span class="text-center">{{ $clave === 'movimiento' ? 'Movimiento entre cuentas' : 'Gasto' }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('form.tipo') <p class="error-campo">{{ $message }}</p> @enderror
                </fieldset>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="etiqueta" for="g-categoria">Categoría{{ $esMovimiento ? ' (opcional)' : '' }}</label>
                    <select id="g-categoria" wire:model.live="form.categoria" class="campo">
                        <option value="">Elige la categoría</option>
                        @foreach ($categoriasForm as $c)
                            <option value="{{ $c }}">{{ $c }}{{ in_array($c, \App\Models\Gasto::CATEGORIAS, true) ? '' : ' (vieja)' }}</option>
                        @endforeach
                    </select>
                    @error('form.categoria') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="g-cuenta">{{ $esMovimiento ? 'Sale de la cuenta' : 'Pagado con' }}</label>
                    <select id="g-cuenta" wire:model.live="form.cuenta_id" class="campo">
                        <option value="">{{ $esMovimiento ? 'Elige la cuenta' : 'Sin cuenta' }}</option>
                        @foreach ($cuentasCaptura as $c)
                            <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                        @endforeach
                        <option value="{{ \App\Livewire\Finanzas\Gastos::OTRA }}">Otra (escribir una nueva)…</option>
                    </select>
                    @error('form.cuenta_id') <p class="error-campo">{{ $message }}</p> @enderror
                    @if (($form['cuenta_id'] ?? '') === \App\Livewire\Finanzas\Gastos::OTRA)
                        <input type="text" wire:model="form.cuenta_nueva" maxlength="80" class="campo mt-2" placeholder="Ej. BBVA Empresarial" aria-label="Nombre de la cuenta nueva" autocomplete="off">
                        @error('form.cuenta_nueva') <p class="error-campo">{{ $message }}</p> @enderror
                    @endif
                </div>
            </div>

            @if ($esMovimiento)
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <label class="etiqueta" for="g-destino">Llega a la cuenta</label>
                    <select id="g-destino" wire:model.live="form.destino_id" class="campo">
                        <option value="">Elige la cuenta destino</option>
                        @foreach ($cuentasCaptura as $c)
                            <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                        @endforeach
                        <option value="{{ \App\Livewire\Finanzas\Gastos::OTRA }}">Otra (escribir una nueva)…</option>
                    </select>
                    @error('form.destino_id') <p class="error-campo">{{ $message }}</p> @enderror
                    @if (($form['destino_id'] ?? '') === \App\Livewire\Finanzas\Gastos::OTRA)
                        <input type="text" wire:model="form.destino_nueva" maxlength="80" class="campo mt-2" placeholder="Nombre de la cuenta destino" aria-label="Nombre de la cuenta destino nueva" autocomplete="off">
                        @error('form.destino_nueva') <p class="error-campo">{{ $message }}</p> @enderror
                    @endif
                </div>
            @endif

            <div>
                <label class="etiqueta" for="g-descripcion">Descripción (opcional)</label>
                <input id="g-descripcion" type="text" wire:model="form.descripcion" maxlength="500" class="campo" placeholder="Ej. Gasolina camioneta" autocomplete="off">
                @error('form.descripcion') <p class="error-campo">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="submit" class="btn-primario w-full text-lg sm:w-auto" wire:loading.attr="disabled" wire:target="guardar">
                    <span wire:loading.remove wire:target="guardar">{{ $editando ? 'Guardar cambios' : 'Guardar gasto' }}</span>
                    <span wire:loading wire:target="guardar">Guardando…</span>
                </button>
                @if ($editando)
                    <button type="button" wire:click="cancelarEdicion" class="btn-secundario w-full sm:w-auto">Cancelar</button>
                @endif
            </div>
        </form>

        {{-- ===== Filtros ===== --}}
        <div class="space-y-3 rounded-xl bg-white p-4 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">Gastos capturados</h2>
            <input type="search" wire:model.live.debounce.300ms="buscar" class="campo" placeholder="Buscar en la descripción" aria-label="Buscar en la descripción">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                <div>
                    <label class="etiqueta" for="f-anio">Año</label>
                    <select id="f-anio" wire:model.live="anio" class="campo">
                        @foreach ($anios as $a)
                            <option value="{{ $a }}">{{ $a }}</option>
                        @endforeach
                        <option value="todos">Todos</option>
                    </select>
                </div>
                <div>
                    <label class="etiqueta" for="f-mes">Mes</label>
                    <select id="f-mes" wire:model.live="mes" class="campo" @disabled($semanaCobra || $todosLosAnios)>
                        <option value="">{{ $todosLosAnios ? '—' : 'Todos' }}</option>
                        @foreach ($meses as $n => $nombre)
                            <option value="{{ $n }}">{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-2 sm:col-span-2">
                    <label class="etiqueta" for="f-semana">Semana (lunes a sábado)</label>
                    <select id="f-semana" wire:model.live="semana" class="campo" @disabled($todosLosAnios)>
                        <option value="">{{ $todosLosAnios ? 'Elige un año' : 'Sin elegir semana' }}</option>
                        @foreach ($semanas as $n => $etiqueta)
                            <option value="{{ $n }}">{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="etiqueta" for="f-de">De quién</label>
                    <select id="f-de" wire:model.live="de" class="campo">
                        <option value="">Todos</option>
                        @foreach (\App\Models\Gasto::DE as $clave => $texto)
                            <option value="{{ $clave }}">{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="etiqueta" for="f-tipo">Tipo</label>
                    <select id="f-tipo" wire:model.live="tipo" class="campo">
                        <option value="">Todos</option>
                        @foreach (\App\Models\Gasto::TIPOS as $clave => $texto)
                            <option value="{{ $clave }}">{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="etiqueta" for="f-categoria">Categoría</label>
                    <select id="f-categoria" wire:model.live="categoria" class="campo">
                        <option value="">Todas</option>
                        @foreach ($categorias as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                        <option value="{{ \App\Livewire\Finanzas\Gastos::SIN_CATEGORIA }}">Sin categoría</option>
                    </select>
                </div>
                <div>
                    <label class="etiqueta" for="f-cuenta">Pagado con</label>
                    <select id="f-cuenta" wire:model.live="cuenta" class="campo">
                        <option value="">Todas</option>
                        @foreach ($cuentasFiltro as $c)
                            <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if ($semanaCobra)
                <p class="text-sm text-slate-600">Del {{ $semanaCobra[0]->format('d/m/Y') }} al {{ $semanaCobra[1]->format('d/m/Y') }}</p>
            @endif
            <button type="button" wire:click="limpiar" class="text-sm font-medium text-marino underline">Limpiar filtros</button>
        </div>

        {{-- ===== Totales de todo el filtro (solo gastos) ===== --}}
        <section class="rounded-xl bg-white p-4 shadow-sm" aria-label="Totales">
            <dl class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="col-span-2 lg:col-span-1">
                    <dt class="text-xs uppercase text-slate-500">Total de gastos</dt>
                    <dd class="text-2xl font-bold text-marino">{{ $dinero($totales['total']) }}</dd>
                    <dd class="text-xs text-slate-500">{{ number_format($totales['n']) }} {{ $totales['n'] === 1 ? 'gasto' : 'gastos' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">MASUME</dt>
                    <dd class="text-lg font-bold text-marino sm:text-xl">{{ $dinero($totales['masume']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Personal</dt>
                    <dd class="text-lg font-bold text-marino sm:text-xl">{{ $dinero($totales['personal']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-slate-500">Movimientos entre cuentas</dt>
                    <dd class="text-xl font-bold text-slate-600">{{ $dinero($totales['mov_total']) }}</dd>
                    <dd class="text-xs text-slate-500">{{ number_format($totales['mov_n']) }} {{ $totales['mov_n'] === 1 ? 'movimiento' : 'movimientos' }} · no suman en el total</dd>
                </div>
            </dl>
        </section>

        {{-- ===== Resumen por categoría ===== --}}
        @if ($resumen)
            <section class="rounded-xl bg-white p-4 shadow-sm" aria-label="Resumen por categoría">
                <h2 class="mb-3 text-lg font-semibold text-marino">Por categoría</h2>
                <ul class="space-y-2">
                    @foreach ($resumen as $r)
                        <li wire:key="cat-{{ $loop->index }}-{{ $r['categoria'] }}">
                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                <span class="min-w-0 truncate font-medium text-slate-800">{{ $r['categoria'] }} <span class="font-normal text-slate-500">({{ number_format($r['n']) }})</span></span>
                                <span class="shrink-0"><span class="font-semibold">{{ $dinero($r['total']) }}</span> <span class="text-slate-500">· {{ number_format($r['pct'], 1) }}%</span></span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                                <div class="h-full rounded-full bg-naranja" style="width: {{ $maxResumen > 0 ? max(2, round(max(0, $r['total']) / $maxResumen * 100)) : 0 }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- ===== Escritorio ===== --}}
        <div class="hidden overflow-x-auto rounded-xl bg-white shadow-sm md:block">
            <table class="w-full text-[13px]">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-2 py-3">Fecha</th>
                        <th class="px-2 py-3">De</th>
                        <th class="px-2 py-3">Categoría</th>
                        <th class="px-2 py-3">Descripción</th>
                        <th class="px-2 py-3">Pagado con</th>
                        <th class="px-2 py-3 text-right">Monto</th>
                        <th class="px-2 py-3"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($gastos as $g)
                        <tr wire:key="fila-{{ $g->id }}" class="{{ $g->tipo === 'movimiento' ? 'bg-slate-50 text-slate-600' : 'hover:bg-slate-50' }}">
                            <td class="whitespace-nowrap px-2 py-2">{{ $g->fecha->format('d/m/Y') }}</td>
                            <td class="px-2 py-2"><span class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold {{ $colorDe[$g->de] ?? 'bg-slate-100 text-slate-700' }}">{{ \App\Models\Gasto::DE[$g->de] ?? $g->de }}</span></td>
                            <td class="px-2 py-2">
                                {{ $g->categoria ?? '—' }}
                                @include('livewire.finanzas.partials.insignias-gasto')
                            </td>
                            <td class="max-w-[18rem] px-2 py-2"><span class="block truncate" title="{{ $g->descripcion }}">{{ $g->descripcion }}</span></td>
                            <td class="px-2 py-2">
                                {{ $g->cuenta?->nombre ?? '—' }}
                                @if ($g->tipo === 'movimiento' && $g->cuentaDestino)
                                    <span class="block text-xs text-slate-500">→ {{ $g->cuentaDestino->nombre }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-2 py-2 text-right font-semibold">{{ $dinero($g->monto) }}</td>
                            <td class="px-2 py-2 text-right">
                                @if ($puedeEditar[$g->id] ?? false)
                                    <div class="flex justify-end gap-1">
                                        <button type="button" wire:click="editar({{ $g->id }})" class="btn-secundario !min-h-8 !px-1.5 !py-1 !text-xs" aria-label="Editar el gasto de {{ $dinero($g->monto) }} del {{ $g->fecha->format('d/m/Y') }}">Editar</button>
                                        <button type="button" wire:click="borrar({{ $g->id }})" wire:confirm="¿Borrar este gasto de {{ $dinero($g->monto) }}?" class="btn-secundario !min-h-8 !px-1.5 !py-1 !text-xs !text-red-700" aria-label="Borrar el gasto de {{ $dinero($g->monto) }} del {{ $g->fecha->format('d/m/Y') }}">Borrar</button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No hay gastos con esos filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ===== Celular ===== --}}
        <ul class="space-y-3 md:hidden">
            @forelse ($gastos as $g)
                <li wire:key="tarjeta-{{ $g->id }}" class="rounded-xl bg-white p-4 shadow-sm {{ $g->tipo === 'movimiento' ? 'bg-slate-50' : '' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm text-slate-500">{{ $g->fecha->format('d/m/Y') }}
                                <span class="ml-1 inline-block rounded-full px-2 py-0.5 align-middle text-xs font-semibold {{ $colorDe[$g->de] ?? 'bg-slate-100 text-slate-700' }}">{{ \App\Models\Gasto::DE[$g->de] ?? $g->de }}</span>
                            </p>
                            <p class="font-semibold text-slate-900">
                                {{ $g->categoria ?? 'Sin categoría' }}
                                @include('livewire.finanzas.partials.insignias-gasto')
                            </p>
                        </div>
                        <p class="shrink-0 text-xl font-bold {{ $g->tipo === 'movimiento' ? 'text-slate-600' : 'text-marino' }}">{{ $dinero($g->monto) }}</p>
                    </div>
                    @if ($g->descripcion)
                        <p class="mt-1 text-sm text-slate-700">{{ $g->descripcion }}</p>
                    @endif
                    @if ($g->cuenta)
                        <p class="mt-1 text-sm text-slate-500">Pagado con {{ $g->cuenta->nombre }}@if ($g->tipo === 'movimiento' && $g->cuentaDestino) → {{ $g->cuentaDestino->nombre }}@endif</p>
                    @endif
                    @if ($puedeEditar[$g->id] ?? false)
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <button type="button" wire:click="editar({{ $g->id }})" class="btn-secundario w-full" aria-label="Editar el gasto de {{ $dinero($g->monto) }} del {{ $g->fecha->format('d/m/Y') }}">Editar</button>
                            <button type="button" wire:click="borrar({{ $g->id }})" wire:confirm="¿Borrar este gasto de {{ $dinero($g->monto) }}?" class="btn-secundario w-full !text-red-700" aria-label="Borrar el gasto de {{ $dinero($g->monto) }} del {{ $g->fecha->format('d/m/Y') }}">Borrar</button>
                        </div>
                    @endif
                </li>
            @empty
                <li class="rounded-xl bg-white p-6 text-center text-slate-500">No hay gastos con esos filtros.</li>
            @endforelse
        </ul>

        {{-- Páginas (en español; el paginador de Laravel trae su texto en inglés) --}}
        @if ($gastos->hasPages())
            <nav class="grid grid-cols-2 items-center gap-3 sm:grid-cols-[auto_1fr_auto]" aria-label="Páginas de gastos">
                <p class="col-span-2 text-center text-sm text-slate-600 sm:order-2 sm:col-span-1">
                    Página {{ $gastos->currentPage() }} de {{ $gastos->lastPage() }} · {{ number_format($gastos->total()) }} registros con estos filtros
                </p>
                <button type="button" wire:click="previousPage" @disabled($gastos->onFirstPage()) class="btn-secundario disabled:cursor-not-allowed disabled:opacity-40 sm:order-1">← Anterior</button>
                <button type="button" wire:click="nextPage" @disabled(! $gastos->hasMorePages()) class="btn-secundario disabled:cursor-not-allowed disabled:opacity-40 sm:order-3">Siguiente →</button>
            </nav>
        @endif
    </div>
</div>

@php
    $dinero = fn ($v) => '$'.number_format((float) $v, 2);
@endphp
<div class="space-y-4">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <button type="button" wire:click="abrirNuevoPrestamo" class="btn-primario w-full sm:w-auto">+ Nuevo préstamo</button>
    </div>

    @if ($nuevoPrestamo)
        <form wire:submit="guardarPrestamo" class="space-y-3 rounded-xl bg-white p-4 shadow-sm" aria-label="Nuevo préstamo">
            <h2 class="text-lg font-semibold text-marino">Nuevo préstamo</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="etiqueta" for="p-usuario">Persona</label>
                    <select id="p-usuario" wire:model="pUsuario" class="campo">
                        <option value="">Elige a quién…</option>
                        @foreach ($enNomina as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                    @error('pUsuario') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="p-monto">Monto</label>
                    <input id="p-monto" type="number" step="0.01" min="0" inputmode="decimal" wire:model="pMonto" class="campo" placeholder="5000">
                    @error('pMonto') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="p-fecha">Fecha</label>
                    <input id="p-fecha" type="date" wire:model="pFecha" class="campo">
                    @error('pFecha') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="p-desc">Descuento semanal</label>
                    <input id="p-desc" type="number" step="0.01" min="0" inputmode="decimal" wire:model="pDescuento" class="campo" placeholder="500">
                    @error('pDescuento') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="etiqueta" for="p-nota">Nota (para qué fue)</label>
                <input id="p-nota" type="text" maxlength="500" wire:model="pNota" class="campo">
                @error('pNota') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="submit" class="btn-primario">Guardar préstamo</button>
                <button type="button" wire:click="cancelarPrestamo" class="btn-secundario">Cancelar</button>
            </div>
        </form>
    @endif

    @forelse ($grupos as $g)
        <section wire:key="grupo-{{ $loop->index }}-{{ $g['nombre'] }}" class="space-y-3 rounded-xl bg-white p-4 shadow-sm">
            <header class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold text-marino">{{ $g['nombre'] }}</h2>
                @if ($g['activos']->isNotEmpty())
                    <p class="text-base font-bold {{ $g['saldo'] > 0 ? 'text-naranja' : 'text-green-700' }}">Debe {{ $dinero($g['saldo']) }}</p>
                @else
                    <p class="text-sm font-medium text-green-700">Sin deudas</p>
                @endif
            </header>

            @foreach ($g['activos'] as $x)
                @include('livewire.finanzas.nomina.prestamo', ['x' => $x, 'saldado' => false])
            @endforeach

            @if ($g['saldados']->isNotEmpty())
                <details class="rounded-lg border border-slate-200 p-3">
                    <summary class="cursor-pointer text-sm font-medium text-slate-600">Saldados ({{ $g['saldados']->count() }})</summary>
                    <div class="mt-3 space-y-3">
                        @foreach ($g['saldados'] as $x)
                            @include('livewire.finanzas.nomina.prestamo', ['x' => $x, 'saldado' => true])
                        @endforeach
                    </div>
                </details>
            @endif
        </section>
    @empty
        <p class="rounded-xl bg-white p-6 text-base text-slate-600 shadow-sm">No hay préstamos todavía.</p>
    @endforelse
</div>

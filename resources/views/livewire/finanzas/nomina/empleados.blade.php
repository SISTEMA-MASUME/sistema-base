@php
    $pctTexto = rtrim(rtrim(number_format($sumaPct, 2), '0'), '.');
@endphp
<div class="space-y-4">
    @if ($sumaPct > 0 && abs($sumaPct - 100) > 0.009)
        <p class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900" role="alert">
            Los porcentajes de comisión de quienes están en nómina suman {{ $pctTexto }}%, no 100%: {{ $sumaPct < 100 ? 'una parte de la bolsa quedaría sin repartir' : 'se pagaría de más' }}.
        </p>
    @endif

    <div class="space-y-3">
        @foreach ($usuarios as $u)
            <form wire:key="emp-{{ $u->id }}" wire:submit="guardarEmpleado({{ $u->id }})" class="space-y-3 rounded-xl bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-marino">{{ $u->name }} <span class="text-sm font-normal text-slate-500">· {{ $u->rol }}</span></h2>
                    <label class="flex items-center gap-2 text-base font-medium">
                        <input type="checkbox" wire:model="emp.{{ $u->id }}.en_nomina" class="h-5 w-5 rounded border-slate-300"> En nómina
                    </label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="etiqueta" for="e-sb-{{ $u->id }}">Sueldo base semanal</label>
                        <input id="e-sb-{{ $u->id }}" type="number" step="0.01" min="0" inputmode="decimal" wire:model="emp.{{ $u->id }}.sueldo_base" class="campo">
                        @error("emp.{$u->id}.sueldo_base") <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="etiqueta" for="e-pc-{{ $u->id }}">% de la comisión</label>
                        <input id="e-pc-{{ $u->id }}" type="number" step="0.01" min="0" max="100" inputmode="decimal" wire:model="emp.{{ $u->id }}.comision_pct" class="campo">
                        @error("emp.{$u->id}.comision_pct") <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="etiqueta" for="e-fi-{{ $u->id }}">Fecha de ingreso</label>
                        <input id="e-fi-{{ $u->id }}" type="date" wire:model="emp.{{ $u->id }}.fecha_ingreso" class="campo">
                        @error("emp.{$u->id}.fecha_ingreso") <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-end">
                        <label class="flex items-center gap-2 py-2 text-base">
                            <input type="checkbox" wire:model="emp.{{ $u->id }}.trabaja_sabados" class="h-5 w-5 rounded border-slate-300"> Trabaja sábados
                        </label>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="etiqueta" for="e-cu-{{ $u->id }}">Cuenta de pago</label>
                        <select id="e-cu-{{ $u->id }}" wire:model="emp.{{ $u->id }}.cuenta_nomina_id" class="campo">
                            <option value="">La general de la nómina{{ $cuentaGeneral ? ' ('.$cuentaGeneral->nombre.')' : '' }}</option>
                            @foreach ($cuentasPago as $c)
                                <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                            @endforeach
                            @foreach ($cuentasInactivas as $c)
                                <option value="{{ $c->id }}">{{ $c->nombre }} (inactiva)</option>
                            @endforeach
                        </select>
                        @error("emp.{$u->id}.cuenta_nomina_id") <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                </div>
                <button type="submit" class="btn-primario w-full sm:w-auto">Guardar a {{ $u->name }}</button>
            </form>
        @endforeach
    </div>
</div>

<div class="space-y-4">
@if ($mensaje)
    <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
@endif
<form wire:submit="guardarFolio" class="rounded-xl bg-white p-5 shadow-sm">
    <label class="etiqueta" for="d-folio">Número de tu próxima cotización (consecutivo)</label>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
        <span class="text-lg font-semibold text-marino">NT-</span>
        <input id="d-folio" type="number" min="1" step="1" inputmode="numeric" wire:model="proximoFolio" class="campo sm:max-w-40">
        <button type="submit" class="btn-secundario shrink-0">Cambiar consecutivo</button>
    </div>
    @error('proximoFolio') <p class="error-campo">{{ $message }}</p> @enderror
</form>
<form wire:submit="guardar" class="space-y-4 rounded-xl bg-white p-5 shadow-sm">
    <div>
        <p class="etiqueta">Logo</p>
        <div class="flex flex-wrap items-center gap-4">
            <img src="{{ route('ned.logo') }}?v={{ now()->timestamp }}" alt="Logo" class="h-16 max-w-xs rounded border border-slate-200 object-contain p-1">
            <span class="text-sm text-slate-500">¿Quieres otro? Súbelo aquí:</span>
            <input type="file" wire:model="logo" accept="image/png,image/jpeg" class="text-sm">
        </div>
        @error('logo') <p class="error-campo">{{ $message }}</p> @enderror
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div><label class="etiqueta" for="d-emp">Empresa</label><input id="d-emp" type="text" wire:model="empresa" class="campo">@error('empresa') <p class="error-campo">{{ $message }}</p> @enderror</div>
        <div><label class="etiqueta" for="d-nom">Nombre (quien emite)</label><input id="d-nom" type="text" wire:model="nombre" class="campo"></div>
        <div><label class="etiqueta" for="d-rfc">RFC</label><input id="d-rfc" type="text" wire:model="rfc" class="campo uppercase"></div>
        <div><label class="etiqueta" for="d-reg">Régimen</label><input id="d-reg" type="text" wire:model="regimen" class="campo"></div>
        <div class="sm:col-span-2"><label class="etiqueta" for="d-dir">Dirección</label><textarea id="d-dir" rows="3" wire:model="direccion" class="campo"></textarea></div>
        <div><label class="etiqueta" for="d-tel">Teléfono</label><input id="d-tel" type="tel" wire:model="telefono" class="campo"></div>
        <div><label class="etiqueta" for="d-correo">Correo</label><input id="d-correo" type="email" wire:model="correo" class="campo">@error('correo') <p class="error-campo">{{ $message }}</p> @enderror</div>
        <div class="sm:col-span-2"><label class="etiqueta" for="d-web">Página de internet</label><input id="d-web" type="text" wire:model="web" class="campo"></div>
        <div class="sm:col-span-2"><label class="etiqueta" for="d-cond">Condiciones</label><textarea id="d-cond" rows="3" wire:model="condiciones" class="campo"></textarea></div>
    </div>
    <button type="submit" class="btn-primario">Guardar datos</button>
</form>
</div>

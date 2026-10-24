<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <a href="{{ route('proveedores.index') }}" class="text-marino hover:underline">&larr; Volver a proveedores</a>
        <a href="{{ route('proveedores.index', ['editar' => $proveedor->id]) }}" class="btn-secundario text-center">Editar datos del proveedor</a>
    </div>

    <section class="rounded-xl bg-white p-5 shadow-sm">
        <h2 class="text-xl font-semibold text-marino">{{ $proveedor->nombre }}</h2>
        <dl class="mt-3 grid gap-x-6 gap-y-2 text-base sm:grid-cols-2">
            <div><dt class="text-sm text-slate-500">Teléfono general</dt><dd>{{ $proveedor->telefono ?: '—' }}</dd></div>
            <div><dt class="text-sm text-slate-500">Correo general</dt><dd class="break-all">{{ $proveedor->correo ?: '—' }}</dd></div>
            @if ($proveedor->notas)
                <div class="sm:col-span-2"><dt class="text-sm text-slate-500">Notas</dt><dd class="whitespace-pre-line">{{ $proveedor->notas }}</dd></div>
            @endif
        </dl>
        <label class="mt-4 flex items-start gap-3 rounded-lg border border-indigo-200 bg-indigo-50 p-3" data-vende-credito>
            <input type="checkbox" wire:click="alternarCredito" @checked($proveedor->vende_a_credito) class="mt-1 h-6 w-6 shrink-0 rounded border-slate-300">
            <span class="block font-semibold text-indigo-900">Nos vende a crédito</span>
        </label>
    </section>

    <section class="rounded-xl bg-white p-5 shadow-sm">
        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-marino">Contactos / vendedores</h2>
            </div>
            <button type="button" wire:click="nuevoContacto" class="btn-primario w-full sm:w-auto">+ Nuevo contacto</button>
        </div>

        @if ($mensaje)
            <div role="status" class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
        @endif

        @if ($mostrarContacto)
            <form wire:submit="guardarContacto" class="mb-4 space-y-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <h3 class="font-semibold">{{ $contactoId ? 'Editar contacto' : 'Nuevo contacto' }}</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="etiqueta" for="pk-nombre">Nombre</label>
                        <input id="pk-nombre" type="text" wire:model="c_nombre" class="campo">
                        @error('c_nombre') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="etiqueta" for="pk-puesto">Puesto</label>
                        <input id="pk-puesto" type="text" wire:model="c_puesto" class="campo" placeholder="Vendedor">
                    </div>
                    <div>
                        <label class="etiqueta" for="pk-correo">Correo</label>
                        <input id="pk-correo" type="email" wire:model="c_correo" class="campo">
                        @error('c_correo') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="etiqueta" for="pk-tel">Teléfono / WhatsApp</label>
                        <input id="pk-tel" type="tel" wire:model="c_telefono" class="campo">
                        @error('c_telefono') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" class="btn-primario">Guardar contacto</button>
                    <button type="button" wire:click="cancelarContacto" class="btn-secundario">Cancelar</button>
                </div>
            </form>
        @endif

        @if ($contactos->isEmpty())
            <p class="text-slate-600">Este proveedor todavía no tiene contactos.</p>
        @else
            <ul class="divide-y divide-slate-200">
                @foreach ($contactos as $k)
                    <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="pcontacto-{{ $k->id }}">
                        <div class="min-w-0">
                            <p class="font-semibold">
                                {{ $k->nombre }}@if ($k->puesto) <span class="font-normal text-slate-600">· {{ $k->puesto }}</span>@endif
                                @if ($k->principal)<span class="ml-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800">Principal</span>@endif
                            </p>
                            <p class="break-all text-sm text-slate-600">{{ collect([$k->correo, $k->telefono])->filter()->implode(' · ') }}</p>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            @unless ($k->principal)
                                <button type="button" wire:click="hacerPrincipal({{ $k->id }})" class="btn-secundario">Hacer principal</button>
                            @endunless
                            <button type="button" wire:click="editarContacto({{ $k->id }})" class="btn-secundario">Editar</button>
                            <button type="button" wire:click="borrarContacto({{ $k->id }})" wire:confirm="¿Borrar a {{ $k->nombre }}?" class="btn-secundario text-red-700">Borrar</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

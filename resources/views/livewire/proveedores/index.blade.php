<div>
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <input type="search" wire:model.live.debounce.300ms="busqueda" placeholder="Buscar por nombre, contacto, correo o teléfono" aria-label="Buscar proveedor" class="campo sm:max-w-md">
        <button type="button" wire:click="nuevo" class="btn-primario w-full sm:w-auto">+ Nuevo proveedor</button>
    </div>

    @if ($mensaje)
        <div role="status" class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    @if ($mostrarFormulario)
        <form wire:submit="guardar" class="mb-6 space-y-4 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">{{ $proveedorId ? 'Editar proveedor' : 'Nuevo proveedor' }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="etiqueta" for="p-nombre">Nombre</label>
                    <input id="p-nombre" type="text" wire:model="nombre" class="campo uppercase">
                    @error('nombre') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="p-tel">Teléfono general</label>
                    <input id="p-tel" type="tel" wire:model="telefono" class="campo">
                    @error('telefono') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="p-correo">Correo general</label>
                    <input id="p-correo" type="email" wire:model="correo" class="campo">
                    @error('correo') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="etiqueta" for="p-notas">Notas</label>
                    <textarea id="p-notas" rows="3" wire:model="notas" class="campo"></textarea>
                    @error('notas') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="submit" class="btn-primario">Guardar</button>
                <button type="button" wire:click="cancelar" class="btn-secundario">Cancelar</button>
            </div>
        </form>
    @endif

    <div class="overflow-hidden rounded-xl bg-white shadow-sm">
        @if ($proveedores->isEmpty())
            <p class="p-6 text-center text-slate-600">
                {{ trim($busqueda) !== '' ? 'No encontramos proveedores con esa búsqueda.' : 'Sin proveedores' }}
            </p>
        @else
            <ul class="divide-y divide-slate-200">
                @foreach ($proveedores as $p)
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="proveedor-{{ $p->id }}">
                        <div class="min-w-0">
                            <a href="{{ route('proveedores.ver', $p) }}" class="font-semibold text-marino hover:underline">{{ $p->nombre }}</a>
                            @php($datosProv = $p->datosContacto())
                            <p class="truncate text-sm text-slate-600">
                                {{ collect([$datosProv['nombre'], $datosProv['telefono'], $datosProv['correo']])->filter()->implode(' · ') }}
                                @if ($p->contactos->count() > 1) · {{ $p->contactos->count() }} contactos @endif
                            </p>
                            @if ($p->notas)
                                <p class="truncate text-sm text-slate-500">{{ $p->notas }}</p>
                            @endif
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <a href="{{ route('proveedores.ver', $p) }}" class="btn-secundario">Contactos</a>
                            <button type="button" wire:click="editar({{ $p->id }})" class="btn-secundario">Editar</button>
                            <button type="button" wire:click="borrar({{ $p->id }})" wire:confirm="¿Borrar a {{ $p->nombre }}?" class="btn-secundario text-red-700">Borrar</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="mt-4">{{ $proveedores->links() }}</div>
</div>

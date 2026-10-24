<div>
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <input type="search" wire:model.live.debounce.300ms="busqueda" placeholder="Buscar por nombre, RFC, correo o teléfono" aria-label="Buscar cliente" class="campo sm:max-w-md">
        <button type="button" wire:click="nuevo" class="btn-primario w-full sm:w-auto">+ Nuevo cliente</button>
    </div>

    @if ($mensaje)
        <div role="status" class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
    @endif

    @if ($mostrarFormulario)
        <form wire:submit="guardar" class="mb-6 space-y-4 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">{{ $clienteId ? 'Editar cliente' : 'Nuevo cliente' }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="etiqueta" for="c-nombre">Nombre corto (como lo llaman ustedes)</label>
                    <input id="c-nombre" type="text" wire:model="nombre" class="campo uppercase">
                    @error('nombre') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="c-razon">Razón social</label>
                    <input id="c-razon" type="text" wire:model="razon_social" class="campo">
                    @error('razon_social') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="c-rfc">RFC</label>
                    <input id="c-rfc" type="text" wire:model="rfc" maxlength="13" class="campo uppercase">
                    @error('rfc') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="c-credito">Días de crédito</label>
                    <input id="c-credito" type="number" inputmode="numeric" min="0" wire:model="dias_credito" class="campo">
                    @error('dias_credito') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="c-tel">Teléfono</label>
                    <input id="c-tel" type="tel" wire:model="telefono" class="campo">
                    @error('telefono') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="c-correo">Correo</label>
                    <input id="c-correo" type="email" wire:model="correo" class="campo">
                    @error('correo') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="etiqueta" for="c-dir">Dirección</label>
                    <input id="c-dir" type="text" wire:model="direccion" class="campo">
                    @error('direccion') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-2 sm:col-span-2">
                    <p class="etiqueta">Después de entregar</p>
                    <label class="flex items-start gap-2 text-base">
                        <input type="checkbox" wire:model="sube_factura_portal" class="mt-1 h-5 w-5">
                        <span>Sube la factura a su portal</span>
                    </label>
                    <label class="flex items-start gap-2 text-base">
                        <input type="checkbox" wire:model="oc_despues_de_facturar" class="mt-1 h-5 w-5">
                        <span>Genera su OC después de facturar</span>
                    </label>
                    <label class="flex items-start gap-2 text-base">
                        <input type="checkbox" wire:model="envia_evidencia_por_correo" class="mt-1 h-5 w-5">
                        <span>Evidencia respondiendo el correo de su OC</span>
                    </label>
                </div>
                <div class="sm:col-span-2">
                    <label class="etiqueta" for="c-portal">Portal de proveedores (URL)</label>
                    <input id="c-portal" type="url" wire:model="portal_url" class="campo" placeholder="https://portal.ejemplo.com">
                    @error('portal_url') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="etiqueta" for="c-notas">Notas</label>
                    <textarea id="c-notas" rows="3" wire:model="notas" class="campo"></textarea>
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
        @if ($clientes->isEmpty())
            <p class="p-6 text-center text-slate-600">
                {{ trim($busqueda) !== '' ? 'No encontramos clientes con esa búsqueda.' : 'Sin clientes' }}
            </p>
        @else
            <ul class="divide-y divide-slate-200">
                @foreach ($clientes as $c)
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="cliente-{{ $c->id }}">
                        <div class="min-w-0">
                            <p class="font-semibold">
                                <a href="{{ route('clientes.ver', $c) }}" class="text-marino hover:underline">{{ $c->nombre }}</a>
                            </p>
                            @if ($c->razon_social)
                                <p class="truncate text-sm text-slate-600">{{ $c->razon_social }}</p>
                            @endif
                            <p class="truncate text-sm text-slate-600">
                                {{ collect([$c->rfc, $c->telefono, $c->correo])->filter()->implode(' · ') }}
                            </p>
                            <p class="mt-1 text-xs">
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">
                                    {{ $c->dias_credito > 0 ? 'Crédito '.$c->dias_credito.' días' : 'Contado' }}
                                </span>
                                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">
                                    {{ $c->contactos_count }} {{ $c->contactos_count === 1 ? 'contacto' : 'contactos' }}
                                </span>
                            </p>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <a href="{{ route('clientes.ver', $c) }}" class="btn-secundario text-center">Ver ficha</a>
                            <button type="button" wire:click="editar({{ $c->id }})" class="btn-secundario">Editar</button>
                            <button type="button" wire:click="borrar({{ $c->id }})" wire:confirm="¿Borrar a {{ $c->nombre }}?" class="btn-secundario text-red-700">Borrar</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="mt-4">{{ $clientes->links() }}</div>
</div>

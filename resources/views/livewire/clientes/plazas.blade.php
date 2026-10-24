<section class="rounded-xl bg-white p-5 shadow-sm">
    <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-marino">Plazas / sucursales ({{ $plazas->count() }})</h2>
        </div>
        <button type="button" wire:click="nueva" class="btn-primario w-full sm:w-auto">+ Nueva plaza</button>
    </div>

    @if ($mensaje)
        <div role="status" class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    @if ($formulario)
        <form wire:submit="guardar" class="mb-4 space-y-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
            <h3 class="font-semibold">{{ $plazaId ? 'Editar plaza' : 'Nueva plaza' }}</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="etiqueta" for="pz-nombre">Nombre de la plaza</label>
                    <input id="pz-nombre" type="text" wire:model="nombre" class="campo uppercase" placeholder="LUMEX MÉRIDA">
                    @error('nombre') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="pz-ciudad">Ciudad</label>
                    <input id="pz-ciudad" type="text" wire:model="ciudad" class="campo">
                </div>
                <div>
                    <label class="etiqueta" for="pz-estado">Estado</label>
                    <input id="pz-estado" type="text" wire:model="estado" class="campo">
                </div>
                <div>
                    <label class="etiqueta" for="pz-tel">Teléfono</label>
                    <input id="pz-tel" type="tel" wire:model="telefono" class="campo">
                </div>
                <div class="sm:col-span-2">
                    <label class="etiqueta" for="pz-dir">Dirección</label>
                    <input id="pz-dir" type="text" wire:model="direccion" class="campo">
                </div>
                <div class="sm:col-span-2">
                    <label class="etiqueta" for="pz-correos">Correos de facturación</label>
                    <input id="pz-correos" type="text" wire:model="correos" class="campo" placeholder="facturas.merida@parques.mx, cxp@parques.mx">
                    @error('correos') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="pz-contacto">Contacto</label>
                    <input id="pz-contacto" type="text" wire:model="contacto" class="campo">
                </div>
                <div>
                    <label class="etiqueta" for="pz-notas">Notas</label>
                    <input id="pz-notas" type="text" wire:model="notas" class="campo">
                </div>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="submit" class="btn-primario">Guardar plaza</button>
                <button type="button" wire:click="cancelar" class="btn-secundario">Cancelar</button>
            </div>
        </form>
    @endif

    @if ($plazas->isEmpty())
        <p class="text-slate-600">Este cliente no tiene plazas.</p>
    @else
        <ul class="divide-y divide-slate-200">
            @foreach ($plazas as $s)
                <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="plaza-{{ $s->id }}">
                    <div class="min-w-0">
                        <p class="font-semibold">📍 {{ $s->ubicacion() }} <span class="font-normal text-slate-500">· {{ $s->pedidos_count }} {{ $s->pedidos_count === 1 ? 'pedido' : 'pedidos' }}</span></p>
                        <p class="break-all text-sm text-slate-600">{{ $s->correos_facturacion ?: 'Sin correos de facturación' }}</p>
                        @if ($s->contacto || $s->telefono)
                            <p class="text-sm text-slate-600">{{ collect([$s->contacto, $s->telefono])->filter()->implode(' · ') }}</p>
                        @endif
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <button type="button" wire:click="editar({{ $s->id }})" class="btn-secundario">Editar</button>
                        <button type="button" wire:click="borrar({{ $s->id }})" wire:confirm="¿Borrar la plaza {{ $s->nombre }}?" class="btn-secundario text-red-700">Borrar</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($esAdmin)
        <details class="mt-4 rounded-lg border border-slate-200 p-3" @if ($unir) open @endif>
            <summary class="cursor-pointer text-sm font-semibold text-marino">Unir otros clientes como plazas de {{ $cliente->nombre }}</summary>
            <div class="mt-3 space-y-3">
                <input type="search" wire:model.live.debounce.400ms="buscarUnir" class="campo" placeholder="Buscar clientes (mínimo 2 letras)" aria-label="Buscar clientes para unir">
                @if ($candidatos->isNotEmpty())
                    <div class="grid gap-1 sm:grid-cols-2">
                        @foreach ($candidatos as $c)
                            <label class="flex min-h-10 items-center gap-2 text-sm" wire:key="unir-{{ $c->id }}">
                                <input type="checkbox" value="{{ $c->id }}" wire:model.live="unir" class="h-5 w-5 rounded border-slate-300">
                                {{ $c->nombre }}
                            </label>
                        @endforeach
                    </div>
                @endif
                <button type="button" wire:click="verVistaPrevia" class="btn-secundario" @disabled(! $unir)>Ver vista previa ({{ count($unir) }})</button>

                @if ($previa)
                    <div class="rounded-lg border border-orange-300 bg-orange-50 p-3">
                        <p class="mb-2 font-semibold text-orange-900">Vista previa: esto va a pasar</p>
                        <ul class="space-y-2 text-sm">
                            @foreach ($previa as $f)
                                <li wire:key="previa-{{ $f['id'] }}">
                                    <strong>{{ $f['nombre'] }}</strong> → plaza de {{ $cliente->nombre }}:
                                    {{ $f['cotizaciones'] }} cotizaciones, {{ $f['pedidos'] }} pedidos y {{ $f['contactos'] }} contactos se mueven.
                                    @foreach ($f['conflictos'] as $x)
                                        <span class="block text-red-700">⚠ {{ $x }}</span>
                                    @endforeach
                                </li>
                            @endforeach
                        </ul>
                        @if (collect($previa)->contains(fn ($f) => $f['conflictos'] !== []))
                            <p class="mt-2 text-sm font-semibold text-red-700">No se puede unir mientras haya OC repetidas.</p>
                        @else
                            <button type="button" wire:click="confirmarUnion" wire:confirm="¿Seguro? Esto mueve cotizaciones y pedidos y no se deshace con un clic." class="btn-primario mt-3">Sí, unir como plazas</button>
                        @endif
                    </div>
                @endif
            </div>
        </details>
    @endif
</section>

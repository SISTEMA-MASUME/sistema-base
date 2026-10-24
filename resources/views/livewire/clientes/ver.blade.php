<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <a href="{{ route('clientes.index') }}" class="text-marino hover:underline">&larr; Volver a clientes</a>
        <a href="{{ route('clientes.index', ['editar' => $cliente->id]) }}" class="btn-secundario text-center">Editar datos del cliente</a>
    </div>

    <section class="rounded-xl bg-white p-5 shadow-sm">
        <h2 class="text-xl font-semibold text-marino">{{ $cliente->nombre }}</h2>
        <dl class="mt-3 grid gap-x-6 gap-y-2 text-base sm:grid-cols-2">
            <div><dt class="text-sm text-slate-500">Razón social</dt><dd>{{ $cliente->razon_social ?: '—' }}</dd></div>
            <div><dt class="text-sm text-slate-500">RFC</dt><dd>{{ $cliente->rfc ?: '—' }}</dd></div>
            <div><dt class="text-sm text-slate-500">Teléfono</dt><dd>{{ $cliente->telefono ?: '—' }}</dd></div>
            <div><dt class="text-sm text-slate-500">Correo</dt><dd class="break-all">{{ $cliente->correo ?: '—' }}</dd></div>
            <div><dt class="text-sm text-slate-500">Días de crédito</dt><dd>{{ $cliente->dias_credito > 0 ? $cliente->dias_credito : 'Contado' }}</dd></div>
            <div><dt class="text-sm text-slate-500">Dirección</dt><dd>{{ $cliente->direccion ?: '—' }}</dd></div>
            <div class="sm:col-span-2">
                <dt class="text-sm text-slate-500">Orden de compra</dt>
                <dd>
                    @if ($cliente->oc_despues_de_facturar)
                        Genera su OC después de facturar.
                        @if ($cliente->portal_url)
                            <a href="{{ $cliente->portal_url }}" target="_blank" rel="noopener" class="break-all text-marino underline">Abrir portal de proveedores</a>
                        @endif
                    @else
                        Manda su OC antes de surtir.
                        @if ($cliente->sube_factura_portal)
                            Sube la factura a su portal.
                            @if ($cliente->portal_url)
                                <a href="{{ $cliente->portal_url }}" target="_blank" rel="noopener" class="break-all text-marino underline">Abrir portal de proveedores</a>
                            @endif
                        @endif
                    @endif
                    @if ($cliente->envia_evidencia_por_correo)
                        <span class="block">Evidencia respondiendo el correo de su OC.</span>
                    @endif
                </dd>
            </div>
            @if ($cliente->notas)
                <div class="sm:col-span-2"><dt class="text-sm text-slate-500">Notas</dt><dd class="whitespace-pre-line">{{ $cliente->notas }}</dd></div>
            @endif
        </dl>
    </section>

    <section class="rounded-xl bg-white p-5 shadow-sm">
        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="text-lg font-semibold text-marino">Contactos</h2>
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
                        <label class="etiqueta" for="k-nombre">Nombre</label>
                        <input id="k-nombre" type="text" wire:model="c_nombre" class="campo">
                        @error('c_nombre') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="etiqueta" for="k-puesto">Puesto</label>
                        <input id="k-puesto" type="text" wire:model="c_puesto" class="campo">
                        @error('c_puesto') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="etiqueta" for="k-correo">Correo</label>
                        <input id="k-correo" type="email" wire:model="c_correo" class="campo">
                        @error('c_correo') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="etiqueta" for="k-tel">Teléfono</label>
                        <input id="k-tel" type="tel" wire:model="c_telefono" class="campo">
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
            <p class="text-slate-600">Este cliente todavía no tiene contactos.</p>
        @else
            <ul class="divide-y divide-slate-200">
                @foreach ($contactos as $k)
                    <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="contacto-{{ $k->id }}">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ $k->nombre }}@if ($k->puesto) <span class="font-normal text-slate-600">· {{ $k->puesto }}</span>@endif</p>
                            <p class="break-all text-sm text-slate-600">{{ collect([$k->correo, $k->telefono])->filter()->implode(' · ') }}</p>
                        </div>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <button type="button" wire:click="editarContacto({{ $k->id }})" class="btn-secundario">Editar</button>
                            <button type="button" wire:click="borrarContacto({{ $k->id }})" wire:confirm="¿Borrar a {{ $k->nombre }}?" class="btn-secundario text-red-700">Borrar</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <livewire:clientes.plazas :cliente-id="$cliente->id" :key="'plazas-'.$cliente->id" />

    @if (auth()->user()->puede('ventas'))
    <section class="rounded-xl bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-marino">Razones sociales con las que factura</h2>
        @if ($mensajeRazon)
            <p role="status" class="mt-3 rounded-lg border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800">{{ $mensajeRazon }}</p>
        @endif
        @if ($razones->isNotEmpty())
            <ul class="mt-3 divide-y divide-slate-200 rounded-lg border border-slate-200">
                @foreach ($razones as $r)
                    <li class="flex items-center justify-between gap-3 px-3 py-2" wire:key="razon-{{ $r->id }}">
                        <span class="min-w-0 break-words">{{ $r->nombre }}@if ($r->rfc) <span class="text-sm text-slate-500">· {{ $r->rfc }}</span>@endif</span>
                        <button type="button" wire:click="quitarRazon({{ $r->id }})" wire:confirm="¿Quitar {{ $r->nombre }}? Sus facturas ya ligadas se quedan como están." class="btn-secundario shrink-0 !min-h-9 !px-3 !py-1 text-sm">Quitar</button>
                    </li>
                @endforeach
            </ul>
        @endif
        <form wire:submit="agregarRazon" class="mt-3 grid gap-2 sm:grid-cols-[1fr_12rem_auto] sm:items-end">
            <div>
                <label class="etiqueta" for="razon-nombre">Nombre en la factura</label>
                <input id="razon-nombre" type="text" wire:model="razonNombre" maxlength="255" class="campo" placeholder="Ej. HOTELES XY" autocomplete="off">
                @error('razonNombre') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="etiqueta" for="razon-rfc">RFC (opcional)</label>
                <input id="razon-rfc" type="text" wire:model="razonRfc" maxlength="20" class="campo" autocomplete="off">
            </div>
            <button type="submit" class="btn-primario">Agregar</button>
        </form>
    </section>
    @endif

    <section class="rounded-xl bg-white p-5 shadow-sm">
        <h2 class="mb-3 text-lg font-semibold text-marino">Cotizaciones</h2>
        @if ($cotizaciones->isEmpty())
            <p class="text-slate-600">Sin cotizaciones</p>
        @else
            <ul class="divide-y divide-slate-200">
                @foreach ($cotizaciones as $cot)
                    <li wire:key="cot-{{ $cot->id }}">
                        <a href="{{ url('/cotizaciones/'.$cot->id) }}" class="flex flex-col gap-1 py-3 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-semibold text-marino">Folio {{ $cot->folio }}</span>
                            <span class="text-sm text-slate-600">{{ $cot->fecha->format('d/m/Y') }}</span>
                            <span class="font-semibold">${{ number_format((float) $cot->total, 2) }}</span>
                            <span class="text-xs"><span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ $cot->estado->etiqueta() }}</span></span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

@php
    $fecha = fn ($f) => $f?->timezone('America/Mexico_City')->format('d/m/Y H:i');
@endphp
<div class="space-y-3">
    @if ($aviso)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $aviso }}</div>
    @endif
    @if ($errorEnvio && ! $preparando)
        <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $errorEnvio }}</div>
    @endif

    {{-- ===== Plaza (PARQUES / LUMEX) ===== --}}
    @if ($sucursales->isNotEmpty() || $pedido->sucursal_id)
        <div class="space-y-2 rounded-lg border border-sky-300 bg-sky-50 p-3">
            <p class="text-sm font-semibold text-sky-900">📍 Plaza del pedido</p>
            <div class="flex flex-col gap-2 sm:flex-row">
                <select wire:model="sucursalId" wire:change="guardarSucursal" class="campo" aria-label="Plaza del pedido">
                    <option value="">Sin plaza</option>
                    @foreach ($sucursales as $s)
                        <option value="{{ $s->id }}">{{ $s->ubicacion() }}</option>
                    @endforeach
                </select>
            </div>
            @if ($pedido->sucursal)
                <p class="text-sm text-sky-900">
                    Facturas a: {{ $pedido->sucursal->correos_facturacion ?: 'sin correos (agrégalos en la ficha del cliente)' }}
                </p>
                @if ($pedido->factura_sucursal_enviada_at)
                    <p class="text-sm text-sky-900">✓ Factura enviada a la plaza por {{ $pedido->facturaSucursalEnviadaPor?->name ?? 'alguien' }} el {{ $fecha($pedido->factura_sucursal_enviada_at) }}.</p>
                @endif
                <button type="button" wire:click="preparar('factura')" class="btn-primario">Enviar factura a {{ $pedido->sucursal->nombre }}</button>
            @endif
        </div>
    @endif

    {{-- ===== Evidencia respondiendo el correo de la OC (ACME) ===== --}}
    @if ($porCorreo)
        <div class="space-y-2 rounded-lg border border-purple-300 bg-purple-50 p-3">
            <p class="text-sm font-semibold text-purple-900">{{ $pedido->cliente->nombre }} pide la evidencia de entrega respondiendo el correo de su OC.</p>
            <form wire:submit="guardarCorreoOc" class="grid gap-2 sm:grid-cols-2">
                <div>
                    <label class="etiqueta" for="oc-rem-{{ $pedidoId }}">Correo de quien mandó la OC</label>
                    <input id="oc-rem-{{ $pedidoId }}" type="email" wire:model="remitente" class="campo" placeholder="compras@cliente.com">
                    @error('remitente') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="oc-asu-{{ $pedidoId }}">Asunto del correo de la OC</label>
                    <input id="oc-asu-{{ $pedidoId }}" type="text" wire:model="asuntoOc" class="campo" placeholder="OC 2000145168">
                </div>
                <div class="sm:col-span-2">
                    <button type="submit" class="btn-secundario">Guardar datos del correo</button>
                </div>
            </form>
            @if ($pedido->evidencia_enviada_at)
                <p class="text-sm text-purple-900">✓ Evidencia enviada por {{ $pedido->evidenciaEnviadaPor?->name ?? 'alguien' }} el {{ $fecha($pedido->evidencia_enviada_at) }}.</p>
            @endif
            <button type="button" wire:click="preparar('evidencia')" class="btn-primario">Enviar evidencia de entrega</button>
        </div>
    @endif

    {{-- ===== Revisar el correo antes de mandarlo ===== --}}
    @if ($preparando)
        <div class="fixed inset-0 z-[60] flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="titulo-reglas-{{ $pedidoId }}">
            <form data-confirmar-envio x-data x-on:submit.prevent="if (window.confirm('¿Seguro que quieres enviar el correo a ' + ($wire.para || '(sin destinatario)') + '?\n\nRevisa que los correos, el asunto y el mensaje estén completos.')) $wire.enviar()" class="max-h-full w-full max-w-2xl space-y-4 overflow-y-auto rounded-t-xl bg-white p-5 shadow-xl sm:rounded-xl">
                <h2 id="titulo-reglas-{{ $pedidoId }}" class="text-lg font-semibold text-marino">{{ $preparando === 'evidencia' ? 'Enviar evidencia de entrega' : 'Enviar factura a la plaza' }}</h2>
                @if ($cuenta)
                    <p class="text-sm text-slate-600">Desde <strong>{{ $cuenta }}</strong> · copia a ti</p>
                @else
                    <p class="text-sm text-amber-800">Tu usuario no tiene cuenta de correo de salida.</p>
                @endif
                @if ($errorEnvio)
                    <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $errorEnvio }}</div>
                @endif
                <div>
                    <label class="etiqueta" for="r-para-{{ $pedidoId }}">Para</label>
                    <input id="r-para-{{ $pedidoId }}" type="text" wire:model="para" class="campo" autocomplete="off">
                    @error('para') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="r-asunto-{{ $pedidoId }}">Asunto</label>
                    <input id="r-asunto-{{ $pedidoId }}" type="text" wire:model="asunto" class="campo">
                    @error('asunto') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="r-msg-{{ $pedidoId }}">Mensaje</label>
                    <textarea id="r-msg-{{ $pedidoId }}" rows="7" wire:model="mensaje" class="campo"></textarea>
                    @error('mensaje') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" class="btn-primario" wire:loading.attr="disabled" wire:target="enviar">
                        <span wire:loading.remove wire:target="enviar">Enviar</span>
                        <span wire:loading wire:target="enviar">Enviando…</span>
                    </button>
                    <button type="button" wire:click="cancelar" class="btn-secundario">Cancelar</button>
                </div>
            </form>
        </div>
    @endif
</div>

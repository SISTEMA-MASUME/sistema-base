<div>
    @if ($aviso)
        <div role="status" class="mb-3 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $aviso }}</div>
    @endif

    @if ($errorEnvio && ! $abierto)
        <div role="alert" class="mb-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $errorEnvio }}</div>
    @endif

    @if ($cuenta)
        <button type="button" wire:click="abrir" @if ($avisoRevision) wire:confirm="{{ $avisoRevision }}" @endif class="btn-primario min-h-14 w-full">✉️ Enviar por correo</button>
    @else
        <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Tu usuario no tiene cuenta de correo de salida.
        </div>
    @endif

    @if ($abierto)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="titulo-enviar">
            <form data-confirmar-envio x-data x-on:submit.prevent="if (window.confirm('¿Seguro que quieres enviar el correo a ' + ($wire.para || '(sin destinatario)') + '?\n\nRevisa que los correos, el asunto y el mensaje estén completos.')) $wire.enviar()" class="max-h-full w-full max-w-2xl space-y-4 overflow-y-auto rounded-t-xl bg-white p-5 shadow-xl sm:rounded-xl">
                <h2 id="titulo-enviar" class="text-lg font-semibold text-marino">Enviar cotización por correo</h2>
                <p class="text-sm text-slate-600">Desde <strong>{{ $cuenta }}</strong> · PDF adjunto</p>

                @if ($errorEnvio)
                    <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $errorEnvio }}</div>
                @endif

                <div>
                    <label class="etiqueta" for="c-para">Para (varios correos separados por coma)</label>
                    <input id="c-para" type="text" wire:model="para" class="campo" autocomplete="off">
                    @error('para') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="c-cc">CC</label>
                    <input id="c-cc" type="text" wire:model="cc" class="campo" autocomplete="off">
                    @error('cc') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="c-asunto">Asunto</label>
                    <input id="c-asunto" type="text" wire:model="asunto" class="campo">
                    @error('asunto') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="c-mensaje">Mensaje</label>
                    <textarea id="c-mensaje" rows="8" wire:model="mensaje" class="campo"></textarea>
                    @error('mensaje') <p class="error-campo">{{ $message }}</p> @enderror
                </div>

                @if ($puedeNed)
                    {{-- Manda en el mismo correo el PDF de MASUME tal cual y el de Ned Tracking con 60% de utilidad sobre el costo. --}}
                    <label class="flex min-h-12 items-center gap-3 rounded-lg border border-slate-300 p-3 text-base font-semibold text-marino">
                        <input type="checkbox" wire:model="conNed" class="h-6 w-6 rounded border-slate-300">
                        Adjuntar también el PDF de Ned Tracking
                    </label>
                @endif

                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" class="btn-primario" wire:loading.attr="disabled" wire:target="enviar">
                        <span wire:loading.remove wire:target="enviar">Enviar</span>
                        <span wire:loading wire:target="enviar">Enviando…</span>
                    </button>
                    <button type="button" wire:click="cerrar" class="btn-secundario">Cancelar</button>
                </div>
            </form>
        </div>
    @endif

</div>

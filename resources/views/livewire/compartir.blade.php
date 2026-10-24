@php
    $iconos = ['correo' => '✉️', 'whatsapp' => '🟢', 'descarga' => '⬇️', 'enlace' => '🔗'];
    $esCotizacion = $tipo === 'cotizacion';
@endphp
<div class="mb-4 rounded-xl bg-white p-4 shadow-sm sm:p-5" id="compartir-documento"
     x-data="compartirDocumento({ telefono: @js($telefono), texto: @js($textoWhatsapp), enlace: @js($enlace), urlPdf: @js($urlPdf), nombrePdf: @js($nombrePdf), esImagen: @js($esImagen) })"
     wire:key="compartir-{{ $tipo }}-{{ $documentoId }}">
    <h2 class="mb-3 text-lg font-semibold text-marino">Compartir {{ $esCotizacion ? 'cotización' : 'orden de compra' }}</h2>

    @if ($aviso)
        <div role="status" class="mb-3 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $aviso }}</div>
    @endif
    @if ($errorEnvio && ! $correoAbierto)
        <div role="alert" class="mb-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $errorEnvio }}</div>
    @endif

    <div class="grid gap-2 sm:grid-cols-3">
        <button type="button" x-on:click="if (whatsapp || !@js($avisoRevision) || window.confirm(@js($avisoRevision))) whatsapp = !whatsapp" class="btn min-h-14 bg-[#25d366] text-white hover:bg-[#1ebe5b]">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 00-8.6 15.1L2 22l5-1.3A10 10 0 1012 2zm0 18.2a8.2 8.2 0 01-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1112 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 01-3.3-2.9c-.3-.4.3-.4.7-1.3a.5.5 0 000-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 00-.7.3 3 3 0 00-.9 2.2 5.2 5.2 0 001.1 2.7 11.8 11.8 0 004.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 001.8-1.2 2.2 2.2 0 00.1-1.2c0-.1-.2-.2-.4-.3z"/></svg>
            WhatsApp
        </button>

        @if ($esCotizacion)
            <livewire:cotizaciones.enviar-correo :cotizacion="$doc" :key="'enviar-correo-'.$doc->id" />
        @elseif ($cuenta)
            <button type="button" wire:click="abrirCorreo" class="btn-primario min-h-14 w-full">✉️ Enviar por correo</button>
        @else
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                Tu usuario no tiene cuenta de correo de salida.
            </div>
        @endif

        <a href="{{ $urlDescarga }}" x-on:click="if (@js($avisoRevision) && !window.confirm(@js($avisoRevision))) { $event.preventDefault(); $event.stopImmediatePropagation(); } else { setTimeout(() => $wire.descargado(), 1500) }" class="btn-secundario min-h-14">⬇️ Descargar PDF</a>
    </div>

    {{-- ===== Panel de WhatsApp ===== --}}
    <div x-show="whatsapp" x-cloak x-transition class="mt-4 space-y-3 rounded-lg border border-green-300 bg-green-50 p-3">
        <div>
            <label class="etiqueta" for="wa-tel-{{ $documentoId }}">Número de WhatsApp (10 dígitos)</label>
            <input id="wa-tel-{{ $documentoId }}" type="tel" inputmode="tel" x-model="telefono" class="campo" placeholder="33 1234 5678" autocomplete="off">
            @if (count($otrosContactos) > 1)
                <div class="mt-2 flex flex-wrap gap-2" aria-label="Elegir contacto">
                    @foreach ($otrosContactos as $oc)
                        <button type="button" x-on:click="telefono = '+{{ $oc['numero'] }}'" class="rounded-full border border-green-400 bg-white px-3 py-1 text-sm">{{ $oc['nombre'] }}</button>
                    @endforeach
                </div>
            @endif
        </div>
        <div>
            <label class="etiqueta" for="wa-texto-{{ $documentoId }}">Mensaje</label>
            <textarea id="wa-texto-{{ $documentoId }}" rows="6" x-model="texto" class="campo"></textarea>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
            {{-- Celular: se comparte el archivo directo. Computadora: se descarga y se abre WhatsApp para adjuntarlo. --}}
            <button type="button" x-show="puedeArchivo" x-cloak x-on:click="compartirArchivo()" class="btn min-h-12 bg-[#25d366] text-white hover:bg-[#1ebe5b]">{{ $esImagen ? 'Mandar la imagen por WhatsApp' : 'Mandar el PDF por WhatsApp' }}</button>
            @if ($esImagen)
                {{-- Computadora: un clic copia la imagen y abre el chat con el mensaje; solo se pega con Ctrl+V. --}}
                <button type="button" x-show="!puedeArchivo && puedeCopiarImagen" x-on:click="copiarImagenYAbrir()" class="btn min-h-12 bg-[#25d366] text-white hover:bg-[#1ebe5b]">Copiar imagen y abrir WhatsApp</button>
                <button type="button" x-show="!puedeArchivo && !puedeCopiarImagen" x-on:click="descargarYAbrir()" class="btn min-h-12 bg-[#25d366] text-white hover:bg-[#1ebe5b]">Descargar imagen y abrir WhatsApp</button>
                <button type="button" x-show="!puedeArchivo && puedeCopiarImagen" x-cloak x-on:click="descargarYAbrir()" class="btn-secundario">Mejor descargarla</button>
            @else
                <button type="button" x-show="!puedeArchivo" x-on:click="descargarYAbrir()" class="btn min-h-12 bg-[#25d366] text-white hover:bg-[#1ebe5b]">Descargar PDF y abrir WhatsApp</button>
            @endif
            <button type="button" x-on:click="copiar()" class="btn-secundario">Copiar mensaje</button>
        </div>
        <p x-show="pdfDescargado" x-cloak class="rounded-lg border border-green-400 bg-white px-3 py-2 text-sm text-green-900" role="status">
            ✓ Descargado: adjunta <strong x-text="nombrePdf"></strong> en el chat (📎 → {{ $esImagen ? 'Fotos y videos' : 'Documento' }})
        </p>
        <p x-show="imagenCopiada" x-cloak class="rounded-lg border border-green-400 bg-white px-3 py-2 text-base text-green-900" role="status">
            ✓ Imagen copiada: pégala en el chat con <strong>Ctrl + V</strong>
        </p>
        <p x-show="copiado" x-cloak class="text-sm text-green-800" role="status">Mensaje copiado.</p>
        <button type="button" x-on:click="abrirWhatsapp(true)" class="text-sm text-slate-600 underline">Mandar enlace en lugar del PDF</button>
    </div>

    {{-- ===== Correo de OC a proveedor ===== --}}
    @if ($correoAbierto)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="titulo-correo-oc">
            <form data-confirmar-envio x-data x-on:submit.prevent="if (window.confirm('¿Seguro que quieres enviar el correo a ' + ($wire.para || '(sin destinatario)') + '?\n\nRevisa que los correos, el asunto y el mensaje estén completos.')) $wire.enviarCorreo()" class="max-h-full w-full max-w-2xl space-y-4 overflow-y-auto rounded-t-xl bg-white p-5 shadow-xl sm:rounded-xl">
                <h2 id="titulo-correo-oc" class="text-lg font-semibold text-marino">Enviar orden de compra por correo</h2>
                <p class="text-sm text-slate-600">Desde <strong>{{ $cuenta }}</strong> · PDF adjunto</p>
                @if ($errorEnvio)
                    <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $errorEnvio }}</div>
                @endif
                <div>
                    <label class="etiqueta" for="oc-para">Para (varios correos separados por coma)</label>
                    <input id="oc-para" type="text" wire:model="para" class="campo" autocomplete="off">
                    @error('para') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="oc-cc">CC</label>
                    <input id="oc-cc" type="text" wire:model="cc" class="campo" autocomplete="off">
                    @error('cc') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="oc-asunto">Asunto</label>
                    <input id="oc-asunto" type="text" wire:model="asunto" class="campo">
                    @error('asunto') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="oc-mensaje">Mensaje</label>
                    <textarea id="oc-mensaje" rows="8" wire:model="mensaje" class="campo"></textarea>
                    @error('mensaje') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" class="btn-primario" wire:loading.attr="disabled" wire:target="enviarCorreo">
                        <span wire:loading.remove wire:target="enviarCorreo">Enviar</span>
                        <span wire:loading wire:target="enviarCorreo">Enviando…</span>
                    </button>
                    <button type="button" wire:click="cerrarCorreo" class="btn-secundario">Cancelar</button>
                </div>
            </form>
        </div>
    @endif

    {{-- ===== Historial de envíos (correo, WhatsApp, descarga y enlace) ===== --}}
    @if ($historial->isNotEmpty())
        <div class="mt-4 border-t border-slate-200 pt-3">
            <h3 class="mb-2 text-base font-semibold text-marino">Historial de envíos</h3>
            <ul class="divide-y divide-slate-200">
                @foreach ($historial as $h)
                    <li class="py-2 text-sm" wire:key="envio-{{ $h['clave'] }}">
                        <p>
                            <span aria-hidden="true">{{ $iconos[$h['canal']] ?? '•' }}</span>
                            <span class="font-semibold {{ $h['exito'] ? 'text-slate-800' : 'text-red-700' }}">{{ $h['exito'] ? ($canales[$h['canal']] ?? $h['canal']) : 'Falló el envío por '.$h['canal'] }}</span>
                            · {{ $h['fecha']?->timezone('America/Mexico_City')->format('d/m/Y H:i') }} · {{ $h['usuario'] }}@if ($h['revision'] > 0) · Rev. {{ $h['revision'] }}@endif
                        </p>
                        @if ($h['destinatario'])
                            <p class="text-slate-600">{{ $h['canal'] === 'correo' ? 'Para: ' : '' }}{{ $h['destinatario'] }}</p>
                        @endif
                        @unless ($h['exito'])
                            <p class="text-red-700">{{ $h['error'] }}</p>
                        @endunless
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

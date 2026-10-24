@php $dinero = fn ($v) => '$'.number_format((float) $v, 2); @endphp
<div class="space-y-4">
    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <a href="{{ route('ned.index') }}" class="btn-secundario">← Mis cotizaciones</a>
        <button type="button" wire:click="guardar" class="btn-primario">Guardar</button>
        <button type="button" class="btn-secundario"
                x-on:click="if (matchMedia('(pointer: coarse)').matches || matchMedia('(display-mode: standalone)').matches) { $wire.guardar().then(() => abrirPdf(@js(route('ned.pdf', $c)), @js('Ned '.$c->folioTexto()))) } else { const w = window.open('', '_blank'); $wire.guardar().then(() => { w.location = @js(route('ned.pdf', $c)) }) }">Guardar y ver PDF</button>
        <button type="button" class="btn-secundario"
                x-on:click="$wire.guardar().then(() => { window.location = @js(route('ned.pdf', ['cotizacion' => $c, 'descargar' => 1])) })">Guardar y descargar PDF</button>
        <button type="button" class="btn min-h-12 bg-[#25d366] text-white hover:bg-[#1ebe5b]"
                x-data="{ async compartir() {
                    await $wire.guardar();
                    const r = await fetch(@js(route('ned.pdf', $c)), { credentials: 'same-origin' });
                    const f = new File([await r.blob()], @js($c->nombreArchivoPdf()), { type: 'application/pdf' });
                    if (navigator.canShare && navigator.canShare({ files: [f] })) { try { await navigator.share({ files: [f] }); } catch (e) {} }
                    else { alert('En la computadora: descarga el PDF y adjúntalo en WhatsApp o en tu correo.'); }
                } }" x-on:click="compartir()">Mandar PDF (WhatsApp)</button>
    </div>

    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    <section class="rounded-xl bg-white p-5 shadow-sm">
        <h2 class="mb-3 text-xl font-bold text-marino">Cotización {{ $c->folioTexto() }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="etiqueta" for="n-cliente">Cliente</label>
                <input id="n-cliente" type="text" wire:model="cliente_nombre" class="campo uppercase" autocomplete="off">
                @error('cliente_nombre') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="etiqueta" for="n-dir">Dirección del cliente</label>
                <textarea id="n-dir" rows="2" wire:model="cliente_direccion" class="campo"></textarea>
            </div>
            <div>
                <label class="etiqueta" for="n-contacto">Atención a</label>
                <input id="n-contacto" type="text" wire:model="cliente_contacto" class="campo" autocomplete="off">
                @if (count($contactos) > 1)
                    <div class="mt-2 flex flex-wrap gap-2" aria-label="Elegir contacto">
                        @foreach ($contactos as $nombreContacto)
                            <button type="button" wire:click="$set('cliente_contacto', @js($nombreContacto))"
                                    class="rounded-full border px-3 py-1 text-sm {{ mb_strtoupper($cliente_contacto) === mb_strtoupper($nombreContacto) ? 'border-marino bg-marino text-white' : 'border-slate-300 bg-white' }}">{{ $nombreContacto }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
            <div>
                <label class="etiqueta" for="n-correo">Correo</label>
                <input id="n-correo" type="email" wire:model="cliente_correo" class="campo" autocomplete="off">
                @error('cliente_correo') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="etiqueta" for="n-folio">Número de cotización</label>
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-marino">NT-</span>
                    <input id="n-folio" type="number" min="1" step="1" inputmode="numeric" wire:model="folio" class="campo">
                </div>
                @error('folio') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="etiqueta" for="n-fecha">Fecha</label>
                <input id="n-fecha" type="date" wire:model="fecha" class="campo">
            </div>
            <div>
                <label class="etiqueta" for="n-vig">Vigencia (días)</label>
                <input id="n-vig" type="number" min="1" wire:model="vigencia_dias" class="campo">
                @error('vigencia_dias') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
        </div>
    </section>

    <section class="rounded-xl bg-white p-5 shadow-sm">
        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="text-lg font-semibold text-marino">Partidas</h2>
            <button type="button" wire:click="$toggle('pegando')" class="btn-secundario">📋 Pegar lista que me mandaron</button>
        </div>

        @if ($pegando)
            <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3">
                <label class="etiqueta" for="n-lista">Lista (un renglón por partida)</label>
                <textarea id="n-lista" rows="6" wire:model="lista" class="campo" placeholder="1) 20 PZA | CUCHILLAS SK4 18 MM PARA CUTTER TRUPER (10PZ)"></textarea>
                @error('lista') <p class="error-campo">{{ $message }}</p> @enderror
                <button type="button" wire:click="pegarLista" class="btn-primario mt-2">Agregar partidas</button>
            </div>
        @endif

        @if ($verCostos)
            {{-- Solo admin: precio = costo × (1 + utilidad). El costo viene de la cotización de MASUME y nunca sale en el PDF. --}}
            <div class="mb-4 flex flex-col gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 sm:flex-row sm:items-end">
                <div>
                    <label class="etiqueta" for="n-utilidad">Utilidad para todas las partidas (%)</label>
                    <input id="n-utilidad" type="text" inputmode="decimal" wire:model="utilidad" class="campo sm:max-w-32" placeholder="40" autocomplete="off">
                </div>
                <button type="button" wire:click="aplicarUtilidad" class="btn-primario">Aplicar a todas</button>
            </div>
            @error('utilidad') <p class="error-campo -mt-2 mb-3">{{ $message }}</p> @enderror
        @endif

        <div class="space-y-3">
            @foreach ($partidas as $i => $p)
                <div class="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-12" wire:key="np-{{ $i }}-{{ count($partidas) }}">
                    <div class="{{ $verCostos ? 'sm:col-span-4' : 'sm:col-span-6' }}">
                        <label class="etiqueta">{{ $i + 1 }}. Descripción</label>
                        <textarea rows="2" wire:model="partidas.{{ $i }}.descripcion" class="campo uppercase" autocomplete="off"></textarea>
                    </div>
                    <div class="sm:col-span-1">
                        <label class="etiqueta">Cant.</label>
                        <input type="text" inputmode="decimal" wire:model="partidas.{{ $i }}.cantidad" class="campo" autocomplete="off">
                    </div>
                    <div class="sm:col-span-1">
                        <label class="etiqueta">Unidad</label>
                        <input type="text" wire:model="partidas.{{ $i }}.unidad" class="campo uppercase" autocomplete="off">
                    </div>
                    @if ($verCostos)
                        <div class="sm:col-span-2">
                            <label class="etiqueta">Costo s/IVA <span class="font-normal text-amber-700">(interno)</span></label>
                            <input type="text" inputmode="decimal" wire:model="partidas.{{ $i }}.costo" class="campo bg-amber-50" autocomplete="off">
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <label class="etiqueta">Precio unitario (sin IVA)</label>
                        <input type="text" inputmode="decimal" wire:model="partidas.{{ $i }}.precio" class="campo" autocomplete="off">
                    </div>
                    <div class="flex flex-col justify-between sm:col-span-2">
                        <p class="text-sm text-slate-600">Importe <strong>{{ $dinero($importes[$i] ?? 0) }}</strong></p>
                        <div class="flex gap-1">
                            <button type="button" wire:click="mover({{ $i }}, -1)" @disabled($i === 0) class="btn-secundario !min-h-9 !px-3 !py-1" aria-label="Subir">↑</button>
                            <button type="button" wire:click="mover({{ $i }}, 1)" @disabled($loop->last) class="btn-secundario !min-h-9 !px-3 !py-1" aria-label="Bajar">↓</button>
                            <button type="button" wire:click="quitar({{ $i }})" class="btn-secundario !min-h-9 !px-3 !py-1 !text-red-600" aria-label="Quitar">✕</button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <button type="button" wire:click="agregar" class="btn-secundario mt-3">+ Agregar partida</button>

        <div class="mt-4 grid gap-4 border-t border-slate-200 pt-4 sm:grid-cols-2">
            <div>
                <label class="etiqueta" for="n-notas">Notas (salen en el PDF)</label>
                <textarea id="n-notas" rows="3" wire:model="notas" class="campo" placeholder="Tiempo de entrega, forma de pago…"></textarea>
            </div>
            <dl class="ml-auto w-full max-w-xs space-y-1 text-base">
                <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ $dinero($totales['subtotal']) }}</dd></div>
                <div class="flex justify-between"><dt>IVA 16%</dt><dd>{{ $dinero($totales['iva']) }}</dd></div>
                <div class="flex justify-between border-t border-slate-200 pt-1 text-lg font-bold text-marino"><dt>Total</dt><dd>{{ $dinero($totales['total']) }}</dd></div>
                <p class="text-xs text-slate-500">Los totales se actualizan al guardar.</p>
            </dl>
        </div>
    </section>

    <div class="flex justify-between">
        <button type="button" wire:click="guardar" class="btn-primario">Guardar</button>
        <button type="button" wire:click="borrar" wire:confirm="¿Borrar esta cotización?" class="btn-secundario !text-red-600">Borrar cotización</button>
    </div>
</div>

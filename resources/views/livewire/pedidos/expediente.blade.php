@php
    $cuando = fn ($a) => ($a->user->name ?? '—').' · '.$a->created_at->format('d/m/Y H:i');
@endphp
<div class="space-y-6">
    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif
    @if ($error)
        <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
    @endif

    {{-- ===== OC del cliente ===== --}}
    {{-- Colores fijos para no confundir dónde va cada archivo: azul = OC del cliente, verde = factura, morado = evidencia. --}}
    <section class="rounded-xl border-l-4 border-blue-500 bg-blue-50/60 p-3" data-seccion="oc">
        <h3 class="mb-2 flex flex-wrap items-center gap-2 text-base font-semibold text-blue-900">📋 OC del cliente</h3>
        @if ($tieneOc)
            <div class="flex flex-col gap-2 rounded-lg border border-slate-200 bg-white p-3 sm:flex-row sm:items-center">
                @if ($ocEsImagen)
                    <img src="{{ $pedido->urlOc() }}" alt="OC del cliente" loading="lazy" class="h-16 w-16 rounded object-cover">
                @else
                    <span class="flex h-16 w-16 items-center justify-center rounded bg-slate-100 text-2xl" aria-hidden="true">📄</span>
                @endif
                <div class="min-w-0 flex-1">
                    <p class="font-medium">OC {{ $pedido->numero_oc_cliente ?: 'P-'.$pedido->folio }}</p>
                    <p class="text-sm text-slate-600">Archivo que mandó el cliente</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ $pedido->urlOc() }}" target="_blank" class="btn-secundario">Ver</a>
                    <a href="{{ $pedido->urlOc(true) }}" class="btn-secundario">Descargar</a>
                    @unless ($esCampo)
                        <label class="btn-secundario cursor-pointer">
                            <span wire:loading.remove wire:target="nuevaOc">Reemplazar</span>
                            <span wire:loading wire:target="nuevaOc">Subiendo…</span>
                            <input type="file" wire:model="nuevaOc" accept="application/pdf,image/jpeg,image/png,image/webp" class="hidden">
                        </label>
                        <button type="button" wire:click="quitarOc" wire:confirm="¿Quitar el archivo de la OC del cliente? El número de OC se queda." class="btn bg-red-50 text-red-700 hover:bg-red-100">Quitar</button>
                    @endunless
                </div>
            </div>
        @else
            <p class="text-sm text-slate-600">Sin archivo</p>
            @unless ($esCampo)
                <label class="btn-secundario mt-2 inline-flex cursor-pointer">
                    <span wire:loading.remove wire:target="nuevaOc">Subir la OC del cliente</span>
                    <span wire:loading wire:target="nuevaOc">Subiendo…</span>
                    <input type="file" wire:model="nuevaOc" accept="application/pdf,image/jpeg,image/png,image/webp" class="hidden">
                </label>
            @endunless
        @endif
    </section>

    {{-- ===== Factura (campo también la ve y la abre: decisión de Daniel; no sube ni borra) ===== --}}
    <section class="rounded-xl border-l-4 border-green-600 bg-green-50/60 p-3" data-seccion="factura">
        <h3 class="mb-2 flex flex-wrap items-center gap-2 text-base font-semibold text-green-900">🧾 Facturas y notas</h3>
        @php
            $coloresVenta = ['pendiente' => 'bg-amber-100 text-amber-800', 'pagada' => 'bg-green-100 text-green-800', 'cancelada' => 'bg-red-100 text-red-700'];
        @endphp
        {{-- Una tarjeta por factura/nota: lo de SICAR (folio, monto, cobro) y abajo sus archivos (PDF y XML). --}}
        <ul class="space-y-2">
            @forelse ($gruposFacturas as $g)
                @php $v = $g['venta']; @endphp
                <li wire:key="grupo-{{ $v?->id ?? 'a'.$g['archivos']->first()?->id }}" class="rounded-lg border border-green-200 bg-white p-3" data-factura>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            @if ($v)
                                <a href="{{ route('finanzas.ventas', ['buscar' => $v->folio, 'anio' => 'todos']) }}" class="font-semibold text-marino hover:underline {{ $v->estatus === 'cancelada' ? 'line-through' : '' }}">{{ $v->tipo === 'nota' ? 'Nota' : 'Factura' }} {{ $v->folio }}</a>
                                <span class="text-sm text-slate-600">· {{ $v->fecha->format('d/m/Y') }} · ${{ number_format((float) $v->total, 2) }}</span>
                            @else
                                <p class="font-semibold text-slate-800">{{ $g['folio'] ? 'Factura '.$g['folio'] : 'Archivo de factura' }}</p>
                                @if ($verFacturas)
                                    <p class="text-sm text-slate-600">{{ $g['folio'] ? '⏳ Esperando que llegue de SICAR; se liga sola.' : 'No se leyó el folio del archivo. Si es factura, agrégala abajo con su folio.' }}</p>
                                @endif
                            @endif
                        </div>
                        @if ($v)
                            <div class="flex items-center gap-2">
                                <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $coloresVenta[$v->estatus] ?? 'bg-slate-100 text-slate-700' }}">{{ $v->estatus === 'pendiente' ? 'Pendiente de cobro' : (\App\Models\Venta::ESTATUS[$v->estatus] ?? $v->estatus) }}</span>
                                <button type="button" wire:click="quitarVenta({{ $v->id }})" wire:confirm="¿Quitar {{ $v->folio }} de este pedido? Los archivos se quedan." class="btn-secundario !min-h-9 !px-3 !py-1 text-sm">Desligar</button>
                            </div>
                        @endif
                    </div>
                    @foreach ($g['archivos'] as $a)
                        <div wire:key="fac-{{ $a->id }}" class="mt-2 flex flex-col gap-2 border-t border-slate-100 pt-2 sm:flex-row sm:items-center">
                            <span class="text-lg" aria-hidden="true">{{ $a->tipo === 'factura_xml' ? '🗎' : '📄' }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ $a->tipo === 'factura_xml' ? 'XML' : 'PDF' }} · {{ $a->nombre_original }}</p>
                                <p class="text-xs text-slate-500">{{ $a->tamanoTexto() }} · {{ $cuando($a) }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('pedidos.archivo', [$pedido, $a]) }}" target="_blank" class="btn-secundario !min-h-9 !py-1 text-sm">Ver</a>
                                <a href="{{ route('pedidos.archivo', ['pedido' => $pedido, 'archivo' => $a, 'descargar' => 1]) }}" class="btn-secundario !min-h-9 !py-1 text-sm">Descargar</a>
                                @if ($puedeBorrar($a))
                                    <button type="button" wire:click="borrar({{ $a->id }})" wire:confirm="¿Borrar este archivo de factura?" class="btn-secundario !min-h-9 !py-1 text-sm !text-red-600">Borrar</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                    @if ($g['falta'])
                        <p class="mt-2 text-sm text-amber-800">Falta subir {{ implode(' y ', $g['falta']) }}.</p>
                    @endif
                </li>
            @empty
                <li class="text-sm text-slate-600">Sin facturas ni notas</li>
            @endforelse
        </ul>
        <x-costo-sicar-pedido :c="$costoSicar" class="mt-2" />

        @if ($ventasPorConfirmar->isNotEmpty())
            <ul class="mt-3 space-y-2">
                @foreach ($ventasPorConfirmar as $v)
                    <li class="rounded-lg border border-dashed border-slate-300 bg-slate-100 px-3 py-3 text-slate-600" wire:key="por-confirmar-{{ $v->id }}">
                        <p class="text-sm"><span class="rounded bg-slate-300 px-1.5 py-0.5 text-xs font-semibold text-slate-700">Por confirmar</span>
                            La {{ $v->tipo === 'nota' ? 'nota' : 'factura' }} <strong>{{ $v->folio }}</strong> sale a nombre de <strong>{{ $v->cliente_nombre }}</strong> y trae la OC {{ $pedido->numero_oc_cliente }} de este pedido.</p>
                        <p class="mt-1 text-sm font-semibold text-slate-700">¿{{ $v->cliente_nombre }} factura por {{ $pedido->cliente?->nombre ?? 'este cliente' }}?</p>
                        <div class="mt-2 flex gap-2">
                            <button type="button" wire:click="confirmarLiga({{ $v->id }})" class="btn-primario !min-h-9 flex-1 !px-3 !py-1 text-sm sm:flex-none">Sí</button>
                            <button type="button" wire:click="rechazarLiga({{ $v->id }})" class="btn-secundario !min-h-9 flex-1 !px-3 !py-1 text-sm sm:flex-none">No</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @unless ($esCampo)
            <div x-data="{ subiendo: false, activo: false, aviso: '',
                    subir(files) {
                        files = [...(files || [])].filter(f => /\.(pdf|xml)$/i.test(f.name));
                        if (!files.length) { this.aviso = 'Solo PDF o XML.'; setTimeout(() => this.aviso = '', 5000); return; }
                        this.aviso = ''; this.subiendo = true;
                        $wire.uploadMultiple('facturas', files, () => this.subiendo = false, () => { this.subiendo = false; this.aviso = 'No se pudo subir.'; }, () => {});
                    } }"
                 tabindex="0" role="button" aria-label="Arrastra aquí la factura (PDF o XML), pega con Ctrl+V o haz clic para elegir"
                 x-on:click="$refs.elegirFactura.click()"
                 x-on:paste.prevent="subir([...($event.clipboardData?.items || [])].filter(t => t.kind === 'file').map(t => t.getAsFile()))"
                 x-on:dragover.prevent="activo = true" x-on:dragleave="activo = false"
                 x-on:drop.prevent="activo = false; subir($event.dataTransfer?.files)"
                 x-bind:class="activo ? 'border-green-600 bg-green-100 ring-2 ring-green-300' : 'border-green-400 bg-white'"
                 class="mt-3 flex min-h-12 cursor-pointer items-center gap-2 rounded-lg border-2 border-dashed px-4 py-2 text-sm text-green-900 outline-none focus:border-green-600">
                <span x-show="subiendo">Subiendo…</span>
                <span x-show="!subiendo">🧾 Subir factura (PDF y XML)</span>
                <input x-ref="elegirFactura" type="file" multiple accept=".pdf,.xml,application/pdf,text/xml,application/xml" class="hidden" x-on:click.stop x-on:change="subir($event.target.files); $event.target.value = ''">
                <span x-show="aviso" x-text="aviso" class="text-red-600"></span>
            </div>
        @endunless
        @if ($verFacturas)
            {{-- Notas de venta (no llevan archivo) o una factura cuyo folio no se leyó: se ligan escribiendo el folio. --}}
            <form wire:submit="ligarVenta" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label class="etiqueta" for="folio-venta-{{ $pedido->id }}">Ligar por folio</label>
                    <input id="folio-venta-{{ $pedido->id }}" type="text" wire:model="folioVenta" maxlength="40" class="campo" placeholder="Ej. 5-3317 o NOTA-298" autocomplete="off">
                    @error('folioVenta') <p class="error-campo" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn-secundario" wire:loading.attr="disabled" wire:target="ligarVenta">Ligar</button>
            </form>
        @endif
    </section>

    {{-- ===== Evidencia de entrega ===== --}}
    <section class="rounded-xl border-l-4 border-purple-500 bg-purple-50/60 p-3" data-seccion="evidencia">
        <h3 class="mb-2 text-base font-semibold text-purple-900">📷 Evidencia de entrega ({{ $listaEvidencias->count() }})</h3>

        <div x-data="{ subiendo: false,
                subir(files) {
                    files = [...(files || [])];
                    if (!files.length) return;
                    this.subiendo = true;
                    $wire.uploadMultiple('fotos', files, () => this.subiendo = false, () => this.subiendo = false, () => {});
                } }" class="space-y-3">
            <div class="flex flex-col gap-2 sm:flex-row">
                <button type="button" x-on:click="$refs.camara.click()" class="btn-primario !min-h-14 flex-1 text-lg sm:flex-none">📷 Tomar foto de entrega</button>
                <button type="button" x-on:click="$refs.galeria.click()" class="btn-secundario !min-h-14 flex-1 sm:flex-none">Elegir fotos</button>
                <input x-ref="camara" type="file" accept="image/*" capture="environment" multiple class="hidden" x-on:change="subir($event.target.files); $event.target.value = ''">
                <input x-ref="galeria" type="file" accept="image/*" multiple class="hidden" x-on:change="subir($event.target.files); $event.target.value = ''">
            </div>
            <p x-show="subiendo" class="text-sm text-slate-700" role="status">Subiendo y reduciendo fotos…</p>

            <div>
                <label class="etiqueta" for="nota-foto">Nota para las próximas fotos (opcional)</label>
                <input id="nota-foto" type="text" wire:model="notaFoto" maxlength="500" class="campo" placeholder="Recibió: Juan, almacén">
            </div>
            @if ($partidas->count() > 1)
                <details class="rounded-lg border border-slate-200 p-3">
                    <summary class="cursor-pointer text-sm font-medium text-slate-700">¿A qué partidas corresponde? (opcional)</summary>
                    <div class="mt-2 grid gap-1 sm:grid-cols-2">
                        @foreach ($partidas as $p)
                            <label class="flex min-h-10 items-start gap-2 text-sm" wire:key="pf-{{ $p->id }}">
                                <input type="checkbox" value="{{ $p->id }}" wire:model="partidasFoto" class="mt-0.5 h-5 w-5 shrink-0 rounded border-slate-300">
                                <span>{{ $p->orden }}. {{ \Illuminate\Support\Str::limit($p->descripcion, 60) }}</span>
                            </label>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>

        @if ($listaEvidencias->isEmpty())
            <p class="mt-3 text-sm text-slate-600">Sin fotos</p>
        @else
            <ul class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($listaEvidencias as $a)
                    <li wire:key="ev-{{ $a->id }}" class="overflow-hidden rounded-lg border border-slate-200">
                        <a href="{{ route('pedidos.archivo', [$pedido, $a]) }}" target="_blank">
                            <img src="{{ route('pedidos.archivo', [$pedido, $a]) }}" alt="{{ $a->notas ?: 'Evidencia de entrega' }}" loading="lazy" class="h-32 w-full object-cover">
                        </a>
                        <div class="space-y-1 p-2 text-xs text-slate-600">
                            <p class="truncate font-medium text-slate-800">{{ $a->nombre_original }}</p>
                            <p>{{ $cuando($a) }}</p>
                            @if ($a->notas)<p class="text-slate-800">{{ $a->notas }}</p>@endif
                            @if ($a->partidas_ids)
                                <p>Partidas: {{ $partidas->whereIn('id', $a->partidas_ids)->pluck('orden')->implode(', ') }}</p>
                            @endif
                            <div class="flex flex-wrap gap-2 pt-1">
                                <a href="{{ route('pedidos.archivo', ['pedido' => $pedido, 'archivo' => $a, 'descargar' => 1]) }}" class="font-semibold text-marino underline">Descargar</a>
                                @if ($puedeBorrar($a))
                                    <button type="button" wire:click="borrar({{ $a->id }})" wire:confirm="¿Borrar esta foto?" class="font-semibold text-red-600 underline">Borrar</button>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>

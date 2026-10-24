<div>
    @if ($mensaje)
        <div role="status" class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    {{-- ===== Cliente ===== --}}
    @if ($mostrarBuscador)
        <div class="mb-6 space-y-4 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">{{ $cot ? 'Cambiar cliente' : '¿Para qué cliente es la cotización?' }}</h2>
            <div>
                <label class="etiqueta" for="buscar-cliente">Buscar cliente</label>
                <input id="buscar-cliente" type="search" wire:model.live.debounce.250ms="buscarCliente" class="campo" placeholder="Escribe parte del nombre" autocomplete="off">
                @if ($resultados->isNotEmpty())
                    <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200">
                        @foreach ($resultados as $r)
                            <li wire:key="cli-{{ $r->id }}">
                                <button type="button" wire:click="seleccionarCliente({{ $r->id }})" class="block min-h-12 w-full px-4 py-3 text-left text-base hover:bg-slate-50">{{ $r->nombre }}</button>
                            </li>
                        @endforeach
                    </ul>
                @elseif (trim($buscarCliente) !== '')
                    <p class="mt-2 text-sm text-slate-500">Sin resultados</p>
                @endif
            </div>
            <form wire:submit="altaCliente" class="border-t border-slate-200 pt-4">
                <label class="etiqueta" for="nuevo-cliente">Alta rápida de cliente nuevo</label>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <input id="nuevo-cliente" type="text" wire:model="nuevoCliente" class="campo" placeholder="Nombre del cliente">
                    <button type="submit" class="btn-secundario shrink-0">Crear y usar</button>
                </div>
                @error('nuevoCliente') <p class="error-campo">{{ $message }}</p> @enderror
            </form>
            @if ($cot)
                <button type="button" wire:click="cancelarCambioCliente" class="btn-secundario">Cancelar</button>
            @endif
        </div>
    @endif

    @if ($cot)
        {{-- Viene de la bandeja "Por cotizar" --}}
        @if ($cot->solicitud)
            <p class="mb-3 text-sm text-slate-600">
                Viene de la solicitud <a href="{{ route('solicitudes.ver', $cot->solicitud) }}" class="font-semibold text-marino underline">{{ $cot->solicitud->folioTexto() }}</a>
                · recibida {{ $cot->solicitud->recibida_at->format('d/m H:i') }}
                @foreach ($cot->solicitud->archivos as $a)
                    · <a href="{{ route('solicitudes.archivo', $a) }}" target="_blank" class="text-marino underline">{{ $a->nombre }}</a>
                @endforeach
            </p>
        @endif

        {{-- ===== Barra de acciones ===== --}}
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <a href="{{ route('cotizaciones.index') }}" class="btn-secundario">← Lista</a>
            {{-- Mientras se guarda un cambio, el PDF espera (si no, saldría con el precio anterior). --}}
            <a href="{{ route('cotizaciones.pdf', $cot) }}" target="_blank" class="btn-primario"
               x-data x-bind:class="$store.guardado.pendientes ? 'pointer-events-none opacity-60' : ''" x-bind:aria-disabled="$store.guardado.pendientes > 0"
               x-text="$store.guardado.pendientes ? 'Guardando…' : 'Ver PDF'">Ver PDF</a>
            <a href="{{ route('cotizaciones.pdf', ['cotizacion' => $cot, 'descargar' => 1]) }}" class="btn-secundario"
               x-data x-bind:class="$store.guardado.pendientes ? 'pointer-events-none opacity-60' : ''" x-bind:aria-disabled="$store.guardado.pendientes > 0">Descargar PDF</a>
            @if ($cot->pdf_original_url)
                <a href="{{ $cot->pdf_original_url }}" target="_blank" rel="noopener" class="btn-secundario" title="Importada del REGISTRO de Google Sheets">PDF original (Drive)</a>
            @endif
            <button type="button" wire:click="duplicarCotizacion" wire:confirm="¿Duplicar esta cotización con un folio nuevo?" class="btn-secundario">Duplicar cotización</button>
            <a href="{{ url('/pedidos/nuevo?cotizacion='.$cot->id) }}" class="btn-secundario">Crear pedido (llegó OC)</a>
            {{-- Copia solo la lista de partidas (sin costos ni precios) para pegarla en Ned Tracking u otro proveedor. --}}
            <button type="button" class="btn-secundario"
                    x-data="{ copiado: false }"
                    x-on:click="const t = @js($listaSinPrecios);
                        navigator.clipboard.writeText(t).then(() => { copiado = true; setTimeout(() => copiado = false, 3000) })
                            .catch(() => window.prompt('Copia la lista:', t))"
                    x-text="copiado ? '✓ Lista copiada' : 'Copiar lista sin precios'">Copiar lista sin precios</button>
            @if (auth()->user()->esAdmin())
                <button type="button" wire:click="eliminarCotizacion" wire:confirm="¿Eliminar la cotización {{ $cot->folio }}? Ya no aparecerá en el sistema."
                        class="btn bg-red-50 text-red-700 hover:bg-red-100">Eliminar cotización</button>
            @endif
            @if (auth()->user()->nedDesdeMasume())
                <button type="button" wire:click="pasarANed" class="btn-secundario"
                        wire:confirm="Se crea una cotización de Ned Tracking con estas partidas (descripción, cantidad, unidad y costo), SIN cliente y SIN precios. ¿Continuar?">Pasar a Ned Tracking</button>
            @endif
        </div>

        <livewire:cotizaciones.revision :cotizacion-id="$cot->id" :key="'revision-'.$cot->id" />

        <div x-data x-bind:class="$store.guardado.pendientes ? 'pointer-events-none opacity-60' : ''" title="Espera a que termine de guardarse el último cambio">
            <livewire:compartir tipo="cotizacion" :documento-id="$cot->id" :key="'compartir-cot-'.$cot->id" />
        </div>

        {{-- ===== Historial de cambios ===== --}}
        <div x-data="{ abierto: false }" class="my-4 rounded-xl bg-white p-5 shadow-sm">
            <button type="button" @click="abierto = !abierto" class="flex min-h-12 w-full items-center justify-between text-left text-lg font-semibold text-marino">
                <span>Historial de cambios ({{ $totalCambios }})</span>
                <span x-text="abierto ? '−' : '+'" class="text-2xl"></span>
            </button>
            <div x-show="abierto" x-cloak class="mt-2">
                @if ($cambios->isEmpty())
                    <p class="text-sm text-slate-500">Sin cambios</p>
                @else
                    <ul class="divide-y divide-slate-200">
                        @foreach ($cambios as $ch)
                            <li class="py-2 text-sm text-slate-700" wire:key="cambio-{{ $ch->id }}">
                                <span class="text-slate-500">{{ $ch->created_at->format('d/m/Y H:i') }} · {{ $ch->user?->name ?? '—' }} ·</span>
                                <span class="{{ $ch->accion === 'revision' ? 'font-semibold text-naranja' : '' }}">{{ $ch->texto() }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($totalCambios > $cambios->count())
                        <button type="button" wire:click="verMasHistorial" class="btn-secundario mt-3">Ver más</button>
                    @endif
                @endif
            </div>
        </div>

        {{-- ===== Encabezado ===== --}}
        <div class="mb-6 rounded-xl bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-2xl font-bold text-marino">Cotización #{{ $cot->folio }}@if ($cot->revision > 0) <span class="ml-1 rounded-full bg-naranja px-2 py-0.5 align-middle text-sm font-semibold text-white">Rev. {{ $cot->revision }}</span>@endif</p>
                    <p class="text-base text-slate-700">{{ $cot->cliente->nombre }}
                        <button type="button" wire:click="cambiarCliente" class="ml-2 text-sm font-medium text-marino underline">cambiar</button>
                    </p>
                </div>
                <div class="w-full sm:w-56">
                    <label class="etiqueta" for="e-estado">Estado</label>
                    <select id="e-estado" wire:model.blur="enc.estado" class="campo">
                        @foreach ($estados as $e)
                            <option value="{{ $e->value }}">{{ $e->etiqueta() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="etiqueta" for="e-contacto">Contacto</label>
                    <select id="e-contacto" wire:model.blur="enc.contacto_id" class="campo">
                        <option value="">— Sin contacto —</option>
                        @foreach ($contactos as $c)
                            <option value="{{ $c->id }}">{{ $c->nombre }}{{ $c->puesto ? ' ('.$c->puesto.')' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="etiqueta" for="e-fecha">Fecha</label>
                    <input id="e-fecha" type="date" wire:model.blur="enc.fecha" class="campo">
                    @error('enc.fecha') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="e-vigencia">Vigencia (días)</label>
                    <input id="e-vigencia" type="text" inputmode="numeric" wire:model.blur="enc.vigencia_dias" class="campo">
                    @error('enc.vigencia_dias') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="e-vendedor">Elaboró</label>
                    <select id="e-vendedor" wire:model.live="enc.vendedor_id" class="campo">
                        @foreach ($vendedores as $v)
                            <option value="{{ $v->id }}">{{ $v->name }}</option>
                        @endforeach
                    </select>
                    @error('enc.vendedor_id') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="etiqueta" for="e-pago">Forma de pago</label>
                    <input id="e-pago" type="text" wire:model.blur="enc.forma_pago" class="campo" placeholder="Contado, Crédito 30 días…">
                </div>
                <div class="sm:col-span-2">
                    <label class="etiqueta" for="e-entrega">Condiciones de entrega</label>
                    <input id="e-entrega" type="text" wire:model.blur="enc.condiciones_entrega" class="campo">
                </div>
                <div class="sm:col-span-2">
                    <label class="etiqueta" for="e-notas">Notas (salen en el PDF)</label>
                    <input id="e-notas" type="text" wire:model.blur="enc.notas" class="campo">
                </div>
            </div>
        </div>

        {{-- ===== Partidas ===== --}}
        <datalist id="lista-proveedores">
            @foreach ($proveedores as $nombre)
                <option value="{{ $nombre }}"></option>
            @endforeach
        </datalist>
        @foreach ($vendedoresPorProveedor as $provId => $vendedores)
            <datalist id="vendedores-{{ $provId }}">
                @foreach ($vendedores as $v)
                    <option value="{{ $v->nombre }}">{{ $v->telefono }}</option>
                @endforeach
            </datalist>
        @endforeach
        <datalist id="lista-unidades">
            @foreach (['PZA', 'KG', 'MT', 'LT', 'JGO', 'CAJA', 'PAQ', 'ROLLO', 'PAR', 'SERVICIO'] as $u)
                <option value="{{ $u }}"></option>
            @endforeach
        </datalist>

        <div class="space-y-3">
            @foreach ($partidas as $i => $p)
                @php($importe = round(((float) str_replace(',', '', $p['precio'] ?: 0)) * ((float) str_replace(',', '', $p['cantidad'] ?: 0)), 2))
                <div wire:key="partida-{{ $p['id'] }}"
                     x-data="partidaEditor({{ (int) $p['id'] }}, @js(\Illuminate\Support\Arr::only($p, ['cantidad', 'unidad', 'descripcion', 'proveedor', 'proveedor_vendedor', 'proveedor_telefono', 'link_compra', 'marca', 'modelo', 'costo_sin', 'costo_con', 'utilidad_pct', 'precio', 'tiempo_entrega'])), window.innerWidth >= 768 || {{ $p['descripcion'] === '' ? 'true' : 'false' }})"
                     x-on:partida-sugerencia.stop="aplicar($event.detail)"
                     class="rounded-xl bg-white shadow-sm {{ $p['incluir'] ? '' : 'opacity-60' }} {{ $p['entrega_lenta'] ? 'border-l-4 border-naranja' : '' }}">
                    <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3">
                        <input type="checkbox" wire:model.live="partidas.{{ $i }}.incluir" class="h-6 w-6 rounded border-slate-300" title="Incluir en el PDF y los totales" aria-label="Incluir partida {{ $i + 1 }}">
                        <button type="button" @click="abierta = !abierta" class="flex min-w-0 flex-1 items-center gap-3 text-left">
                            @if ($p['imagen_path'])
                                <img src="{{ Storage::disk('public')->url($p['imagen_path']) }}" alt="" class="h-10 w-10 shrink-0 rounded border border-slate-200 object-cover">
                            @endif
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-marino">{{ $i + 1 }}. {{ $p['descripcion'] !== '' ? $p['descripcion'] : 'Partida nueva' }}</span>
                                <span class="block text-xs text-slate-500">{{ $p['cantidad'] }} {{ $p['unidad'] }} · ${{ number_format($importe, 2) }}</span>
                            </span>
                        </button>
                        <div class="flex items-center gap-1">
                            <button type="button" wire:click="subir({{ $i }})" @disabled($i === 0) class="btn-secundario !min-h-10 !px-3 !py-1" aria-label="Subir">↑</button>
                            <button type="button" wire:click="bajar({{ $i }})" @disabled($loop->last) class="btn-secundario !min-h-10 !px-3 !py-1" aria-label="Bajar">↓</button>
                            <button type="button" wire:click="duplicarPartida({{ $i }})" class="btn-secundario !min-h-10 !px-3 !py-1" title="Duplicar partida">⧉</button>
                            <button type="button" wire:click="borrarPartida({{ $i }})" wire:confirm="¿Borrar esta partida?" class="btn-secundario !min-h-10 !px-3 !py-1 !text-red-600" title="Borrar partida">✕</button>
                        </div>
                    </div>

                    <div x-show="abierta" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
                        <div class="lg:col-span-1">
                            <label class="etiqueta">Cantidad</label>
                            <input type="text" inputmode="decimal" x-model="f.cantidad" x-on:input="editar('cantidad')" x-on:focus="enfocado = 'cantidad'" x-on:blur="salir('cantidad')" name="partida_{{ $i }}_cantidad" autocomplete="off" class="campo">
                        </div>
                        <div class="lg:col-span-1">
                            <label class="etiqueta">Unidad</label>
                            <input type="text" list="lista-unidades" x-model="f.unidad" x-on:input="editar('unidad')" x-on:focus="enfocado = 'unidad'" x-on:blur="salir('unidad')" name="partida_{{ $i }}_unidad" autocomplete="off" class="campo uppercase">
                        </div>
                        <div class="sm:col-span-2 lg:col-span-4">
                            <label class="etiqueta">Descripción</label>
                            <x-sugerencias-descripcion :indice="$i" :cliente-id="$cot->cliente_id" />
                            @if (! empty($p['articulo_id']))
                                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm" data-ligada-catalogo>
                                    <a href="{{ route('articulos.ver', $p['articulo_id']) }}" target="_blank" rel="noopener" class="rounded-full bg-green-100 px-2.5 py-0.5 font-semibold text-green-800 hover:underline">✓ Del catálogo</a>
                                    <button type="button" wire:click="desligarArticulo({{ (int) $p['id'] }})" class="text-slate-600 underline">Desligar</button>
                                </p>
                            @endif
                        </div>

                        <div class="lg:col-span-2">
                            <label class="etiqueta">Proveedor</label>
                            <input type="text" list="lista-proveedores" x-model="f.proveedor" x-on:input="editar('proveedor'); $nextTick(() => elegidoDeLista('proveedor', $el))" x-on:focus="enfocado = 'proveedor'" x-on:blur="salir('proveedor')" name="partida_{{ $i }}_proveedor" autocomplete="off" class="campo" placeholder="Elige o escribe uno nuevo">
                        </div>
                        <div>
                            <label class="etiqueta">Vendedor del proveedor</label>
                            <input type="text" @if ($p['proveedor_id']) list="vendedores-{{ $p['proveedor_id'] }}" @endif x-model="f.proveedor_vendedor" x-on:input="editar('proveedor_vendedor'); $nextTick(() => elegidoDeLista('proveedor_vendedor', $el))" x-on:focus="enfocado = 'proveedor_vendedor'" x-on:blur="salir('proveedor_vendedor')" name="partida_{{ $i }}_proveedor_vendedor" autocomplete="off" class="campo">
                        </div>
                        <div>
                            <label class="etiqueta">Teléfono / WhatsApp del vendedor</label>
                            <input type="tel" inputmode="tel" x-model="f.proveedor_telefono" x-on:input="editar('proveedor_telefono')" x-on:focus="enfocado = 'proveedor_telefono'" x-on:blur="salir('proveedor_telefono')" name="partida_{{ $i }}_proveedor_telefono" autocomplete="off" class="campo" placeholder="Desde donde mandó la cotización">
                        </div>
                        <div class="sm:col-span-2 lg:col-span-4">
                            <label class="etiqueta" for="link-{{ $i }}">Link de compra</label>
                            <div class="flex gap-2">
                                <input id="link-{{ $i }}" type="text" inputmode="url" x-model="f.link_compra" x-on:input="editar('link_compra')" x-on:focus="enfocado = 'link_compra'" x-on:blur="salir('link_compra')" name="partida_{{ $i }}_link_compra" autocomplete="off" class="campo" placeholder="https://articulo.mercadolibre.com.mx/…">
                                @if (preg_match('#^https?://#i', trim((string) ($p['link_compra'] ?? ''))))
                                    <a href="{{ trim($p['link_compra']) }}" target="_blank" rel="noopener noreferrer" class="btn-secundario shrink-0">Abrir</a>
                                @endif
                            </div>
                        </div>
                        <div>
                            <label class="etiqueta">Marca</label>
                            <input type="text" x-model="f.marca" x-on:input="editar('marca')" x-on:focus="enfocado = 'marca'" x-on:blur="salir('marca')" name="partida_{{ $i }}_marca" autocomplete="off" class="campo">
                        </div>
                        <div>
                            <label class="etiqueta">Modelo / SKU</label>
                            <input type="text" x-model="f.modelo" x-on:input="editar('modelo')" x-on:focus="enfocado = 'modelo'" x-on:blur="salir('modelo')" name="partida_{{ $i }}_modelo" autocomplete="off" class="campo">
                        </div>

                        <div>
                            <label class="etiqueta">Costo sin IVA</label>
                            <input type="text" inputmode="decimal" x-model="f.costo_sin" x-on:input="editar('costo_sin')" x-on:focus="enfocado = 'costo_sin'" x-on:blur="salir('costo_sin')" name="partida_{{ $i }}_costo_sin" autocomplete="off" class="campo">
                        </div>
                        <div>
                            <label class="etiqueta">Costo con IVA</label>
                            <input type="text" inputmode="decimal" x-model="f.costo_con" x-on:input="editar('costo_con')" x-on:focus="enfocado = 'costo_con'" x-on:blur="salir('costo_con')" name="partida_{{ $i }}_costo_con" autocomplete="off" class="campo">
                        </div>
                        <div>
                            <label class="etiqueta">Utilidad %</label>
                            <input type="text" inputmode="decimal" x-model="f.utilidad_pct" x-on:input="editar('utilidad_pct')" x-on:focus="enfocado = 'utilidad_pct'" x-on:blur="salir('utilidad_pct')" name="partida_{{ $i }}_utilidad_pct" autocomplete="off" class="campo">
                        </div>
                        <div>
                            <label class="etiqueta">Precio sin IVA</label>
                            <input type="text" inputmode="decimal" x-model="f.precio" x-on:input="editar('precio')" x-on:focus="enfocado = 'precio'" x-on:blur="salir('precio')" name="partida_{{ $i }}_precio" autocomplete="off" class="campo">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="etiqueta">Tiempo de entrega</label>
                            <input type="text" x-model="f.tiempo_entrega" x-on:input="editar('tiempo_entrega')" x-on:focus="enfocado = 'tiempo_entrega'" x-on:blur="salir('tiempo_entrega')" name="partida_{{ $i }}_tiempo_entrega" autocomplete="off" class="campo" placeholder="Inmediata, 3-5 días hábiles…">
                        </div>

                        <div class="flex items-center sm:col-span-2 lg:col-span-2">
                            <label class="flex items-center gap-2 text-base">
                                <input type="checkbox" wire:model.live="partidas.{{ $i }}.entrega_lenta" class="h-5 w-5 rounded border-slate-300">
                                Entrega lenta (naranja en el PDF)
                            </label>
                        </div>
                        <div class="sm:col-span-2 lg:col-span-4">
                            <label class="etiqueta">Imagen (JPG, PNG o WEBP, máx. 5 MB)</label>
                            <div class="flex flex-wrap items-start gap-3"
                                 x-data="{ subiendo: false, aviso: '', activo: false,
                                    subir(file) {
                                        if (!file || !file.type.startsWith('image/')) { this.aviso = 'Eso no es una imagen. Usa la Herramienta de recortes y vuelve a pegar.'; setTimeout(() => this.aviso = '', 5000); return; }
                                        this.aviso = ''; this.subiendo = true;
                                        $wire.upload('imagenes.{{ $i }}', file, () => this.subiendo = false, () => { this.subiendo = false; this.aviso = 'No se pudo subir la imagen.'; }, () => {});
                                    } }">
                                <div tabindex="0" role="button" aria-label="Recuadro de imagen: pega con Ctrl+V, arrastra o haz doble clic (en celular, un toque)"
                                     x-on:click="matchMedia('(pointer: coarse)').matches ? $refs.archivo.click() : $el.focus()"
                                     x-on:dblclick="$refs.archivo.click()"
                                     x-on:focus="activo = true" x-on:blur="activo = false"
                                     x-on:paste.prevent="const it = [...($event.clipboardData?.items || [])].find(t => t.type.startsWith('image/')); subir(it ? it.getAsFile() : null)"
                                     x-on:dragover.prevent="activo = true" x-on:dragleave="activo = false"
                                     x-on:drop.prevent="activo = false; subir([...($event.dataTransfer?.files || [])].find(f => f.type.startsWith('image/')) || null)"
                                     x-bind:class="activo ? 'border-orange-500 ring-2 ring-orange-300 bg-orange-50' : 'border-slate-300'"
                                     class="flex h-24 w-24 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded border-2 border-dashed p-1 text-center text-xs leading-tight text-slate-500 outline-none">
                                    <span x-show="subiendo" class="text-slate-600">Subiendo…</span>
                                    <span x-show="!subiendo" class="flex h-full w-full items-center justify-center">
                                        @if ($p['imagen_path'])
                                            <img src="{{ Storage::disk('public')->url($p['imagen_path']) }}" alt="" class="h-full w-full object-cover">
                                        @else
                                            <span>📷 Imagen</span>
                                        @endif
                                    </span>
                                </div>
                                <div class="flex flex-col items-start gap-2">
                                    <input type="file" x-ref="archivo" wire:model="imagenes.{{ $i }}" accept="image/jpeg,image/png,image/webp" class="block text-sm">
                                    <button type="button" wire:click="abrirCatalogo({{ $i }})" class="btn-secundario !min-h-10">Buscar en catálogo</button>
                                    @if ($p['imagen_path'])
                                        <button type="button" wire:click="quitarImagen({{ $i }})" class="btn-secundario !min-h-10">Quitar imagen</button>
                                    @endif
                                    <span wire:loading wire:target="imagenes.{{ $i }}" class="text-sm text-slate-500">Subiendo…</span>
                                    <p x-show="aviso" x-text="aviso" class="text-sm text-red-600"></p>
                                </div>
                            </div>
                            @error('imagenes.'.$i) <p class="error-campo">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($catalogoPara !== null)
            {{-- Buscar en catálogo: elegir la imagen de un producto ya guardado --}}
            <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 sm:items-center sm:p-4" x-data x-on:keydown.escape.window="$wire.cerrarCatalogo()">
                <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-t-xl bg-white shadow-xl sm:rounded-xl" role="dialog" aria-label="Buscar en catálogo">
                    <div class="flex items-center gap-3 border-b border-slate-200 p-4">
                        <h2 class="flex-1 text-lg font-semibold text-marino">Buscar en catálogo · partida {{ $catalogoPara + 1 }}</h2>
                        <button type="button" wire:click="cerrarCatalogo" class="btn-secundario !min-h-10 !px-3" aria-label="Cerrar">✕</button>
                    </div>
                    <div class="p-4 pb-2">
                        <input type="search" wire:model.live.debounce.300ms="buscarCatalogo" placeholder="Busca por descripción o modelo" aria-label="Buscar en catálogo" class="campo" autofocus>
                    </div>
                    <div class="overflow-y-auto p-4 pt-2">
                        @forelse ($catalogo as $f)
                            @if ($loop->first) <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4"> @endif
                            <button type="button" wire:key="cat-{{ $f->id }}" wire:click="usarDelCatalogo({{ $f->id }})"
                                    class="flex flex-col overflow-hidden rounded-lg border border-slate-200 text-left hover:border-naranja hover:ring-2 hover:ring-orange-200">
                                <span class="flex h-28 items-center justify-center bg-slate-50 p-1">
                                    <img src="{{ Storage::disk('public')->url($f->imagen_path) }}" alt="" loading="lazy" class="max-h-full max-w-full object-contain">
                                </span>
                                <span class="line-clamp-3 p-2 text-xs font-semibold text-marino">{{ $f->descripcion }}</span>
                                @if ($f->modelo)
                                    <span class="px-2 pb-2 font-mono text-xs text-slate-600">{{ $f->modelo }}</span>
                                @endif
                            </button>
                            @if ($loop->last) </div> @endif
                        @empty
                            <p class="py-6 text-center text-base text-slate-500">Sin resultados</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        {{-- Barra fija abajo: totales, aviso de guardado y "+ Agregar partida" sin subir y bajar en cotizaciones largas. --}}
        <div class="sticky bottom-0 z-20 -mx-4 mt-4 border-t border-slate-200 bg-white/95 px-4 py-2 shadow-[0_-4px_12px_rgba(0,0,0,0.08)] backdrop-blur sm:mx-0 sm:rounded-xl sm:border" data-barra-totales>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <button type="button" wire:click="agregarPartida" class="btn-primario !min-h-11 shrink-0">+ Agregar partida</button>
                <dl class="flex flex-1 flex-wrap items-baseline gap-x-4 gap-y-1 text-sm">
                    <div class="flex items-baseline gap-1"><dt class="text-slate-600">Subtotal</dt><dd class="font-bold text-marino">${{ number_format($totales['venta'], 2) }}</dd></div>
                    <div class="flex items-baseline gap-1"><dt class="text-slate-600">Utilidad</dt><dd class="font-bold text-marino">{{ $totales['utilidad_pct'] !== null ? number_format($totales['utilidad_pct'], 1).'%' : '—' }}</dd></div>
                    <div class="flex items-baseline gap-1"><dt class="text-slate-600">Total con IVA</dt><dd class="text-base font-extrabold text-naranja">${{ number_format($totales['total'], 2) }}</dd></div>
                </dl>
                <p x-data class="shrink-0 text-sm font-semibold" role="status" aria-live="polite">
                    <span x-show="$store.guardado.pendientes" x-cloak class="text-slate-600">Guardando…</span>
                    <span x-show="!$store.guardado.pendientes && $store.guardado.error" x-cloak class="text-red-700">No se guardó el último cambio</span>
                    <span x-show="!$store.guardado.pendientes && !$store.guardado.error && $store.guardado.listo" x-cloak class="text-green-700">✓ Guardado</span>
                </p>
            </div>
        </div>

        {{-- ===== Totales (solo pantalla) ===== --}}
        <div class="mt-6 rounded-xl bg-marino p-5 text-white shadow-sm">
            <p class="mb-3 text-xs uppercase tracking-wide text-white/70">{{ $totales['incluidas'] }} de {{ count($partidas) }} partidas incluidas</p>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-sm text-white/70">Venta (subtotal)</dt><dd class="text-xl font-bold">${{ number_format($totales['venta'], 2) }}</dd></div>
                <div><dt class="text-sm text-white/70">Costo total</dt><dd class="text-xl font-bold">${{ number_format($totales['costo'], 2) }}</dd></div>
                <div><dt class="text-sm text-white/70">Utilidad</dt><dd class="text-xl font-bold">${{ number_format($totales['utilidad'], 2) }}</dd></div>
                <div><dt class="text-sm text-white/70">Utilidad %</dt><dd class="text-xl font-bold">{{ $totales['utilidad_pct'] !== null ? number_format($totales['utilidad_pct'], 2).'%' : '—' }}</dd></div>
            </dl>
            <div class="mt-4 flex flex-wrap gap-x-8 gap-y-1 border-t border-white/20 pt-3 text-base">
                <span>IVA: <strong>${{ number_format($totales['iva'], 2) }}</strong></span>
                <span>Total con IVA: <strong class="text-naranja">${{ number_format($totales['total'], 2) }}</strong></span>
            </div>
        </div>
    @endif
</div>

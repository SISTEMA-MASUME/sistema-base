@php
    $dinero = fn ($v) => '$'.number_format((float) $v, 2);
    $hace = fn ($f) => $f->isToday() ? 'hoy' : 'hace '.(int) $f->copy()->startOfDay()->diffInDays(today()).' días';
@endphp
<div class="space-y-4">
    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif
    @if ($error)
        <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
    @endif

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex gap-2" role="tablist">
            @foreach (['pendientes' => 'Por pagar ('.$grupos->sum(fn ($g) => $g['deudas']->count()).')', 'pagadas' => 'Pagadas'] as $clave => $texto)
                <button type="button" role="tab" wire:click="$set('pestana', '{{ $clave }}')" aria-selected="{{ $pestana === $clave ? 'true' : 'false' }}"
                        class="min-h-11 rounded-lg px-4 text-base font-semibold {{ $pestana === $clave ? 'bg-marino text-white' : 'bg-white text-slate-700 shadow-sm' }}">{{ $texto }}</button>
            @endforeach
        </div>
        <button type="button" wire:click="abrirAgregar" class="btn-secundario">+ Agregar deuda a mano</button>
    </div>

    @if ($agregando)
        <form wire:submit="agregar" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-3" data-agregar-deuda>
            <h2 class="text-lg font-semibold text-marino sm:col-span-3">{{ $editandoId ? 'Corregir deuda' : 'Anotar una deuda que ya traes' }}</h2>
            <div class="sm:col-span-2">
                <label class="etiqueta" for="a-prov">Proveedor</label>
                <input id="a-prov" type="text" list="lista-prov-pagar" wire:model="aProveedor" class="campo" autocomplete="off" placeholder="Escribe y elige de la lista">
                <datalist id="lista-prov-pagar">@foreach ($proveedores as $p)<option value="{{ $p }}"></option>@endforeach</datalist>
                @error('aProveedor') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="etiqueta" for="a-folio">Factura (folio)</label>
                <input id="a-folio" type="text" wire:model="aFolio" class="campo" maxlength="60">
                @error('aFolio') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="etiqueta" for="a-fecha">Fecha de la factura</label>
                <input id="a-fecha" type="date" wire:model="aFecha" class="campo">
                @error('aFecha') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="etiqueta" for="a-vence">Vence (opcional)</label>
                <input id="a-vence" type="date" wire:model="aVence" class="campo">
            </div>
            <div>
                <label class="etiqueta" for="a-imp">Importe con IVA</label>
                <input id="a-imp" type="text" inputmode="decimal" wire:model="aImporte" class="campo" placeholder="Ej. 17417">
                @error('aImporte') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-3">
                <label class="etiqueta" for="a-desc">Nota (opcional)</label>
                <input id="a-desc" type="text" wire:model="aDescripcion" class="campo" maxlength="255">
            </div>
            <div class="flex flex-col gap-2 sm:col-span-3 sm:flex-row">
                <button type="submit" class="btn-primario">{{ $editandoId ? 'Guardar cambios' : 'Anotar deuda' }}</button>
                <button type="button" wire:click="$set('agregando', false)" class="btn-secundario">Cancelar</button>
            </div>
        </form>
    @endif

    @if ($pestana === 'pendientes')
        <section class="grid gap-3 sm:grid-cols-3" aria-label="Resumen">
            <div class="rounded-2xl bg-marino p-4 text-white shadow-sm">
                <p class="text-sm font-semibold text-indigo-200">Debo a proveedores</p>
                <p class="text-3xl font-extrabold">{{ $dinero($total) }}</p>
                <p class="text-sm text-indigo-100">{{ $grupos->sum(fn ($g) => $g['deudas']->count()) }} compras a crédito · {{ $grupos->count() }} {{ $grupos->count() === 1 ? 'proveedor' : 'proveedores' }}</p>
            </div>
            <div class="rounded-2xl bg-white p-4 shadow-sm">
                <p class="text-sm font-semibold text-slate-600">La más vieja</p>
                @if ($masVieja)
                    <p class="text-3xl font-extrabold text-orange-700">{{ $hace($masVieja->fecha) }}</p>
                    <p class="text-sm text-slate-600">{{ $masVieja->proveedor->nombre }} · {{ $masVieja->folio ?: 'sin folio' }}@if ($masVieja->vence) · vence {{ $masVieja->vence->format('d/m/Y') }}@endif</p>
                @else
                    <p class="text-3xl font-extrabold text-green-700">—</p>
                    <p class="text-sm text-slate-600">No le debes a nadie.</p>
                @endif
            </div>
            <div class="rounded-2xl bg-white p-4 shadow-sm">
                <p class="text-sm font-semibold text-slate-600">Pagado este mes</p>
                <p class="text-3xl font-extrabold text-green-700">{{ $dinero($pagadoMes) }}</p>
                <p class="text-sm text-slate-600">Cada pago queda con su comprobante.</p>
            </div>
        </section>

        @if ($pagandoProveedor)
            <form wire:submit="guardarPago" class="space-y-3 rounded-xl border-2 border-green-700 bg-white p-4 shadow-sm" data-registrar-pago>
                <h2 class="text-lg font-bold text-green-800">Registrar pago a {{ $pagandoNombre }} · {{ $pagando->count() }} {{ $pagando->count() === 1 ? 'factura' : 'facturas' }} · {{ $dinero($pagando->sum('importe')) }}</h2>
                <p class="text-sm text-slate-600">{{ $pagando->map(fn ($d) => ($d->folio ?: 'sin folio').' ('.$dinero($d->importe).')')->implode(', ') }}</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="etiqueta" for="p-fecha">Fecha del pago</label>
                        <input id="p-fecha" type="date" wire:model="pFecha" class="campo">
                        @error('pFecha') <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="etiqueta" for="p-cuenta">¿De qué cuenta salió?</label>
                        <select id="p-cuenta" wire:model="pCuenta" class="campo">
                            <option value="">— No anotar —</option>
                            @foreach ($cuentas as $c)
                                <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="etiqueta" for="p-ref">Referencia (opcional)</label>
                        <input id="p-ref" type="text" wire:model="pReferencia" maxlength="120" class="campo" placeholder="Folio de la transferencia">
                    </div>
                </div>
                <div x-data="{ activo: false, subiendo: false, falla: '', nombre: '',
                        subir(f) {
                            if (!f) return;
                            this.falla = ''; this.subiendo = true; this.nombre = f.name || 'captura';
                            $wire.upload('pComprobante', f, () => { this.subiendo = false }, () => { this.subiendo = false; this.nombre = ''; this.falla = 'No se pudo subir el archivo. Revisa tu internet e inténtalo otra vez; si es foto de iPhone, mándala como captura o PDF.' });
                        } }"
                     x-on:dragover.prevent="activo = true" x-on:dragleave="activo = false"
                     x-on:drop.prevent="activo = false; subir($event.dataTransfer?.files?.[0])"
                     x-on:paste.window="const it = [...($event.clipboardData?.items || [])].find(t => t.kind === 'file'); if (it) { $event.preventDefault(); subir(it.getAsFile()); }"
                     x-bind:class="activo ? 'border-green-700 bg-green-100' : 'border-green-300 bg-green-50'"
                     class="rounded-xl border-2 border-dashed p-4 text-center" data-comprobante>
                    <label for="p-comp" class="cursor-pointer text-base font-semibold text-green-900">Comprobante: arrastra el PDF o la captura, pégala con Ctrl+V o toca aquí</label>
                    <input id="p-comp" type="file" x-on:change="subir($event.target.files[0]); $event.target.value = ''" accept="application/pdf,image/*" class="mt-2 block w-full text-sm">
                    <p x-show="subiendo" class="mt-1 text-sm text-slate-600" role="status">Subiendo <span x-text="nombre"></span>…</p>
                    <p x-show="falla" x-text="falla" class="error-campo mt-1" role="alert"></p>
                    @if ($pComprobante && ! $errors->has('pComprobante'))
                        <p class="mt-1 text-sm font-semibold text-green-800">✓ Listo para guardar: {{ $pComprobante->getClientOriginalName() }}</p>
                    @endif
                    @error('pComprobante') <p class="error-campo">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" wire:loading.attr="disabled" class="btn bg-green-700 font-bold text-white hover:bg-green-800">Guardar pago y quitar la deuda</button>
                    <button type="button" wire:click="cancelarPago" class="btn-secundario">Cancelar</button>
                </div>
            </form>
        @endif

        @forelse ($grupos as $g)
            <section wire:key="prov-{{ $g['proveedor']->id }}" class="space-y-3 rounded-xl bg-white p-4 shadow-sm" aria-label="{{ $g['proveedor']->nombre }}">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-xl font-extrabold text-marino">{{ $g['proveedor']->nombre }}</h2>
                        <p class="text-sm text-slate-600">{{ $g['deudas']->count() }} {{ $g['deudas']->count() === 1 ? 'compra' : 'compras' }} · la más vieja de {{ $hace($g['vieja']) }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <p class="text-2xl font-extrabold">{{ $dinero($g['total']) }}</p>
                        <button type="button" wire:click="pagarMarcadas({{ $g['proveedor']->id }})" class="btn bg-green-700 text-white hover:bg-green-800">Pagar marcadas</button>
                    </div>
                </div>
                <ul class="divide-y divide-slate-100">
                    @foreach ($g['deudas'] as $d)
                        @php $marcada = in_array((string) $d->id, $marcadas, true); @endphp
                        <li wire:key="deuda-{{ $d->id }}" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center {{ $marcada ? 'bg-green-50' : '' }}">
                            <label class="flex min-w-0 flex-1 items-start gap-3">
                                <input type="checkbox" value="{{ $d->id }}" wire:model.live="marcadas" class="mt-1 h-6 w-6 shrink-0 rounded border-slate-300" aria-label="Marcar {{ $d->folio ?: 'deuda' }}">
                                <span class="min-w-0">
                                    <span class="block font-bold">{{ $d->folio ?: 'Sin folio' }}
                                        @if ($d->orden)
                                            <a href="{{ route('ordenes-proveedor.ver', $d->orden) }}" class="ml-1 text-sm font-semibold text-marino underline">ver OC</a>
                                        @endif
                                    </span>
                                    <span class="block text-sm text-slate-600">
                                        {{ $d->descripcion ?: ($d->orden ? '' : 'Agregada a mano') }}
                                        · {{ $d->fecha->format('d/m/Y') }} ({{ $hace($d->fecha) }})
                                        @if ($d->vence)
                                            · <span class="{{ $d->vence->isPast() && ! $d->vence->isToday() ? 'font-semibold text-red-700' : ($d->vence->lte(today()->addDays(7)) ? 'font-semibold text-orange-700' : '') }}">vence {{ $d->vence->format('d/m/Y') }}</span>
                                        @endif
                                    </span>
                                </span>
                            </label>
                            <div class="flex items-center justify-between gap-3 pl-9 sm:pl-0">
                                <span class="text-lg font-extrabold">{{ $dinero($d->importe) }}</span>
                                <button type="button" wire:click="pagarUna({{ $d->id }})" class="btn-secundario !min-h-10 !text-green-800">Ya la pagué</button>
                                @unless ($d->orden_proveedor_id)
                                    <button type="button" wire:click="editar({{ $d->id }})" class="text-sm text-marino underline">Editar</button>
                                    <button type="button" wire:click="quitar({{ $d->id }})" wire:confirm="¿Quitar esta deuda? Úsalo solo si se anotó por error." class="text-sm text-slate-500 underline">Quitar</button>
                                @endunless
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="rounded-xl bg-white p-8 text-center text-slate-600 shadow-sm">No le debes nada a ningún proveedor.</div>
        @endforelse
    @else
        <div class="space-y-3">
            @forelse ($pagos as $p)
                <article wire:key="pago-{{ $p->id }}" class="rounded-xl bg-white p-4 shadow-sm {{ $p->anulado_at ? 'opacity-60' : '' }}">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-lg font-bold text-marino">{{ $p->proveedor->nombre }} · {{ $dinero($p->monto) }}
                                @if ($p->anulado_at)<span class="ml-1 rounded bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-700">Anulado</span>@endif
                            </p>
                            <p class="text-sm text-slate-600">
                                Pagado el {{ $p->fecha->format('d/m/Y') }}@if ($p->cuenta) desde {{ $p->cuenta->nombre }}@endif
                                @if ($p->referencia) · ref. {{ $p->referencia }}@endif · lo registró {{ $p->user?->name }}
                            </p>
                            <p class="text-sm text-slate-700">Facturas: {{ collect($p->detalle)->map(fn ($d) => ($d['folio'] ?: 'sin folio').' ('.$dinero($d['importe']).')')->implode(', ') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($p->comprobante_path)
                                <a href="{{ route('finanzas.por-pagar.comprobante', $p) }}" target="_blank" class="btn-secundario !min-h-10">Ver comprobante</a>
                            @else
                                <span class="rounded bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800">Sin comprobante</span>
                            @endif
                            @unless ($p->anulado_at)
                                {{-- Subir (o cambiar) el comprobante después: si al pagar no se pudo. --}}
                                <div x-data="{ subiendo: false, falla: '' }" class="flex flex-col items-start" data-subir-comprobante>
                                    <label class="btn !min-h-10 cursor-pointer {{ $p->comprobante_path ? 'border border-slate-300 bg-white text-slate-700' : 'bg-green-700 text-white hover:bg-green-800' }}">
                                        <span x-show="!subiendo">{{ $p->comprobante_path ? 'Cambiar comprobante' : 'Subir comprobante' }}</span>
                                        <span x-show="subiendo">Subiendo…</span>
                                        <input type="file" accept="application/pdf,image/*" class="hidden"
                                               x-on:change="const f = $event.target.files[0]; $event.target.value = ''; if (!f) return; falla = ''; subiendo = true;
                                                   $wire.upload('comprobanteTarde', f, () => { $wire.guardarComprobante({{ $p->id }}).then(() => subiendo = false) }, () => { subiendo = false; falla = 'No se pudo subir. Inténtalo otra vez o mándalo como PDF o captura.' })">
                                    </label>
                                    <p x-show="falla" x-text="falla" class="error-campo" role="alert"></p>
                                </div>
                            @endunless
                            @unless ($p->anulado_at)
                                <button type="button" wire:click="anularPago({{ $p->id }})" wire:confirm="¿Anular este pago? Sus facturas regresan a Por pagar." class="text-sm text-slate-500 underline">Fue un error: anular</button>
                            @endunless
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-xl bg-white p-8 text-center text-slate-600 shadow-sm">Todavía no hay pagos registrados.</div>
            @endforelse
        </div>
    @endif
</div>
